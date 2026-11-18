<?php
/**
 * Path containment tests.
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\Files;

use SugarCommerce\Storage\Files\Path;
use PHPUnit\Framework\TestCase;

/**
 * Whether a file is where it is supposed to be.
 *
 * This is the one function in the library that anything security related
 * should be calling, so most of what is here is an attack: a plugin serving
 * paid downloads takes a path from a query argument and hands it to something
 * that reads bytes off disk, and getting this wrong is how a download
 * endpoint serves wp-config.php.
 *
 * The version this replaces did `str_replace( '..', '', $path )`, which is
 * the canonical broken one — `....//` has its inner `..` removed and comes
 * out as `../`. Every case below was reachable.
 */
final class PathTest extends TestCase {

	/**
	 * The directory downloads are pretended to be in.
	 *
	 * @var string
	 */
	private string $root = '';

	/**
	 * Build a small tree to point at.
	 */
	protected function setUp(): void {
		$this->root = (string) realpath( (string) sys_get_temp_dir() ) . '/fu-' . bin2hex( random_bytes( 4 ) );

		mkdir( $this->root . '/downloads/2026/08', 0o777, true );
		mkdir( $this->root . '/private', 0o777, true );

		file_put_contents( $this->root . '/downloads/2026/08/manual.pdf', 'pdf' );
		file_put_contents( $this->root . '/private/secrets.txt', 'secret' );
	}

	/**
	 * Take it away again.
	 */
	protected function tearDown(): void {
		foreach ( [ '/downloads/2026/08/manual.pdf', '/private/secrets.txt' ] as $file ) {
			if ( file_exists( $this->root . $file ) ) {
				unlink( $this->root . $file );
			}
		}

		foreach ( [ '/downloads/2026/08', '/downloads/2026', '/downloads', '/private', '' ] as $directory ) {
			if ( is_dir( $this->root . $directory ) ) {
				rmdir( $this->root . $directory );
			}
		}
	}

	/**
	 * Where downloads live.
	 *
	 * @return string
	 */
	private function downloads(): string {
		return $this->root . '/downloads';
	}

	/**
	 * A file inside the directory resolves.
	 */
	public function test_a_file_inside_resolves(): void {
		$this->assertSame(
			Path::normalize( $this->downloads() . '/2026/08/manual.pdf' ),
			Path::within( $this->downloads(), '2026/08/manual.pdf' )
		);
	}

	/**
	 * An absolute path inside it resolves too.
	 */
	public function test_an_absolute_path_inside_resolves(): void {
		$this->assertNotNull( Path::within( $this->downloads(), $this->downloads() . '/2026/08/manual.pdf' ) );
	}

	/**
	 * Every way of pointing outside is refused.
	 *
	 * @dataProvider escapeProvider
	 *
	 * @param string $candidate The path to try.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'escapeProvider' )]
	public function test_an_escape_is_refused( string $candidate ): void {
		$candidate = str_replace( '{root}', $this->root, $candidate );

		$this->assertNull(
			Path::within( $this->downloads(), $candidate ),
			sprintf( '%s was allowed out.', $candidate )
		);
	}

	/**
	 * One row per way out.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function escapeProvider(): array {
		return [
			'plain traversal'      => [ '../private/secrets.txt' ],

			// The one the old check produced: it removed the inner `..` from
			// `....//` and handed back `../`, so filtering made the attack
			// rather than stopping it.
			'doubled traversal'    => [ '....//private/secrets.txt' ],

			'deep traversal'       => [ '2026/08/../../../private/secrets.txt' ],
			'absolute elsewhere'   => [ '{root}/private/secrets.txt' ],
			'backslashes'          => [ '..\\private\\secrets.txt' ],
			// realpath() happens to refuse these too, so removing the
			// explicit check does not change the answer here. It stays
			// because "realpath happens to" is not a guarantee across PHP
			// versions, and phar:// turns a file read into object
			// instantiation — a remote code execution bug wearing a
			// filename.
			'a stream wrapper'     => [ 'php://filter/read=convert.base64-encode/resource=/etc/passwd' ],
			'a phar'               => [ 'phar://{root}/downloads/2026/08/manual.pdf' ],
			'a remote url'         => [ 'https://example.test/x.pdf' ],
			'nothing'              => [ '' ],
		];
	}

	/**
	 * A null byte is refused.
	 *
	 * It truncates the string for every C function underneath PHP, so
	 * "manual.pdf\0../../secrets.txt" passes an extension check and opens
	 * something else.
	 */
	public function test_a_null_byte_is_refused(): void {
		$this->assertNull( Path::within( $this->downloads(), "2026/08/manual.pdf\0../../private/secrets.txt" ) );
		$this->assertNull( Path::within( $this->downloads(), "\0" ) );
	}

