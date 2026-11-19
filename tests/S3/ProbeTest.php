<?php
/**
 * The connection test, step by step.
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\S3;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Storage\S3\Bucket;
use SugarCommerce\Storage\S3\Cors\Policy;
use SugarCommerce\Storage\S3\Cors\Rule;
use SugarCommerce\Storage\S3\Probe;
use SugarCommerce\Storage\S3\ProbeCheck;
use SugarCommerce\Storage\S3\ProbeFault;
use SugarCommerce\Storage\S3\ProbeStep;
use SugarCommerce\Storage\S3\Signer;
use SugarCommerce\Storage\Tests\Support\FakeHttp;
use SugarCommerce\Storage\Tests\Support\FakeS3;
use WP_Error;

/**
 * Probe against FakeS3.
 */
final class ProbeTest extends TestCase {

	/**
	 * The site the uploader runs on.
	 */
	private const ORIGIN = 'https://shop.example';

	/**
	 * The bucket in memory.
	 *
	 * @var FakeS3
	 */
	private FakeS3 $s3;

	/**
	 * The bucket.
	 *
	 * @var Bucket
	 */
	private Bucket $bucket;

	/**
	 * A fresh bucket with no CORS.
	 */
	protected function setUp(): void {
		wp_test_reset();

		$this->s3     = FakeS3::install( 'bucket' );
		$this->bucket = new Bucket( new Signer( 'test-access', 'test-secret', 'storage.example.com', 'auto', 1700000000 ), 'bucket' );
	}

	/**
	 * Clear the responder.
	 */
	protected function tearDown(): void {
		FakeHttp::reset();
	}

	/**
	 * A bucket set up for browser uploads passes every step and is left as
	 * it was found.
	 */
	public function test_a_good_bucket_passes_everything(): void {
		$this->bucket->set_cors( Policy::for_browser_uploads( [ self::ORIGIN ] ) );

		$steps = ( new Probe() )->run( $this->bucket, [ self::ORIGIN ] );

		$this->assertTrue( Probe::passed( $steps ) );
		$this->assertSame( array_map( static fn( ProbeCheck $c ) => $c->value, ProbeCheck::cases() ), array_map( static fn( ProbeStep $s ) => $s->check->value, $steps ) );
		$this->assertSame( '', $steps[5]->note );
		$this->assertSame( [], $this->s3->objects );
		$this->assertSame( [], $this->s3->uploads );

		// The write carried the uploader's Origin, and the test object went
		// under the prefix.
		$write = array_values( array_filter( $this->s3->requests( 'PUT' ), static fn( array $put ): bool => ! isset( $put['query']['cors'] ) ) )[0];
		$this->assertSame( self::ORIGIN, $write['headers']['origin'] );
		$this->assertStringStartsWith( Probe::PREFIX, $write['key'] );
	}

	/**
	 * With no origin to ask about, the CORS step passes and says it was not
	 * checked.
	 */
	public function test_no_origins_skips_the_cors_question(): void {
		$steps = ( new Probe() )->run( $this->bucket );

		$this->assertTrue( Probe::passed( $steps ) );
		$this->assertNotSame( '', $steps[5]->note );
		$this->assertSame( [], $this->s3->requests( 'GET', 'cors' ) );
	}

	/**
	 * A secret that does not match is said to be the secret, and nothing
	 * after it is tried.
	 */
	public function test_a_wrong_secret_is_named(): void {
		$this->s3->fail( static fn() => true, FakeS3::error( 403, 'SignatureDoesNotMatch', 'The request signature we calculated does not match' ), 99 );

		$steps = ( new Probe() )->run( $this->bucket );

		$this->assertSame( ProbeFault::Secret, $steps[0]->fault );
		$this->assertSame( 'SignatureDoesNotMatch', $steps[0]->code );
		$this->assertStringContainsString( 'secret key does not match', $steps[0]->message );
		$this->assertSame( ProbeFault::Skipped, $steps[1]->fault );
		$this->assertCount( 1, $this->s3->log );
		$this->assertSame( $steps[0], Probe::failure( $steps ) );
	}

	/**
	 * An access key storage does not know is said to be the access key.
	 */
	public function test_an_unknown_access_key_is_named(): void {
		$this->s3->fail( static fn() => true, FakeS3::error( 403, 'InvalidAccessKeyId', 'The AWS Access Key Id you provided does not exist in our records.' ) );

		$this->assertSame( ProbeFault::Key, ( new Probe() )->run( $this->bucket )[0]->fault );
	}

	/**
	 * A bucket that is not there is said to be missing, by name.
	 */
	public function test_a_missing_bucket_is_named(): void {
		$other = new Bucket( $this->bucket->signer(), 'nope' );
		$step  = ( new Probe() )->run( $other )[0];

		$this->assertSame( ProbeFault::Missing, $step->fault );
		$this->assertStringContainsString( '"nope"', $step->message );
	}

