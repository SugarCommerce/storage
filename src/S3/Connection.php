<?php
/**
 * Storage settings, checked when they are saved.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\S3;

use InvalidArgumentException;
use WP_Error;

/**
 * Class Connection
 *
 * The provider, bucket, keys, region, account id and endpoint a settings
 * screen collects, checked where they are saved rather than where they are
 * first used. A wrong account id or region otherwise shows up when a request
 * is signed -- and for a buyer that is a download that fails.
 *
 * Two checks, both returning a WP_Error whose data names the field to fix:
 *
 *   - problem() looks at the values alone: an account id the shape
 *     Cloudflare's are, a bucket name storage would accept, a region that is
 *     one label and gives the provider's own address, an endpoint where the
 *     provider has no address of its own, keys without stray spaces.
 *   - verify() then tries them: lists the bucket, writes, reads back and
 *     deletes a test object, and starts and aborts a large-file upload
 *     (Probe). With no bucket chosen yet it asks for the bucket list
 *     instead, where a key scoped to one bucket being refused is fine.
 *
 * The secret is kept out of var_dump() and print_r().
 *
 *     $connection = Connection::from_array( $input );
 *     $checked    = is_wp_error( $connection ) ? $connection : $connection->verify();
 *
 *     if ( is_wp_error( $checked ) ) {
 *         add_settings_error( 'myplugin', $checked->get_error_data()['field'], $checked->get_error_message() );
 *     }
 *
 * @since 1.0.0
 */
final class Connection {

	/**
	 * Settings for one bucket.
	 *
	 * @since 1.0.0
	 *
	 * @param Provider $provider   Who runs the bucket.
	 * @param string   $bucket     Bucket name, or '' when none is chosen yet.
	 * @param string   $access_key Access key id.
	 * @param string   $secret_key Secret access key.
	 * @param string   $region     Region, or '' for the provider's default.
	 * @param string   $account_id Cloudflare account id, for R2.
	 * @param string   $endpoint   Host, with a port if it needs one, for
	 *                             MinIO and Other.
	 */
	public function __construct(
		public readonly Provider $provider,
		public readonly string $bucket,
		#[\SensitiveParameter] private readonly string $access_key,
		#[\SensitiveParameter] private readonly string $secret_key,
		public readonly string $region = '',
		public readonly string $account_id = '',
		public readonly string $endpoint = '',
	) {}

	/**
	 * Settings from what somebody typed.
	 *
	 * Every field is trimmed; the bucket, region and account id are lower-
	 * cased; an endpoint pasted with its scheme or a trailing slash is cut to
	 * the host; an account id is kept only for a provider that needs one
	 * (R2), an endpoint only for one with no address of its own (MinIO and
	 * Other) -- so a value left from another provider is ignored.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input `provider` (a Provider value),
	 *                                    `bucket`, `access_key`,
	 *                                    `secret_key`, `region`, `account_id`,
	 *                                    `endpoint`. Anything not a string
	 *                                    counts as empty.
	 *
	 * @return self|WP_Error The settings, or problem()'s error.
	 */
	public static function from_array( #[\SensitiveParameter] array $input ): self|WP_Error {
		$field    = static fn( string $name ): string => trim( is_scalar( $input[ $name ] ?? null ) ? (string) $input[ $name ] : '' );
		$provider = Provider::tryFrom( $field( 'provider' ) );

		if ( null === $provider ) {
			return self::error( 'provider', __( 'Choose who runs your bucket.', 'sugarcommerce' ) );
		}

		$connection = new self(
			$provider,
			strtolower( $field( 'bucket' ) ),
			$field( 'access_key' ),
			$field( 'secret_key' ),
			strtolower( $field( 'region' ) ),
			$provider->needs_account_id() ? strtolower( $field( 'account_id' ) ) : '',
			// A provider with an address of its own ignores a typed one, so
			// a value left from another provider is not held against it.
			$provider->needs_endpoint() ? self::bare_host( $field( 'endpoint' ) ) : ''
		);

		$problem = $connection->problem();

		return null === $problem ? $connection : $problem;
	}

