<?php
/**
 * Runtime names
 *
 * @package     SugarCommerce\Storage
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Utils;

/**
 * Every runtime string this package registers, derived from its own namespace.
 *
 * Strauss rewrites class namespaces and leaves string literals alone. Two
 * plugins each bundling a prefixed copy of this package would otherwise
 * register identical hooks, script handles and transient keys -- so one
 * plugin's cached answer about its own protected folder would be read as the
 * other's, and a filter one of them added would steer both.
 *
 * The derivation uses the one thing Strauss does rewrite: this file's
 * namespace. In a prefixed build `__NAMESPACE__` begins with the consumer's
 * prefix ("MyPlugin\SugarCommerce\Storage\Utils"), unique per plugin by
 * construction, so every name comes out distinct with no configuration.
 *
 * Unprefixed, a hook is `sugarcommerce_storage_{name}`; in a build prefixed
 * `MyPlugin\` it is `myplugin_sugarcommerce_storage_{name}`.
 */
final class Runtime {

	/**
	 * This package's own identifier, used when running unprefixed.
	 */
	private const LIBRARY = 'sugarcommerce_storage';

	/**
	 * How many segments this class's own namespace has --
	 * SugarCommerce\Storage\Utils -- before any prefix. Counted rather than
	 * written out: Strauss rewrites a namespace it finds in a string, and
	 * would prefix the very thing being compared against.
	 */
	private const OWN_SEGMENTS = 3;

	/**
	 * The per-build prefix.
	 *
	 * @return string
	 */
	public static function prefix(): string {
		return self::prefix_for( __NAMESPACE__ );
	}

	/**
	 * The prefix a given namespace would produce.
	 *
	 * Split out so the rule can be tested: prefix() reads __NAMESPACE__,
	 * which cannot change at runtime, so a test reaches the prefixed case by
	 * asking the rule directly.
	 *
	 * @param string $under The namespace this class is compiled under, prefixed or not.
	 *
	 * @return string
	 */
	public static function prefix_for( string $under ): string {
		$under = explode( '\\', trim( $under, '\\' ) );

		// What Strauss put in front of this package's own namespace. A
		// plugin of the SugarCommerce family prefixes with a namespace that
		// starts "SugarCommerce\" too -- "SugarCommerce\Playground\Vendor\"
		// -- so the first segment alone cannot tell a prefixed build from an
		// unprefixed one: it named both "sugarcommerce_storage", and that
		// plugin's hooks were every unprefixed copy's.
		$segments = array_slice( $under, 0, -self::OWN_SEGMENTS );

		if ( [] === $segments || '' === $segments[0] ) {
			return self::LIBRARY;
		}

		// Only the first segment, as it always was, where that is enough:
		// "SugarCart\Vendor" is "sugarcart". A family plugin's prefix needs
		// all of its segments to be told apart from the library and from
		// its siblings.
		$name = 'SugarCommerce' === $segments[0] ? implode( '_', $segments ) : $segments[0];
		$slug = strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9]+/', '_', $name ), '_' ) );

		return '' === $slug ? self::LIBRARY : $slug . '_' . self::LIBRARY;
	}

	/**
	 * A hook name for this build.
	 *
	 * @param string $name What the hook is for.
	 *
	 * @return string
	 */
	public static function hook( string $name ): string {
		return self::prefix() . '_' . $name;
	}

	/**
	 * An option or transient key for this build.
	 *
	 * @param string $suffix Optional key suffix.
	 *
	 * @return string
	 */
	public static function key( string $suffix = '' ): string {
		return '' === $suffix ? self::prefix() : self::prefix() . '_' . $suffix;
	}

	/**
	 * A script or style handle for this build.
	 *
	 * Hyphenated, as handles are.
	 *
	 * @param string $suffix Optional handle suffix.
	 *
	 * @return string
	 */
	public static function handle( string $suffix = '' ): string {
		$base = str_replace( '_', '-', self::prefix() );

		return '' === $suffix ? $base : $base . '-' . $suffix;
	}
}
