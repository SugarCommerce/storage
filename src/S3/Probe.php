<?php
/**
 * A connection test that does what a store will do.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\S3;

use SugarCommerce\Storage\S3\Cors\Policy;
use WP_Error;

/**
 * Class Probe
 *
 * Does, with a tiny object, everything a store will later do with real ones,
 * and says how each step went. A bucket that passes this works; one that
 * fails it would have failed the first real upload, at 100%, or the first
 * buyer's download, with an error naming neither the cause nor the fix.
 *
 * Steps run in order and stop at the first failure -- a write that fails
 * after the keys were refused tells nobody anything new:
 *
 *   1. Reach     -- list one key. The endpoint is right, the keys were
 *                   accepted and they may list the bucket. A wrong region
 *                   answers here, naming the right one.
 *   2. Write     -- PUT the test object, with the uploader's Origin, so the
 *                   answer carries the CORS headers a browser upload gets.
 *   3. Read      -- GET it back by presigned URL, the way a download does,
 *                   and check it is what was written.
 *   4. Delete    -- remove it.
 *   5. Multipart -- start an upload, list its parts and abort it: what a
 *                   file over 5 GB needs.
 *   6. Cors      -- would a browser on each origin be let upload? The
 *                   policy is read and checked when the keys may read it; a
 *                   well-scoped key often may not, so failing that the
 *                   bucket is asked the way a browser asks -- a preflight
 *                   per origin -- and the write's own answer shows whether
 *                   ETag is exposed. Whether Content-MD5 may be sent, so
 *                   parts carry a checksum, is a note, not a failure.
 *
 * The test object is removed whatever happens after it was written. Every
 * answer is capped at MAX_READ.
 *
 * @since 1.0.0
 */
final class Probe {

	/**
	 * Where the test object goes, by default.
	 *
	 * @since 1.0.0
	 */
	public const PREFIX = 'connection-test/';

	/**
	 * What the test object holds, by default, so reading it back proves
	 * something.
	 *
	 * @since 1.0.0
	 */
	public const BODY = "Connection test. Safe to delete.\n";

	/**
	 * Most bytes any answer in the test may carry.
	 *
	 * @since 1.0.0
	 */
	public const MAX_READ = 65536;

	/**
	 * Set up a test.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Key prefix for the test object. Keep it outside
	 *                       wherever real files live, so it can never be
	 *                       mistaken for one.
	 * @param string $body   The test object's contents.
	 */
	public function __construct(
		private readonly string $prefix = self::PREFIX,
		private readonly string $body = self::BODY
	) {}

	/**
	 * Run every step.
	 *
	 * @since 1.0.0
	 *
	 * @param Bucket             $bucket  The bucket, on its keys.
	 * @param array<int, string> $origins Origins a browser uploader runs on.
	 *                                    None skips the CORS question, which
	 *                                    then passes.
	 *
	 * @return array<int, ProbeStep> One per ProbeCheck, in order. After the
	 *                               first failure the rest are Skipped.
	 */
	public function run( Bucket $bucket, array $origins = array() ): array {
		$key     = $this->prefix . bin2hex( random_bytes( 8 ) ) . '.txt';
		$origins = array_values( array_filter( array_map( 'strval', $origins ) ) );
		$put     = null;
		$steps   = array();
		$failed  = false;
		$written = false;
		$removed = false;

		foreach ( ProbeCheck::cases() as $check ) {
			if ( $failed ) {
				$steps[] = new ProbeStep( $check, ProbeFault::Skipped, 0, '', __( 'Not tried, because an earlier step failed.', 'sugarcommerce' ) );

				continue;
			}

			$step = match ( $check ) {
				ProbeCheck::Reach     => $this->reach( $bucket ),
				ProbeCheck::Write     => $this->write( $bucket, $key, $origins[0] ?? '', $put ),
				ProbeCheck::Read      => $this->read( $bucket, $key ),
				ProbeCheck::Delete    => $this->delete( $bucket, $key ),
				ProbeCheck::Multipart => $this->multipart( $bucket, $key ),
				ProbeCheck::Cors      => $this->cors( $bucket, $key, $origins, $put ),
			};

			$written = $written || ( ProbeCheck::Write === $check && $step->ok() );
			$removed = $removed || ( ProbeCheck::Delete === $check && $step->ok() );
			$failed  = ! $step->ok();
			$steps[] = $step;
		}

		// A read that came back altered stops the test before the delete,
		// and the test object is not to be left behind for that.
		if ( $written && ! $removed ) {
			$bucket->delete( $key );
		}

		return $steps;
	}

