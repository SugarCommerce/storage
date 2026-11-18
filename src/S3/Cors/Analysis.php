<?php
/**
 * What a bucket's CORS rules permit.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\S3\Cors;

/**
 * Class Analysis
 *
 * Reads a bucket's CORS rules, as arrays in the shape S3 names its fields, and
 * reports what they permit -- for a settings screen that shows the bucket's
 * configuration back to whoever set it. Pure inspection: no requests, no
 * state.
 *
 * The questions that decide whether an uploader works are Policy's; this adds
 * the summary a person reads, and takes rules as they came, including ones a
 * Policy would refuse to build.
 *
 * @since 1.0.0
 */
final class Analysis {

	/**
	 * Methods a browser uses to upload an object.
	 */
	private const UPLOAD_METHODS = array( 'PUT', 'POST' );

	/**
	 * Methods that change the bucket, and so are worth warning about when
	 * any origin may call them.
	 */
	private const WRITE_METHODS = array( 'PUT', 'POST', 'DELETE' );

	/**
	 * A preflight cached beyond this is slow to correct after a rule change.
	 */
	private const LONG_CACHE = 86400;

	/**
	 * Describe what a rule set permits.
	 *
	 * With an origin, `multipart_upload` says whether a browser multipart
	 * upload from it will actually work -- PUT allowed and ETag exposed -- in
	 * Policy::supports_multipart_upload()'s words.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $rules  CORS rules.
	 * @param string|null                      $origin Origin an uploader runs on, or null.
	 *
	 * @return array<string, mixed> Analysis.
	 */
	public static function describe( array $rules, ?string $origin = null ): array {
		$analysis = array(
			'has_cors'             => (bool) $rules,
			'rules_count'          => count( $rules ),
			'supports_public_read' => false,
			'supports_upload'      => false,
			'supports_delete'      => false,
			'allows_all_origins'   => false,
			'max_cache_time'       => 0,
			'capabilities'         => array(),
			'security_warnings'    => array(),
		);

		foreach ( $rules as $rule ) {
			$methods = self::field( $rule, 'AllowedMethods' );
			$origins = self::field( $rule, 'AllowedOrigins' );
			$headers = self::field( $rule, 'AllowedHeaders' );

			if ( in_array( 'GET', $methods, true ) ) {
				$analysis['supports_public_read'] = true;
				$analysis['capabilities'][]       = 'read';
			}

			if ( array_intersect( self::UPLOAD_METHODS, $methods ) ) {
				$analysis['supports_upload'] = true;
				$analysis['capabilities'][]  = 'upload';
			}

			if ( in_array( 'DELETE', $methods, true ) ) {
				$analysis['supports_delete'] = true;
				$analysis['capabilities'][]  = 'delete';
			}

			if ( in_array( '*', $origins, true ) ) {
				$analysis['allows_all_origins'] = true;

				if ( array_intersect( self::WRITE_METHODS, $methods ) ) {
					$analysis['security_warnings'][] = __( 'Allows write operations from any origin (*)', 'sugarcommerce' );
				}
			}

			if ( in_array( '*', $headers, true ) ) {
				$analysis['security_warnings'][] = __( 'Allows all headers (*)', 'sugarcommerce' );
			}

			$analysis['max_cache_time'] = max( $analysis['max_cache_time'], (int) ( $rule['MaxAgeSeconds'] ?? 0 ) );
		}

		$analysis['capabilities']      = array_values( array_unique( $analysis['capabilities'] ) );
		$analysis['security_warnings'] = array_values( array_unique( $analysis['security_warnings'] ) );
		$analysis['recommendations']   = self::recommend( $analysis );

		if ( null !== $origin ) {
			$analysis['multipart_upload'] = Policy::from_array( $rules )->supports_multipart_upload( $origin );
		}

		return $analysis;
	}

