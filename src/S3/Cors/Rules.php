<?php
/**
 * CORS rules for the common cases.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\S3\Cors;

/**
 * Class Rules
 *
 * Ready-made rule sets, by what the bucket is for, as arrays in the shape S3
 * names its fields -- what a settings screen offers as a choice and what
 * Policy::from_array() reads.
 *
 * Every scenario that lets a browser upload exposes ETag. A multipart upload
 * reads each part's ETag back to assemble the object, and a rule that allows
 * the PUT but hides the ETag lets every part upload and then fails at the
 * last step (see Rule::browser_upload()).
 *
 * @since 1.0.0
 */
final class Rules {

	/**
	 * Rule templates, keyed by scenario.
	 *
	 * Each entry is a partial rule; origins and max-age are filled in by
	 * generate() so a caller can override them per call.
	 */
	private const SCENARIOS = array(
		'public_read'      => array(
			'ID'             => 'PublicRead',
			'AllowedMethods' => array( 'GET', 'HEAD' ),
			'AllowedHeaders' => array( 'Range' ),
			'ExposeHeaders'  => Rule::READ_HEADERS,
			'MaxAgeSeconds'  => 86400,
		),
		'upload_only'      => array(
			'ID'             => 'UploadOnly',
			'AllowedMethods' => array( 'PUT', 'POST' ),
			'AllowedHeaders' => array( 'Content-Type', 'Content-Length', 'Content-MD5', 'x-amz-*' ),
			'ExposeHeaders'  => array( 'ETag' ),
			'MaxAgeSeconds'  => 3600,
		),
		'presigned_upload' => array(
			'ID'             => 'PresignedUpload',
			'AllowedMethods' => array( 'PUT' ),
			'AllowedHeaders' => array( 'Content-Type', 'Content-Length', 'Content-MD5' ),
			'ExposeHeaders'  => array( 'ETag' ),
			'MaxAgeSeconds'  => 600,
		),
		'full_access'      => array(
			'ID'             => 'FullAccess',
			'AllowedMethods' => array( 'GET', 'PUT', 'POST', 'DELETE', 'HEAD' ),
			'AllowedHeaders' => array( '*' ),
			'ExposeHeaders'  => Rule::READ_HEADERS,
			'MaxAgeSeconds'  => 3600,
		),
	);

	/**
	 * Build the rules for a scenario.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $scenario One of scenarios(); anything else is
	 *                                       a read-only rule.
	 * @param array<int, string>   $origins  Origins the rule should allow.
	 * @param array<string, mixed> $extra    Overrides merged over the template;
	 *                                       `max_age` sets MaxAgeSeconds.
	 *
	 * @return array<int, array<string, mixed>> CORS rules.
	 */
	public static function generate( string $scenario = 'public_read', array $origins = array( '*' ), array $extra = array() ): array {
		if ( 'mixed' === $scenario ) {
			// Read stays open to everyone; only the write half is pinned to
			// the caller's origins.
			return array_merge(
				self::generate( 'public_read', array( '*' ) ),
				self::generate( 'upload_only', $origins, $extra )
			);
		}

		$rule = self::SCENARIOS[ $scenario ] ?? array(
			'ID'             => 'Custom',
			'AllowedMethods' => array( 'GET' ),
			'MaxAgeSeconds'  => 3600,
		);

		$rule['AllowedOrigins'] = $origins;

		if ( isset( $extra['max_age'] ) ) {
			$rule['MaxAgeSeconds'] = (int) $extra['max_age'];
			unset( $extra['max_age'] );
		}

		return array( array_merge( $rule, $extra ) );
	}

	/**
	 * The scenarios generate() understands.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string>
	 */
	public static function scenarios(): array {
		return array_merge( array_keys( self::SCENARIOS ), array( 'mixed' ) );
	}

	/**
	 * A scenario's rules as a Policy, ready for Bucket::set_cors().
	 *
	 * @since 1.0.0
	 *
	 * @param string               $scenario As for generate().
	 * @param array<int, string>   $origins  As for generate().
	 * @param array<string, mixed> $extra    As for generate().
	 *
	 * @return Policy
	 */
	public static function policy( string $scenario = 'public_read', array $origins = array( '*' ), array $extra = array() ): Policy {
		return Policy::from_array( self::generate( $scenario, $origins, $extra ) );
	}
}