	/**
	 * Whether every step passed.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, ProbeStep> $steps What run() returned.
	 *
	 * @return bool
	 */
	public static function passed( array $steps ): bool {
		return array() !== $steps && null === self::failure( $steps );
	}

	/**
	 * The step that failed, if one did.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, ProbeStep> $steps What run() returned.
	 *
	 * @return ProbeStep|null
	 */
	public static function failure( array $steps ): ?ProbeStep {
		foreach ( $steps as $step ) {
			if ( ! $step->ok() ) {
				return $step;
			}
		}

		return null;
	}

	/* ─── Steps ─────────────────────────────────────────────────────── */

	/**
	 * Step 1: list one key under the test prefix.
	 *
	 * @param Bucket $bucket The bucket.
	 *
	 * @return ProbeStep
	 */
	private function reach( Bucket $bucket ): ProbeStep {
		$request  = $bucket->signer()->sign_list_objects( $bucket->name(), 1, $this->prefix );
		$response = Transport::request( 'GET', $request->url, $request->headers, null, $this->capped() );

		return Transport::is_success( $response['status'] ) && str_contains( $response['body'], 'ListBucketResult' )
			? new ProbeStep( ProbeCheck::Reach, null, $response['status'] )
			: self::failed( ProbeCheck::Reach, $response, $bucket );
	}

	/**
	 * Step 2: write the test object, with the uploader's Origin.
	 *
	 * @param Bucket     $bucket The bucket.
	 * @param string     $key    Test object key.
	 * @param string     $origin An origin the uploader runs on, or ''.
	 * @param array|null $put    Set to the answer, for the CORS step.
	 *
	 * @return ProbeStep
	 */
	private function write( Bucket $bucket, string $key, string $origin, ?array &$put ): ProbeStep {
		$request = $bucket->signer()->sign( Method::PUT, $bucket->name(), $key, array(), $this->body, array( 'content-type' => 'text/plain' ) );
		$headers = $request->headers + ( '' === $origin ? array() : array( 'Origin' => $origin ) );
		$put     = Transport::request( 'PUT', $request->url, $headers, $this->body, $this->capped() );

		return Transport::is_success( $put['status'] )
			? new ProbeStep( ProbeCheck::Write, null, $put['status'] )
			: self::failed( ProbeCheck::Write, $put, $bucket );
	}

	/**
	 * Step 3: read it back by presigned URL, and compare.
	 *
	 * @param Bucket $bucket The bucket.
	 * @param string $key    Test object key.
	 *
	 * @return ProbeStep
	 */
	private function read( Bucket $bucket, string $key ): ProbeStep {
		$response = Transport::request( 'GET', $bucket->url( $key, 60 ), array(), null, $this->capped() );

		if ( ! Transport::is_success( $response['status'] ) ) {
			return self::failed( ProbeCheck::Read, $response, $bucket );
		}

		return $this->body === $response['body']
			? new ProbeStep( ProbeCheck::Read, null, $response['status'] )
			: new ProbeStep(
				ProbeCheck::Read,
				ProbeFault::Altered,
				$response['status'],
				'',
				__( 'The test file read back differently from how it was written. Something between this site and storage is changing files: a proxy, or a setting that compresses or rewrites them.', 'sugarcommerce' )
			);
	}