	/**
	 * Whether a rule set permits uploads from an origin.
	 *
	 * Each rule is asked on its own: a rule naming the origin for GET and
	 * another allowing PUT from somewhere else do not add up to an upload.
	 * A wildcard covers every origin; otherwise it has to be named, scheme
	 * and port included -- S3 does no subdomain matching.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $rules  CORS rules.
	 * @param string                           $origin Origin to test.
	 *
	 * @return array{origin: string, allows_upload: bool, allowed_methods: array<int, string>, matching_rules: array<int, array<string, mixed>>, rules_checked: int}
	 */
	public static function allows_upload( array $rules, string $origin = '*' ): array {
		$methods  = array();
		$matching = array();

		foreach ( $rules as $rule ) {
			$parsed = self::rule( $rule );

			if ( null === $parsed || ! $parsed->covers( $origin ) ) {
				continue;
			}

			$allowed = array_values(
				array_filter( self::UPLOAD_METHODS, static fn( string $method ): bool => $parsed->allows( $origin, $method ) )
			);

			if ( ! $allowed ) {
				continue;
			}

			$methods    = array_merge( $methods, $allowed );
			$matching[] = $rule;
		}

		return array(
			'origin'          => $origin,
			'allows_upload'   => (bool) $matching,
			'allowed_methods' => array_values( array_unique( $methods ) ),
			'matching_rules'  => $matching,
			'rules_checked'   => count( $rules ),
		);
	}

	/**
	 * Every origin any rule allows.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $rules CORS rules.
	 *
	 * @return array<int, string>
	 */
	public static function origins( array $rules ): array {
		return self::collect( $rules, 'AllowedOrigins' );
	}

	/**
	 * Every method any rule allows.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $rules CORS rules.
	 *
	 * @return array<int, string>
	 */
	public static function methods( array $rules ): array {
		return self::collect( $rules, 'AllowedMethods' );
	}

	/**
	 * A rule array as a Rule, or null when S3 would not have accepted it.
	 *
	 * @param mixed $rule A rule.
	 *
	 * @return Rule|null
	 */
	private static function rule( mixed $rule ): ?Rule {
		if ( ! is_array( $rule ) ) {
			return null;
		}

		try {
			return Rule::from_array( $rule );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * One list field of a rule, tolerating a rule that lacks it.
	 *
	 * @param mixed  $rule  A rule.
	 * @param string $field Field name.
	 *
	 * @return array<int, string>
	 */
	private static function field( mixed $rule, string $field ): array {
		$value = is_array( $rule ) ? ( $rule[ $field ] ?? array() ) : array();

		if ( is_string( $value ) ) {
			return array( $value );
		}

		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	/**
	 * Gather one field across every rule.
	 *
	 * @param array<int, array<string, mixed>> $rules CORS rules.
	 * @param string                           $field Field to collect.
	 *
	 * @return array<int, string> Unique values.
	 */
	private static function collect( array $rules, string $field ): array {
		$values = array();

		foreach ( $rules as $rule ) {
			$values = array_merge( $values, self::field( $rule, $field ) );
		}

		return array_values( array_unique( $values ) );
	}

	/**
	 * Suggest changes based on an analysis.
	 *
	 * @param array<string, mixed> $analysis Output of describe().
	 *
	 * @return array<int, string> Recommendations.
	 */
	private static function recommend( array $analysis ): array {
		$recommendations = array();

		if ( $analysis['allows_all_origins'] && $analysis['supports_upload'] ) {
			$recommendations[] = __( 'Consider naming the origins allowed to upload instead of using "*".', 'sugarcommerce' );
		}

		if ( $analysis['allows_all_origins'] && $analysis['supports_delete'] ) {
			$recommendations[] = __( 'Allowing DELETE from any origin lets any site remove your objects.', 'sugarcommerce' );
		}

		if ( $analysis['max_cache_time'] > self::LONG_CACHE ) {
			$recommendations[] = __( 'A long preflight cache delays how quickly a CORS change takes effect.', 'sugarcommerce' );
		}

		if ( ! $analysis['has_cors'] ) {
			$recommendations[] = __( 'Add a CORS configuration if this bucket is read or written from a browser.', 'sugarcommerce' );
		}

		if ( $analysis['has_cors'] && ! $analysis['security_warnings'] ) {
			$recommendations[] = __( 'This CORS configuration looks sound.', 'sugarcommerce' );
		}

		return $recommendations;
	}
}
