<?php
/**
 * MIME tests.
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\Files;

use SugarCommerce\Storage\Files\Filename;
use SugarCommerce\Storage\Files\Mime;
use PHPUnit\Framework\TestCase;

/**
 * What a file is, and what a browser should do with it.
 *
 * The rule that matters is precedence: core answers first. A table of its own
 * meant the same file was one type through the media library and another
 * through a download endpoint, and nothing said so.
 */
final class MimeTest extends TestCase {

	/**
	 * A file core knows about gets core's type.
	 *
	 * Worth saying plainly: the precedence in of() is a statement of intent
	 * rather than a load-bearing rule, because the two tables are disjoint —
	 * which is what test_nothing_added_contradicts_core() below actually
	 * enforces. Swap the order and nothing changes, and that is the design.
	 */
	public function test_a_file_core_knows_gets_cores_type(): void {
		$this->assertSame( 'image/jpeg', Mime::of( 'holiday.jpg' ) );
		$this->assertSame( 'application/pdf', Mime::of( 'manual.pdf' ) );
	}

	/**
	 * Even where this library would have said something else.
	 *
	 * Core calls a Photoshop file application/octet-stream, which a browser
	 * downloads. The old table called it image/vnd.adobe.photoshop, which
	 * some browsers try to render — and disagreed with what the same file
	 * got everywhere else on the site. The entry is gone, so core's answer
	 * is the only one.
	 */
	public function test_a_type_core_disagreed_about_is_cores(): void {
		$this->assertSame( 'application/octet-stream', Mime::of( 'artwork.psd' ) );
	}

	/**
	 * And this library fills in what core has no answer for.
	 *
	 * Which is what a store sells: books, fonts, installers, design files.
	 */
	public function test_it_knows_what_core_does_not(): void {
		$this->assertSame( 'application/epub+zip', Mime::of( 'novel.epub' ) );
		$this->assertSame( 'font/woff2', Mime::of( 'brand.woff2' ) );
		$this->assertSame( 'application/x-apple-diskimage', Mime::of( 'app.dmg' ) );
	}

	/**
	 * Nothing recognisable is the type that means "just save it".
	 */
	public function test_an_unknown_type_is_a_download(): void {
		$this->assertSame( 'application/octet-stream', Mime::of( 'mystery.qqq' ) );
		$this->assertSame( 'application/octet-stream', Mime::of( 'no-extension' ) );
	}

	/**
	 * A query string is not part of the name.
	 *
	 * `image.jpg?v=2` used to have an extension of `jpg?v=2`.
	 */
	public function test_a_query_string_is_not_part_of_the_extension(): void {
		$this->assertSame( 'jpg', Filename::extension( 'https://example.test/holiday.jpg?v=2' ) );
		$this->assertSame( 'image/jpeg', Mime::of( 'https://example.test/holiday.jpg?v=2' ) );
	}

	/**
	 * Nothing this library adds may contradict core.
	 *
	 * The point of the whole rearrangement: an entry that core also has is
	 * either redundant or a disagreement, and both are worse than nothing.
	 */
	public function test_nothing_added_contradicts_core(): void {
		$core = [];

		foreach ( wp_get_mime_types() as $extensions => $type ) {
			foreach ( explode( '|', $extensions ) as $extension ) {
				$core[ $extension ] = $type;
			}
		}

		foreach ( Mime::extras() as $extension => $type ) {
			$this->assertArrayNotHasKey(
				$extension,
				$core,
				sprintf( 'Core already knows .%s; this table can only disagree with it.', $extension )
			);
		}
	}

	/**
	 * A type a browser would execute is never shown inline.
	 *
	 * Rendered rather than downloaded, each of these runs in the origin of
	 * the site serving it — so whatever a customer uploaded runs against the
	 * store's own cookies.
	 *
	 * @dataProvider dangerousProvider
	 *
	 * @param string $type A MIME type.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'dangerousProvider' )]
	public function test_an_executable_type_is_never_inline( string $type ): void {
		$this->assertTrue( Mime::must_download( $type ), sprintf( '%s would be shown inline.', $type ) );
		$this->assertTrue( Mime::is_dangerous_inline( $type ) );
	}

	/**
	 * The types that execute in a browser.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function dangerousProvider(): array {
		$types = [ 'text/html', 'application/xhtml+xml', 'text/javascript', 'application/javascript', 'application/x-httpd-php', 'image/svg+xml' ];

		return array_combine( $types, array_map( static fn( string $type ): array => [ $type ], $types ) );
	}

	/**
	 * An SVG is an image and is still never shown inline.
	 *
	 * It is the one that catches people out: it passes every `image/` check
	 * and it is a document that can carry script.
	 */
	public function test_an_svg_is_an_image_and_still_downloads(): void {
		$this->assertTrue( Mime::is_media( 'image/svg+xml' ) );
		$this->assertTrue( Mime::must_download( 'image/svg+xml' ) );
	}

