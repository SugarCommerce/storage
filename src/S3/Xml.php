<?php
/**
 * Reading the XML storage answers in.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\S3;

use SimpleXMLElement;

/**
 * Class Xml
 *
 * S3 answers in its 2006-03-01 namespace, some compatible providers in none,
 * and R2 leaves out the `<?xml` prolog. Every reader here takes an element's
 * children in whichever namespace that element itself is in, so all three
 * read the same.
 *
 * @since 1.0.0
 */
final class Xml {

	/**
	 * Parse a document.
	 *
	 * LIBXML_NONET: the document is a provider's answer, and nothing in it
	 * gets to make this process fetch anything else.
	 *
	 * @since 1.0.0
	 *
	 * @param string $xml The document.
	 *
	 * @return SimpleXMLElement|null Null when it is not XML.
	 */
	public static function load( string $xml ): ?SimpleXMLElement {
		$xml = trim( $xml );

		if ( '' === $xml ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$document = simplexml_load_string( $xml, SimpleXMLElement::class, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return false === $document ? null : $document;
	}

	/**
	 * The children of an element with one name.
	 *
	 * @since 1.0.0
	 *
	 * @param SimpleXMLElement $node Parent.
	 * @param string           $name Element name.
	 *
	 * @return array<int, SimpleXMLElement>
	 */
	public static function children( SimpleXMLElement $node, string $name ): array {
		$found = array();

		foreach ( $node->children( (string) ( $node->getNamespaces()[''] ?? '' ) ) as $child ) {
			if ( $child->getName() === $name ) {
				$found[] = $child;
			}
		}

		return $found;
	}

	/**
	 * The text of the first child with a name, or ''.
	 *
	 * @since 1.0.0
	 *
	 * @param SimpleXMLElement $node Parent.
	 * @param string           $name Element name.
	 *
	 * @return string
	 */
	public static function text( SimpleXMLElement $node, string $name ): string {
		return (string) ( self::children( $node, $name )[0] ?? '' );
	}
}
