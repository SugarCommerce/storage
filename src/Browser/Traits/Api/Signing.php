<?php
/**
 * Authentication Trait
 *
 * Adapts the package's S3 signer to the browser's signing interface.
 *
 * @package     SugarCommerce\Storage\Browser\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     2.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Browser\Traits\Api;

use SugarCommerce\Storage\S3\AddressingStyle;
use SugarCommerce\Storage\S3\Method;
use SugarCommerce\Storage\S3\Signer as SigV4;

/**
 * Trait Signing
 *
 * SigV4 itself lives in SugarCommerce\Storage\S3\Signer, which has its own
 * tests and is shared with everything else that talks to a bucket. This trait
 * is the seam: it maps the browser's storage account onto that signer and
 * returns headers in the shape the transport traits already expect.
 *
 * Keeping the signing rules in one package matters because the failure mode is
 * silent — a canonical request that does not describe the request actually
 * sent produces SignatureDoesNotMatch with no indication of which part is
 * wrong.
 */
trait Signing {

	/**
	 * Memoised signer, keyed by addressing host.
	 *
	 * @var array<string, SigV4>
	 */
	private array $sigv4 = [];

	/**
	 * Get a signer configured for this provider.
	 *
	 * @return SigV4
	 */
	private function sigv4(): SigV4 {
		$endpoint = $this->account->get_endpoint();

		if ( ! isset( $this->sigv4[ $endpoint ] ) ) {
			$this->sigv4[ $endpoint ] = new SigV4(
				$this->access_key,
				$this->secret_key,
				$endpoint,
				$this->account->get_region(),
				null,
				$this->account->uses_path_style()
					? AddressingStyle::Path
					: AddressingStyle::VirtualHosted
			);
		}

		return $this->sigv4[ $endpoint ];
	}

	/**
	 * Generate authorization headers for an S3 request
	 *
	 * @param string $method        HTTP method (GET, PUT, etc.)
	 * @param string $bucket        Bucket name
	 * @param string $object_key    Object key (if applicable)
	 * @param array  $query_params  Query parameters
	 * @param string $payload       Request payload (or empty string)
	 * @param array  $extra_headers Additional headers to include *in the
	 *                              signature*. S3 requires every x-amz-* header
	 *                              sent to appear in SignedHeaders, so anything
	 *                              like x-amz-copy-source belongs here rather
	 *                              than being bolted on afterwards.
	 *
	 * @return array Headers with AWS signature
	 */
	public function generate_auth_headers(
		string $method,
		string $bucket,
		string $object_key = '',
		array $query_params = [],
		string $payload = '',
		array $extra_headers = []
	): array {
		$query = array_map( 'strval', $query_params );

		$request = $this->sigv4()->sign(
			Method::from( strtoupper( $method ) ),
			$bucket,
			$object_key,
			$query,
			$payload,
			$extra_headers
		);

		$this->debug( 'Signed request', $request->url );

		// Ask for XML explicitly. Not signed, and not required to be: S3 only
		// verifies the headers named in SignedHeaders.
		return $request->headers + [ 'Accept' => 'application/xml' ];
	}
}