	/**
	 * What is wrong with the values, before anything is asked of storage.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_Error|null `s3_connection_invalid`, with the `field` to fix
	 *                       in its data; null when nothing is.
	 */
	public function problem(): ?WP_Error {
		$label     = $this->provider->label();
		$typed     = $this->provider->needs_endpoint();
		$printable = static fn( string $value ): bool => 1 === preg_match( '/^[\x21-\x7E]{1,256}$/', $value );

		$found = match ( true ) {
			$typed && '' === $this->endpoint => array(
				'endpoint',
				sprintf(
					/* translators: %s: a storage provider, such as MinIO. */
					__( '%s has no address of its own: type the endpoint, such as minio.example.com:9000.', 'sugarcommerce' ),
					$label
				),
			),
			$typed && ! self::is_host( $this->endpoint ) => array( 'endpoint', __( 'An endpoint is a host name, with a port if it needs one, such as minio.example.com:9000.', 'sugarcommerce' ) ),
			$this->provider->needs_account_id() && 1 !== preg_match( '/^[a-z0-9]{8,64}$/', $this->account_id ) => array( 'account_id', __( 'Paste your Cloudflare account ID: the 32 letters and numbers at the start of your R2 endpoint address.', 'sugarcommerce' ) ),
			'' !== $this->bucket && ! Validate::bucket( $this->bucket ) => array( 'bucket', __( 'A bucket name is 3 to 63 lowercase letters, numbers, dots and hyphens, starting and ending with a letter or number.', 'sugarcommerce' ) ),
			'' !== $this->bucket && AddressingStyle::VirtualHosted === $this->provider->addressing() && ! AddressingStyle::is_dns_compatible( $this->bucket ) => array(
				'bucket',
				sprintf(
					/* translators: %s: a storage provider. */
					__( '%s needs a bucket name without dots.', 'sugarcommerce' ),
					$label
				),
			),
			! $printable( $this->access_key ) => array( 'access_key', __( 'Paste the access key ID, without spaces.', 'sugarcommerce' ) ),
			! $printable( $this->secret_key ) => array( 'secret_key', __( 'Paste the secret access key, without spaces.', 'sugarcommerce' ) ),
			'' !== $this->region && ! Provider::is_label( $this->region ) => array( 'region', __( 'A region is letters, numbers and hyphens, such as eu-west-2.', 'sugarcommerce' ) ),
			Provider::R2 === $this->provider && ! in_array( $this->region, array( '', 'auto' ), true ) => array( 'region', __( 'Cloudflare R2 does not use a region: leave it empty.', 'sugarcommerce' ) ),
			! $typed && ! $this->provider->is_own_endpoint( $this->host() ) => array(
				'region',
				sprintf(
					/* translators: %s: a storage provider. */
					__( 'That region does not give an address at %s. Check the region.', 'sugarcommerce' ),
					$label
				),
			),
			default => null,
		};

		return null === $found ? null : self::error( $found[0], $found[1] );
	}

	/**
	 * Try the settings against storage.
	 *
	 * With a bucket: every Probe step but the browser's, unless origins are
	 * given. Without one: the bucket list, where a key scoped to buckets is
	 * refused and that is fine -- there is nothing more to try until one is
	 * chosen.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $origins Origins a browser uploader runs on,
	 *                                    to check CORS as well. Default none.
	 * @param Probe|null         $probe   The test to run. Default a Probe
	 *                                    with its own prefix.
	 *
	 * @return true|WP_Error `s3_connection_{fault}` -- `key`, `secret`,
	 *                       `missing`, `elsewhere`, `refused`, `unreachable`
	 *                       and the rest of ProbeFault -- with the `field`,
	 *                       the `check` that failed, its `status`, storage's
	 *                       `code`, and every `steps` entry in its data.
	 */
	public function verify( array $origins = array(), ?Probe $probe = null ): true|WP_Error {
		$problem = $this->problem();

		if ( null !== $problem ) {
			return $problem;
		}

		if ( '' === $this->bucket ) {
			return $this->verify_keys();
		}

		$steps   = $this->check( $origins, $probe );
		$failure = Probe::failure( $steps );

		if ( null === $failure ) {
			return true;
		}

		return new WP_Error(
			's3_connection_' . $failure->fault->value,
			$failure->message,
			array(
				// A bucket refused at the first request may be a name that
				// is not there.
				'field'  => ProbeFault::Refused === $failure->fault && ProbeCheck::Reach === $failure->check ? 'bucket' : $failure->fault->field( $this->provider ),
				'check'  => $failure->check->value,
				'status' => $failure->status,
				'code'   => $failure->code,
				'steps'  => array_map( static fn( ProbeStep $step ): array => $step->to_array(), $steps ),
			)
		);
	}

	/**
	 * Every Probe step, for a connection test that shows each.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $origins Origins a browser uploader runs on.
	 * @param Probe|null         $probe   The test to run.
	 *
	 * @return array<int, ProbeStep>
	 *
	 * @throws InvalidArgumentException Without a bucket, or with settings
	 *                                  problem() refuses.
	 */
	public function check( array $origins = array(), ?Probe $probe = null ): array {
		$bucket = $this->bucket();

		if ( null === $bucket ) {
			throw new InvalidArgumentException( 'A connection test needs a bucket.' );
		}

		return ( $probe ?? new Probe() )->run( $bucket, $origins );
	}

