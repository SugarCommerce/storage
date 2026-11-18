<?php
/**
 * XML request bodies.
 *
 * @package     SugarCommerce\Storage\Browser\Xml
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     3.1.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Browser\Xml;

use SugarCommerce\Storage\S3\Cors\Policy;

/**
 * Class Builder
 *
 * Builds the XML bodies S3 expects for the handful of operations that take
 * one. These were a trait composed into the API client, but neither method
 * touches any state: given the same array they return the same string. As a
 * trait they could only be exercised by constructing a client and issuing a
 * request; as static functions they can be tested directly, which matters for
 * output that has to match a provider's parser exactly.
 */
final class Builder {

	/**
	 * CORS configuration XML from a list of rules.
	 *
	 * Written by S3\Cors\Policy, the one writer in the package, so the
	 * browser and a Bucket put the same document to storage. The rules have
	 * been through Validate::cors_rules() by the time they get here.
	 *
	 * @param array $cors_rules Rules in S3's field names (AllowedOrigins, AllowedMethods, ...).
	 *
	 * @return string XML string for CORS configuration
	 */
	public static function cors_configuration( array $cors_rules ): string {
		return Policy::from_array( $cors_rules )->to_xml();
	}

	/**
	 * Build XML for batch delete request with R2 compatibility
	 *
	 * @param array $object_keys Array of object keys
	 *
	 * @return string XML string
	 */
	public static function batch_delete( array $object_keys ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

		// Try without namespace first for R2 compatibility
		$xml .= '<Delete>' . "\n";
		$xml .= '  <Quiet>false</Quiet>' . "\n";

		foreach ( $object_keys as $key ) {
			$clean_key = rawurldecode( $key ); // Decode first to avoid double encoding
			$xml       .= '  <Object>' . "\n";
			$xml       .= '    <Key>' . htmlspecialchars( $clean_key, ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</Key>' . "\n";
			$xml       .= '  </Object>' . "\n";
		}

		$xml .= '</Delete>';

		return $xml;
	}
}
