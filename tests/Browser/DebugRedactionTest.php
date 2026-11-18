<?php
declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\Browser;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Storage\Browser\Api;
use SugarCommerce\Storage\S3\Account;
use SugarCommerce\Storage\Tests\Support\FakeHttp;

/**
 * Debug output is for a log, and a log is read by more people than the keys
 * are meant for. The signed headers go there with the signature taken out.
 */
final class DebugRedactionTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset();
	}

	public function test_the_signature_never_reaches_the_debug_output(): void {
		$seen = [];
		$api  = new Api( Account::r2( 'abc123' ), 'EXAMPLEKEYIDNOTREAL', 'secret' );

		$api->set_debug( true );
		$api->set_debug_callback(
			static function ( string $title, $data ) use ( &$seen ): void {
				$seen[ $title ] = $data;
			}
		);

		FakeHttp::queue_fixture( 'list-buckets.xml' );
		$api->list_buckets();

		$sent    = (array) ( FakeHttp::last()['args']['headers'] ?? [] );
		$logged  = $seen['List buckets Request Headers'] ?? null;
		$written = print_r( $seen, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions

		$this->assertIsArray( $logged );
		$this->assertArrayHasKey( 'Authorization', $sent );
		$this->assertSame( '[FILTERED]', $logged['Authorization'] );
		$this->assertStringNotContainsString( (string) $sent['Authorization'], $written );
		$this->assertStringNotContainsString( 'Signature=', $written );

		// The rest is left alone: it is what makes the log worth reading.
		$this->assertSame( $sent['x-amz-date'], $logged['x-amz-date'] );
	}
}
