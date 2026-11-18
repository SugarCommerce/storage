<?php
/**
 * The connection test.
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\S3;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Storage\S3\Bucket;
use SugarCommerce\Storage\S3\Connection;
use SugarCommerce\Storage\S3\Cors\Policy;
use SugarCommerce\Storage\S3\Cors\Rule;
use SugarCommerce\Storage\S3\Probe;
use SugarCommerce\Storage\S3\ProbeCheck;
use SugarCommerce\Storage\S3\ProbeFault;
use SugarCommerce\Storage\S3\ProbeStep;
use SugarCommerce\Storage\Tests\Support\FakeHttp;
use WP_Error;

/**
 * Probe::run(), against a bucket that answers by method and URL.
 */
final class ProbeTest extends TestCase {

	private const ORIGIN = 'https://app.example.com';

	protected function setUp(): void {
		wp_test_reset();
	}

	/**
	 * A bucket that works, or fails in one named way.
	 *
	 * @param string $fails '', keys, etag, cors, scoped, hidden or altered.
	 */
	private static function bucket( string $fails = '' ): void {
		FakeHttp::respond_with(
			static function ( string $url, array $args ) use ( $fails ) {
				$method  = $args['method'];
				$headers = $args['headers'] ?? array();

				if ( 'keys' === $fails ) {
					return FakeHttp::response( 403, '<Error><Code>AccessDenied</Code></Error>' );
				}

				if ( 'GET' === $method && str_contains( $url, '?cors' ) ) {
					if ( in_array( $fails, array( 'cors', 'scoped', 'hidden' ), true ) ) {
						return FakeHttp::response( 403, '<Error><Code>AccessDenied</Code></Error>' );
					}

					$rule = 'etag' === $fails ? new Rule( array( self::ORIGIN ), array( 'PUT', 'POST' ) ) : Rule::browser_upload( array( self::ORIGIN ) );

					return FakeHttp::response( 200, ( new Policy( array( $rule ) ) )->to_xml() );
				}

				return match ( $method ) {
					'HEAD'    => FakeHttp::response( 404 ),
					'PUT'     => FakeHttp::response( 200, '', in_array( $fails, array( 'cors', 'hidden' ), true ) ? array() : array( 'Access-Control-Expose-Headers' => 'ETag' ) ),
					'GET'     => FakeHttp::response( 200, 'altered' === $fails ? 'rewritten' : Probe::BODY ),
					'OPTIONS' => 'cors' === $fails
						? FakeHttp::response( 403 )
						: FakeHttp::response(
							200,
							'',
							array(
								'Access-Control-Allow-Origin'  => (string) ( $headers['Origin'] ?? '' ),
								'Access-Control-Allow-Methods' => 'PUT, POST',
							)
						),
					default   => FakeHttp::response( 204 ),
				};
			}
		);
	}

	/**
	 * Run the probe on the typed connection.
	 *
	 * @param array<int, string> $origins Origins.
	 *
	 * @return array<int, ProbeStep>
	 */
	private static function probe( array $origins = array( self::ORIGIN ) ): array {
		return ( new Probe() )->run( Connection::from_array( ConnectionTest::TYPED ), $origins );
	}

	/**
	 * @param array<int, ProbeStep> $steps Steps.
	 *
	 * @return array<int, ProbeFault|null>
	 */
	private static function faults( array $steps ): array {
		return array_map( static fn( ProbeStep $step ): ?ProbeFault => $step->fault, $steps );
	}

	/**
	 * @param string $method Method.
	 *
	 * @return array<int, array{url: string, args: array}>
	 */
	private static function sent( string $method ): array {
		return array_values( array_filter( FakeHttp::requests(), static fn( array $request ): bool => $method === $request['args']['method'] ) );
	}

	/**
	 * @return array<int, string>
	 */
	private static function methods(): array {
		return array_map( static fn( array $request ): string => $request['args']['method'], FakeHttp::requests() );
	}

	public function test_a_working_bucket_passes_every_step(): void {
		self::bucket();
		$steps = self::probe();

		$this->assertTrue( Probe::passed( $steps ) );
		$this->assertSame( ProbeCheck::cases(), array_map( static fn( ProbeStep $step ): ProbeCheck => $step->check, $steps ) );
		// HEAD, PUT, GET, DELETE of the test object, then its CORS policy.
		$this->assertSame( array( 'HEAD', 'PUT', 'GET', 'DELETE', 'GET' ), self::methods() );
	}

	public function test_the_site_s_own_bucket_can_be_probed(): void {
		self::bucket();

		$bucket = Connection::from_array( ConnectionTest::TYPED )->bucket();

		$this->assertInstanceOf( Bucket::class, $bucket );
		$this->assertTrue( Probe::passed( ( new Probe() )->run( $bucket, array( self::ORIGIN ) ) ) );
	}

