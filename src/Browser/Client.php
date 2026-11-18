<?php
/**
 * S3 Client Class - Refactored with Traits
 *
 * Main client for interacting with S3-compatible storage.
 *
 * @package     SugarCommerce\Storage
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Browser;

use SugarCommerce\Storage\Browser\Utils\Sanitize;
use SugarCommerce\Storage\S3\Account;
use SugarCommerce\Storage\Browser\Traits\Client\Buckets;
use SugarCommerce\Storage\Browser\Traits\Client\Bucket;
use SugarCommerce\Storage\Browser\Traits\Client\Folder;
use SugarCommerce\Storage\Browser\Traits\Client\Files;
use SugarCommerce\Storage\Browser\Traits\Client\File;
use SugarCommerce\Storage\Browser\Traits\Client\PresignedUrls;
use SugarCommerce\Storage\Browser\Traits\Client\Batch;
use SugarCommerce\Storage\Browser\Traits\Client\Cors;
use SugarCommerce\Storage\Browser\Traits\Client\Options;
use SugarCommerce\Storage\Browser\Traits\Shared\Debug;
use SugarCommerce\Storage\Browser\Traits\Shared\Context;
use SugarCommerce\Storage\Browser\Api;

/**
 * Class Client
 */
class Client {
	use Buckets;
	use Bucket;
	use Folder;
	use Files;
	use File;
	use PresignedUrls;
	use Batch;
	// Aliased so the override below can set the flag before propagating.
	use Debug {
		set_debug as private set_debug_flag;
	}
	use Context;
	use Cors;
	use Options;

	/**
	 * Provider instance
	 *
	 * @var Provider
	 */
	private Account $account;

	/**
	 * Raw S3 API client
	 *
	 * @var Api
	 */
	private Api $api;

	/**
	 * Response cache.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Credential permission probe, built on first use.
	 *
	 * @var Permissions|null
	 */
	private ?Permissions $permissions = null;

	/**
	 * Constructor
	 *
	 * @param Account    $account    Storage account
	 * @param string      $access_key Access key ID
	 * @param string      $secret_key Secret access key
	 * @param bool        $use_cache  Whether to use cache
	 * @param int         $cache_ttl  Cache TTL in seconds
	 * @param bool        $debug      Whether to enable debug mode
	 * @param string|null $context    Optional. Context identifier for filtering and customization
	 */
	public function __construct(
		Account $account,
		string $access_key,
		string $secret_key,
		bool $use_cache = true,
		int $cache_ttl = 86400, // DAY_IN_SECONDS
		bool $debug = false,
		?string $context = null
	) {
		$this->account = $account;
		$this->api      = new Api( $account, $access_key, $secret_key );
		$this->cache    = new Cache( $use_cache, $cache_ttl, self::cache_prefix( $context ) );

		// Both objects carry their own debug flag, so setting only this one
		// left every debug call in the request path silently disabled — the
		// canonical request, the string to sign, the response body. Those are
		// exactly what a SignatureDoesNotMatch needs, and asking for debug got
		// you none of them.
		$this->set_debug( $debug );

		// Set context if provided
		if ( $context !== null ) {
			$this->set_context( $context );
		}
	}

	/**
	 * Enable or disable debug output
	 *
	 * A client owns an Api with its own flag, and setting only one of them is
	 * how debug output came to be unreachable: the canonical request, the
	 * string to sign and the response body all come from the Api, and those
	 * are exactly what a SignatureDoesNotMatch needs.
	 *
	 * @param bool $enable Whether to enable debug output.
	 *
	 * @return self
	 */
	public function set_debug( bool $enable ): self {
		$this->set_debug_flag( $enable );
		$this->api->set_debug( $enable );

		return $this;
	}

	/**
	 * Cache key prefix for this build of the library.
	 *
	 * Every Client used to take Cache's default prefix, so two plugins each
	 * shipping their own Strauss-prefixed copy addressed the same transients:
	 * one read back a response object built from the other's classes, which
	 * satisfies no return type here. The root namespace differs per build
	 * (WCR2\, EDDR2\) and is exactly the discriminator needed. $context
	 * separates several clients within a single plugin.
	 *
	 * @param string|null $context Optional context identifier.
	 *
	 * @return string
	 */
	private static function cache_prefix( ?string $context ): string {
		$parts = [ Sanitize::slug( strtok( __NAMESPACE__, '\\' ) ), 's3' ];

		if ( null !== $context && '' !== $context ) {
			$parts[] = Sanitize::slug( $context );
		}

		return implode( '_', array_filter( $parts ) ) . '_';
	}

	/**
	 * Get this client's response cache
	 *
	 * @return Cache
	 */
	public function cache(): Cache {
		return $this->cache;
	}

	/**
	 * Get the signing and transport layer
	 *
	 * @return Api
	 */
	public function api(): Api {
		return $this->api;
	}

	/**
	 * Get the credential permission probe
	 *
	 * @return Permissions
	 */
	public function permissions(): Permissions {
		return $this->permissions ??= new Permissions( $this, $this->cache );
	}

	/**
	 * The storage account requests go to
	 *
	 * @return Provider
	 */
	public function get_account(): Account {
		return $this->account;
	}
}
