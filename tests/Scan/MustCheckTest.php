<?php
/**
 * MustCheckTest
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\Scan;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Storage\Scan\{Clamd, Verdict};

/**
 * An infected file nothing acts on is still served, and a health check
 * nobody reads checks nothing. #[\NoDiscard] makes PHP warn where such a
 * result is dropped; this keeps the attribute from being lost in a refactor.
 */
final class MustCheckTest extends TestCase {

	/**
	 * @return array<string, array{string}>
	 */
	public static function methods(): array {
		return array(
			'Verdict::is_infected' => array( Verdict::class . '::is_infected' ),
			'Clamd::ping'          => array( Clamd::class . '::ping' ),
		);
	}

	#[DataProvider( 'methods' )]
	public function test_the_result_must_be_used( string $method ): void {
		$attributes = ( new \ReflectionMethod( ...explode( '::', $method ) ) )->getAttributes( 'NoDiscard' );

		$this->assertCount( 1, $attributes, $method . ' lost #[\NoDiscard].' );

		// Read by name, the attribute is there on 8.3 and 8.4 as well; only
		// 8.5 has the class to build it from.
		if ( ! class_exists( 'NoDiscard' ) ) {
			return;
		}
		$this->assertNotSame( '', $attributes[0]->newInstance()->message, 'Say why it matters.' );
	}
}
