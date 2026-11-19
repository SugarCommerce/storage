<?php
/**
 * One thing the connection test tries.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\S3;

/**
 * Enum ProbeCheck
 *
 * The steps of Probe::run(), in the order they run. Each is something a
 * plugin storing files in the bucket will later do for real.
 *
 * @since 1.0.0
 */
enum ProbeCheck: string {

	/** The endpoint answers, the keys are accepted and the bucket is listed. */
	case Reach = 'reach';

	/** The keys may write. */
	case Write = 'write';

	/** And read back what they wrote, by presigned URL, unchanged. */
	case Read = 'read';

	/** And delete it, which cleaning up a refused upload needs. */
	case Delete = 'delete';

	/** And start, list and abort a multipart upload: a file over 5 GB. */
	case Multipart = 'multipart';

	/** A browser on the uploader's page may upload straight to it. */
	case Cors = 'cors';

	/**
	 * The step as a verb with its preposition, for "may not write to".
	 *
	 * @since 1.0.0
	 *
	 * @return string Translated. '' for the CORS check, which is not a
	 *                request of its own.
	 */
	public function verb(): string {
		return match ( $this ) {
			self::Reach     => __( 'list', 'sugarcommerce' ),
			self::Write     => __( 'write to', 'sugarcommerce' ),
			self::Read      => __( 'read from', 'sugarcommerce' ),
			self::Delete    => __( 'delete from', 'sugarcommerce' ),
			self::Multipart => __( 'upload large files to', 'sugarcommerce' ),
			self::Cors      => '',
		};
	}

	/**
	 * A short name for the step, for a checklist.
	 *
	 * @since 1.0.0
	 *
	 * @return string Translated.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Reach     => __( 'Connect and list', 'sugarcommerce' ),
			self::Write     => __( 'Write a test file', 'sugarcommerce' ),
			self::Read      => __( 'Read it back', 'sugarcommerce' ),
			self::Delete    => __( 'Delete it', 'sugarcommerce' ),
			self::Multipart => __( 'Large-file uploads', 'sugarcommerce' ),
			self::Cors      => __( 'Uploads from this site', 'sugarcommerce' ),
		};
	}
}