	/**
	 * A bucket in another region names the region it is in.
	 */
	public function test_a_wrong_region_names_the_right_one(): void {
		$this->s3->fail( static fn() => true, FakeHttp::response( 301, '<Error><Code>PermanentRedirect</Code><Message>Wrong endpoint</Message></Error>', [ 'x-amz-bucket-region' => 'eu-west-2' ] ) );

		$step = ( new Probe() )->run( $this->bucket )[0];

		$this->assertSame( ProbeFault::Elsewhere, $step->fault );
		$this->assertSame( 'eu-west-2', $step->detail );
		$this->assertStringContainsString( 'Set the region to eu-west-2', $step->message );
	}

	/**
	 * The region can also come in the error document.
	 */
	public function test_a_region_from_the_error_document(): void {
		$this->s3->fail( static fn() => true, FakeHttp::response( 400, '<Error><Code>AuthorizationHeaderMalformed</Code><Message>The authorization header is malformed</Message><Region>us-west-2</Region></Error>' ) );

		$this->assertSame( 'us-west-2', ( new Probe() )->run( $this->bucket )[0]->detail );
	}

	/**
	 * Any other 400 is not taken for a region.
	 */
	public function test_another_400_is_a_failure(): void {
		$this->s3->fail( static fn() => true, FakeS3::error( 400, 'InvalidRequest', 'Bad' ) );

		$step = ( new Probe() )->run( $this->bucket )[0];

		$this->assertSame( ProbeFault::Failed, $step->fault );
		$this->assertStringContainsString( '400', $step->message );
	}

	/**
	 * No answer at all is said in terms of the address.
	 */
	public function test_no_answer_is_unreachable(): void {
		$this->s3->fail( static fn() => true, new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ) );

		$step = ( new Probe() )->run( $this->bucket )[0];