	/**
	 * Step 4: remove the test object.
	 *
	 * @param Bucket $bucket The bucket.
	 * @param string $key    Test object key.
	 *
	 * @return ProbeStep
	 */
	private function delete( Bucket $bucket, string $key ): ProbeStep {
		$request  = $bucket->signer()->sign_delete_object( $bucket->name(), $key );
		$response = Transport::request( 'DELETE', $request->url, $request->headers, null, $this->capped() );

		return Transport::is_success( $response['status'] )
			? new ProbeStep( ProbeCheck::Delete, null, $response['status'] )
			: self::failed( ProbeCheck::Delete, $response, $bucket );
	}

	/**
	 * Step 5: start a multipart upload, list its parts, abort it.
	 *
	 * @param Bucket $bucket The bucket.
	 * @param string $key    Test object key.
	 *
	 * @return ProbeStep
	 */
	private function multipart( Bucket $bucket, string $key ): ProbeStep {
		$upload = $bucket->start_upload( $key, 'text/plain' );

		if ( $upload instanceof WP_Error ) {
			return self::refused( ProbeCheck::Multipart, $upload, $bucket );
		}

		$listed  = null !== $bucket->parts( $key, $upload );
		$aborted = $bucket->abort_upload( $key, $upload );

		if ( $listed && $aborted ) {
			return new ProbeStep( ProbeCheck::Multipart, null, 200 );
		}

		return new ProbeStep(
			ProbeCheck::Multipart,
			ProbeFault::Failed,
			0,
			'',
			$listed
				? __( 'A large-file upload could be started but not cancelled. These keys need permission to abort multipart uploads, or unfinished uploads are left costing storage.', 'sugarcommerce' )
				: __( 'A large-file upload could be started, but storage would not list its parts, which resuming an interrupted upload needs.', 'sugarcommerce' )
		);
	}

	/**
	 * Step 6: would a browser on each origin be allowed to upload?
	 *
	 * @param Bucket             $bucket  The bucket.
	 * @param string             $key     Test object key.
	 * @param array<int, string> $origins Origins the uploader runs on.
	 * @param array|null         $put     The answer to the test write.
	 *
	 * @return ProbeStep
	 */
	private function cors( Bucket $bucket, string $key, array $origins, ?array $put ): ProbeStep {
		if ( array() === $origins ) {
			return new ProbeStep( ProbeCheck::Cors, null, 0, '', '', __( 'Not checked: no site address was given to check uploads from.', 'sugarcommerce' ) );
		}

		$stored = $bucket->cors();

		if ( $stored['ok'] && $stored['policy'] instanceof Policy ) {
			$verdict = $stored['policy']->supports_multipart_upload_from( $origins );

			if ( ! $verdict['ok'] ) {
				return new ProbeStep(
					ProbeCheck::Cors,
					ProbeFault::CorsPolicy,
					0,
					$verdict['reason'],
					sprintf(
						/* translators: %s: why the bucket's CORS rules do not allow it. */
						__( 'Uploads from this site\'s browser will not work: %s', 'sugarcommerce' ),
						$verdict['reason']
					)
				);
			}

			foreach ( $origins as $origin ) {
				if ( ! $stored['policy']->accepts( $origin, 'PUT', 'Content-MD5' ) ) {
					return new ProbeStep( ProbeCheck::Cors, null, 200, '', '', self::no_checksums() );
				}
			}

			return new ProbeStep( ProbeCheck::Cors, null, 200 );
		}

		// The keys may not read the policy, which a well-scoped key often
		// may not: ask the way a browser does.
		$url      = $bucket->signer()->presign_put( $bucket->name(), $key, 300 );
		$checksum = true;

		foreach ( $origins as $origin ) {
			if ( ! self::preflight( $url, $origin, 'content-type' ) ) {
				return new ProbeStep(
					ProbeCheck::Cors,
					ProbeFault::CorsOrigin,
					0,
					$origin,
					sprintf(
						/* translators: %s: a web origin, such as https://example.com. */
						__( 'The bucket\'s CORS rules do not let a browser on %s upload to it. Add a rule allowing PUT from that origin.', 'sugarcommerce' ),
						$origin
					)
				);
			}

			$checksum = $checksum && self::preflight( $url, $origin, 'content-type,content-md5' );
		}

		if ( ! str_contains( strtolower( Transport::header( $put ?? array( 'headers' => array() ), 'access-control-expose-headers' ) ), 'etag' ) ) {
			return new ProbeStep(
				ProbeCheck::Cors,
				ProbeFault::EtagHidden,
				0,
				'',
				__( 'The bucket\'s CORS rules allow uploads but do not expose the ETag header. Add ETag to ExposeHeaders, or large uploads cannot confirm their parts.', 'sugarcommerce' )
			);
		}

		return new ProbeStep( ProbeCheck::Cors, null, 200, '', '', $checksum ? '' : self::no_checksums() );
	}

