<?php
/**
 * A bucket's whole CORS configuration.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\S3\Cors;

use SugarCommerce\Storage\S3\Xml;

/**
 * Class Policy
 *
 * An ordered set of rules, with the XML on both sides of the wire and the
 * questions worth asking of it.
 *
 * The questions are the reason this is a class rather than an array. "Is CORS
 * configured?" is not a useful thing to know — a bucket can have three rules
 * and still refuse the upload you are about to attempt. What a deployment
 * check actually wants to ask is whether *this* origin may perform *this*
 * operation, which is what supports_multipart_upload() answers.
 *
 * @since 1.0.0
 *
 * @implements \IteratorAggregate<int, Rule>
 */
final readonly class Policy implements \Countable, \IteratorAggregate {

	/**
	 * Most providers cap a bucket at 100 rules.
	 */
	public const MAX_RULES = 100;

	/**
	 * @since 1.0.0
	 *
	 * @param list<Rule> $rules The rules, in the order S3 evaluates them.
	 *
	 * @throws \InvalidArgumentException Above MAX_RULES.
	 */
	public function __construct( public array $rules = array() ) {
		if ( count( $rules ) > self::MAX_RULES ) {
			throw new \InvalidArgumentException(
				sprintf( 'A bucket takes at most %d CORS rules.', self::MAX_RULES )
			);
		}
	}

	/**
	 * The policy a direct-to-storage uploader needs.
	 *
	 * Upload is pinned to the origins that serve the uploader; reading stays
	 * open, because a presigned download link is a credential in itself and
	 * restricting which page may follow it protects nothing.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $origins Origins the uploader is served from.
	 *
	 * @return self
	 */
	public static function for_browser_uploads( array $origins ): self {
		return new self( array(
			Rule::browser_upload( $origins ),
			Rule::browser_read(),
		) );
	}

	/**
	 * A policy from rules in the shape S3 names its fields.
	 *
	 * For rules that arrived as arrays -- parsed by another reader, typed into
	 * a settings screen, made by Rules::generate(). A rule with no origin, no
	 * method or a method S3 would reject is left out rather than thrown on,
	 * because a configuration a bucket already holds is a fact to be asked
	 * about, not an argument to be refused. Past MAX_RULES the rest are left
	 * out too.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $rules Rules, as Rule::to_array() writes them.
	 *
	 * @return self
	 */
	public static function from_array( array $rules ): self {
		$kept = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			try {
				$kept[] = Rule::from_array( $rule );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}

		return new self( array_slice( $kept, 0, self::MAX_RULES ) );
	}

	/**
	 * The policy as rules in the shape S3 names their fields.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function to_array(): array {
		return array_map( static fn( Rule $rule ): array => $rule->to_array(), $this->rules );
	}

	/**
	 * Parse a GetBucketCors response.
	 *
	 * @since 1.0.0
	 *
	 * @param string $xml Response body. An empty body — which is what a
	 *                    bucket with no configuration returns — gives an
	 *                    empty policy rather than an error.
	 *
	 * @return self
	 *
	 * @throws \RuntimeException When the body is not parseable XML.
	 * @throws \InvalidArgumentException When a rule in it has no origin, no
	 *                                   method, or a method S3 would reject.
	 */
	public static function from_xml( string $xml ): self {
		$xml = trim( $xml );

		if ( '' === $xml ) {
			return new self();
		}

		$document = Xml::load( $xml );

		if ( null === $document ) {
			throw new \RuntimeException( 'Could not parse the CORS configuration.' );
		}

		$rules = array();

		foreach ( Xml::children( $document, 'CORSRule' ) as $node ) {
			$rules[] = Rule::from_array(
				array(
					'ID'             => Xml::text( $node, 'ID' ),
					'AllowedOrigins' => self::strings( Xml::children( $node, 'AllowedOrigin' ) ),
					'AllowedMethods' => self::strings( Xml::children( $node, 'AllowedMethod' ) ),
					'AllowedHeaders' => self::strings( Xml::children( $node, 'AllowedHeader' ) ),
					'ExposeHeaders'  => self::strings( Xml::children( $node, 'ExposeHeader' ) ),
					'MaxAgeSeconds'  => (int) Xml::text( $node, 'MaxAgeSeconds' ),
				)
			);
		}

		return new self( $rules );
	}

	/**
	 * The policy as a PutBucketCors body.
	 *
	 * @since 1.0.0
	 *
	 * @return string XML.
	 */
	public function to_xml(): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<CORSConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">' . "\n";

		foreach ( $this->rules as $rule ) {
			$xml .= '  <CORSRule>' . "\n";

			if ( '' !== $rule->id ) {
				$xml .= self::element( 'ID', $rule->id );
			}

			foreach ( $rule->origins as $origin ) {
				$xml .= self::element( 'AllowedOrigin', $origin );
			}

			foreach ( $rule->methods as $method ) {
				$xml .= self::element( 'AllowedMethod', $method );
			}

			foreach ( $rule->headers as $header ) {
				$xml .= self::element( 'AllowedHeader', $header );
			}

			foreach ( $rule->expose as $header ) {
				$xml .= self::element( 'ExposeHeader', $header );
			}

			if ( $rule->max_age > 0 ) {
				$xml .= self::element( 'MaxAgeSeconds', (string) $rule->max_age );
			}

			$xml .= '  </CORSRule>' . "\n";
		}

		return $xml . '</CORSConfiguration>' . "\n";
	}

	/**
	 * May this origin use this method?
	 *
	 * @since 1.0.0
	 *
	 * @param string $origin Scheme and host.
	 * @param string $method HTTP method.
	 *
	 * @return bool
	 */
	public function allows( string $origin, string $method ): bool {
		foreach ( $this->rules as $rule ) {
			if ( $rule->allows( $origin, $method ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * May this origin read this response header, for this method?
	 *
	 * The method matters. Exposing ETag on a rule that only allows GET does
	 * nothing for an upload, and a check that ignored the method would call
	 * that configuration good.
	 *
	 * @since 1.0.0
	 *
	 * @param string $origin Scheme and host.
	 * @param string $method HTTP method.
	 * @param string $header Response header name.
	 *
	 * @return bool
	 */
	public function exposes( string $origin, string $method, string $header ): bool {
		foreach ( $this->rules as $rule ) {
			if ( $rule->allows( $origin, $method ) && $rule->exposes( $header ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * May this origin send this request header, with this method?
	 *
	 * For a header the uploader would like to send rather than must: a
	 * part's Content-MD5 lets storage check it, and an upload without one
	 * still works.
	 *
	 * @since 1.0.0
	 *
	 * @param string $origin Scheme and host.
	 * @param string $method HTTP method.
	 * @param string $header Request header name.
	 *
	 * @return bool
	 */
	public function accepts( string $origin, string $method, string $header ): bool {
		foreach ( $this->rules as $rule ) {
			if ( $rule->allows( $origin, $method ) && $rule->accepts( $header ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Will a browser multipart upload from this origin actually work?
	 *
	 * The question a deployment check should ask, rather than "is CORS set?".
	 * Both halves have to hold: the browser must be allowed to PUT, and it
	 * must be allowed to read back the ETag of what it PUT. A policy with the
	 * first and not the second lets every part upload and then fails at
	 * assembly, with an error that mentions neither CORS nor ETag.
	 *
	 * @since 1.0.0
	 *
	 * @param string $origin Origin the uploader is served from.
	 *
	 * @return array{ok: bool, reason: string} A reason when it will not.
	 */
	public function supports_multipart_upload( string $origin ): array {
		if ( ! $this->rules ) {
			return array(
				'ok'     => false,
				'reason' => __( 'The bucket has no CORS configuration.', 'sugarcommerce' ),
			);
		}

		if ( ! $this->allows( $origin, 'PUT' ) ) {
			return array(
				'ok'     => false,
				'reason' => sprintf(
					/* translators: %s: a web origin, such as https://example.com. */
					__( 'No rule allows PUT from %s.', 'sugarcommerce' ),
					$origin
				),
			);
		}

		if ( ! $this->exposes( $origin, 'PUT', 'ETag' ) ) {
			return array(
				'ok'     => false,
				'reason' => __( 'PUT is allowed but ETag is not exposed, so parts upload and the upload then fails at assembly.', 'sugarcommerce' ),
			);
		}

		return array(
			'ok'     => true,
			'reason' => '',
		);
	}

	/**
	 * Will a browser multipart upload work from every one of these origins?
	 *
	 * An uploader is usually served from more than one place — its own
	 * hostname and a shared one, say — and a policy that covers one of them
	 * fails for whoever happens to use the other. Asked per origin, in order,
	 * stopping at the first that will not work.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $origins Origins the uploader is served from.
	 *
	 * @return array{ok: bool, reason: string} The first failing origin's
	 *                                         reason. No origins at all is
	 *                                         not a pass.
	 */
	public function supports_multipart_upload_from( array $origins ): array {
		if ( array() === $origins ) {
			return array(
				'ok'     => false,
				'reason' => __( 'No origins were given to check.', 'sugarcommerce' ),
			);
		}

		foreach ( $origins as $origin ) {
			$result = $this->supports_multipart_upload( $origin );

			if ( ! $result['ok'] ) {
				return $result;
			}
		}

		return array(
			'ok'     => true,
			'reason' => '',
		);
	}

	/**
	 * The policy as the JSON a provider's dashboard takes.
	 *
	 * For a bucket whose settings you cannot write — someone else's, reached
	 * with keys scoped to objects — the policy has to be pasted by its owner,
	 * and the R2 and S3 consoles take a JSON list of rules with exactly the
	 * PutBucketCors field names. The `ID` is left out: it is a name for
	 * reading a configuration back, and some consoles refuse a field they do
	 * not know.
	 *
	 * @since 1.0.0
	 *
	 * @return string Pretty-printed JSON.
	 */
	public function to_console_json(): string {
		$rules = array();

		foreach ( $this->rules as $rule ) {
			$shape = $rule->to_array();

			unset( $shape['ID'] );

			$rules[] = $shape;
		}

		return (string) json_encode( $rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Every origin any rule allows.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	public function origins(): array {
		return $this->unique( static fn( Rule $rule ): array => $rule->origins );
	}

	/**
	 * Every method any rule allows.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	public function methods(): array {
		return $this->unique( static fn( Rule $rule ): array => $rule->methods );
	}

	/**
	 * How many rules.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->rules );
	}

	/**
	 * Iterate the rules.
	 *
	 * @since 1.0.0
	 *
	 * @return \ArrayIterator<int, Rule>
	 */
	public function getIterator(): \ArrayIterator {
		return new \ArrayIterator( $this->rules );
	}

	/**
	 * Collect one field across every rule, without duplicates.
	 *
	 * @since 1.0.0
	 *
	 * @param \Closure( Rule ): list<string> $field Returns the field's values
	 *                                             for one rule.
	 *
	 * @return list<string>
	 */
	private function unique( \Closure $field ): array {
		$values = array();

		foreach ( $this->rules as $rule ) {
			$values = array_merge( $values, $field( $rule ) );
		}

		return array_values( array_unique( $values ) );
	}

	/**
	 * One escaped XML element.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name  Element name.
	 * @param string $value Text content.
	 *
	 * @return string
	 */
	private static function element( string $name, string $value ): string {
		return '    <' . $name . '>'
			. htmlspecialchars( $value, ENT_XML1 | ENT_COMPAT, 'UTF-8' )
			. '</' . $name . '>' . "\n";
	}

	/**
	 * Elements as a list of strings.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, \SimpleXMLElement> $nodes The elements.
	 *
	 * @return array<int, string>
	 */
	private static function strings( array $nodes ): array {
		return array_map( 'strval', $nodes );
	}
}
