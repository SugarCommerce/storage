<?php
/**
 * Bucket Operations Trait - Simplified Version
 *
 * Handles bucket-related operations for S3-compatible storage using
 * centralized XML parsing methods.
 *
 * @package     SugarCommerce\Storage\Browser\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Browser\Traits\Api;

use SugarCommerce\Storage\Browser\Interfaces\Response as ResponseInterface;
use SugarCommerce\Storage\Browser\Responses\BucketsResponse;
use SugarCommerce\Storage\Browser\Responses\ErrorResponse;
use SugarCommerce\Storage\Browser\Responses\SuccessResponse;
use InvalidArgumentException;
use SugarCommerce\Storage\Browser\Xml\Parser;
use SugarCommerce\Storage\Browser\Xml\Extract;
use SugarCommerce\Storage\Browser\Xml\Response;

/**
 * Trait Bucket
 */
trait Bucket {

	/**
	 * Get bucket location
	 *
	 * Retrieves the region where the bucket is located.
	 *
	 * @param string $bucket Bucket name
	 *
	 * @return ResponseInterface Response with location information
	 *
	 * @see   BucketsResponse For detailed response structure and methods
	 * @see   ErrorResponse For error response structure and methods
	 */
	public function get_bucket_location( string $bucket ): ResponseInterface {

		// Build URL for bucket location request
		$url = $this->account->build_url_with_query( $bucket, '', [ 'location' => '' ] );

		// Generate authorization headers
		$headers = $this->generate_auth_headers( 'GET', $bucket, '', [ 'location' => '' ] );

		// Add base request headers (including user agent)
		$headers = $this->get_base_request_headers( $headers );

		// Debug request
		$this->debug_request_details( 'get_bucket_location', $url, $headers );

		// Make request
		$response = wp_remote_get( $url, [
			'headers' => $headers,
			'timeout' => $this->get_operation_timeout( 'get_bucket_location' ),
		] );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response, 400, $this->account->get_endpoint() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug response
		$this->debug_response_details( 'get_bucket_location', $status_code, $body );

		// Check for error status code
		if ( $status_code < 200 || $status_code >= 300 ) {
			return Response::error( $status_code, $body, 'Failed to get bucket location' );
		}

		// Parse XML response
		$xml = Parser::parse( $body );
		if ( $xml instanceof ErrorResponse ) {
			return $xml;
		}

		// Extract location from XML
		$location = Extract::location( $xml, $this->account->get_region() );

		return new SuccessResponse(
			/* translators: %s: bucket name */
			sprintf( __( 'Bucket location retrieved for "%s"', 'sugarcommerce' ), $bucket ),
			$status_code,
			[
				'bucket'   => $bucket,
				'location' => $location,
			]
		);
	}
}
