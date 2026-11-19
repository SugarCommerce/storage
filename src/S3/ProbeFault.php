<?php
/**
 * What went wrong with one step of the connection test.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\S3;

/**
 * Enum ProbeFault
 *
 * A code as well as a sentence (ProbeStep::$message), so a caller can act on
 * which it was -- point at the field to fix, say it in its own words. Each
 * names what the person who typed the settings should check.
 *
 * @since 1.0.0
 */
enum ProbeFault: string {

	/** Not tried, because an earlier step failed. */
	case Skipped = 'skipped';

	/** No answer at all: DNS, TLS, a refused connection, a timeout. */
	case Unreachable = 'unreachable';

	/** Storage does not know the access key id (InvalidAccessKeyId). */
	case Key = 'key';

	/** The secret does not match the access key (SignatureDoesNotMatch). */
	case Secret = 'secret';

	/** 403: the keys were accepted but may not do this. */
	case Refused = 'refused';

	/** 404: no bucket by that name at that endpoint. */
	case Missing = 'missing';

	/** 301, 307 or 400: the bucket answered from another region. */
	case Elsewhere = 'elsewhere';

	/** Any other status; the step's status says which. */
	case Failed = 'failed';

	/** Read back something other than what was written. */
	case Altered = 'altered';

	/** The bucket's CORS policy will not let the uploader work; the detail says why. */
	case CorsPolicy = 'cors_policy';

	/** A browser preflight from an origin was refused; the detail is the origin. */
	case CorsOrigin = 'cors_origin';

	/** Uploads are allowed but ETag is not exposed, so every one fails at assembly. */
	case EtagHidden = 'etag_hidden';

	/**
	 * The fault a failed response stands for, from its status alone.
	 *
	 * @since 1.0.0
	 *
	 * @param int $status HTTP status, 0 for no answer.
	 *
	 * @return self
	 */
	public static function of_status( int $status ): self {
		return match ( true ) {
			0 === $status                                     => self::Unreachable,
			403 === $status                                   => self::Refused,
			404 === $status                                   => self::Missing,
			in_array( $status, array( 301, 307, 400 ), true ) => self::Elsewhere,
			default                                           => self::Failed,
		};
	}

	/**
	 * The fault a failed response stands for, from its status and storage's
	 * own code for it, which tells a wrong key from a key that may not.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $status HTTP status, 0 for no answer.
	 * @param string $code   Storage's error code, or ''.
	 *
	 * @return self
	 */
	public static function of( int $status, string $code ): self {
		return match ( $code ) {
			'InvalidAccessKeyId'                                 => self::Key,
			'SignatureDoesNotMatch'                              => self::Secret,
			'NoSuchBucket'                                       => self::Missing,
			'PermanentRedirect', 'AuthorizationHeaderMalformed', 'IllegalLocationConstraintException' => self::Elsewhere,
			'AccessDenied'                                       => self::Refused,
			// A 400 storage named is something wrong with the request, not
			// the region, unless it said so above.
			default                                              => '' !== $code && 400 === $status ? self::Failed : self::of_status( $status ),
		};
	}

	/**
	 * The settings field that most likely needs fixing.
	 *
	 * @since 1.0.0
	 *
	 * @param Provider $provider Who runs the bucket.
	 *
	 * @return string `access_key`, `secret_key`, `bucket`, `region`,
	 *                `account_id`, `endpoint`, or '' when it is not a field.
	 */
	public function field( Provider $provider ): string {
		return match ( $this ) {
			self::Key         => 'access_key',
			self::Secret      => 'secret_key',
			self::Missing     => 'bucket',
			self::Elsewhere   => 'region',
			self::Unreachable => $provider->needs_account_id() ? 'account_id' : ( $provider->needs_endpoint() ? 'endpoint' : 'region' ),
			default           => '',
		};
	}
}
