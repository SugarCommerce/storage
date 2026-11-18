<?php
/**
 * MIME
 *
 * @package     SugarCommerce\Storage
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Files;

use SugarCommerce\Storage\Utils\Runtime;

/**
 * What a file is, and what a browser should do with it.
 *
 * Core knows a hundred and thirty extensions and this used to carry its own
 * table of sixty-four — thirty-eight of them identical to core's, five of
 * them *disagreeing* with core. That last group is the problem: the same file
 * uploaded through the media library got one type and served through a
 * download endpoint got another, and nothing said so.
 *
 * The worst of the five was `psd`. Core answers `application/octet-stream`,
 * which a browser downloads; this answered `image/vnd.adobe.photoshop`, which
 * some browsers try to render.
 *
 * So core answers first, and the table below holds only what core has no
 * answer for — the twenty-one a store actually sells that WordPress will not
 * let you upload: ebooks, fonts, installers, design files.
 */
final class Mime {

	/**
	 * What core does not know.
	 *
	 * Every one of these is absent from wp_get_mime_types(), which is a
	 * different statement from "WordPress will not accept it": these are
	 * types a digital store sells rather than types a site publishes.
	 *
	 * @var array<string, string>
	 */
	private const EXTRA = [
		// Vector and design.
		'svg'    => 'image/svg+xml',
		'ai'     => 'application/illustrator',
		'sketch' => 'application/x-sketch',

		// Books.
		'epub'   => 'application/epub+zip',
		'mobi'   => 'application/x-mobipocket-ebook',
		'azw'    => 'application/vnd.amazon.ebook',
		'azw3'   => 'application/vnd.amazon.ebook',

		// Data.
		'json'   => 'application/json',
		'xml'    => 'application/xml',
		'sql'    => 'application/sql',
		'db'     => 'application/x-sqlite3',

		// Fonts.
		'ttf'    => 'font/ttf',
		'otf'    => 'font/otf',
		'woff'   => 'font/woff',
		'woff2'  => 'font/woff2',
		'eot'    => 'application/vnd.ms-fontobject',
		'ttc'    => 'font/collection',

		// Installers and archives core has no entry for.
		'bz2'    => 'application/x-bzip2',
		'dmg'    => 'application/x-apple-diskimage',
		'apk'    => 'application/vnd.android.package-archive',
		'deb'    => 'application/x-deb',
		'rpm'    => 'application/x-rpm',
		'xz'     => 'application/x-xz',
		'zst'    => 'application/zstd',
		'iso'    => 'application/x-iso9660-image',
		'msi'    => 'application/x-msdownload',
		'jar'    => 'application/java-archive',

		// Three dimensions, which is a large part of what a digital store
		// sells now: print files, game assets, CAD.
		'stl'    => 'model/stl',
		'obj'    => 'model/obj',
		'gltf'   => 'model/gltf+json',
		'glb'    => 'model/gltf-binary',
		'blend'  => 'application/x-blender',

		// The rest of the design formats. Core knows psd and xcf and stops
		// there.
		'indd'   => 'application/x-indesign',
		'eps'    => 'application/postscript',

		// Sound core has no entry for. Both are types a browser can play,
		// which is why they are named rather than left to fall through --
		// octet-stream would force a download on a preview.
		'aiff'   => 'audio/aiff',
		'aif'    => 'audio/aiff',
		'opus'   => 'audio/opus',

		// Documentation and configuration, which ship inside almost every
		// developer product.
		'md'     => 'text/markdown',
		'yml'    => 'application/yaml',
		'yaml'   => 'application/yaml',
		'toml'   => 'application/toml',
	];

	/**
	 * Types a browser must never be invited to render.
	 *
	 * Sent inline, each of these executes in the origin of the site serving
	 * it — which for a paid download means whatever the customer uploaded
	 * runs against the store's own cookies.
	 *
	 * @var string[]
	 */
	private const NEVER_INLINE = [
		'text/html',
		'application/xhtml+xml',
		'text/javascript',
		'application/javascript',
		'application/x-javascript',
		'application/x-httpd-php',
		'image/svg+xml',
	];

