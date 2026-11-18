<?php
/**
 * A connection test for a bucket.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\S3;

/**
 * Class Probe
 *
 * Does, with a tiny object, everything a site will later do with real ones,
 * and says how each step went. A bucket that passes this works; one that fails
 * it would have failed the first real upload, at 100%, with an error naming
 * neither the cause nor the fix.
 *
 * Steps run in order and stop at the first failure -- a write that fails after
 * the keys were refused tells nobody anything new:
 *
 *   1. Reach  -- a HEAD for an object that is not there: 404 is the answer
 *               wanted. The endpoint is right and the keys were accepted.
 *   2. Write  -- PUT the test object, with the uploader's Origin, so the answer
 *               carries the CORS headers a browser upload would get.
 *   3. Read   -- GET it back by presigned URL, the way a download does, and
 *               check it is what was written.
 *   4. Delete -- remove it.
 *   5. Cors   -- would a browser on each origin be let upload? The policy is
 *               read and checked when the keys may read it; a well-scoped key
 *               often may not, so failing that the bucket is asked the way a
 *               browser asks -- a preflight per origin -- and the write's own
 *               answer shows whether ETag is exposed.
 *
 * Results are codes (ProbeFault), not sentences, so the site words them.
 * Every answer is capped at MAX_READ: the test object is a line and a CORS
 * policy a few rules, and a bucket sending more is not one this test needs to
 * read into memory.
 *
 *     $steps = ( new Probe() )->run( $connection, array( Cors\Origin::current() ) );
 *
 *     if ( ! Probe::passed( $steps ) ) { ... }
 *
 * @since 1.0.0
 */
final class Probe {

	/**
	 * Where the test object goes, by default.
	 */
	public const PREFIX = 'connection-test/';

	/**
	 * What the test object holds, by default, so reading it back proves
	 * something.
	 */
	public const BODY = "Connection test. Safe to delete.\n";

	/**
	 * Most bytes any answer in the test may carry.
	 */
	public const MAX_READ = 65536;

