<?php
/**
 * Storage settings checked on save.
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\S3;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Storage\S3\Connection;
use SugarCommerce\Storage\S3\Provider;
use SugarCommerce\Storage\Tests\Support\FakeHttp;
use SugarCommerce\Storage\Tests\Support\FakeS3;
use WP_Error;

/**
 * Connection's shape checks, and its live check against FakeS3.
 */
final class ConnectionTest extends TestCase {

	/**
	 * An account id the shape Cloudflare's are.
	 */
	private const ACCOUNT = '0123456789abcdef0123456789abcdef';

	/**
	 * Fresh state.
	 */
	protected function setUp(): void {
		wp_test_reset();
	}

	/**
	 * Clear the responder.
	 */
	protected function tearDown(): void {
		FakeHttp::reset();
	}

	/**
	 * Good R2 settings, trimmed and lower-cased on the way in.
	 */
	public function test_r2_settings_are_taken_tidied(): void {
		$connection = Connection::from_array( $this->r2( [
			'bucket'     => ' My-Files ',
			'account_id' => strtoupper( self::ACCOUNT ),
			'endpoint'   => 'left.over.example',
		] ) );

		$this->assertInstanceOf( Connection::class, $connection );
		$this->assertSame( 'my-files', $connection->bucket );
		$this->assertSame( self::ACCOUNT, $connection->account_id );
		$this->assertSame( '', $connection->endpoint );
		$this->assertSame( self::ACCOUNT . '.r2.cloudflarestorage.com', $connection->host() );
		$this->assertSame( 'my-files', $connection->bucket()->name() );
	}

