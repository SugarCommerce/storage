<?php
/**
 * The bucket client: listing, batch deletes, copies in place, location and
 * removing CORS -- what the bucket browser asks of it.
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\S3;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Storage\S3\Account;
use SugarCommerce\Storage\S3\AddressingStyle;
use SugarCommerce\Storage\S3\Bucket;
use SugarCommerce\Storage\S3\Endpoint;
use SugarCommerce\Storage\S3\Method;
use SugarCommerce\Storage\S3\Provider;
use SugarCommerce\Storage\S3\StorageError;
use SugarCommerce\Storage\Tests\Support\FakeHttp;
use WP_Error;

/**
 * Bucket, against FakeHttp.
 */
final class BucketListingTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset();
	}

	private function bucket( string $name = 'files' ): Bucket {
		return new Bucket( Provider::R2->signer( 'key', 'secret', 'auto', 'acct1234' ), $name );
	}

	private static function fixture( string $name ): string {
		return (string) file_get_contents( __DIR__ . '/../fixtures/' . $name );
	}

	/* ─── list() ────────────────────────────────────────────────────── */

	public function test_a_page_is_read_in_the_s3_namespace(): void {
		FakeHttp::queue( 200, self::fixture( 'list-objects.xml' ) );

		$page = $this->bucket()->list( 'testing/' );

		$this->assertSame(
			array(
				'key'           => 'testing/Phonk - Demo Mix.mp3',
				'size'          => 7340032,
				'last_modified' => '2026-08-21T14:29:12.000Z',
				'etag'          => '9f1c2d3e4a5b6c7d8e9f0a1b2c3d4e5f',
				'storage_class' => 'STANDARD',
			),
			$page['objects'][0]
		);
		$this->assertSame( 'testing/Déjà vu.wav', $page['objects'][1]['key'] );
		$this->assertSame( array( 'testing/stems/' ), $page['prefixes'] );
		$this->assertFalse( $page['truncated'] );
		$this->assertSame( '', $page['next_token'] );
	}

	public function test_a_page_without_a_namespace_or_prolog_reads_the_same(): void {
		FakeHttp::queue( 200, '<ListBucketResult><IsTruncated>true</IsTruncated><NextContinuationToken>t2</NextContinuationToken><Contents><Key>a.txt</Key><Size>3</Size></Contents></ListBucketResult>' );

		$page = $this->bucket()->list();

		$this->assertSame( 'a.txt', $page['objects'][0]['key'] );
		$this->assertSame( 'STANDARD', $page['objects'][0]['storage_class'] );
		$this->assertTrue( $page['truncated'] );
		$this->assertSame( 't2', $page['next_token'] );
	}

	/**
	 * A token is only handed back when there is a next page to ask for.
	 */
	public function test_no_token_is_handed_back_on_the_last_page(): void {
		FakeHttp::queue( 200, '<ListBucketResult><IsTruncated>false</IsTruncated><NextContinuationToken>stale</NextContinuationToken></ListBucketResult>' );

		$this->assertSame( '', $this->bucket()->list()['next_token'] );
	}

	public function test_the_query_is_what_was_asked_for(): void {
		FakeHttp::queue( 200, self::fixture( 'list-objects-empty.xml' ) );

		$this->bucket()->list( 'my photos/', '', 'tok en', 5 );

		$url = FakeHttp::last()['url'];

		$this->assertStringContainsString( 'prefix=my%20photos%2F', $url );
		$this->assertStringContainsString( 'continuation-token=tok%20en', $url );
		$this->assertStringContainsString( 'max-keys=5', $url );
		$this->assertStringNotContainsString( 'delimiter', $url );
	}

	public function test_a_refused_listing_carries_storages_code(): void {
		FakeHttp::queue( 404, self::fixture( 'error-no-such-bucket.xml' ) );

		$error = $this->bucket()->list();

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 's3_list_failed', $error->get_error_code() );
		$this->assertSame( 'NoSuchBucket', $error->get_error_data()['error_code'] );
		$this->assertSame( 'The specified bucket does not exist', $error->get_error_data()['reason'] );
		$this->assertSame( 404, $error->get_error_data()['status'] );
	}

	public function test_a_200_that_is_not_xml_is_refused(): void {
		FakeHttp::queue( 200, 'upstream says hello' );

		$this->assertInstanceOf( WP_Error::class, $this->bucket()->list() );
	}

	public function test_no_answer_is_the_transports_own_error(): void {
		FakeHttp::queue_error();

		$this->assertSame( 'http_request_failed', $this->bucket()->list()->get_error_code() );
	}

	/* ─── all() ─────────────────────────────────────────────────────── */

	public function test_every_bucket_is_listed_from_the_service(): void {
		FakeHttp::queue( 200, self::fixture( 'list-buckets.xml' ) );

		$this->assertSame(
			array(
				array(
					'name'    => 'test-bucket',
					'created' => '2025-12-24T10:07:59.000Z',
				),
				array(
					'name'    => 'customer-backups',
					'created' => '2026-01-05T08:00:00.000Z',
				),
			),
			Bucket::all( Provider::R2->signer( 'key', 'secret', 'auto', 'acct1234' ) )
		);
		$this->assertSame( 'https://acct1234.r2.cloudflarestorage.com/', FakeHttp::last()['url'] );
	}

	/**
	 * Under virtual-hosted addressing the service is the endpoint itself:
	 * there is no bucket to put in front of it.
	 */
	public function test_the_service_is_addressed_without_a_bucket(): void {
		FakeHttp::queue( 200, self::fixture( 'list-buckets.xml' ) );

		Bucket::all( Account::aws( 'eu-west-2' )->signer( 'key', 'secret' ) );

		$this->assertSame( 'https://s3.eu-west-2.amazonaws.com/', FakeHttp::last()['url'] );
		$this->assertSame( 's3.eu-west-2.amazonaws.com', Endpoint::host( 's3.eu-west-2.amazonaws.com', '', AddressingStyle::VirtualHosted ) );
	}

	public function test_a_scoped_key_is_refused_with_its_status(): void {
		FakeHttp::queue( 403, self::fixture( 'error-access-denied-r2.xml' ) );

		$error = Bucket::all( Provider::R2->signer( 'key', 'secret', 'auto', 'acct1234' ) );

		$this->assertSame( 403, $error->get_error_data()['status'] );
		$this->assertSame( 'AccessDenied', $error->get_error_data()['error_code'] );
	}

	/* ─── delete_many() ─────────────────────────────────────────────── */

	public function test_many_keys_go_in_one_signed_request(): void {
		FakeHttp::queue( 200, '<DeleteResult><Deleted><Key>a &amp; b.txt</Key></Deleted><Error><Key>c.txt</Key><Code>AccessDenied</Code><Message>Access Denied</Message></Error></DeleteResult>' );

		$result = $this->bucket()->delete_many( array( 'a & b.txt', 'c.txt', 'c.txt' ) );

		$this->assertSame( array( 'a & b.txt' ), $result['deleted'] );
		$this->assertSame(
			array(
				array(
					'key'     => 'c.txt',
					'code'    => 'AccessDenied',
					'message' => 'Access Denied',
				),
			),
			$result['errors']
		);

		$request = FakeHttp::last();
		$headers = array_change_key_case( $request['args']['headers'] );

		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertStringEndsWith( '/files?delete=', $request['url'] );
		$this->assertStringContainsString( '<Key>a &amp; b.txt</Key>', $request['args']['body'] );
		$this->assertSame( 1, substr_count( $request['args']['body'], '<Key>c.txt</Key>' ), 'A key asked for twice is sent once.' );
		$this->assertSame( base64_encode( md5( $request['args']['body'], true ) ), $headers['content-md5'] );
		$this->assertStringContainsString( 'content-md5', $headers['authorization'] );
	}

	public function test_more_than_a_thousand_keys_are_split(): void {
		FakeHttp::respond_with( static fn(): array => FakeHttp::response( 200, '<DeleteResult/>' ) );

		$this->bucket()->delete_many( array_map( static fn( int $n ): string => 'k' . $n, range( 1, 2001 ) ) );

		$this->assertCount( 3, FakeHttp::requests() );
		$this->assertSame( 1000, substr_count( FakeHttp::requests()[0]['args']['body'], '<Object>' ) );
		$this->assertSame( 1, substr_count( FakeHttp::requests()[2]['args']['body'], '<Object>' ) );
	}

	/**
	 * A provider without DeleteObjects refuses the request whole -- sometimes
	 * inside a 200 -- and that is an error, so the caller can go key by key.
	 */
	public function test_a_request_refused_whole_is_an_error(): void {
		FakeHttp::queue( 501, '<Error><Code>NotImplemented</Code><Message>Not implemented</Message></Error>' );
		$this->assertSame( 'NotImplemented', $this->bucket()->delete_many( array( 'a' ) )->get_error_data()['error_code'] );

		FakeHttp::queue( 200, '<Error><Code>MalformedXML</Code></Error>' );
		$this->assertInstanceOf( WP_Error::class, $this->bucket()->delete_many( array( 'a' ) ) );
	}

	/* ─── copy() ────────────────────────────────────────────────────── */

	public function test_a_copy_in_place_signs_its_source_and_keeps_metadata(): void {
		FakeHttp::queue( 200, '<CopyObjectResult><ETag>"x"</ETag></CopyObjectResult>' );

		$this->assertTrue( $this->bucket()->copy( 'Drum Kits/old.zip', 'Drum Kits/new.zip' ) );

		$headers = array_change_key_case( FakeHttp::last()['args']['headers'] );

		$this->assertSame( '/files/Drum%20Kits/old.zip', $headers['x-amz-copy-source'] );
		$this->assertStringContainsString( 'x-amz-copy-source', $headers['authorization'] );
		$this->assertArrayNotHasKey( 'x-amz-metadata-directive', $headers, 'COPY, the default, keeps the type it had.' );
		$this->assertStringEndsWith( '/files/Drum%20Kits/new.zip', FakeHttp::last()['url'] );
	}

	public function test_a_copy_refused_inside_a_200_is_refused(): void {
		FakeHttp::queue( 200, '<Error><Code>InternalError</Code><Message>Try again</Message></Error>' );

		$error = $this->bucket()->copy( 'a.zip', 'b.zip' );

		$this->assertSame( 's3_copy_failed', $error->get_error_code() );
		$this->assertSame( 'InternalError', $error->get_error_data()['error_code'] );
	}

	public function test_a_traversal_source_is_never_sent(): void {
		$this->expectException( InvalidArgumentException::class );

		try {
			$this->bucket()->copy( 'a/../../b.zip', 'b.zip' );
		} finally {
			$this->assertSame( array(), FakeHttp::requests() );
		}
	}

	/* ─── head(), region(), delete_cors() ───────────────────────────── */

	/**
	 * "Not there" is an answer the caller has to be able to tell apart.
	 */
	public function test_a_refused_head_carries_its_status(): void {
		FakeHttp::queue( 404 );
		$this->assertSame( 404, $this->bucket()->head( 'a.zip' )->get_error_data()['status'] );

		FakeHttp::queue( 403 );
		$this->assertSame( 403, $this->bucket()->head( 'a.zip' )->get_error_data()['status'] );
	}

	public function test_the_region_is_read_from_its_root_element(): void {
		FakeHttp::queue( 200, '<?xml version="1.0" encoding="UTF-8"?><LocationConstraint xmlns="http://s3.amazonaws.com/doc/2006-03-01/">eu-west-2</LocationConstraint>' );
		$this->assertSame( 'eu-west-2', $this->bucket()->region() );
		$this->assertStringEndsWith( '/files?location=', FakeHttp::last()['url'] );

		// S3 says nothing for us-east-1.
		FakeHttp::queue( 200, '<LocationConstraint xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>' );
		$this->assertSame( 'us-east-1', $this->bucket()->region() );

		FakeHttp::queue( 403, self::fixture( 'error-access-denied-r2.xml' ) );
		$this->assertSame( 's3_location_failed', $this->bucket()->region()->get_error_code() );
	}

	public function test_cors_is_removed_and_a_refusal_says_why(): void {
		FakeHttp::queue( 204 );
		$this->assertTrue( $this->bucket()->delete_cors()['ok'] );
		$this->assertSame( 'DELETE', FakeHttp::last()['args']['method'] );
		$this->assertStringEndsWith( '/files?cors=', FakeHttp::last()['url'] );

		FakeHttp::queue( 403, self::fixture( 'error-access-denied-r2.xml' ) );
		$refused = $this->bucket()->delete_cors();

		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 403, $refused['status'] );
		$this->assertSame( 'AccessDenied', $refused['code'] );
		$this->assertSame( 'Access Denied', $refused['reason'] );
	}

	/* ─── Account::signer(), StorageError ───────────────────────────── */

	public function test_an_account_signs_for_its_own_endpoint_and_addressing(): void {
		$path    = Account::r2( 'abc123' )->signer( ' key ', 'secret' )->sign( Method::GET, 'files' );
		$virtual = Account::aws( 'eu-west-2' )->signer( 'key', 'secret' )->sign( Method::GET, 'files' );

		$this->assertSame( 'https://abc123.r2.cloudflarestorage.com/files', $path->url );
		$this->assertStringContainsString( 'Credential=key/', $path->headers['Authorization'], 'Pasted keys are trimmed.' );
		$this->assertSame( 'https://files.s3.eu-west-2.amazonaws.com/', $virtual->url );
	}

	public function test_a_refusal_keeps_storages_own_words(): void {
		$error = StorageError::refused( 'read', 403, '<Error><Code>AccessDenied</Code><Message>Access &amp; Denied</Message></Error>' );

		$this->assertSame( 'Access & Denied', $error->reason );
		$this->assertSame( 'Access & Denied', $error->to_wp_error()->get_error_data()['reason'] );
	}
}
