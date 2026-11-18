<?php
/**
 * The calls that replaced the global functions.
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\Folders;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Storage\Folders\Delivery;
use SugarCommerce\Storage\Folders\Folders;
use SugarCommerce\Storage\Utils\Runtime;

/**
 * Folders::deliver(), is_protected() and server_rules() were global
 * functions, which only the first plugin to load could declare. What each
 * answers for a folder nobody registered, and a path that is not inside one,
 * is the part a download endpoint depends on.
 */
final class RegistryTest extends TestCase {

	/**
	 * Reset the stubbed globals.
	 */
	protected function setUp(): void {
		wp_test_reset();
		unset( $_SERVER['SERVER_SOFTWARE'] );
	}

	/**
	 * A folder nobody registered is not delivered from, not protected, and
	 * has no rules.
	 */
	public function test_an_unknown_folder_answers_no(): void {
		$this->assertFalse( Folders::deliver( 'nowhere', 'a.zip' ) );
		$this->assertFalse( Folders::is_protected( 'nowhere' ) );
		$this->assertSame( '', Folders::server_rules( 'nowhere' ) );
	}

	/**
	 * A path that resolves outside the folder is refused before anything is
	 * sent -- the reason deliver() takes a relative path at all.
	 */
	public function test_a_path_out_of_the_folder_is_not_delivered(): void {
		$folder = Folders::register( 'downloads' );

		$folder->create();
		file_put_contents( $GLOBALS['wp_test_uploads'] . '/secret.txt', 'x' );

		$this->assertFalse( Folders::deliver( 'downloads', '../secret.txt' ) );
		$this->assertFalse( Folders::deliver( 'downloads', 'missing.zip' ) );

		unlink( $GLOBALS['wp_test_uploads'] . '/secret.txt' );
	}

	/**
	 * The rules for a registered folder name its location.
	 */
	public function test_a_registered_folder_has_rules(): void {
		Folders::register( 'downloads', [ 'public_extensions' => [ 'jpg' ] ] );

		$rules = Folders::server_rules( 'downloads' );

		$this->assertStringContainsString( 'location ^~ /wp-content/uploads/downloads/', $rules );
		$this->assertStringContainsString( 'jpg', $rules );
	}

	/**
	 * Servers, and whether each sends a file it is handed.
	 *
	 * @return array<string, array{string, bool, bool}>
	 */
	public static function servers(): array {
		return [
			'LiteSpeed always'            => [ 'LiteSpeed', false, true ],
			'nginx not until told'        => [ 'nginx/1.27.0', false, false ],
			'nginx once told'             => [ 'nginx/1.27.0', true, true ],
			'Flywheel is nginx'           => [ 'Flywheel/5.1.0', true, true ],
			'Apache, modules unknown'     => [ 'Apache/2.4.62', true, false ],
			'Apache with mod_xsendfile'   => [ 'Apache/2.4.62', false, true, [ 'core', 'mod_xsendfile' ] ],
			'Apache without it'           => [ 'Apache/2.4.62', false, false, [ 'core', 'mod_rewrite' ] ],
			'nginx is not Apache'         => [ 'nginx/1.27.0', false, false, [ 'mod_xsendfile' ] ],
			'nothing said'                => [ '', true, false ],
		];
	}

	/**
	 * X-Sendfile is used only where the server will act on it; elsewhere
	 * the header would be sent and the body would be empty.
	 *
	 * @param string $software What the server calls itself.
	 * @param bool   $told     Whether the nginx filter says yes.
	 * @param bool   $expected Whether the server is handed the file.
	 * @param array  $modules  The Apache modules PHP can see.
	 */
	#[DataProvider( 'servers' )]
	public function test_the_server_is_handed_a_file_only_when_it_can_send_it( string $software, bool $told, bool $expected, array $modules = [] ): void {
		$_SERVER['SERVER_SOFTWARE']        = $software;
		$GLOBALS['wp_test_apache_modules'] = $modules;

		if ( $told ) {
			$GLOBALS['wp_test_filters'][ Runtime::hook( 'nginx_xsendfile' ) ] = static fn(): bool => true;
		}

		$this->assertSame( $expected, Delivery::server_sends_files() );
	}

	/**
	 * Only letters and digits reach a server configuration file: an
	 * "extension" carrying a bracket or a newline would break the file or
	 * add a line to it.
	 */
	public function test_only_plain_extensions_reach_the_rules(): void {
		$this->assertSame(
			[ 'jpg', 'pdf' ],
			\SugarCommerce\Storage\Folders\Rules::safe_extensions( [ 'jpg', 'png)$|.*', "x\ny", '.PDF', 'jpg', 'averyverylongone' ] )
		);
	}
}
