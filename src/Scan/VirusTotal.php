<?php
/**
 * VirusTotal
 *
 * @package     SugarCommerce\Storage
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Scan;

/**
 * A VirusTotal lookup by digest.
 *
 * Asks whether anybody has already scanned these exact bytes. The file is
 * never sent, only its SHA-256, so a seller's unreleased album does not end
 * up in a third party's corpus. That makes it blind to anything new: a file
 * VirusTotal has never seen is unknown, which is not the same as infected
 * and is never treated as such.
 *
 * Works at any size, which is why it is the check for files too big to
 * stream to clamd.
 *
 * It judges the digest it is given. Give it one computed from the bytes
 * (Subject::digest()), never the one an upload arrived with: known malware
 * under a made-up digest is a file VirusTotal has "never seen".
 *
 * @since 1.0.0
 */
final readonly class VirusTotal implements Scanner {

	/**
	 * Engines that must agree before a file counts as malware.
	 *
	 * Two, not one. A single engine flagging a legitimate installer is
	 * common enough that one vote would block honest software; two
	 * independent engines agreeing rarely happens by accident.
	 */
	public const int ENGINES = 2;

	/**
	 * The files endpoint.
	 */
	public const string ENDPOINT = 'https://www.virustotal.com/api/v3/files/';

	/**
	 * The most of an answer that is read, in bytes.
	 */
	public const int MAX_RESPONSE = 1048576;

	/**
	 * Hold the key, and how many engines must agree.
	 *
	 * @since 1.0.0
	 *
	 * Asked through the WordPress HTTP API, so a test answers with
	 * the `pre_http_request` filter and nothing in a suite reaches
	 * VirusTotal.
	 *
	 * @param string $key     VirusTotal API key.
	 * @param int    $engines Engines that must agree. At least one.
	 * @param int    $timeout Seconds to wait.
	 */
	public function __construct(
		#[\SensitiveParameter]
		private string $key,
		private int $engines = self::ENGINES,
		private int $timeout = 20,
	) {}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	public function name(): string {
		return 'virustotal';
	}

	/**
	 * What var_dump() and print_r() show: the settings, with the key redacted.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function __debugInfo(): array {
		return array(
			'key'     => '' === $this->key ? '' : '[redacted]',
			'engines' => $this->engines,
			'timeout' => $this->timeout,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	public function accepts( int $bytes ): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	public function scan( Subject $subject ): Verdict {
		if ( '' === $subject->sha256 ) {
			return Verdict::unknown( 'no whole-file digest to look up' );
		}

		$response = wp_remote_get(
			self::ENDPOINT . $subject->sha256,
			array(
				'timeout'             => $this->timeout,
				'redirection'         => 0,
				// The answer is a page of JSON. Anything far past that is not
				// VirusTotal, and is not read into memory to find out.
				'limit_response_size' => self::MAX_RESPONSE,
				'headers'             => array(
					'x-apikey' => $this->key,
					'Accept'   => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return Verdict::error( 'VirusTotal could not be reached: ' . $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return match ( true ) {
			404 === $status                  => Verdict::unknown( 'VirusTotal has not seen this file' ),
			$status >= 200 && $status < 300 => self::judge( is_array( $body ) ? $body : array(), $this->engines ),
			default                          => Verdict::error( sprintf( 'VirusTotal answered %d', $status ) ),
		};
	}

	/**
	 * What the last analysis said.
	 *
	 * Clean only when some engine looked: an analysis in which every engine
	 * timed out, failed or could not read the type has no opinion, and is
	 * unknown — not a pass.
	 *
	 * @since 1.0.0
	 *
	 * @param array<mixed> $body    The /files/{id} answer.
	 * @param int          $engines Engines that must agree.
	 *
	 * @return Verdict Infected with the suggested label and the vote count;
	 *                 unknown when there is no analysis, or no engine judged.
	 */
	public static function judge( array $body, int $engines = self::ENGINES ): Verdict {
		$attributes = self::at( $body, 'data', 'attributes' );
		$stats      = self::at( $attributes, 'last_analysis_stats' );
		$count      = static fn ( string $key ): int => is_numeric( $stats[ $key ] ?? null ) ? max( 0, (int) $stats[ $key ] ) : 0;
		$votes      = $count( 'malicious' );

		if ( $votes >= max( 1, $engines ) ) {
			$label = self::at( $attributes, 'popular_threat_classification' )['suggested_threat_label'] ?? '';
			$label = is_string( $label ) ? $label : '';

			return Verdict::infected( sprintf( '%s (%d engines on VirusTotal)', '' === $label ? 'malicious' : $label, $votes ) );
		}

		if ( array() === $stats ) {
			return Verdict::unknown( 'VirusTotal has no analysis of this file' );
		}

		$judged = $votes + $count( 'suspicious' ) + $count( 'undetected' ) + $count( 'harmless' );

		return 0 === $judged ? Verdict::unknown( 'no engine on VirusTotal could judge this file' ) : Verdict::clean();
	}

	/**
	 * The array at a path into decoded JSON, or an empty one.
	 *
	 * @param array<mixed> $json    Decoded JSON.
	 * @param string       ...$path Keys, outermost first.
	 *
	 * @return array<mixed>
	 */
	private static function at( array $json, string ...$path ): array {
		foreach ( $path as $key ) {
			$next = $json[ $key ] ?? null;
			$json = is_array( $next ) ? $next : array();
		}

		return $json;
	}
}
