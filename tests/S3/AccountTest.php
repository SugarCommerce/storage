<?php
declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\S3;

use SugarCommerce\Storage\S3\Account;
use SugarCommerce\Storage\S3\Method;
use PHPUnit\Framework\TestCase;

/**
 * Request URLs, from an account's signer.
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
		$url = $this->r2()->signer( 'key', 'secret' )->sign( Method::GET, 'b', '', [ 'prefix' => 'my photos/~2024/' ] )->url;

		$this->assertStringContainsString( 'prefix=my%20photos%2F~2024%2F', $url );
		$this->assertStringNotContainsString( '+', $url );
		$this->assertStringNotContainsString( '%7E', $url );
	}

	public function test_the_object_key_is_encoded(): void {
		$this->assertSame(
			'https://abc123.r2.cloudflarestorage.com/b/a%20b.txt',
			$this->r2()->signer( 'key', 'secret' )->sign( Method::GET, 'b', 'a b.txt' )->url
		);
	}
}