	/* ─── Internals ─────────────────────────────────────────────────── */

	/**
	 * Ask the bucket, as a browser would, whether a page on one origin may
	 * PUT with these request headers.
	 *
	 * A CORS preflight, sent from here: the answer is the bucket's, whoever
	 * asks, so the uploader can know before its first part whether a
	 * Content-MD5 header will be let through.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url     A URL the browser would PUT to.
	 * @param string $origin  The page's origin.
	 * @param string $headers Request headers the browser would send,
	 *                        comma-separated and lower-case.
	 *
	 * @return bool
	 */
	public static function preflight( string $url, string $origin, string $headers ): bool {
		$response = Transport::request(
			'OPTIONS',
			$url,
			array(
				'Origin'                         => $origin,
				'Access-Control-Request-Method'  => 'PUT',
				'Access-Control-Request-Headers' => $headers,
			),
			null,
			array(
				'limit_response_size' => self::MAX_READ,
				'timeout'             => 15,
			)
		);

		$allowed = Transport::header( $response, 'access-control-allow-origin' );
		$methods = strtoupper( Transport::header( $response, 'access-control-allow-methods' ) );

		return Transport::is_success( $response['status'] ) && in_array( $allowed, array( $origin, '*' ), true ) && str_contains( $methods, 'PUT' );
	}

	/**
	 * Request arguments capping the answer.
	 *
	 * @return array<string, int>
	 */
	private function capped(): array {
		return array(
			'limit_response_size' => self::MAX_READ,
			'timeout'             => 15,
		);
	}

	/**
	 * The note for a bucket that will not take Content-MD5 from a browser.
	 *
	 * @return string
	 */
	private static function no_checksums(): string {
		return __( 'Uploads work, but the CORS rules do not allow the Content-MD5 header, so browser uploads go without a checksum per part. Add Content-MD5 (or *) to AllowedHeaders.', 'sugarcommerce' );
	}

	/**
	 * A step failed by one answer.
	 *
	 * @param ProbeCheck $check    What was tried.
	 * @param array      $response What Transport::request() returned.
	 * @param Bucket     $bucket   The bucket.
	 *
	 * @return ProbeStep
	 */
	private static function failed( ProbeCheck $check, array $response, Bucket $bucket ): ProbeStep {
		$error  = StorageError::refused( $check->value, (int) $response['status'], '' !== $response['body'] ? $response['body'] : (string) $response['error'] );
		$fault  = ProbeFault::of( $error->status, $error->error_code );
		$detail = match ( $fault ) {
			ProbeFault::Unreachable => (string) $response['error'],
			ProbeFault::Elsewhere   => self::region( $response ),
			default                 => $error->reason,
		};

		return new ProbeStep( $check, $fault, $error->status, $detail, self::say( $check, $fault, $error->status, $detail, $bucket ), '', $error->error_code );
	}

