<?php
/**
 * S3 API Client
 *
 * Core implementation of AWS Signature Version 4 for S3-compatible storage.
 *
 * @package     SugarCommerce\Storage
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Browser;

use SugarCommerce\Storage\S3\Account;
use SugarCommerce\Storage\Browser\Interfaces\Api as ApiInterface;
use SugarCommerce\Storage\Browser\Traits\Api\Signing;
use SugarCommerce\Storage\Browser\Traits\Api\Buckets;
use SugarCommerce\Storage\Browser\Traits\Api\Bucket;
use SugarCommerce\Storage\Browser\Traits\Api\Files;
use SugarCommerce\Storage\Browser\Traits\Api\File;
use SugarCommerce\Storage\Browser\Traits\Api\PresignedUrls;
use SugarCommerce\Storage\Browser\Traits\Api\Batch;
use SugarCommerce\Storage\Browser\Traits\Api\Headers;
use SugarCommerce\Storage\Browser\Traits\Api\Cors;
use SugarCommerce\Storage\Browser\Traits\Shared\Debug;
use SugarCommerce\Storage\Browser\Traits\Shared\Timeouts;

/**
 * Class Api
 */
class Api implements ApiInterface {
	use Signing;
	use Buckets;
	use Bucket;
	use Files;
	use File;
	use PresignedUrls;
	use Batch;
	use Headers;
	use Debug;
	use Timeouts;
	use Cors;

	/**
	 * Provider instance
	 *
	 * @var Provider
	 */
	private Account $account;

	/**
	 * Access key ID
	 *
	 * @var string
	 */
	private string $access_key;

	/**
	 * Secret access key
	 *
	 * @var string
	 */
	private string $secret_key;

	/**
	 * Constructor
	 *
	 * @param Account $account   Provider instance
	 * @param string   $access_key Access key ID
	 * @param string   $secret_key Secret access key
	 */
	public function __construct(
		Account $account,
		string $access_key,
		string $secret_key
	) {
		$this->account   = $account;
		$this->access_key = trim( $access_key );
		$this->secret_key = trim( $secret_key );
	}
}
