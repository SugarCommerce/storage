<?php
/**
 * Filename
 *
 * @package     SugarCommerce\Storage
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Files;

/**
 * The name on the end of a path.
 *
 * `sanitize_file_name()` and `wp_unique_filename()` are core's and are better
 * than anything here would be. What is left is the work they do not cover:
 * reading an extension that cannot carry an attack, renaming a file without
 * letting it change type, refusing a name that hides its real extension, and
 * shortening or transliterating a name for a header without losing the part
 * that says what the file is.
 */
final class Filename {

	/**
	 * The most characters of an extension worth keeping.
	 *
	 * Long enough for the real ones and short enough that a "file.zzzzz…"
	 * cannot eat a whole budget.
	 */
	private const MAX_EXTENSION = 10;

	/**
	 * Extensions that run on a double-click, or render as a page.
	 *
	 * A sensible default for a site that serves files to strangers. HTML and
	 * SVG are here because served from the site's own domain they execute
	 * script in its origin; the PHP variants and `.htaccess` because an
	 * upload that lands under a web root can be executed or reconfigure it.
	 *
	 * @var string[]
	 */
	public const EXECUTABLE = [
		'exe',
		'scr',
		'bat',
		'cmd',
		'com',
		'msi',
		'msp',
		'dll',
		'cpl',
		'jar',
		'pif',
		'hta',
		'lnk',
		'reg',
		'scf',
		'js',
		'jse',
		'mjs',
		'vbs',
		'vbe',
		'wsf',
		'wsh',
		'ps1',
		'psm1',
		'sh',
		'bash',
		'zsh',
		'app',
		'dmg',
		'pkg',
		'deb',
		'rpm',
		'apk',
		'html',
		'htm',
		'shtml',
		'svg',
		'svgz',
		'xhtml',
		'xht',
		'xhtm',
		'mht',
		'mhtml',
		'php',
		'php3',
		'php4',
		'php5',
		'php7',
		'php8',
		'phps',
		'pht',
		'phtm',
		'phtml',
		'phar',
		'htaccess',
	];

	/**
	 * Characters that are invisible, or that change the order the rest of a
	 * name is displayed in: zero-width characters, direction marks,
	 * embeddings, overrides and isolates, and the byte order mark.
	 *
	 * `Invoice\u{202E}fdp.exe` displays as `Invoiceexe.pdf`.
	 */
	private const INVISIBLE = '[\x{061C}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]';

	/**
	 * The part of INVISIBLE that reorders what follows it -- embeddings,
	 * overrides and isolates. A filename has no honest use for one.
	 */
	private const REORDERING = '[\x{202A}-\x{202E}\x{2066}-\x{2069}]';

	/**
	 * Names Windows reserves for devices, with or without an extension. A
	 * file called `CON.wav` cannot be saved there at all.
	 */
	private const RESERVED = '/^(CON|PRN|AUX|NUL|COM[0-9¹²³]|LPT[0-9¹²³])(?=\.|$)/iu';

	/**
	 * The last segment of a path, whichever separator was used.
	 *
	 * Windows separators are converted first, because `basename()` only
	 * understands the host's -- so on Linux a name of
	 * `..\..\windows\system32\evil.exe` comes back whole.
	 *
	 * @param string $path A filename, an object key or a path.
	 *
	 * @return string
	 */
	public static function basename( string $path ): string {
		return basename( str_replace( '\\', '/', $path ) );
	}