	/**
	 * Each value that cannot work names its field.
	 *
	 * @param array  $input What was typed.
	 * @param string $field The field to fix.
	 */
	#[DataProvider( 'problems' )]
	public function test_a_problem_names_its_field( array $input, string $field ): void {
		$error = Connection::from_array( $input );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 's3_connection_invalid', $error->get_error_code() );
		$this->assertSame( $field, $error->get_error_data()['field'] );
		$this->assertNotSame( '', $error->get_error_message() );
	}

	/**
	 * Settings that cannot work, and the field that is wrong.
	 *
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function problems(): array {
		$r2 = [
			'provider'   => 'r2',
			'bucket'     => 'files',
			'access_key' => 'access',
			'secret_key' => 'secret',
			'account_id' => self::ACCOUNT,
		];

		return [
			'no provider'          => [ [ 'provider' => 'nope' ] + $r2, 'provider' ],
			'short account id'     => [ [ 'account_id' => 'abc' ] + $r2, 'account_id' ],
			'account id with dots' => [ [ 'account_id' => 'abc.def.ghi.jkl' ] + $r2, 'account_id' ],
			'bucket in capitals'   => [ [ 'bucket' => 'a_b' ] + $r2, 'bucket' ],
			'bucket too short'     => [ [ 'bucket' => 'ab' ] + $r2, 'bucket' ],
			'spaced access key'    => [ [ 'access_key' => 'ab cd' ] + $r2, 'access_key' ],
			'empty secret'         => [ [ 'secret_key' => '' ] + $r2, 'secret_key' ],
			'r2 with a region'     => [ [ 'region' => 'eu-west-2' ] + $r2, 'region' ],
			'region with a slash'  => [ [ 'provider' => 'aws', 'region' => 'eu/west' ] + $r2, 'region' ],
			'dotted bucket on aws' => [ [ 'provider' => 'aws', 'bucket' => 'my.files' ] + $r2, 'bucket' ],
			'minio without host'   => [ [ 'provider' => 'minio' ] + $r2, 'endpoint' ],
			'minio with a path'    => [ [ 'provider' => 'minio', 'endpoint' => 'user@minio.local' ] + $r2, 'endpoint' ],
			'minio with a bad port' => [ [ 'provider' => 'minio', 'endpoint' => 'minio.local:99999' ] + $r2, 'endpoint' ],
		];
	}

	/**
	 * An endpoint pasted from a dashboard is cut to its host.
	 */
	public function test_a_typed_endpoint_is_cut_to_its_host(): void {
		$connection = Connection::from_array( [
			'provider'   => 'minio',
			'bucket'     => 'files',
			'access_key' => 'a',
			'secret_key' => 'b',
			'endpoint'   => 'https://MinIO.Local:9000/console/',
			'account_id' => 'ignored',
		] );

		$this->assertSame( 'minio.local:9000', $connection->endpoint );
		$this->assertSame( '', $connection->account_id );
	}

	/**
	 * Settings that work verify, and leave nothing behind.
	 */
	public function test_verify_passes_and_tidies(): void {
		$s3 = FakeS3::install( 'files' );

		$this->assertTrue( Connection::from_array( $this->r2() )->verify() );
		$this->assertSame( [], $s3->objects );
		$this->assertSame( [], $s3->uploads );
		$this->assertNotEmpty( $s3->requests( 'POST', 'uploads' ) );
	}

	/**
	 * A wrong secret is reported as the secret, on its field.
	 */
	public function test_verify_names_a_wrong_secret(): void {
		$s3 = FakeS3::install( 'files' );
		$s3->fail( static fn() => true, FakeS3::error( 403, 'SignatureDoesNotMatch', 'The request signature we calculated does not match' ), 99 );

		$error = Connection::from_array( $this->r2() )->verify();

		$this->assertSame( 's3_connection_secret', $error->get_error_code() );
		$this->assertSame( 'secret_key', $error->get_error_data()['field'] );
		$this->assertSame( 'reach', $error->get_error_data()['check'] );
		$this->assertCount( 6, $error->get_error_data()['steps'] );
	}

	/**
	 * A bucket that is not there is reported on the bucket field.
	 */
	public function test_verify_names_a_missing_bucket(): void {
		FakeS3::install( 'other' );

		$error = Connection::from_array( $this->r2() )->verify();

		$this->assertSame( 's3_connection_missing', $error->get_error_code() );
		$this->assertSame( 'bucket', $error->get_error_data()['field'] );
	}

	/**
	 * No answer from R2 points at the account id.
	 */
	public function test_verify_unreachable_points_at_the_account_id(): void {
		FakeHttp::respond_with( static fn() => new WP_Error( 'http_request_failed', 'cURL error 35: SSL connect error' ) );

		$error = Connection::from_array( $this->r2() )->verify();

		$this->assertSame( 's3_connection_unreachable', $error->get_error_code() );
		$this->assertSame( 'account_id', $error->get_error_data()['field'] );
		$this->assertStringContainsString( 'Account ID', $error->get_error_message() );
	}

	/**
	 * A shape problem is reported before anything is asked.
	 */
	public function test_verify_checks_the_shape_first(): void {
		$connection = new Connection( Provider::R2, 'files', 'access', 'secret', 'eu-west-2', self::ACCOUNT );

		$this->assertSame( 'region', $connection->verify()->get_error_data()['field'] );
		$this->assertSame( [], FakeHttp::requests() );
	}

	/**
	 * Without a bucket, the keys are tried on the bucket list; a key scoped
	 * to buckets being refused that list is fine.
	 */
	public function test_verify_without_a_bucket(): void {
		$connection = Connection::from_array( $this->r2( [ 'bucket' => '' ] ) );

		FakeHttp::queue( 200, '<ListAllMyBucketsResult><Buckets><Bucket><Name>files</Name></Bucket></Buckets></ListAllMyBucketsResult>' );
		$this->assertTrue( $connection->verify() );

		FakeHttp::queue( 403, '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>' );
		$this->assertTrue( $connection->verify() );

		FakeHttp::queue( 403, '<Error><Code>InvalidAccessKeyId</Code><Message>Unknown</Message></Error>' );
		$error = $connection->verify();

		$this->assertSame( 's3_connection_key', $error->get_error_code() );
		$this->assertSame( 'access_key', $error->get_error_data()['field'] );
		$this->assertNull( $connection->bucket() );
	}

	/**
	 * A connection test needs a bucket.
	 */
	public function test_check_needs_a_bucket(): void {
		$this->expectException( \InvalidArgumentException::class );

		Connection::from_array( $this->r2( [ 'bucket' => '' ] ) )->check();
	}

	/**
	 * The secret never shows in a dump.
	 */
	public function test_the_secret_stays_out_of_dumps(): void {
		$dump = print_r( Connection::from_array( $this->r2( [ 'secret_key' => 'very-secret-value' ] ) ), true );

		$this->assertStringNotContainsString( 'very-secret-value', $dump );
		$this->assertStringContainsString( 'files', $dump );
	}

	/**
	 * R2 settings, with some changed.
	 *
	 * @param array $with Fields to change.
	 *
	 * @return array
	 */
	private function r2( array $with = [] ): array {
		return $with + [
			'provider'   => 'r2',
			'bucket'     => 'files',
			'access_key' => 'access',
			'secret_key' => 'secret',
			'account_id' => self::ACCOUNT,
		];
	}
}
