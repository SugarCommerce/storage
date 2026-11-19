<?php
/**
 * Runtime name derivation tests.
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\Utils;

use SugarCommerce\Storage\Utils\Runtime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Hooks, keys, handles, the REST namespace and the cache prefix all come from
 * the build this copy is compiled into, so two plugins bundling the library
 * -- edd-cloudflare-r2 and wc-cloudflare-r2 -- never answer for each other.
 */
final class RuntimeTest extends TestCase {

	/**
	 * Unprefixed, the names are the library's own.
	 */
	public function test_unprefixed(): void {
		$this->assertSame( '', Runtime::build() );
		$this->assertSame( 'sugarcommerce_storage', Runtime::prefix() );
	}

	/**
	 * Each namespace gives its build's name and prefix.
	 *
	 * @param string $under  The namespace the class is compiled under.
	 * @param string $build  The build name it gives.
	 * @param string $prefix The hook and key prefix it gives.
	 */
	#[DataProvider( 'namespaces' )]
	public function test_a_build_is_named_for_itself( string $under, string $build, string $prefix ): void {
		$this->assertSame( $build, Runtime::build_for( $under ) );
		$this->assertSame( $prefix, Runtime::prefix_for( $under ) );
	}

	/**
	 * Namespaces and what each gives.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function namespaces(): array {
		return [
			'unprefixed'         => [ 'SugarCommerce\\Storage\\Utils', '', 'sugarcommerce_storage' ],
			'a plugin prefix'    => [ 'EDDR2\\SugarCommerce\\Storage\\Utils', 'eddr2', 'eddr2_sugarcommerce_storage' ],
			'a vendor prefix'    => [ 'SugarCart\\Vendor\\SugarCommerce\\Storage\\Utils', 'sugarcart', 'sugarcart_sugarcommerce_storage' ],
			'a family plugin'    => [ 'SugarCommerce\\Playground\\Vendor\\SugarCommerce\\Storage\\Utils', 'sugarcommerce-playground-vendor', 'sugarcommerce_playground_vendor_sugarcommerce_storage' ],
			'a plugin namespace' => [ 'ArrayPress\\EDD\\CloudflareR2\\Lib\\Storage\\Utils', 'arraypress-edd-cloudflarer2', 'arraypress_edd_cloudflarer2_sugarcommerce_storage' ],
			'its Woo sibling'    => [ 'ArrayPress\\WooCommerce\\CloudflareR2\\Lib\\Storage\\Utils', 'arraypress-woocommerce-cloudflarer2', 'arraypress_woocommerce_cloudflarer2_sugarcommerce_storage' ],
		];
	}
}
