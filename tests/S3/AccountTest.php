<?php
declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\S3;

use SugarCommerce\Storage\S3\Account;
use PHPUnit\Framework\TestCase;

/**
 * Canonical URI and request URL construction.
 *
 * Note: the domain-provenance tests that used to live here covered
 * is_provider_url() and parse_provider_url(), which have been removed —
 * nothing in this library or any consumer called them.
 */
final class AccountTest extends TestCase {

	private function r2(): Account {
		return Account::r2( 'abc123' );
	}

	/**
	 * The signed canonical query is rawurlencode()'d, so the URL must be too.
	 * http_build_query()'s default renders a space as '+' and a tilde as '%7E'.
	 */
	public function test_query_string_uses_rfc3986_encoding(): void {
		$url = $this->r2()->build_url_with_query( 'b', '', [ 'prefix' => 'my photos/~2024/' ] );

		$this->assertStringContainsString( 'prefix=my%20photos%2F~2024%2F', $url );
		$this->assertStringNotContainsString( '+', $url );
		$this->assertStringNotContainsString( '%7E', $url );
	}

	public function test_format_url_encodes_the_object_key(): void {
		$this->assertSame(
			'https://abc123.r2.cloudflarestorage.com/b/a%20b.txt',
			$this->r2()->format_url( 'b', 'a b.txt' )
		);
	}
}