	public function test_the_test_object_is_written_under_its_prefix_and_removed(): void {
		self::bucket();

		( new Probe( 'mine-test/', Probe::BODY ) )->run( Connection::from_array( ConnectionTest::TYPED ), array( self::ORIGIN ) );

		$put    = self::sent( 'PUT' )[0];
		$delete = self::sent( 'DELETE' )[0]['url'];

		$this->assertStringContainsString( '/seller-files/mine-test/', $put['url'] );
		$this->assertSame( self::ORIGIN, $put['args']['headers']['Origin'] );
		$this->assertSame( Probe::BODY, $put['args']['body'] );
		$this->assertSame( strtok( $put['url'], '?' ), strtok( $delete, '?' ) );
	}

	public function test_refused_keys_stop_at_the_first_step(): void {
		self::bucket( 'keys' );
		$steps = self::probe();

		$this->assertFalse( Probe::passed( $steps ) );
		$this->assertSame( array( ProbeFault::Refused, ProbeFault::Skipped, ProbeFault::Skipped, ProbeFault::Skipped, ProbeFault::Skipped ), self::faults( $steps ) );
		$this->assertSame( 403, $steps[0]->status );
		$this->assertSame( array( 'HEAD' ), self::methods() );
	}

	public function test_a_refusal_to_connect_is_unreachable_with_the_reason_kept(): void {
		FakeHttp::respond_with( static fn() => new WP_Error( 'http_request_failed', 'Could not resolve host' ) );

		$step = self::probe()[0];

		$this->assertSame( ProbeFault::Unreachable, $step->fault );
		$this->assertSame( 'Could not resolve host', $step->detail );
	}

	#[DataProvider( 'statuses' )]
	public function test_a_status_names_what_to_check( int $status, ProbeFault $fault ): void {
		$this->assertSame( $fault, ProbeFault::of_status( $status ) );
	}

	public static function statuses(): array {
		return array(
			'no answer'      => array( 0, ProbeFault::Unreachable ),
			'refused'        => array( 403, ProbeFault::Refused ),
			'no such bucket' => array( 404, ProbeFault::Missing ),
			'moved'          => array( 301, ProbeFault::Elsewhere ),
			'wrong region'   => array( 400, ProbeFault::Elsewhere ),
			'anything else'  => array( 500, ProbeFault::Failed ),
		);
	}

	public function test_something_else_read_back_is_altered(): void {
		self::bucket( 'altered' );

		$this->assertSame( ProbeFault::Altered, self::probe()[2]->fault );
	}

	public function test_answers_are_capped(): void {
		self::bucket();
		self::probe();

		foreach ( FakeHttp::requests() as $request ) {
			$this->assertLessThanOrEqual( Probe::MAX_READ * 4, $request['args']['limit_response_size'] ?? PHP_INT_MAX, $request['args']['method'] . ' ' . $request['url'] );
		}

		$this->assertSame( Probe::MAX_READ, self::sent( 'HEAD' )[0]['args']['limit_response_size'] );
		$this->assertSame( Probe::MAX_READ, self::sent( 'PUT' )[0]['args']['limit_response_size'] );
	}

	public function test_a_policy_that_forgets_etag_fails(): void {
		self::bucket( 'etag' );
		$step = self::probe()[4];

		$this->assertSame( ProbeFault::CorsPolicy, $step->fault );
		$this->assertStringContainsString( 'ETag', $step->detail );
	}

	public function test_keys_that_cannot_read_the_policy_are_checked_by_preflight(): void {
		self::bucket( 'scoped' );
		$steps = self::probe();

		$this->assertTrue( Probe::passed( $steps ) );
		$this->assertSame( 'PUT', self::sent( 'OPTIONS' )[0]['args']['headers']['Access-Control-Request-Method'] );
		$this->assertSame( self::ORIGIN, self::sent( 'OPTIONS' )[0]['args']['headers']['Origin'] );
	}

	public function test_a_refused_preflight_names_the_origin(): void {
		self::bucket( 'cors' );
		$step = self::probe()[4];

		$this->assertSame( ProbeFault::CorsOrigin, $step->fault );
		$this->assertSame( self::ORIGIN, $step->detail );
	}

	public function test_a_preflight_that_passes_still_needs_etag_exposed(): void {
		self::bucket( 'hidden' );

		$this->assertSame( ProbeFault::EtagHidden, self::probe()[4]->fault );
	}

	public function test_no_origins_skips_the_cors_question(): void {
		self::bucket( 'cors' );
		$steps = self::probe( array() );

		$this->assertTrue( Probe::passed( $steps ) );
		$this->assertSame( array(), self::sent( 'OPTIONS' ) );
		$this->assertArrayNotHasKey( 'Origin', self::sent( 'PUT' )[0]['args']['headers'] );
	}

	public function test_no_steps_is_not_a_pass(): void {
		$this->assertFalse( Probe::passed( array() ) );
	}

	public function test_each_check_says_what_it_was_doing(): void {
		$this->assertSame( 'write to', ProbeCheck::Write->verb() );
		$this->assertSame( '', ProbeCheck::Cors->verb() );
	}
}
