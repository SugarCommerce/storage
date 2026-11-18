<?php
/**
 * Filename tests.
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\Files;

use SugarCommerce\Storage\Files\Filename;
use PHPUnit\Framework\TestCase;

/**
 * The name on the end of a path.
 *
 * Small, because sanitize_file_name() and wp_unique_filename() are core's and
 * are better than anything here would be. What is left is renaming, which is
 * the one a store needs and the one with a trap in it.
 */
final class FilenameTest extends TestCase {

	/**
	 * An extension can be swapped.
	 */
	public function test_an_extension_can_be_swapped(): void {
		$this->assertSame( 'manual.txt', Filename::with_extension( 'manual.pdf', 'txt' ) );
		$this->assertSame( 'manual.txt', Filename::with_extension( 'manual.pdf', '.txt' ) );
		$this->assertSame( 'manual', Filename::with_extension( 'manual.pdf', '' ) );
	}

	/**
	 * Renaming keeps the extension the file already had.
	 *
	 * Which is the whole point: a store letting somebody rename a download
	 * must not let them turn invoice.pdf into invoice.php, and must not make
	 * them retype .pdf either.
	 */
	public function test_renaming_keeps_the_extension(): void {
		// Core turns a run of whitespace into a dash, which is why the name
		// comes back hyphenated rather than spaced.
		$this->assertSame( 'Invoice-2026.pdf', Filename::rename( 'Invoice 2026', 'original.pdf' ) );
		$this->assertSame( 'invoice.pdf', Filename::rename( 'Invoice', 'original.pdf', true ) );
	}

	/**
	 * Including when the new name brings one of its own.
	 */
	public function test_a_new_extension_does_not_come_along(): void {
		$this->assertSame( 'shell.pdf', Filename::rename( 'shell.php', 'original.pdf' ) );
		$this->assertSame( 'shell.pdf', Filename::rename( 'shell.phar', 'original.pdf' ) );
	}

	/**
	 * A name that is a path is not a path.
	 */
	public function test_a_name_cannot_be_a_path(): void {
		$renamed = Filename::rename( '../../wp-config', 'original.pdf' );

		$this->assertStringNotContainsString( '/', $renamed );
		$this->assertStringNotContainsString( '..', $renamed );
		$this->assertStringEndsWith( '.pdf', $renamed );
	}

	/**
	 * A name that sanitizes away falls back to the original's.
	 *
	 * Better than a file called ".pdf", which is a hidden file on every
	 * system that has the concept.
	 */
	public function test_a_name_that_sanitizes_away_falls_back(): void {
		$this->assertSame( 'original.pdf', Filename::rename( '///', 'original.pdf' ) );
		$this->assertSame( 'original.pdf', Filename::rename( '', 'original.pdf' ) );
	}

	/**
	 * An extension comes back lower-cased and without a query string.
	 */
	public function test_an_extension_is_read_off_the_path(): void {
		$this->assertSame( 'pdf', Filename::extension( 'MANUAL.PDF' ) );
		$this->assertSame( 'jpg', Filename::extension( '/uploads/2026/holiday.jpg?v=2' ) );
		$this->assertSame( '', Filename::extension( 'no-extension' ) );
	}
}