	/**
	 * What a browser can be trusted to show rather than save.
	 *
	 * @var string[]
	 */
	private const INLINE = [
		'application/pdf',
		'text/plain',
	];

	/**
	 * Types filed as documents.
	 *
	 * @var string[]
	 */
	private const DOCUMENTS = [
		'application/pdf',
		'application/msword',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.ms-excel',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.ms-powerpoint',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'application/vnd.oasis.opendocument.text',
		'application/vnd.oasis.opendocument.spreadsheet',
		'application/vnd.oasis.opendocument.presentation',
		'application/rtf',
		'application/epub+zip',
		'text/plain',
		'text/csv',
		'text/markdown',
	];

	/**
	 * Types filed as archives.
	 *
	 * @var string[]
	 */
	private const ARCHIVES = [
		'application/zip',
		'application/x-zip-compressed',
		'application/rar',
		'application/vnd.rar',
		'application/x-rar-compressed',
		'application/x-tar',
		'application/gzip',
		'application/x-gzip',
		'application/x-7z-compressed',
		'application/x-bzip2',
		'application/x-xz',
		'application/zstd',
	];

	/**
	 * A file's type, from its name.
	 *
	 * @param string $filename The name or path.
	 *
	 * @return string Always something; `application/octet-stream` when nothing is known.
	 */
	public static function of( string $filename ): string {
		$extension = Filename::extension( $filename );

		if ( '' === $extension ) {
			return 'application/octet-stream';
		}

		// Core first, so a file served here is the type it is everywhere
		// else on the site — including whatever a plugin has added through
		// the `mime_types` filter, which this table would otherwise override
		// without saying so.
		$known = wp_check_filetype( 'x.' . $extension );

		if ( ! empty( $known['type'] ) ) {
			return (string) $known['type'];
		}

		return self::EXTRA[ $extension ] ?? 'application/octet-stream';
	}

	/**
	 * The extension a type is usually written with.
	 *
	 * @param string $type The MIME type.
	 *
	 * @return string|null
	 */
	public static function extension_for( string $type ): ?string {
		$type = strtolower( trim( $type ) );

		if ( '' === $type ) {
			return null;
		}

		// Not wp_get_default_extension_for_mime_type(): that matches the type
		// exactly, and core's own table spells some in mixed case
		// (`…macroEnabled.12`), so a type given in lower case -- as headers
		// and this method's callers usually give it -- would find nothing.
		foreach ( wp_get_mime_types() as $extensions => $known ) {
			if ( strtolower( $known ) === $type ) {
				return (string) strtok( $extensions, '|' );
			}
		}

		$extension = array_search( $type, self::EXTRA, true );

		return false === $extension ? null : (string) $extension;
	}

	/**
	 * Whether a type has to be downloaded rather than displayed.
	 *
	 * The default is yes. A download endpoint that guesses wrong in this
	 * direction shows somebody a file they wanted to save; guessing wrong in
	 * the other renders whatever was uploaded in the store's own origin.
	 *
	 * @param string $type The MIME type.
	 *
	 * @return bool
	 */
	public static function must_download( string $type ): bool {
		$type = strtolower( trim( $type ) );

		if ( in_array( $type, self::NEVER_INLINE, true ) ) {
			return true;
		}

		if ( in_array( $type, self::INLINE, true ) ) {
			return false;
		}

		// A browser has a player for these and people expect one.
		return ! str_starts_with( $type, 'image/' )
			&& ! str_starts_with( $type, 'video/' )
			&& ! str_starts_with( $type, 'audio/' );
	}

	/**
	 * Whether a type is one a browser must never render inline.
	 *
	 * Separate from must_download() because the answers differ: a zip must be
	 * downloaded and is perfectly harmless, an SVG is neither.
	 *
	 * @param string $type The MIME type.
	 *
	 * @return bool
	 */
	public static function is_dangerous_inline( string $type ): bool {
		return in_array( strtolower( trim( $type ) ), self::NEVER_INLINE, true );
	}

