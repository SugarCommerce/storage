<?php
/**
 * Assets
 *
 * @package     SugarCommerce\Storage
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Utils;

/**
 * This package's own stylesheets, scripts and icons, wherever it was installed.
 *
 * A library has no plugin file to hand plugins_url(), and it sits wherever
 * Composer or Strauss put it: in a plugin's vendor directory, under a
 * prefixed `libraries/` folder, or symlinked in from somewhere else during
 * development. So the assets directory is found from this file -- two levels
 * up is the package root in every one of those layouts -- and turned into a
 * URL through the directories WordPress knows the URLs of.
 *
 * Versions are the file's modification time, so a changed stylesheet is
 * never served stale from a browser's cache.
 */
final class Assets {

	/**
	 * The assets directory's URL, once worked out. False when it has none.
	 *
	 * @var string|false|null
	 */
	private static string|false|null $url = null;

	/**
	 * The assets directory on disk.
	 *
	 * @return string
	 */
	public static function path(): string {
		return dirname( __DIR__, 2 ) . '/assets';
	}

	/**
	 * The URL of a file under assets/, or null when there is none.
	 *
	 * @param string $file Path relative to assets/, e.g. `css/browser.css`.
	 *
	 * @return string|null
	 */
	public static function url( string $file ): ?string {
		if ( null === self::file( $file ) ) {
			return null;
		}

		if ( null === self::$url ) {
			self::$url = self::path_to_url( self::path() ) ?? false;
		}

		return false === self::$url ? null : self::$url . '/' . ltrim( $file, '/' );
	}

	/**
	 * A file under assets/ on disk, or null when it is not there.
	 *
	 * The relative path is meant to come from code, but one built from a
	 * setting -- an icon name, say -- has been handed to a user, and
	 * `../../wp-config.php` reads as an asset path to everything below. Nothing
	 * legitimate leaves the assets directory.
	 *
	 * @param string $file Path relative to assets/.
	 *
	 * @return string|null
	 */
	public static function file( string $file ): ?string {
		if ( '' === $file || str_contains( $file, "\0" ) ) {
			return null;
		}

		if ( in_array( '..', explode( '/', str_replace( '\\', '/', $file ) ), true ) ) {
			return null;
		}

		$path = self::path() . '/' . ltrim( $file, '/' );

		return is_file( $path ) ? $path : null;
	}

	/**
	 * Register and enqueue a stylesheet from assets/.
	 *
	 * @param string   $handle Handle.
	 * @param string   $file   Path relative to assets/.
	 * @param string[] $deps   Dependencies.
	 *
	 * @return bool Whether the file was found.
	 */
	public static function enqueue_style( string $handle, string $file, array $deps = [] ): bool {
		$url = self::url( $file );

		if ( null === $url ) {
			return false;
		}

		wp_enqueue_style( $handle, $url, $deps, self::version( $file ) );

		return true;
	}

	/**
	 * Register and enqueue a script from assets/, in the footer.
	 *
	 * @param string   $handle Handle.
	 * @param string   $file   Path relative to assets/.
	 * @param string[] $deps   Dependencies.
	 *
	 * @return bool Whether the file was found.
	 */
	public static function enqueue_script( string $handle, string $file, array $deps = [] ): bool {
		$url = self::url( $file );

		if ( null === $url ) {
			return false;
		}

		wp_enqueue_script( $handle, $url, $deps, self::version( $file ), [ 'in_footer' => true ] );

		return true;
	}

	/**
	 * A file's version: its modification time.
	 *
	 * @param string $file Path relative to assets/.
	 *
	 * @return string
	 */
	private static function version( string $file ): string {
		$path = self::file( $file );

		return null === $path ? '1.0.0' : (string) filemtime( $path );
	}

	/**
	 * Convert a path on disk to a URL.
	 *
	 * Tries the content directory, then the WordPress root. A path under
	 * neither is usually a symlinked plugin: __FILE__ resolves symlinks, so
	 * the real path can sit anywhere on disk. Core keeps a map from each
	 * plugin's path under wp-content to its real path for exactly this --
	 * plugins_url() reads it -- and it is read here too.
	 *
	 * @param string $path Absolute path.
	 *
	 * @return string|null
	 */
	public static function path_to_url( string $path ): ?string {
		$path = wp_normalize_path( $path );

		$url = self::url_within( $path, wp_normalize_path( WP_CONTENT_DIR ), content_url() )
			?? self::url_within( $path, wp_normalize_path( ABSPATH ), site_url( '/' ) );

		if ( null !== $url ) {
			return $url;
		}

		foreach ( (array) ( $GLOBALS['wp_plugin_paths'] ?? [] ) as $plugin_path => $real_path ) {
			$real_path = rtrim( wp_normalize_path( (string) $real_path ), '/' );

			if ( '' === $real_path || ! str_starts_with( $path, $real_path . '/' ) ) {
				continue;
			}

			$mapped = rtrim( wp_normalize_path( (string) $plugin_path ), '/' ) . substr( $path, strlen( $real_path ) );

			// The map only ever holds paths that differ, but a path that maps
			// to itself would recurse forever, so it is checked anyway.
			if ( $mapped !== $path ) {
				return self::path_to_url( $mapped );
			}
		}

		return null;
	}

	/**
	 * The URL of a path, if it sits under a base directory with a known URL.
	 *
	 * A prefix replacement, not str_replace(): that replaces every
	 * occurrence, so a base directory whose name recurs further down the path
	 * was rewritten twice.
	 *
	 * @param string $path     Normalised absolute path.
	 * @param string $base     Normalised base directory.
	 * @param string $base_url The base directory's URL.
	 *
	 * @return string|null
	 */
	private static function url_within( string $path, string $base, string $base_url ): ?string {
		$base = rtrim( $base, '/' );

		if ( '' === $base || ! str_starts_with( $path, $base . '/' ) ) {
			return null;
		}

		return rtrim( $base_url, '/' ) . substr( $path, strlen( $base ) );
	}

	/**
	 * Forget the worked-out URL. For tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$url = null;
	}
}