	/**
	 * A sibling directory whose name starts the same is outside.
	 *
	 * Without the separator in the comparison, /var/www/uploads-public passes
	 * as being inside /var/www/uploads.
	 */
	public function test_a_sibling_with_a_shared_prefix_is_outside(): void {
		mkdir( $this->downloads() . '-public' );
		file_put_contents( $this->downloads() . '-public/leak.txt', 'x' );

		$this->assertNull( Path::within( $this->downloads(), $this->downloads() . '-public/leak.txt' ) );

		unlink( $this->downloads() . '-public/leak.txt' );
		rmdir( $this->downloads() . '-public' );
	}

	/**
	 * A symlink out of the directory is followed and refused.
	 *
	 * String comparison cannot see this one at all: the path is entirely
	 * inside the directory right up until the filesystem resolves it.
	 */
	public function test_a_symlink_out_is_refused(): void {
		$link = $this->downloads() . '/escape';

		if ( ! @symlink( $this->root . '/private', $link ) ) {
			$this->markTestSkipped( 'Symlinks are not available here.' );
		}

		$this->assertNull( Path::within( $this->downloads(), 'escape/secrets.txt' ) );

		unlink( $link );
	}

	/**
	 * A file that is not there is not a path.
	 *
	 * Answering "safe" for one would be answering a question nobody asked:
	 * a path that does not resolve is not a file anybody is about to read.
	 */
	public function test_a_file_that_is_not_there_is_refused(): void {
		$this->assertNull( Path::within( $this->downloads(), '2026/08/nothing.pdf' ) );
	}

	/**
	 * The directory itself is inside itself.
	 */
	public function test_the_directory_is_within_itself(): void {
		$this->assertNotNull( Path::within( $this->downloads(), $this->downloads() ) );
	}

	/**
	 * A directory that is not there refuses everything.
	 */
	public function test_a_missing_directory_refuses_everything(): void {
		$this->assertNull( Path::within( $this->root . '/nowhere', 'manual.pdf' ) );
	}

	/* --- to_url ----------------------------------------------------------- */

	/**
	 * Uploads come first, from wp_upload_dir(): they can live outside the
	 * content folder, at a URL nothing else knows.
	 */
	public function test_an_upload_outside_the_content_folder_keeps_its_own_url(): void {
		wp_test_reset();

		$this->assertSame(
			'https://example.test/wp-content/uploads/2026/11/a b.zip',
			Path::to_url( $GLOBALS['wp_test_uploads'] . '/2026/11/a b.zip' )
		);
	}

	/**
	 * A folder that merely starts with the uploads folder's name is not in it.
	 */
	public function test_a_sibling_of_the_uploads_folder_is_not_an_upload(): void {
		wp_test_reset();

		$this->assertNull( Path::to_url( $GLOBALS['wp_test_uploads'] . '-old/a.zip' ) );
	}

	/**
	 * Everything else is Plugin\Assets': the content folder, then the root.
	 */
	public function test_anything_else_is_mapped_by_plugin_assets(): void {
		wp_test_reset();

		$this->assertSame( 'https://example.test/wp-content/plugins/x/a.css', Path::to_url( WP_CONTENT_DIR . '/plugins/x/a.css' ) );
		$this->assertSame( 'https://example.test/readme.html', Path::to_url( ABSPATH . 'readme.html' ) );
		$this->assertNull( Path::to_url( '/somewhere/else/a.css' ) );
		$this->assertNull( Path::to_url( '' ) );
	}
}
