<?php
/**
 * Folders
 *
 * @package     SugarCommerce\Storage
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Folders;

/**
 * The registered folders, the hooks they share, and the calls a download
 * endpoint makes.
 *
 * Static methods rather than the global functions this used to define:
 * every plugin bundles its own prefixed copy, and a global function only one
 * of them can declare would be whichever copy loaded first.
 *
 *     Folders::register( 'downloads', [
 *         'public_extensions' => [ 'jpg', 'png' ],
 *         'upload_for'        => [ 'product' ],
 *     ] );
 *
 *     Folders::deliver( 'downloads', $order->file_path, [ 'filename' => $name ] );
 */
final class Folders {

	/**
	 * The folders, by identifier.
	 *
	 * @var array<string, Folder>
	 */
	private static array $folders = [];

	/**
	 * Whether the shared hooks are attached.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Register a folder.
	 *
	 * @param string               $id     Its identifier, which is its directory name.
	 * @param array<string, mixed> $config Its configuration.
	 *
	 * @return Folder|null Null when the identifier is not one.
	 */
	public static function register( string $id, array $config = [] ): ?Folder {
		$folder = new Folder( $id, $config );

		if ( '' === $folder->id() ) {
			return null;
		}

		self::hook();

		self::$folders[ $folder->id() ] = $folder;

		if ( [] !== (array) $folder->get( 'upload_for', [] ) ) {
			add_filter( 'upload_dir', [ $folder, 'filter_upload_dir' ] );
		}

		return $folder;
	}

	/**
	 * Attach the hooks every folder shares, once.
	 *
	 * @return void
	 */
	private static function hook(): void {
		if ( self::$hooked ) {
			return;
		}

		self::$hooked = true;

		add_action( 'admin_init', [ __CLASS__, 'protect_all' ] );
	}

	/**
	 * Write the guard files for every folder that asked.
	 *
	 * @return void
	 */
	public static function protect_all(): void {
		foreach ( self::$folders as $folder ) {
			if ( $folder->get( 'auto_protect', true ) ) {
				$folder->protect();
			}
		}
	}

	/**
	 * A registered folder.
	 *
	 * @param string $id Its identifier.
	 *
	 * @return Folder|null
	 */
	public static function get( string $id ): ?Folder {
		return self::$folders[ sanitize_key( $id ) ] ?? null;
	}

	/**
	 * Every registered folder.
	 *
	 * @return array<string, Folder>
	 */
	public static function all(): array {
		return self::$folders;
	}

	/**
	 * Forget one, or all of them.
	 *
	 * @param string $id Its identifier, or empty for all.
	 *
	 * @return void
	 */
	public static function forget( string $id = '' ): void {
		if ( '' === $id ) {
			self::$folders = [];

			return;
		}

		unset( self::$folders[ sanitize_key( $id ) ] );
	}

	/**
	 * Send a file out of a protected folder.
	 *
	 * The path is resolved inside the folder, so a value that came from a
	 * request cannot be made to point at wp-config.php. Whether *this person*
	 * may have the file is the caller's question and this does not ask it.
	 *
	 * Exits when it finds the file. Returns false when it does not, so a
	 * caller can say something better than a 404.
	 *
	 * @param string               $id       The folder's identifier.
	 * @param string               $relative Where the file is inside it.
	 * @param array<string, mixed> $options  filename, mime_type, force_download, ranges.
	 *
	 * @return false
	 */
	public static function deliver( string $id, string $relative, array $options = [] ): bool {
		$path = self::get( $id )?->file( $relative );

		if ( null === $path ) {
			return false;
		}

		Delivery::send( $path, $options );
	}

	/**
	 * Whether the server actually refuses files in a folder.
	 *
	 * Asked over HTTP against a file written for the purpose, and cached for
	 * an hour. Checking that an .htaccess exists tells you an .htaccess
	 * exists. False for a folder that is not registered.
	 *
	 * @param string $id    The folder's identifier.
	 * @param bool   $force Ask again rather than using the cached answer.
	 *
	 * @return bool
	 */
	public static function is_protected( string $id, bool $force = false ): bool {
		return (bool) self::get( $id )?->is_protected( $force );
	}

	/**
	 * The configuration a server needs that cannot be configured from PHP.
	 *
	 * nginx has no per-directory file, so an .htaccess achieves nothing
	 * there. This is the text to give whoever administers the server.
	 *
	 * @param string $id The folder's identifier.
	 *
	 * @return string '' for a folder that is not registered.
	 */
	public static function server_rules( string $id ): string {
		return self::get( $id )?->rules_for_server() ?? '';
	}
}