		$this->assertSame( ProbeFault::Unreachable, $step->fault );
		$this->assertStringContainsString( 'storage.example.com', $step->message );
		$this->assertStringContainsString( 'Could not resolve host', $step->detail );
	}

	/**
	 * Keys that may list but not write are told so, for the write.
	 */
	public function test_keys_that_may_not_write(): void {
		$this->s3->fail( static fn( $method ) => 'PUT' === $method, FakeS3::error( 403, 'AccessDenied', 'Access Denied' ) );

		$steps = ( new Probe() )->run( $this->bucket );

		$this->assertTrue( $steps[0]->ok() );
		$this->assertSame( ProbeFault::Refused, $steps[1]->fault );
		$this->assertStringContainsString( 'may not write to the bucket "bucket"', $steps[1]->message );
	}

	/**
	 * A file read back changed is a fault, and the test object is still
	 * removed.
	 */
	public function test_an_altered_read_still_cleans_up(): void {
		$this->s3->fail( static fn( $method, $key, $query, $headers ) => 'GET' === $method && [] === array_diff_key( $query, array_flip( [ 'X-Amz-Algorithm', 'X-Amz-Credential', 'X-Amz-Date', 'X-Amz-Expires', 'X-Amz-SignedHeaders', 'X-Amz-Signature' ] ) ), FakeHttp::response( 200, 'something else' ) );

		$steps = ( new Probe() )->run( $this->bucket );

		$this->assertSame( ProbeFault::Altered, $steps[2]->fault );
		$this->assertSame( ProbeFault::Skipped, $steps[3]->fault );
		$this->assertSame( [], $this->s3->objects );
	}

	/**
	 * Keys that may not start a multipart upload cannot send a big file.
	 */
	public function test_multipart_refused(): void {
		$this->s3->fail( static fn( $method, $key, $query ) => isset( $query['uploads'] ), FakeS3::error( 403, 'AccessDenied', 'Access Denied' ) );

		$step = ( new Probe() )->run( $this->bucket )[4];

		$this->assertSame( ProbeFault::Refused, $step->fault );
		$this->assertStringContainsString( 'may not upload large files to', $step->message );
	}

	/**
	 * A multipart upload that cannot be aborted is a fault: it would be left
	 * costing storage.
	 */
	public function test_multipart_that_cannot_be_aborted(): void {
		$this->s3->fail( static fn( $method, $key, $query ) => 'DELETE' === $method && isset( $query['uploadId'] ), FakeS3::error( 403, 'AccessDenied', 'Access Denied' ) );

		$step = ( new Probe() )->run( $this->bucket )[4];

		$this->assertSame( ProbeFault::Failed, $step->fault );
		$this->assertStringContainsString( 'not cancelled', $step->message );
	}

	/**
	 * A readable policy without ETag exposed fails, with the policy's reason.
	 */
	public function test_a_policy_that_hides_etag(): void {
		$this->bucket->set_cors( new Policy( [ new Rule( [ self::ORIGIN ], [ 'PUT' ] ) ] ) );

		$step = ( new Probe() )->run( $this->bucket, [ self::ORIGIN ] )[5];

		$this->assertSame( ProbeFault::CorsPolicy, $step->fault );
		$this->assertStringContainsString( 'ETag', $step->message );
	}

	/**
	 * A policy that will not take Content-MD5 passes, with a note.
	 */
	public function test_a_policy_without_checksums_is_a_note(): void {
		$this->bucket->set_cors( new Policy( [ new Rule( [ self::ORIGIN ], [ 'PUT' ], [ 'Content-Type' ], [ 'ETag' ] ) ] ) );

		$step = ( new Probe() )->run( $this->bucket, [ self::ORIGIN ] )[5];

		$this->assertTrue( $step->ok() );
		$this->assertStringContainsString( 'Content-MD5', $step->note );
	}

	/**
	 * Keys that may not read the policy are answered the way a browser is:
	 * by a preflight, and the write's exposed headers.
	 */
	public function test_the_preflight_when_the_policy_is_hidden(): void {
		$this->bucket->set_cors( Policy::for_browser_uploads( [ self::ORIGIN ] ) );
		$this->hide_policy();

		$steps = ( new Probe() )->run( $this->bucket, [ self::ORIGIN ] );

		$this->assertTrue( Probe::passed( $steps ) );
		$this->assertSame( '', $steps[5]->note );
		$this->assertCount( 2, $this->s3->requests( 'OPTIONS' ) );
	}

	/**
	 * A preflight refused names the origin.
	 */
	public function test_a_refused_preflight_names_the_origin(): void {
		$this->bucket->set_cors( Policy::for_browser_uploads( [ 'https://elsewhere.example' ] ) );
		$this->hide_policy();

		$step = ( new Probe() )->run( $this->bucket, [ self::ORIGIN ] )[5];

		$this->assertSame( ProbeFault::CorsOrigin, $step->fault );
		$this->assertSame( self::ORIGIN, $step->detail );
	}

	/**
	 * A preflight that passes while the write's answer hides ETag fails.
	 */
	public function test_a_hidden_etag_by_preflight(): void {
		$this->bucket->set_cors( new Policy( [ new Rule( [ self::ORIGIN ], [ 'PUT' ] ) ] ) );
		$this->hide_policy();

		$this->assertSame( ProbeFault::EtagHidden, ( new Probe() )->run( $this->bucket, [ self::ORIGIN ] )[5]->fault );
	}

	/**
	 * Content-MD5 refused in the preflight is a note.
	 */
	public function test_checksums_refused_by_preflight(): void {
		$this->bucket->set_cors( new Policy( [ new Rule( [ self::ORIGIN ], [ 'PUT' ], [ 'Content-Type' ], [ 'ETag' ] ) ] ) );
		$this->hide_policy();

		$step = ( new Probe() )->run( $this->bucket, [ self::ORIGIN ] )[5];

		$this->assertTrue( $step->ok() );
		$this->assertStringContainsString( 'Content-MD5', $step->note );
	}

	/**
	 * A step for a script carries its label and words.
	 */
	public function test_a_step_as_an_array(): void {
		$array = ( new ProbeStep( ProbeCheck::Write, ProbeFault::Refused, 403, '', 'No.', '', 'AccessDenied' ) )->to_array();

		$this->assertSame( 'write', $array['check'] );
		$this->assertFalse( $array['ok'] );
		$this->assertSame( 'refused', $array['fault'] );
		$this->assertSame( 'No.', $array['message'] );
		$this->assertNotSame( '', $array['label'] );
		$this->assertFalse( Probe::passed( [] ) );
	}

	/**
	 * The fault for each status and code.
	 */
	public function test_faults_from_answers(): void {
		$this->assertSame( ProbeFault::Unreachable, ProbeFault::of( 0, '' ) );
		$this->assertSame( ProbeFault::Refused, ProbeFault::of( 403, '' ) );
		$this->assertSame( ProbeFault::Refused, ProbeFault::of( 403, 'AccessDenied' ) );
		$this->assertSame( ProbeFault::Missing, ProbeFault::of( 404, '' ) );
		$this->assertSame( ProbeFault::Missing, ProbeFault::of( 404, 'NoSuchBucket' ) );
		$this->assertSame( ProbeFault::Elsewhere, ProbeFault::of( 301, '' ) );
		$this->assertSame( ProbeFault::Elsewhere, ProbeFault::of( 307, '' ) );
		$this->assertSame( ProbeFault::Elsewhere, ProbeFault::of( 400, '' ) );
		$this->assertSame( ProbeFault::Failed, ProbeFault::of( 400, 'InvalidArgument' ) );
		$this->assertSame( ProbeFault::Failed, ProbeFault::of( 500, '' ) );
		$this->assertSame( ProbeFault::Key, ProbeFault::of( 403, 'InvalidAccessKeyId' ) );
		$this->assertSame( ProbeFault::Secret, ProbeFault::of( 403, 'SignatureDoesNotMatch' ) );
	}

	/**
	 * Answer every request for the bucket's CORS policy with AccessDenied, as
	 * a key scoped to objects gets.
	 *
	 * @return void
	 */
	private function hide_policy(): void {
		$this->s3->fail( static fn( $method, $key, $query ) => 'GET' === $method && isset( $query['cors'] ), FakeS3::error( 403, 'AccessDenied', 'Access Denied' ), 99 );
	}
}