	/**
	 * What a browser has a player for is shown, because people expect it.
	 */
	public function test_media_is_shown(): void {
		$this->assertFalse( Mime::must_download( 'image/jpeg' ) );
		$this->assertFalse( Mime::must_download( 'video/mp4' ) );
		$this->assertFalse( Mime::must_download( 'audio/mpeg' ) );
		$this->assertFalse( Mime::must_download( 'application/pdf' ) );
	}

	/**
	 * Everything else is a download.
	 */
	public function test_anything_else_is_a_download(): void {
		$this->assertTrue( Mime::must_download( 'application/zip' ) );
		$this->assertTrue( Mime::must_download( 'application/epub+zip' ) );
		$this->assertTrue( Mime::must_download( 'application/octet-stream' ) );
	}

	/**
	 * A type maps back to an extension.
	 */
	public function test_a_type_maps_back_to_an_extension(): void {
		$this->assertSame( 'jpg', Mime::extension_for( 'image/jpeg' ) );
		$this->assertSame( 'epub', Mime::extension_for( 'application/epub+zip' ) );
		$this->assertNull( Mime::extension_for( 'application/nothing-like-this' ) );
	}

	/**
	 * The formats a digital store actually sells are named.
	 *
	 * Not an exhaustive list of file types -- the fallback handles those --
	 * but the ones where falling through to application/octet-stream would be
	 * a worse answer than the real type. A 3D print file, an InDesign
	 * document, a font collection: all things people sell, none of which core
	 * has heard of.
	 *
	 * @param string $extension The extension.
	 * @param string $expected  The type it should get.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'storeFormatProvider' )]
	public function test_the_formats_a_store_sells_are_named( string $extension, string $expected ): void {
		$this->assertSame( $expected, Mime::of( 'product.' . $extension ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function storeFormatProvider(): array {
		return [
			'a 3D print file'   => [ 'stl', 'model/stl' ],
			'a 3D model'        => [ 'obj', 'model/obj' ],
			'a glTF scene'      => [ 'glb', 'model/gltf-binary' ],
			'a Blender file'    => [ 'blend', 'application/x-blender' ],
			'an InDesign file'  => [ 'indd', 'application/x-indesign' ],
			'an EPS'            => [ 'eps', 'application/postscript' ],
			'a font collection' => [ 'ttc', 'font/collection' ],
			'markdown'          => [ 'md', 'text/markdown' ],
			'YAML'              => [ 'yml', 'application/yaml' ],
			'an installer'      => [ 'msi', 'application/x-msdownload' ],
			'a disc image'      => [ 'iso', 'application/x-iso9660-image' ],
		];
	}

	/**
	 * Sound a browser can play is not forced to download.
	 *
	 * The reason these are named rather than left to fall through: octet-
	 * stream forces a download, and a store offering a preview wants the
	 * player.
	 */
	public function test_playable_sound_is_not_forced_to_download(): void {
		$this->assertSame( 'audio/opus', Mime::of( 'preview.opus' ) );
		$this->assertFalse( Mime::must_download( 'audio/opus' ) );
		$this->assertTrue( Mime::is_media( 'audio/opus' ) );
	}

	/**
	 * A raw photograph is not claimed as a browser image.
	 *
	 * Deliberately absent from the table. Giving `cr2` an `image/` type would
	 * make must_download() answer false, and a browser handed a Canon raw
	 * inline shows nothing at all.
	 */
	public function test_a_raw_photograph_still_downloads(): void {
		$this->assertSame( 'application/octet-stream', Mime::of( 'shoot.cr2' ) );
		$this->assertTrue( Mime::must_download( Mime::of( 'shoot.cr2' ) ) );
	}
}
