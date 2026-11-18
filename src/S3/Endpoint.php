<?php
/**
 * Endpoint, host and canonical URI construction.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\S3;

use InvalidArgumentException;

/**
 * Class Endpoint
 *
 * Where a request goes, and what its path looks like once the bucket has been
 * put wherever the addressing style says it belongs.
 *
 * These three answers have to agree with each other exactly: the `Host`
 * header and the canonical URI are both signed, so putting the bucket in the
 * hostname and *also* in the path produces a signature for a request nobody
 * made. Keeping them in one class is what makes that visible.
 *
 * @since 1.0.0
 */
final class Endpoint {

	/**
	 * Reduce a configured endpoint to a bare host.
	 *
	 * Callers reasonably paste `https://host/` out of a dashboard. Signing
	 * that verbatim produces a `Host` header of `https://host/`, which fails
	 * with an opaque signature error rather than a clear one.
	 *
	 * @since 1.0.0
	 *
	 * @param string $endpoint Raw endpoint.
	 *
	 * @return string Bare host, lower-cased, optionally with a port.
	 *
	 * @throws InvalidArgumentException When nothing usable remains.
	 */
	public static function normalize( string $endpoint ): string {
		$host = Address::host( $endpoint );

		if ( '' === $host || ! Validate::host( $host ) ) {
			throw new InvalidArgumentException( 'S3 endpoint must be a bare host, e.g. "s3.eu-west-2.amazonaws.com".' );
		}

		return $host;
	}

	/**
	 * The `Host` header for a request against a bucket.
	 *
	 * Under virtual-hosted addressing the bucket becomes a DNS label, so the
	 * name is checked here — an incompatible one would otherwise be signed
	 * into a hostname that cannot resolve, or whose wildcard certificate will
	 * not match. No bucket at all is a request to the service itself --
	 * ListBuckets -- which goes to the endpoint under either addressing.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $endpoint   Normalised host.
	 * @param string          $bucket     Bucket name, or '' for the service.
	 * @param AddressingStyle $addressing Where the bucket goes.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the bucket cannot be a DNS label.
	 */
	public static function host( string $endpoint, string $bucket, AddressingStyle $addressing ): string {
		if ( AddressingStyle::Path === $addressing || '' === $bucket ) {
			return $endpoint;
		}

		if ( ! Validate::dns_label( $bucket ) ) {
			throw new InvalidArgumentException(
				'Bucket "' . esc_html( $bucket ) . '" cannot be used with virtual-hosted addressing: '
				. 'it must be 3-63 characters of lowercase letters, digits and hyphens, with no dots.'
			);
		}

		return $bucket . '.' . $endpoint;
	}

	/**
	 * The canonical resource path for a bucket and optional key.
	 *
	 * Under virtual-hosted addressing the bucket lives in the hostname, so it
	 * must not appear in the path as well.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $bucket     Bucket name.
	 * @param string          $key        Object key, or '' for the bucket itself.
	 * @param AddressingStyle $addressing Where the bucket goes.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException On a `.` or `..` path segment, which
	 *                                   something between here and the bucket
	 *                                   would collapse after signing.
	 */
	public static function canonical_uri( string $bucket, string $key, AddressingStyle $addressing ): string {
		if ( ! Validate::segments( $bucket ) || ! Validate::segments( $key ) ) {
			throw new InvalidArgumentException( 'An S3 bucket or key cannot contain a "." or ".." path segment.' );
		}

		if ( AddressingStyle::VirtualHosted === $addressing ) {
			return '' === $key ? '/' : '/' . self::encode_key( $key );
		}

		$uri = '/' . rawurlencode( $bucket );

		if ( '' !== $key ) {
			$uri .= '/' . self::encode_key( $key );
		}

		return $uri;
	}

	/**
	 * Percent-encode an object key, keeping its slashes.
	 *
	 * Everything is encoded and then `/` is put back, so a multi-segment key
	 * keeps its hierarchy while every other character is escaped.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Raw object key. A leading slash is dropped.
	 *
	 * @return string
	 */
	public static function encode_key( string $key ): string {
		return str_replace( '%2F', '/', rawurlencode( ltrim( $key, '/' ) ) );
	}

	/**
	 * The canonical query string: sorted by name, RFC 3986 encoded.
	 *
	 * Sorted as strings: SigV4 orders the names byte by byte, and a plain
	 * ksort() compares numeric-looking names as numbers.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $query Unencoded parameters.
	 *
	 * @return string
	 */
	public static function canonical_query( array $query ): string {
		ksort( $query, SORT_STRING );

		return http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Encode a key for a request the browser or a REST route asked for.
	 *
	 * Stricter than encode_key(), for keys that arrived from outside: the key
	 * is decoded first, so one that came in already percent-encoded is not
	 * encoded twice, and a NUL byte or a `.` or `..` segment is refused
	 * rather than sent. Only whole segments count -- S3 permits dots anywhere
	 * in a key, so `mixes/v1..2.wav` and `report..final.pdf` are fine, and
	 * mangling them would be its own bug. The result is used both for the URL
	 * that goes on the wire and for the canonical URI, so the two cannot
	 * drift.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Object key, raw or already encoded.
	 *
	 * @return string Encoded key, '' for an empty one.
	 *
	 * @throws InvalidArgumentException On a NUL byte or a traversal segment.
	 */
	public static function request_key( string $key ): string {
		$key = ltrim( $key, '/' );

		if ( '' === $key ) {
			return '';
		}

		$decoded = rawurldecode( $key );

		// A NUL byte truncates the key in any C-backed consumer.
		if ( str_contains( $decoded, "\0" ) ) {
			throw new InvalidArgumentException( 'S3 object key contains a NUL byte.' );
		}

		// A backslash is a separator to Windows and to some proxies, so it
		// counts as one here.
		if ( ! Validate::segments( str_replace( '\\', '/', $decoded ) ) ) {
			throw new InvalidArgumentException( 'S3 object key contains a path traversal segment: ' . $key );
		}

		return str_replace( '%2F', '/', rawurlencode( $decoded ) );
	}
}