	/**
	 * Whether a type is an image, a video or a sound.
	 *
	 * @param string $type The MIME type.
	 *
	 * @return bool
	 */
	public static function is_media( string $type ): bool {
		$type = strtolower( trim( $type ) );

		foreach ( [ 'image/', 'video/', 'audio/' ] as $family ) {
			if ( str_starts_with( $type, $family ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every type this library can name, core's and its own.
	 *
	 * @return array<string, string> Extension to type.
	 */
	public static function all(): array {
		$types = [];

		foreach ( wp_get_mime_types() as $extensions => $type ) {
			foreach ( explode( '|', $extensions ) as $extension ) {
				$types[ $extension ] = $type;
			}
		}

		// Core wins where both know, which is the whole point.
		return array_merge( self::EXTRA, $types );
	}

	/**
	 * The types core has no answer for.
	 *
	 * @return array<string, string>
	 */
	public static function extras(): array {
		return self::EXTRA;
	}

	/**
	 * The kind of file a name or a type is, for an icon or a filter.
	 *
	 * One of `image`, `video`, `audio`, `document`, `archive` or `other`.
	 * Both spellings of the archive types are listed, because core answers
	 * `application/x-gzip` and `application/rar` where the registered names
	 * are `application/gzip` and `application/vnd.rar` -- a list of only the
	 * registered ones filed every .gz and .rar as "other".
	 *
	 * @param string $file A filename, a key or a MIME type.
	 *
	 * @return string
	 */
	public static function category( string $file ): string {
		$type = str_contains( $file, '/' ) && ! str_contains( $file, '.' ) ? strtolower( trim( $file ) ) : self::of( $file );

		foreach ( [ 'image', 'video', 'audio' ] as $family ) {
			if ( str_starts_with( $type, $family . '/' ) ) {
				return $family;
			}
		}

		if ( in_array( $type, self::DOCUMENTS, true ) ) {
			return 'document';
		}

		if ( in_array( $type, self::ARCHIVES, true ) ) {
			return 'archive';
		}

		return 'other';
	}

	/**
	 * The types this site lets people upload, for one context.
	 *
	 * Core's get_allowed_mime_types(), then the
	 * `{prefix}_allowed_mime_types` filter, then
	 * `{prefix}_allowed_mime_types_{$context}` when a context is named -- so
	 * a store can allow fonts in its downloads browser without allowing them
	 * in the media library.
	 *
	 * @param string|null $context Optional context, such as a plugin's id.
	 *
	 * @return array<string, string> Extensions (`jpg|jpeg|jpe`) to type.
	 */
	public static function allowed_types( ?string $context = null ): array {
		$types = (array) apply_filters( Runtime::hook( 'allowed_mime_types' ), get_allowed_mime_types(), $context );

		if ( null !== $context && '' !== $context ) {
			$types = (array) apply_filters( Runtime::hook( 'allowed_mime_types_' . $context ), $types );
		}

		return $types;
	}

	/**
	 * The extensions this site lets people upload, for one context.
	 *
	 * @param string|null $context Optional context.
	 *
	 * @return string[] Lower-cased, each once.
	 */
	public static function allowed_extensions( ?string $context = null ): array {
		$extensions = [];

		foreach ( array_keys( self::allowed_types( $context ) ) as $group ) {
			foreach ( explode( '|', (string) $group ) as $extension ) {
				$extensions[] = strtolower( trim( $extension ) );
			}
		}

		return array_values( array_unique( array_filter( $extensions ) ) );
	}

	/**
	 * Whether a filename's extension is one this site lets people upload.
	 *
	 * @param string      $filename The name.
	 * @param string|null $context  Optional context.
	 *
	 * @return bool
	 */
	public static function is_allowed( string $filename, ?string $context = null ): bool {
		$extension = Filename::extension( $filename );

		return '' !== $extension && in_array( $extension, self::allowed_extensions( $context ), true );
	}
}
