<?php
/**
 * Path
 *
 * @package     SugarCommerce\Storage
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Files;

use SugarCommerce\Plugin\Assets;

/**
 * Where a file is, and whether it is where it is supposed to be.
 *
 * The last of those is the point of this class. A plugin that serves paid
 * downloads takes a path from somewhere — a query argument, a database row, a
 * REST parameter — and hands it to something that reads bytes off disk. If
 * that path can be made to point outside the folder it is meant to be in, the
 * download endpoint reads wp-config.php.
 *
 * This used to be guarded with `str_replace( '..', '', $path )`, which is the
 * canonical broken version: `....//` has its inner `..` removed and comes out
 * as `../`. A check that can be defeated by writing the attack twice is worse
 * than no check at all, because callers stop looking for a real one.
 *
 * What actually works is resolving both paths and comparing them, which is
 * what within() does — and it is the only function here anything security
 * related should be calling.
 */
final class Path {

	/**
	 * Resolve a path and confirm it is inside a directory.
	 *
	 * Both sides are resolved with realpath(), so `..`, symlinks, doubled
	 * slashes and Windows separators are all gone before the comparison —
	 * there is nothing left to be clever with.
	 *
	 * The candidate has to exist. That is not a limitation: a path that does
	 * not resolve is not a file anybody is about to read, and returning
	 * "safe" for one would be answering a question nobody asked.
	 *
	 *     $file = Path::within( $downloads_dir, $requested );
	 *
	 *     if ( null === $file ) {
	 *         wp_die( 'No.' );
	 *     }
	 *
	 * @param string $directory Where it has to be.
	 * @param string $candidate The path in question, absolute or relative to it.
	 *
	 * @return string|null The resolved path, or null when it is not in there.
	 */
	public static function within( string $directory, string $candidate ): ?string {
		if ( '' === $directory || '' === $candidate ) {
			return null;
		}

		// A null byte truncates the string for every C function underneath
		// PHP, so "safe.jpg\0../../wp-config.php" passes an extension check
		// and opens something else. realpath() rejects one, but only after
		// this has been said out loud.
		if ( str_contains( $candidate, "\0" ) || str_contains( $directory, "\0" ) ) {
			return null;
		}

		// A stream wrapper is not a path. phar:// in particular turns a file
		// read into object instantiation, which is a remote code execution
		// bug wearing a filename.
		if ( 1 === preg_match( '#^[a-z0-9.+-]+://#i', $candidate ) ) {
			return null;
		}

		$base = realpath( $directory );

		if ( false === $base ) {
			return null;
		}

		$target = realpath( self::is_absolute( $candidate ) ? $candidate : $base . '/' . ltrim( $candidate, '/\\' ) );

		if ( false === $target ) {
			return null;
		}

		$base   = self::normalize( $base );
		$target = self::normalize( $target );

		// The separator matters: without it, /var/www/uploads-public passes
		// as being inside /var/www/uploads.
		return $target === $base || str_starts_with( $target, $base . '/' ) ? $target : null;
	}

	/**
	 * Whether a path names a place rather than a way to get there.
	 *
	 * @param string $path The path.
	 *
	 * @return bool
	 */
	public static function is_absolute( string $path ): bool {
		return str_starts_with( $path, '/' )
			|| str_starts_with( $path, '\\' )
			|| 1 === preg_match( '#^[a-zA-Z]:[\\\\/]#', $path );
	}

	/**
	 * Forward slashes, no doubles.
	 *
	 * wp_normalize_path() when WordPress is here, which is almost always —
	 * this only stands in for it so the class can be reasoned about, and
	 * tested, without booting anything.
	 *
	 * @param string $path The path.
	 *
	 * @return string
	 */
	public static function normalize( string $path ): string {
		if ( function_exists( 'wp_normalize_path' ) ) {
			return wp_normalize_path( $path );
		}

		$path = str_replace( '\\', '/', $path );
		$path = (string) preg_replace( '|(?<=.)/+|', '/', $path );

		return str_starts_with( $path, '//' ) ? '/' . ltrim( $path, '/' ) : $path;
	}

	/**
	 * A local URL's path on disk.
	 *
	 * Uploads first, because that is where nearly everything is. The file
	 * has to be inside the folder its address names, resolved, so `..` in
	 * the address cannot climb out of it. Each branch
	 * confirms the file is actually there — a URL that maps to nothing is not
	 * a path, and handing one back means the caller finds out two functions
	 * later.
	 *
	 * @param string $url The URL, or a path already.
	 *
	 * @return string|null
	 */
	public static function from_url( string $url ): ?string {
		if ( '' === $url ) {
			return null;
		}

		if ( ! str_contains( $url, '://' ) ) {
			return file_exists( $url ) ? $url : null;
		}

		$uploads = wp_upload_dir();

		foreach (
			[
				[ (string) ( $uploads['baseurl'] ?? '' ), (string) ( $uploads['basedir'] ?? '' ) ],
				[ content_url(), WP_CONTENT_DIR ],
				[ home_url(), untrailingslashit( ABSPATH ) ],
			] as [ $prefix, $directory ]
		) {
			if ( '' === $prefix || ! str_starts_with( $url, $prefix ) ) {
				continue;
			}

			// The query string is not part of the path. A URL carrying a
			// cache buster used to map to a filename with `?ver=2` on the end
			// of it, which exists nowhere.
			$relative = (string) strtok( substr( $url, strlen( $prefix ) ), '?#' );

			// Inside the folder the address names, as within() decides: an
			// uploads URL ending `../../wp-config.php` used to come back as
			// the path of wp-config.php.
			$relative = ltrim( $relative, '/' );

			return '' === $relative ? null : self::within( $directory, $relative );
		}

		return null;
	}

	/**
	 * A local path's URL.
	 *
	 * Uploads first: they can be configured to live outside the content
	 * folder, at a URL of their own that only wp_upload_dir() knows. Anything
	 * else is SugarCommerce\Plugin\Assets::path_to_url()'s -- the content
	 * folder, the WordPress root, and plugins symlinked in from elsewhere.
	 *
	 * @param string $path The path.
	 *
	 * @return string|null Null when WordPress serves nothing there.
	 */
	public static function to_url( string $path ): ?string {
		if ( '' === $path ) {
			return null;
		}

		$path    = self::normalize( $path );
		$uploads = wp_upload_dir();
		$base    = rtrim( self::normalize( (string) ( $uploads['basedir'] ?? '' ) ), '/' );

		if ( '' !== $base && str_starts_with( $path, $base . '/' ) ) {
			return rtrim( (string) ( $uploads['baseurl'] ?? '' ), '/' ) . substr( $path, strlen( $base ) );
		}

		return Assets::path_to_url( $path );
	}

	/**
	 * Whether something names a file on this site's disk.
	 *
	 * @param string $file A URL or a path.
	 *
	 * @return bool
	 */
	public static function is_local( string $file ): bool {
		return null !== self::from_url( $file );
	}
}
