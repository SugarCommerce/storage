<?php
declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\Browser;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Storage\Utils\Assets;

/**
 * Finding the package's own assets, wherever it was installed.
 */
final class AssetsTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset();
		Assets::reset();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_plugin_paths'] );
		Assets::reset();
	}

	public function test_a_file_in_assets_is_found(): void {
		$this->assertFileExists( (string) Assets::file( 'css/browser.css' ) );
		$this->assertNull( Assets::file( 'css/nothing.css' ) );
	}

	/**
	 * A relative path that leaves assets/ is refused before the disk is asked.
	 */
	public function test_nothing_outside_assets_is_reachable(): void {
		$this->assertNull( Assets::file( '../composer.json' ) );
		$this->assertNull( Assets::file( 'css/../../composer.json' ) );
		$this->assertNull( Assets::file( 'css\\..\\..\\composer.json' ) );
		$this->assertNull( Assets::file( "css/browser.css\0.php" ) );
		$this->assertNull( Assets::file( '' ) );
	}

	public function test_a_path_under_the_content_directory_is_a_content_url(): void {
		$this->assertSame(
			'https://example.test/wp-content/plugins/shop/vendor/x/assets',
			Assets::path_to_url( WP_CONTENT_DIR . '/plugins/shop/vendor/x/assets' )
		);
	}

	/**
	 * The base is replaced once, at the front: a directory name that recurs
	 * further down the path is not rewritten twice.
	 */
	public function test_only_the_leading_base_is_replaced(): void {
		$this->assertSame(
			'https://example.test/wp-content/plugins/wp-content/assets',
			Assets::path_to_url( WP_CONTENT_DIR . '/plugins/wp-content/assets' )
		);
	}

	/**
	 * A symlinked plugin resolves through core's map of real paths.
	 */
	public function test_a_symlinked_plugin_is_mapped_back(): void {
		$GLOBALS['wp_plugin_paths'] = [ WP_CONTENT_DIR . '/plugins/shop' => '/Users/dev/code/shop' ];

		$this->assertSame(
			'https://example.test/wp-content/plugins/shop/vendor/x/assets/css/a.css',
			Assets::path_to_url( '/Users/dev/code/shop/vendor/x/assets/css/a.css' )
		);
	}

	public function test_a_path_wordpress_cannot_serve_has_no_url(): void {
		$this->assertNull( Assets::path_to_url( '/somewhere/else/assets' ) );
	}
}