	/**
	 * A step failed by a WP_Error from Bucket.
	 *
	 * @param ProbeCheck $check  What was tried.
	 * @param WP_Error   $error  The refusal.
	 * @param Bucket     $bucket The bucket.
	 *
	 * @return ProbeStep
	 */
	private static function refused( ProbeCheck $check, WP_Error $error, Bucket $bucket ): ProbeStep {
		$data   = (array) $error->get_error_data();
		$status = (int) ( $data['status'] ?? 0 );
		$code   = (string) ( $data['error_code'] ?? '' );
		$fault  = ProbeFault::of( $status, $code );
		$detail = ProbeFault::Unreachable === $fault ? $error->get_error_message() : (string) ( $data['reason'] ?? '' );

		return new ProbeStep( $check, $fault, $status, $detail, self::say( $check, $fault, $status, $detail, $bucket ), '', $code );
	}

	/**
	 * The region a wrong-region answer names, or ''.
	 *
	 * @param array $response What Transport::request() returned.
	 *
	 * @return string
	 */
	private static function region( array $response ): string {
		$region = Transport::header( $response, 'x-amz-bucket-region' );

		if ( '' === $region && 1 === preg_match( '#<Region>([A-Za-z0-9\-]+)</Region>#', (string) $response['body'], $match ) ) {
			$region = $match[1];
		}

		return Validate::region( $region ) ? $region : '';
	}

	/**
	 * What went wrong, in a sentence for whoever fixes the settings.
	 *
	 * @param ProbeCheck $check  What was tried.
	 * @param ProbeFault $fault  What went wrong.
	 * @param int        $status HTTP status.
	 * @param string     $detail The step's detail.
	 * @param Bucket     $bucket The bucket.
	 *
	 * @return string
	 */
	public static function say( ProbeCheck $check, ProbeFault $fault, int $status, string $detail, Bucket $bucket ): string {
		$name = $bucket->name();

		return match ( $fault ) {
			ProbeFault::Unreachable => Transport::explain( $detail, $bucket->origin() ),
			ProbeFault::Key         => __( 'Storage does not recognise this access key. Paste the access key ID again, from the API token you created.', 'sugarcommerce' ),
			ProbeFault::Secret      => __( 'The secret key does not match the access key. Paste the secret again; most providers show it only once, when the token is created.', 'sugarcommerce' ),
			ProbeFault::Missing     => sprintf(
				/* translators: %s: bucket name. */
				__( 'There is no bucket called "%s" here. Check the bucket name, and the account ID or region it is under.', 'sugarcommerce' ),
				$name
			),
			ProbeFault::Elsewhere   => '' !== $detail
				? sprintf(
					/* translators: 1: bucket name, 2: a region, such as eu-west-2. */
					__( 'The bucket "%1$s" is in the %2$s region. Set the region to %2$s.', 'sugarcommerce' ),
					$name,
					$detail
				)
				: sprintf(
					/* translators: %s: bucket name. */
					__( 'The bucket "%s" answered from another region. Check the region setting.', 'sugarcommerce' ),
					$name
				),
			// R2 refuses a bucket that is not there the same way as one the
			// keys may not touch, so the first request cannot tell which.
			ProbeFault::Refused     => ProbeCheck::Reach === $check
				? sprintf(
					/* translators: %s: bucket name. */
					__( 'These keys may not list the bucket "%s", or there is no bucket by that name. Check the name, and give the API token read and write permission for it.', 'sugarcommerce' ),
					$name
				)
				: sprintf(
					/* translators: 1: what was tried, such as "write to", 2: bucket name. */
					__( 'These keys may not %1$s the bucket "%2$s". Give the API token read and write permission for it.', 'sugarcommerce' ),
					$check->verb(),
					$name
				),
			default                 => sprintf(
				/* translators: 1: an HTTP status code, 2: what was tried, such as "write to", 3: bucket name, 4: storage's own words. */
				__( 'Storage answered %1$d when asked to %2$s the bucket "%3$s". %4$s', 'sugarcommerce' ),
				$status,
				'' === $check->verb() ? __( 'check', 'sugarcommerce' ) : $check->verb(),
				$name,
				$detail
			),
		};
	}
}