	/**
	 * Build a probe.
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
	 * @param Connection|Bucket  $target  A bucket somebody brought, or the
	 *                                    site's own.
	 * @param array<int, string> $origins Origins a browser uploader runs on.
	 *                                    None skips the CORS question.
	 *
	 * @return array<int, ProbeStep> One per ProbeCheck, in order. After the
	 *                               first failure the rest are Skipped.
	 */
	public function run( Connection|Bucket $target, array $origins = array() ): array {
		$bucket  = $target instanceof Connection ? $target->bucket() : $target;
		$signer  = $bucket->signer();
		$name    = $bucket->name();
		$key     = $this->prefix . bin2hex( random_bytes( 8 ) ) . '.txt';
		$origins = array_values( $origins );
		$put     = null;

		$checks = array(
			ProbeCheck::Reach->value  => fn(): ProbeStep => $this->reach( $signer, $name, $key ),
			ProbeCheck::Write->value  => function () use ( $signer, $name, $key, $origins, &$put ): ProbeStep {
				$put = $this->write( $signer, $name, $key, $origins[0] ?? '' );

				return self::step( ProbeCheck::Write, $put, Transport::is_success( $put['status'] ) );
			},
			ProbeCheck::Read->value   => fn(): ProbeStep => $this->read( $signer, $name, $key ),
			ProbeCheck::Delete->value => fn(): ProbeStep => $this->delete( $signer, $name, $key ),
			// By reference, not an arrow function: that would capture the
			// write's answer before there was one.
			ProbeCheck::Cors->value   => function () use ( $bucket, $key, $origins, &$put ): ProbeStep {
				return $this->cors( $bucket, $key, $origins, $put );
			},
		);

		$steps  = array();
		$failed = false;

		foreach ( $checks as $check => $run ) {
			$step    = $failed ? new ProbeStep( ProbeCheck::from( $check ), ProbeFault::Skipped ) : $run();
			$steps[] = $step;
			$failed  = $failed || ! $step->ok();
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
		foreach ( $steps as $step ) {
			if ( ! $step->ok() ) {
				return false;
			}
		}

		return array() !== $steps;
	}

	/**
	 * Step 1: ask about an object that is not there yet.
	 *
	 * It cannot tell a missing bucket from a missing object -- the write after
	 * it can.
	 *
	 * @param Signer $signer Signer.
	 * @param string $bucket Bucket name.
	 * @param string $key    Test object key.
	 *
	 * @return ProbeStep
	 */
	private function reach( Signer $signer, string $bucket, string $key ): ProbeStep {
		$request  = $signer->sign_head_object( $bucket, $key );
		$response = $this->send( 'HEAD', $request->url, $request->headers );

		return self::step( ProbeCheck::Reach, $response, in_array( $response['status'], array( 200, 404 ), true ) );
	}

	/**
	 * Step 2: write the test object.
	 *
	 * Sent with the uploader's Origin, unsigned, so the answer carries the
	 * bucket's CORS headers for an upload from that page.
	 *
	 * @param Signer $signer Signer.
	 * @param string $bucket Bucket name.
	 * @param string $key    Test object key.
	 * @param string $origin An origin the uploader runs on, or ''.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string, error: string}
	 */
	private function write( Signer $signer, string $bucket, string $key, string $origin ): array {
		$request = $signer->sign( Method::PUT, $bucket, $key, array(), $this->body, array( 'content-type' => 'text/plain' ) );
		$headers = $request->headers + ( '' === $origin ? array() : array( 'Origin' => $origin ) );

		return $this->send( 'PUT', $request->url, $headers, $this->body );
	}

	/**
	 * Step 3: read it back, the way a download does -- by presigned URL -- and
	 * check it is what was written.
	 *
	 * @param Signer $signer Signer.
	 * @param string $bucket Bucket name.
	 * @param string $key    Test object key.
	 *
	 * @return ProbeStep
	 */
	private function read( Signer $signer, string $bucket, string $key ): ProbeStep {
		$response = $this->send( 'GET', $signer->presign_get( $bucket, $key, 60 ) );

		if ( ! Transport::is_success( $response['status'] ) ) {
			return self::step( ProbeCheck::Read, $response, false );
		}

		return $this->body === $response['body']
			? new ProbeStep( ProbeCheck::Read, null, $response['status'] )
			: new ProbeStep( ProbeCheck::Read, ProbeFault::Altered, $response['status'] );
	}

	/**
	 * Step 4: remove the test object.
	 *
	 * @param Signer $signer Signer.
	 * @param string $bucket Bucket name.
	 * @param string $key    Test object key.
	 *
	 * @return ProbeStep
	 */
	private function delete( Signer $signer, string $bucket, string $key ): ProbeStep {
		$request  = $signer->sign_delete_object( $bucket, $key );
		$response = $this->send( 'DELETE', $request->url, $request->headers );

		return self::step( ProbeCheck::Delete, $response, Transport::is_success( $response['status'] ) );
	}

	/**
	 * Step 5: would a browser on each origin be allowed to upload?
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
			return new ProbeStep( ProbeCheck::Cors );
		}

		$stored = $bucket->cors();

		if ( $stored['ok'] && null !== $stored['policy'] ) {
			$verdict = $stored['policy']->supports_multipart_upload_from( $origins );

			return $verdict['ok']
				? new ProbeStep( ProbeCheck::Cors )
				: new ProbeStep( ProbeCheck::Cors, ProbeFault::CorsPolicy, 0, $verdict['reason'] );
		}

		foreach ( $origins as $origin ) {
			if ( ! $this->preflight( $bucket->signer()->presign_put( $bucket->name(), $key, 300 ), $origin ) ) {
				return new ProbeStep( ProbeCheck::Cors, ProbeFault::CorsOrigin, 0, $origin );
			}
		}

		return str_contains( strtolower( null === $put ? '' : Transport::header( $put, 'access-control-expose-headers' ) ), 'etag' )
			? new ProbeStep( ProbeCheck::Cors )
			: new ProbeStep( ProbeCheck::Cors, ProbeFault::EtagHidden );
	}

	/**
	 * Ask the bucket whether a browser on one origin may PUT.
	 *
	 * @param string $url    A URL the browser would PUT to.
	 * @param string $origin The page's origin.
	 *
	 * @return bool
	 */
	private function preflight( string $url, string $origin ): bool {
		$response = $this->send(
			'OPTIONS',
			$url,
			array(
				'Origin'                         => $origin,
				'Access-Control-Request-Method'  => 'PUT',
				'Access-Control-Request-Headers' => 'content-type',
			)
		);

		$allowed = Transport::header( $response, 'access-control-allow-origin' );
		$methods = strtoupper( Transport::header( $response, 'access-control-allow-methods' ) );

		return Transport::is_success( $response['status'] ) && in_array( $allowed, array( $origin, '*' ), true ) && str_contains( $methods, 'PUT' );
	}

	/**
	 * One request, its answer capped at MAX_READ.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     URL.
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $body    Body.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string, error: string}
	 */
	private function send( string $method, string $url, array $headers = array(), ?string $body = null ): array {
		return Transport::request( $method, $url, $headers, $body, array( 'limit_response_size' => self::MAX_READ ) );
	}

	/**
	 * A step decided by one response.
	 *
	 * @param ProbeCheck $check  What was tried.
	 * @param array      $answer What came back.
	 * @param bool       $passed Whether that counts as passing.
	 *
	 * @return ProbeStep
	 */
	private static function step( ProbeCheck $check, array $answer, bool $passed ): ProbeStep {
		if ( $passed ) {
			return new ProbeStep( $check, null, $answer['status'] );
		}

		$fault  = ProbeFault::of_status( $answer['status'] );
		$detail = ProbeFault::Unreachable === $fault ? ( '' !== $answer['error'] ? $answer['error'] : 'no answer' ) : '';

		return new ProbeStep( $check, $fault, $answer['status'], $detail );
	}
}
