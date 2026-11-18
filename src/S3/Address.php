<?php
/**
 * URL parsing.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\S3;

/**
 * Class Address
 *
 * Two kinds of address a plugin handles before it signs anything:
 *
 *   - the endpoint somebody pasted, reduced to its host ({@see self::host()}).
 *     A step that has nothing to do with S3, and leaving it inline meant
 *     {@see Endpoint::normalize()} opened with four unexplained lines before
 *     it got to the question it was actually asking;
 *   - the `s3://bucket/key` address a plugin stores to say "this file lives in
 *     a bucket" ({@see self::is()}, {@see self::parse()},
 *     {@see self::to()}). Every plugin that keeps files in storage writes its
 *     own parser for it otherwise, and they drift on the edge cases — a
 *     doubled slash, a bucket with no key.
 *
 * `parse_url()` is not used. It refuses a bare host with a port —
 * `localhost:9000` parses as scheme `localhost`, path `9000` — which is
 * exactly the case a self-hosted MinIO endpoint arrives as.
 *
 * @since 1.0.0
 */
final class Address {

	/**
	 * What a stored object-storage address starts with.
	 *
	 * Lower case and matched as written: `S3://` is not one. Stored paths
	 * are written by code, so a second spelling would only ever be a typo,
	 * and treating it as remote would send a local path to the signer.
	 *
	 * @since 1.0.0
	 */
	public const SCHEME = 's3://';

	/**
	 * The host part of whatever was given.
	 *
	 * Tolerant on purpose. Callers paste `https://host/` out of a dashboard,
	 * or type a bare host, or leave a trailing slash, or leave the root dot
	 * on a fully-qualified name. All four mean the same host, and the one
	 * thing they must not do is reach a `Host` header as written.
	 *
	 * No validation: whether the result is a *usable* host is a separate
	 * question, and one the caller is better placed to ask — see
	 * {@see Validate::host()}.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url A URL, or a host, or something in between.
	 *
	 * @return string The host, lower-cased, with any port kept. '' when
	 *                there is nothing host-shaped in it.
	 */
	public static function host( string $url ): string {
		$host = trim( $url );

		// A scheme, if there is one. Deliberately any scheme rather than
		// http and https alone: `s3://` and `r2://` both turn up in
		// configuration copied from other tools.
		$host = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*://#i', '', $host );

		// Credentials, before the path is cut. Left on, `key:secret@host`
		// fails the "is this a host" check further up the call and throws
		// "must be a bare host" about a string that looks like one.
		if ( str_contains( $host, '@' ) ) {
			$host = substr( $host, (int) strrpos( $host, '@' ) + 1 );
		}

		// Everything from the first slash, question mark or hash on.
		$host = (string) strtok( $host, '/?#' );

		// The root dot of a fully-qualified name. `example.com.` and
		// `example.com` are the same host and sign differently.
		return strtolower( rtrim( $host, '.' ) );
	}

	/**
	 * Whether a stored path names an object in storage.
	 *
	 * Only the scheme is looked at. Whether the rest names a bucket and a key
	 * is {@see self::parse()}'s question; this one is "is it ours to sign
	 * at all", asked before deciding between a bucket and the local disk.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path A stored path.
	 *
	 * @return bool
	 */
	public static function is( string $path ): bool {
		return str_starts_with( $path, self::SCHEME );
	}

	/**
	 * The bucket and key an `s3://bucket/key` address names.
	 *
	 * The bucket is everything up to the first slash and the key everything
	 * after it, with any slashes that open the key dropped:
	 * `s3://bucket//packs/a.zip` is the object `packs/a.zip`, because no
	 * storage console shows a key that starts with a slash and somebody
	 * joining a bucket and a path with one too many meant that. A slash that
	 * *ends* the key is kept — `packs/` is a folder's prefix, not `packs`.
	 *
	 * With `$scheme_optional`, `bucket/key` is accepted too, as typed into a
	 * field or a command line, and surrounding whitespace and leading slashes
	 * are forgiven. Off by default: a stored path without the scheme is a
	 * path on this server, and reading it as a bucket would be wrong.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path            An `s3://bucket/key` address.
	 * @param bool   $scheme_optional Accept `bucket/key` without the scheme.
	 *
	 * @return array{bucket: string, key: string}|null Null when it is not one,
	 *                                                 or names no bucket or
	 *                                                 no key.
	 */
	public static function parse( string $path, bool $scheme_optional = false ): ?array {
		if ( $scheme_optional ) {
			$path = ltrim( trim( $path ), '/' );
		}

		if ( self::is( $path ) ) {
			$rest = substr( $path, strlen( self::SCHEME ) );
		} elseif ( $scheme_optional ) {
			$rest = $path;
		} else {
			return null;
		}

		$slash = strpos( $rest, '/' );

		if ( false === $slash ) {
			return null;
		}

		$bucket = substr( $rest, 0, $slash );
		$key    = ltrim( substr( $rest, $slash + 1 ), '/' );

		if ( '' === $bucket || '' === $key ) {
			return null;
		}

		return array(
			'bucket' => $bucket,
			'key'    => $key,
		);
	}

	/**
	 * The `s3://bucket/key` address of an object, to store.
	 *
	 * The inverse of {@see self::parse()}: slashes opening the key are
	 * dropped so the two round-trip.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket The bucket.
	 * @param string $key    The object's key.
	 *
	 * @return string
	 */
	public static function to( string $bucket, string $key ): string {
		return self::SCHEME . $bucket . '/' . ltrim( $key, '/' );
	}
}