	/**
	 * A file's extension, lower-cased, without its dot.
	 *
	 * Reduced to letters and digits and clamped to ten, so an "extension"
	 * that is really the rest of an attack cannot be carried through. A query
	 * string is not part of the name -- `image.jpg?v=2` is a `jpg` -- and nor
	 * is a URL's fragment; a `#` in a plain filename is kept, because
	 * `Track #1.wav` is a `wav`.
	 *
	 * @param string $filename The name, a path or a URL.
	 *
	 * @return string '' when there is none worth keeping.
	 */
	public static function extension( string $filename ): string {
		$filename = (string) strtok( $filename, '?' );

		if ( str_contains( $filename, '://' ) ) {
			$filename = (string) strtok( $filename, '#' );
		}

		$extension = (string) preg_replace( '/[^A-Za-z0-9]/', '', pathinfo( self::basename( $filename ), PATHINFO_EXTENSION ) );

		return strtolower( substr( $extension, 0, self::MAX_EXTENSION ) );
	}

	/**
	 * A filename's stem: everything before the extension.
	 *
	 * @param string $filename The name.
	 *
	 * @return string
	 */
	public static function stem( string $filename ): string {
		return (string) pathinfo( self::basename( $filename ), PATHINFO_FILENAME );
	}

	/**
	 * Swap a file's extension.
	 *
	 * Only the last one is swapped, so `archive.2024.tar.gz` keeps its dots.
	 *
	 * @param string $filename  The name.
	 * @param string $extension The new extension, with or without its dot. ''
	 *                          takes the extension off.
	 *
	 * @return string
	 */
	public static function with_extension( string $filename, string $extension ): string {
		$extension = ltrim( trim( $extension ), '.' );
		$base      = pathinfo( $filename, PATHINFO_FILENAME );

		return '' === $extension ? $base : $base . '.' . $extension;
	}

	/**
	 * Rename a file on disk, keeping the extension it already had.
	 *
	 * Which is the whole point: a store letting somebody rename a download
	 * must not let them rename `invoice.pdf` to `invoice.php`, and must not
	 * make them retype `.pdf` either.
	 *
	 * Direction overrides and invisible characters are taken out first, then
	 * the name goes through sanitize_file_name(), so a name carrying a slash
	 * or a null byte comes back as a name. One that cleans away to nothing
	 * keeps the old name.
	 *
	 * For a name a person reads -- a download's title, an object's label --
	 * where spaces and capitals should survive, use relabel().
	 *
	 * @param string $name     What to call it.
	 * @param string $original The file it is being renamed from.
	 * @param bool   $lower    Whether to lower-case the result.
	 *
	 * @return string
	 */
	public static function rename( string $name, string $original, bool $lower = false ): string {
		$extension = strtolower( (string) pathinfo( $original, PATHINFO_EXTENSION ) );
		$name      = (string) preg_replace( '/[\x00-\x1F\x7F]|' . self::INVISIBLE . '/u', '', $name );
		$name      = sanitize_file_name( self::with_extension( $name, '' ) );

		if ( '' === $name ) {
			$name = (string) pathinfo( $original, PATHINFO_FILENAME );
		}

		if ( $lower ) {
			$name = strtolower( $name );
		}

		return '' === $extension ? $name : $name . '.' . $extension;
	}

