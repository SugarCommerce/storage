<?php
/**
 * Which storage account requests go to.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\S3;

/**
 * Class Account
 *
 * Identifies which storage account requests go to, and gives a signer for it.
 *
 * This replaces an interface, an abstract base and nine concrete subclasses.
 * Once the unreachable code was removed, what remained of each subclass was
 * data -- an id, a label, an endpoint pattern, an addressing style and a region
 * -- and all of it already existed in the Provider enum, which is where signing
 * needs it anyway. Two copies of that table could
 * disagree; one cannot.
 *
 * URLs are the signer's: it signs the URL it hands back, so the two cannot
 * disagree about how a key is encoded.
 *
 * @since 1.0.0
 */
final class Account {

	/**
	 * Which provider this is.
	 *
	 * @var Provider
	 */
	private Provider $type;

	/**
	 * Resolved region.
	 *
	 * @var string
	 */
	private string $region;

	/**
	 * Resolved endpoint host, without scheme.
	 *
	 * @var string
	 */
	private string $endpoint;

	/**
	 * Build a provider.
	 *
	 * @param Provider $type       Which provider.
	 * @param string       $region     Region. Empty uses the provider default.
	 * @param string       $account_id Account id — Cloudflare R2 only.
	 * @param string       $endpoint   Explicit host — MinIO and Other only.
	 *
	 * @throws \InvalidArgumentException When a required part is missing.
	 */
	public function __construct(
		Provider $type,
		string $region = '',
		string $account_id = '',
		string $endpoint = ''
	) {
		$this->type     = $type;
		$this->region   = '' !== trim( $region ) ? trim( $region ) : $type->default_region();
		$this->endpoint = $type->endpoint( $region, $account_id, $endpoint );
	}

	/**
	 * Cloudflare R2.
	 *
	 * @param string $account_id Cloudflare account id.
	 *
	 * @return self
	 */
	public static function r2( string $account_id ): self {
		return new self( Provider::R2, '', $account_id );
	}

	/**
	 * Amazon S3.
	 *
	 * @param string $region AWS region.
	 *
	 * @return self
	 */
	public static function aws( string $region = '' ): self {
		return new self( Provider::Aws, $region );
	}

	/**
	 * Any provider identified only by region.
	 *
	 * @param Provider $type   Which provider.
	 * @param string       $region Region.
	 *
	 * @return self
	 */
	public static function regional( Provider $type, string $region = '' ): self {
		return new self( $type, $region );
	}

	/**
	 * MinIO, Ceph, or any other S3-compatible endpoint.
	 *
	 * @param string $endpoint Host, optionally with a port.
	 * @param string $region   Region label.
	 *
	 * @return self
	 */
	public static function custom( string $endpoint, string $region = '' ): self {
		return new self( Provider::Other, $region, '', $endpoint );
	}

	/**
	 * Which provider this is.
	 *
	 * @since 1.0.0
	 *
	 * @return Provider
	 */
	public function provider(): Provider {
		return $this->type;
	}

	/**
	 * Provider identifier, e.g. 'r2'.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->type->value;
	}

	/**
	 * Human label, for admin output.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return $this->type->label();
	}

	/**
	 * Region this provider signs with.
	 *
	 * @return string
	 */
	public function get_region(): string {
		return $this->region;
	}

	/**
	 * Endpoint host, without scheme.
	 *
	 * @return string
	 */
	public function get_endpoint(): string {
		return $this->endpoint;
	}

	/**
	 * Whether the bucket goes in the path rather than the host.
	 *
	 * @return bool
	 */
	public function uses_path_style(): bool {
		return AddressingStyle::Path === $this->type->addressing();
	}

	/**
	 * A signer for this account.
	 *
	 * @since 1.0.0
	 *
	 * @param string $access_key Access key id.
	 * @param string $secret_key Secret access key.
	 *
	 * @return Signer
	 *
	 * @throws \InvalidArgumentException When a credential is empty.
	 */
	public function signer(
		#[\SensitiveParameter]
		string $access_key,
		#[\SensitiveParameter]
		string $secret_key
	): Signer {
		return new Signer(
			trim( $access_key ),
			trim( $secret_key ),
			$this->endpoint,
			$this->region,
			null,
			$this->uses_path_style() ? AddressingStyle::Path : AddressingStyle::VirtualHosted
		);
	}
}