	/**
	 * A signer for the settings.
	 *
	 * @since 1.0.0
	 *
	 * @return Signer
	 *
	 * @throws InvalidArgumentException When the provider cannot build an
	 *                                  address from them.
	 */
	public function signer(): Signer {
		return $this->provider->signer( $this->access_key, $this->secret_key, $this->region, $this->account_id, $this->endpoint );
	}

	/**
	 * The bucket, ready to call.
	 *
	 * @since 1.0.0
	 *
	 * @return Bucket|null Null when no bucket is chosen.
	 *
	 * @throws InvalidArgumentException When the provider cannot build an
	 *                                  address from the settings.
	 */
	public function bucket(): ?Bucket {
		return '' === $this->bucket ? null : new Bucket( $this->signer(), $this->bucket );
	}

	/**
	 * The endpoint host, built from the provider, region and account id, or
	 * the endpoint as typed for MinIO and Other.
	 *
	 * @since 1.0.0
	 *
	 * @return string Host, or '' when the provider cannot build one.
	 */
	public function host(): string {
		try {
			return $this->provider->endpoint( $this->region, $this->account_id, $this->endpoint );
		} catch ( InvalidArgumentException ) {
			return '';
		}
	}

	/**
	 * Kept out of var_dump() and print_r(), which an error page or a log
	 * line can reach.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return array(
			'provider'   => $this->provider->value,
			'bucket'     => $this->bucket,
			'region'     => $this->region,
			'account_id' => $this->account_id,
			'endpoint'   => $this->endpoint,
			'access_key' => '' === $this->access_key ? '' : substr( $this->access_key, 0, 4 ) . '…',
			'secret_key' => '' === $this->secret_key ? '' : '(hidden)',
		);
	}

	/**
	 * The keys alone, for settings with no bucket chosen yet.
	 *
	 * @return true|WP_Error
	 */
	private function verify_keys(): true|WP_Error {
		$buckets = Bucket::all( $this->signer() );

		if ( ! $buckets instanceof WP_Error ) {
			return true;
		}

		$data   = (array) $buckets->get_error_data();
		$status = (int) ( $data['status'] ?? 0 );
		$code   = (string) ( $data['error_code'] ?? '' );
		$fault  = ProbeFault::of( $status, $code );

		// Scoped to buckets: the keys are right, and may not list them.
		if ( ProbeFault::Refused === $fault ) {
			return true;
		}

		$message = match ( $fault ) {
			ProbeFault::Unreachable => Transport::explain( $buckets->get_error_message(), 'https://' . $this->host() ),
			ProbeFault::Key         => __( 'Storage does not recognise this access key. Paste the access key ID again, from the API token you created.', 'sugarcommerce' ),
			ProbeFault::Secret      => __( 'The secret key does not match the access key. Paste the secret again; most providers show it only once, when the token is created.', 'sugarcommerce' ),
			default                 => sprintf(
				/* translators: 1: an HTTP status code, 2: storage's own words. */
				__( 'Storage answered %1$d when asked for the list of buckets. %2$s', 'sugarcommerce' ),
				$status,
				(string) ( $data['reason'] ?? '' )
			),
		};

		return new WP_Error(
			's3_connection_' . $fault->value,
			$message,
			array(
				'field'  => $fault->field( $this->provider ),
				'check'  => ProbeCheck::Reach->value,
				'status' => $status,
				'code'   => $code,
				'steps'  => array(),
			)
		);
	}

	/**
	 * A settings problem, naming its field.
	 *
	 * @param string $field   Field name.
	 * @param string $message What to fix.
	 *
	 * @return WP_Error
	 */
	private static function error( string $field, string $message ): WP_Error {
		return new WP_Error( 's3_connection_invalid', $message, array( 'field' => $field ) );
	}

	/**
	 * An endpoint as pasted, cut down to the host and port.
	 *
	 * @param string $endpoint What was typed, e.g. `https://MinIO.local:9000/`.
	 *
	 * @return string e.g. `minio.local:9000`.
	 */
	private static function bare_host( string $endpoint ): string {
		$host = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*://#i', '', trim( $endpoint ) );

		return strtolower( rtrim( explode( '/', $host, 2 )[0], '.' ) );
	}

	/**
	 * Whether an endpoint is a host name, with an optional port and nothing
	 * else: no user, path, query or bracketed address, which the signer
	 * cannot sign for.
	 *
	 * @param string $endpoint Endpoint, as bare_host() leaves it.
	 *
	 * @return bool
	 */
	private static function is_host( string $endpoint ): bool {
		$label = '[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?';

		if ( strlen( $endpoint ) > 259 || 1 !== preg_match( '/^' . $label . '(?:\.' . $label . ')*(?::(\d{1,5}))?$/', $endpoint, $match ) ) {
			return false;
		}

		return ! isset( $match[1] ) || ( (int) $match[1] >= 1 && (int) $match[1] <= 65535 );
	}
}