	/**
	 * The name a stored file ends up with when somebody renames it, as a
	 * person reads it, or '' when what they typed will not do.
	 *
	 * The extension is kept whatever is typed: the file was inspected under
	 * it, previews and players decide from it, and the computer it is
	 * downloaded to opens it by it. Typing the extension anyway is harmless:
	 * it is not doubled, and `Final.exe` becomes `Final.exe.wav`, a wav.
	 *
	 * What is typed is cleaned the way a filename needs:
	 *
	 *   - control characters, zero-width characters, direction marks,
	 *     overrides and isolates go -- `Invoice\u{202E}fdp.exe` displays as
	 *     `Invoiceexe.pdf`, and is how a name is made to lie;
	 *   - runs of whitespace become one space;
	 *   - slashes and backslashes become hyphens, because a name with a
	 *     separator in it is a path to some unzip tools and save dialogs, and
	 *     so do the other characters Windows will not save (`: * ? " < > |`);
	 *   - leading and trailing dots and spaces go: a leading dot hides a file,
	 *     and Windows drops a trailing one;
	 *   - a name Windows reserves for a device (`CON`, `NUL`, `COM1` …) gets
	 *     an underscore, since it could not be saved there otherwise.
	 *
	 * @param string $current The file's name now, whose extension is kept.
	 * @param string $typed   What was typed, with or without the extension.
	 * @param int    $max     Most characters in the result, extension
	 *                        included. 255 is what file systems allow.
	 *
	 * @return string '' when nothing is left with a letter or digit in it.
	 */
	public static function relabel( string $current, string $typed, int $max = 255 ): string {
		$extension = pathinfo( $current, PATHINFO_EXTENSION );
		$suffix    = '' === $extension ? '' : '.' . $extension;

		$base = (string) preg_replace( '/[\x00-\x1F\x7F]|' . self::INVISIBLE . '/u', '', $typed );
		$base = trim( (string) preg_replace( '/\s+/u', ' ', $base ) );
		$base = str_replace( [ '/', '\\', ':', '*', '?', '"', '<', '>', '|' ], '-', $base );

		if ( '' !== $suffix && str_ends_with( mb_strtolower( $base ), mb_strtolower( $suffix ) ) ) {
			$base = mb_substr( $base, 0, -mb_strlen( $suffix ) );
		}

		$base = trim( $base, ' .' );

		if ( 1 !== preg_match( '/[\p{L}\p{N}]/u', $base ) ) {
			return '';
		}

		$base = (string) preg_replace( self::RESERVED, '$1_', $base );

		return rtrim( mb_substr( $base, 0, max( 1, $max - mb_strlen( $suffix ) ) ), ' .' ) . $suffix;
	}

