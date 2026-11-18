<?php
/**
 * A storage call that did not produce an answer.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\S3;

use RuntimeException;
use WP_Error;

/**
 * Class StorageError
 *
 * Why storage said no, read from its error document. S3 and everything
 * compatible with it answer `<Error><Code>…</Code><Message>…</Message></Error>`:
 * the code says which fix -- a rolled secret (SignatureDoesNotMatch), a
 * revoked key (InvalidAccessKeyId), a missing bucket (NoSuchBucket) -- and the
 * message says it in words.
 *
 * Bucket hands these back as a WP_Error, through to_wp_error(), with the
 * status, the operation and storage's own code in the error's data. The
 * exception is for code that would rather throw one.
 *
 * @since 1.0.0
 */
final class StorageError extends RuntimeException {

	/**
	 * What was being done, e.g. "read".
	 *
	 * @var string
	 */
	public readonly string $operation;

	/**
	 * HTTP status, 0 when nothing answered.
	 *
	 * @var int
	 */
	public readonly int $status;

	/**
	 * Storage's own name for the refusal, or ''.
	 *
	 * @var string
	 */
	public readonly string $error_code;

	/**
	 * Storage's own sentence for the refusal, or ''.
	 *
	 * @var string
	 */
	public readonly string $reason;

	/**
	 * Build one.
	 *
	 * @since 1.0.0
	 *
	 * @param string $operation  What was being done, e.g. "read".
	 * @param int    $status     HTTP status, 0 when nothing answered.
	 * @param string $detail     Start of the response, for the log.
	 * @param string $error_code Storage's own name for the refusal, e.g.
	 *                           "SignatureDoesNotMatch", or '' when it gave none.
	 * @param string $reason     Storage's own sentence for it, e.g. "Access
	 *                           Denied", or ''.
	 */
	public function __construct( string $operation, int $status, string $detail = '', string $error_code = '', string $reason = '' ) {
		$this->operation  = $operation;
		$this->status     = $status;
		$this->error_code = $error_code;
		$this->reason     = $reason;

		parent::__construct( sprintf( 'Storage %s failed (%d)%s', $operation, $status, '' === $detail ? '' : ': ' . substr( $detail, 0, 200 ) ) );
	}

	/**
	 * A refusal, read from storage's error document.
	 *
	 * Anything that is not an error document is kept as it came, tags
	 * stripped, for the log.
	 *
	 * @since 1.0.0
	 *
	 * @param string $operation What was being done.
	 * @param int    $status    HTTP status, 0 when nothing answered.
	 * @param string $body      The answer, or the transport's error.
	 *
	 * @return self
	 */
	public static function refused( string $operation, int $status, string $body ): self {
		$code    = 1 === preg_match( '#<Code>([^<]*)</Code>#', $body, $found ) ? $found[1] : '';
		$message = 1 === preg_match( '#<Message>([^<]*)</Message>#', $body, $found ) ? $found[1] : '';
		$detail  = '' !== $code ? trim( $code . ': ' . $message, ': ' ) : trim( strip_tags( $body ) );

		return new self( $operation, $status, $detail, $code, html_entity_decode( $message, ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
	}

	/**
	 * The same refusal, as WordPress reports one.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code The WP_Error's code. Default `s3_{operation}_failed`.
	 *
	 * @return WP_Error With `status`, `operation`, `error_code` (storage's
	 *                  own) and `reason` (storage's own words) in its data.
	 */
	public function to_wp_error( string $code = '' ): WP_Error {
		return new WP_Error(
			'' === $code ? 's3_' . $this->operation . '_failed' : $code,
			$this->getMessage(),
			array(
				'status'     => $this->status,
				'operation'  => $this->operation,
				'error_code' => $this->error_code,
				'reason'     => $this->reason,
			)
		);
	}
}
