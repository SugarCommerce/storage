<?php
/**
 * Path::clean(): where a file is, as a column keeps it.
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\Files;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Storage\Files\Path;

/**
 * Good, messy and not a path at all; the last is always ''.
 */
final class PathCleanTest extends TestCase {

	/**
	 * @param array<int, mixed> $args   What it is given.
	 * @param string            $expect What it answers.
	 */
	#[DataProvider( 'cases' )]
	public function test_clean( array $args, string $expect ): void {
		$this->assertSame( $expect, Path::clean( ...$args ) );
	}

	/**
	 * @return array<string, array{0: array<int, mixed>, 1: string}>
	 */
	public static function cases(): array {
		return [
			// %20 and the spaces in a name are kept: not sanitize_text_field().
			'a bucket key'        => [ [ 's3://bucket/My%20Kit (v2).zip', 1000 ], 's3://bucket/My%20Kit (v2).zip' ],
			'tags, nul, padding'  => [ [ " packs/<b>kit</b>.zip\x00 ", 1000 ], 'packs/kit.zip' ],
			'a script'            => [ [ 'a<script>alert(1)</script>.zip' ], 'a.zip' ],
			'control characters'  => [ [ "a\tb\nc\x7F.zip" ], 'abc.zip' ],
			'not utf-8'           => [ [ "kit\xC3\x28.zip" ], '' ],
			'cut by character'    => [ [ str_repeat( 'é', 10 ), 4 ], 'éééé' ],
			'no limit'            => [ [ 'packs/kit.zip', 0 ], 'packs/kit.zip' ],
			'a number'            => [ [ 42 ], '42' ],
			'an array'            => [ [ [ 'x' ], 1000 ], '' ],
			'null'                => [ [ null ], '' ],
		];
	}
}
