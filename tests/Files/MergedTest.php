<?php
/**
 * What the merge added to Files.
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\Files;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Storage\Files\Filename;
use SugarCommerce\Storage\Files\Mime;
use SugarCommerce\Storage\Utils\Runtime;

/**
 * The places three libraries' answers had to become one.
 *
 * Two extension readers disagreed -- one cut at `?` and `#`, which lost the
 * extension of `Track #1.wav`, and one kept anything after the dot, which let
 * `zip%00.php` through as an "extension". The browser filed files into
 * categories against a list of archive types core never answers with, so
 * every .gz and .rar was "other". This pins the merged behaviour.
 */
final class MergedTest extends TestCase {

	/**
	 * Empty the stubbed store.
	 */
	protected function setUp(): void {
		wp_test_reset();
	}

	/**
	 * Names and what their extension is.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function extensions(): array {
		return [
			'plain'                  => [ 'master.WAV', 'wav' ],
			'a query string'         => [ 'image.jpg?v=2', 'jpg' ],
			'a URL with a fragment'  => [ 'https://example.test/a.pdf#page=2', 'pdf' ],
			'a hash in a filename'   => [ 'Track #1.wav', 'wav' ],
			'only the last'          => [ 'archive.2024.tar.gz', 'gz' ],
			'junk is dropped'        => [ 'evil.ph%p', 'php' ],
			'a path'                 => [ 'C:\\music\\a.flac', 'flac' ],
			'clamped to ten'         => [ 'x.abcdefghijklmnop', 'abcdefghij' ],
			'none'                   => [ 'README', '' ],
			'a dot in a folder only' => [ 'v1.2/README', '' ],
		];
	}

	/**
	 * One extension reader, for disk, keys and URLs alike.
	 *
	 * @param string $name     The name.
	 * @param string $expected Its extension.
	 */
	#[DataProvider( 'extensions' )]
	public function test_an_extension_is_read_one_way( string $name, string $expected ): void {
		$this->assertSame( $expected, Filename::extension( $name ) );
	}

	/**
	 * A rename on disk cannot be made to lie about its extension with a
	 * direction override, which sanitize_file_name() alone does not remove.
	 */
	public function test_a_rename_on_disk_drops_direction_overrides(): void {
		$this->assertSame( 'Invoicefdp.pdf', Filename::rename( "Invoice\u{202E}fdp.exe", 'scan.pdf' ), 'The typed extension goes, the override with it.' );
		$this->assertSame( 'scan.pdf', Filename::rename( "\u{202E}", 'scan.pdf' ), 'Nothing left keeps the old name.' );
	}

	/**
	 * The categories the browser draws icons by.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function categories(): array {
		return [
			'an image'                 => [ 'cover.jpg', 'image' ],
			'a sound'                  => [ 'master.wav', 'audio' ],
			'a film'                   => [ 'trailer.mp4', 'video' ],
			'a document'               => [ 'manual.pdf', 'document' ],
			'a spreadsheet'            => [ 'sales.xlsx', 'document' ],
			'a zip'                    => [ 'stems.zip', 'archive' ],
			'core\'s gzip spelling'    => [ 'backup.tar.gz', 'archive' ],
			'core\'s rar spelling'     => [ 'pack.rar', 'archive' ],
			'only this package knows'  => [ 'book.epub', 'document' ],
			'anything else'            => [ 'plugin.dmg', 'other' ],
			'a type rather than a name' => [ 'audio/flac', 'audio' ],
		];
	}

	/**
	 * Every file falls into one category.
	 *
	 * @param string $file     A name or a type.
	 * @param string $expected Its category.
	 */
	#[DataProvider( 'categories' )]
	public function test_a_file_is_filed_by_category( string $file, string $expected ): void {
		$this->assertSame( $expected, Mime::category( $file ) );
	}

	/**
	 * The allowed list is core's until a filter says otherwise, and a
	 * context's filter only changes that context.
	 */
	public function test_allowed_types_are_filtered_per_context(): void {
		$this->assertTrue( Mime::is_allowed( 'cover.jpg' ) );
		$this->assertFalse( Mime::is_allowed( 'font.woff2' ) );
		$this->assertFalse( Mime::is_allowed( 'README' ), 'No extension is never allowed.' );

		add_filter(
			Runtime::hook( 'allowed_mime_types_downloads' ),
			static fn( array $types ): array => $types + [ 'woff2' => 'font/woff2' ]
		);

		$this->assertTrue( Mime::is_allowed( 'font.woff2', 'downloads' ) );
		$this->assertFalse( Mime::is_allowed( 'font.woff2', 'media' ), 'Another context is untouched.' );
		$this->assertContains( 'jpeg', Mime::allowed_extensions(), 'Grouped extensions are split.' );
	}

	/**
	 * A hook belongs to the build it was compiled into.
	 */
	public function test_hooks_are_named_for_the_build(): void {
		$this->assertSame( 'sugarcommerce_storage_redirect_to', Runtime::hook( 'redirect_to' ) );
		$this->assertSame( 'sugarcommerce_storage', Runtime::prefix_for( 'SugarCommerce\\Storage\\Utils' ) );
		$this->assertSame( 'myplugin_vendor_sugarcommerce_storage', Runtime::prefix_for( 'MyPlugin_Vendor\\SugarCommerce\\Storage\\Utils' ) );
		$this->assertSame( 'sugarcommerce-storage-browser', Runtime::handle( 'browser' ) );
	}
}
