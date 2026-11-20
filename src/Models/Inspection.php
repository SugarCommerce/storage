<?php
/**
 * Inspection
 *
 * @package     SugarCommerce\Storage
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Models;

/**
 * What an inspection found.
 *
 * Not the virus scanner's Verdict, which says whether a file is malware:
 * this says whether it is what it claims and the kind of thing the site is
 * willing to host.
 *
 * Three outcomes, because there are three things a host does next: store it,
 * store it and have a person look, or refuse it and remove the bytes.
 */
final readonly class Inspection {

	/**
	 * An inspection; made through {@see self::clean()}, {@see self::flagged()}
	 * or {@see self::blocked()}.
	 *
	 * @param string $state  ok | flagged | blocked
	 * @param string $reason What was found, in a sentence an operator can act
	 *                       on and an uploader can be told.
	 */
	private function __construct(
		public string $state,
		public string $reason = '',
	) {}

	/**
	 * Nothing found.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function clean(): self {
		return new self( 'ok' );
	}

	/**
	 * Worth a look, but not worth refusing on.
	 *
	 * @since 1.0.0
	 *
	 * @param string $reason What was found.
	 *
	 * @return self
	 */
	public static function flagged( string $reason ): self {
		return new self( 'flagged', $reason );
	}

	/**
	 * Refused. The bytes should not stay.
	 *
	 * @since 1.0.0
	 *
	 * @param string $reason What was found.
	 *
	 * @return self
	 */
	public static function blocked( string $reason ): self {
		return new self( 'blocked', $reason );
	}

	/**
	 * Whether the file may be stored at all.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function allowed(): bool {
		return 'blocked' !== $this->state;
	}
}