	/**
	 * Why a filename is refused, or '' when it is acceptable.
	 *
	 * The check that runs before an upload starts: a statement of intent,
	 * since anybody can rename a file, but the cheapest refusal there is.
	 * Every part of the name is checked, not just the last -- a double
	 * extension like `invoice.pdf.exe` is how the real one is hidden.
	 *
	 * Each part between dots is compared with its surrounding whitespace
	 * removed, since Windows drops trailing dots and spaces when it saves a
	 * file -- `setup.exe .` is saved as `setup.exe`. A colon separates a part
	 * too, because on NTFS `setup.exe:x` is a stream of `setup.exe`. A name
	 * carrying a direction override or isolate is refused outright: it
	 * displays as something other than what it is.
	 *
	 * The reasons are translated, so they can be shown to whoever uploaded.
	 *
	 * @param string   $filename The name as given.
	 * @param string[] $banned   Extensions refused anywhere in the name, in any case.
	 *
	 * @return string
	 */
	public static function refusal( string $filename, array $banned = self::EXECUTABLE ): string {
		$filename = trim( (string) preg_replace( '/[\x00-\x1f\x7f]/u', '', $filename ) );

		if ( '' === $filename ) {
			return __( 'A filename is required.', 'sugarcommerce' );
		}

		if ( 1 === preg_match( '/' . self::REORDERING . '/u', $filename ) ) {
			return __( 'The filename contains characters that change how it is displayed.', 'sugarcommerce' );
		}

		$visible = (string) preg_replace( '/' . self::INVISIBLE . '/u', '', mb_strtolower( $filename ) );
		$banned  = array_map( 'mb_strtolower', $banned );

		foreach ( preg_split( '/[.:]/', $visible ) ?: [] as $part ) {
			$part = (string) preg_replace( '/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $part );

			if ( in_array( $part, $banned, true ) ) {
				return sprintf(
					/* translators: %s: a file extension, such as exe. */
					__( 'Files of type .%s cannot be stored.', 'sugarcommerce' ),
					$part
				);
			}
		}

		return '';
	}

	/**
	 * Shorten a filename to a byte budget without losing its extension.
	 *
	 * Bytes rather than characters, because the limits this exists for --
	 * header lengths, filesystem name limits -- are counted in bytes and a
	 * name of two hundred Han characters is six hundred of them.
	 *
	 * The cut is multibyte-safe, so a shortened name never ends in half a
	 * character; and the extension is put back afterwards, because a file
	 * that arrives as `report-of-the-annual` opens in nothing.
	 *
	 * @param string $filename The name.
	 * @param int    $bytes    The budget.
	 *
	 * @return string
	 */
	public static function clamp( string $filename, int $bytes ): string {
		if ( strlen( $filename ) <= $bytes ) {
			return $filename;
		}

		$extension = self::extension( $filename );
		$suffix    = '' === $extension ? '' : '.' . $extension;
		$stem      = '' === $suffix ? $filename : substr( $filename, 0, strlen( $filename ) - strlen( $suffix ) );

		// At least one character of stem, however small the budget: a name
		// that is only an extension is a hidden file on most systems.
		$stem = mb_strcut( $stem, 0, max( 1, $bytes - strlen( $suffix ) ), 'UTF-8' );

		return rtrim( $stem, ' .' ) . $suffix;
	}

	/**
	 * An ASCII version of a name that a person can still read.
	 *
	 * Transliterated rather than blanked: Cyrillic, Greek, Han, Kana and
	 * accented Latin all become something recognisable, so a customer who
	 * bought "Симфония №5.wav" gets "Simfoniia No5.wav" rather than
	 * "________.wav". Anything with no Latin representation at all -- emoji,
	 * symbols -- is dropped.
	 *
	 * Separators are trimmed but a dot is kept, so a name whose stem
	 * transliterated away entirely is still recognisably ".wav" and the
	 * caller can decide what to put in front of it.
	 *
	 * @param string $filename A UTF-8 filename.
	 *
	 * @return string
	 */
	public static function to_ascii( string $filename ): string {
		$ascii = self::transliterate( $filename );

		// Whatever survived that is not printable ASCII becomes '_'.
		$ascii = (string) preg_replace( '/[^\x20-\x7E]/', '_', $ascii );

		// A long run of underscores is what a blanked-out script looks like.
		$ascii = (string) preg_replace( '/_{2,}/', '_', $ascii );
		$ascii = (string) preg_replace( '/\s{2,}/', ' ', $ascii );

		return trim( $ascii, ' _' );
	}

	/**
	 * Best-effort script-to-Latin conversion.
	 *
	 * Prefers ext-intl's transliterator, which understands Han, Kana,
	 * Cyrillic, Greek, Arabic and more. Without intl, iconv handles accented
	 * Latin and drops the rest.
	 *
	 * @param string $name UTF-8 string.
	 *
	 * @return string
	 */
	private static function transliterate( string $name ): string {
		// Building a Transliterator costs roughly ten times as much as the
		// signature it is helping to produce, so it is built once per
		// process. `false` is the "tried, unavailable" sentinel.
		static $transliterator = null;

		if ( null === $transliterator ) {
			$transliterator = class_exists( \Transliterator::class )
				? ( \Transliterator::create( 'Any-Latin; Latin-ASCII' ) ?? false )
				: false;
		}

		if ( false !== $transliterator ) {
			$result = $transliterator->transliterate( $name );

			if ( false !== $result ) {
				return $result;
			}
		}

		$previous = setlocale( LC_CTYPE, '0' );
		setlocale( LC_CTYPE, 'C.UTF-8', 'en_US.UTF-8', 'C' );

		// iconv() emits a notice for any character it cannot transliterate,
		// which is the ordinary case for a filename in a script this locale
		// does not cover. The false return is checked below.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$result = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $name );

		if ( false !== $previous ) {
			setlocale( LC_CTYPE, $previous );
		}

		if ( false === $result ) {
			return $name;
		}

		// iconv's TRANSLIT emits things like "?" and "'a" for characters it
		// can only approximate; strip the noise it leaves behind.
		return (string) preg_replace( '/[?]+/', '', $result );
	}
}
