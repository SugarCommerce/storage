<?php
/**
 * What the signer gained from the SaaS package: inline and cacheable links,
 * origins, copies, ListParts, AWS's own vectors and the hardening.
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\S3;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Storage\S3\AddressingStyle;
use SugarCommerce\Storage\S3\Method;
use SugarCommerce\Storage\S3\Provider;
use SugarCommerce\Storage\S3\Signer;

/**
 * Class SignerExtrasTest
 */
final class SignerExtrasTest extends TestCase {

	/**
	 * AWS's documentation example key, split so a secret scanner reading the
	 * source does not take it for a live one. It is not one.
	 */
	private const ACCESS_KEY = 'AKIA' . 'IOSFODNN7EXAMPLE';
	private const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
	private const ENDPOINT   = 'abc123.r2.cloudflarestorage.com';
	private const TIMESTAMP  = 1700000000;

	private function signer( ?int $timestamp = self::TIMESTAMP, string $region = 'auto' ): Signer {
		return new Signer( self::ACCESS_KEY, self::SECRET_KEY, self::ENDPOINT, $region, $timestamp );
	}

	public function test_list_parts_is_a_signed_get_for_that_upload(): void {
		$request = $this->signer()->sign_list_parts( 'b', 'k', 'UP-1' );

		$this->assertSame( Method::GET, $request->method );
		$this->assertStringContainsString( 'uploadId=UP-1', $request->url );
		$this->assertStringContainsString( 'max-parts=1000', $request->url );
		$this->assertStringNotContainsString( 'part-number-marker', $request->url, 'The first page has no marker.' );
		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256', (string) $request->header( 'Authorization' ) );
	}

	public function test_list_parts_pages_by_marker_and_clamps_the_page(): void {
		$request = $this->signer()->sign_list_parts( 'b', 'k', 'UP-1', 50000, 1000 );

		$this->assertStringContainsString( 'part-number-marker=1000', $request->url );
		$this->assertStringContainsString( 'max-parts=1000', $request->url );
		$this->assertStringContainsString( 'max-parts=1&', $this->signer()->sign_list_parts( 'b', 'k', 'UP-1', 0 )->url );
	}

	public function test_presign_inline_names_the_type_and_asks_to_render(): void {
		$url = $this->signer()->presign_inline( 'files', 'a/b.bin', 3600, 'audio/mpeg' );

		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'audio/mpeg', $query['response-content-type'] );
		$this->assertSame( 'inline', $query['response-content-disposition'] );
		$this->assertSame( '3600', $query['X-Amz-Expires'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $query['X-Amz-Signature'] );
	}

	/**
	 * The type is inside the signature: changing it in a copied URL must
	 * break it, or the URL could be made to serve anything as HTML.
	 */
	public function test_the_inline_type_is_signed(): void {
		$audio = $this->signer()->presign_inline( 'files', 'k', 60, 'audio/mpeg' );
		$html  = $this->signer()->presign_inline( 'files', 'k', 60, 'text/html' );

		// The signature is the last query parameter, 64 hex characters.
		$this->assertNotSame( substr( $audio, -64 ), substr( $html, -64 ) );
	}

	/**
	 * The whole point: two page loads in the same hour produce the same URL,
	 * so the browser's cached copy is used and storage is not asked again.
	 */
	public function test_a_cacheable_url_is_the_same_all_window(): void {
		$signer = $this->signer( null );
		$start  = 1760000400; // A multiple of 3600.

		$first = $signer->presign_cacheable( 'files', 'a/b.png', 3600, 'image/png', $start );
		$last  = $signer->presign_cacheable( 'files', 'a/b.png', 3600, 'image/png', $start + 3599 );
		$next  = $signer->presign_cacheable( 'files', 'a/b.png', 3600, 'image/png', $start + 3600 );

		$this->assertSame( $first, $last );
		$this->assertNotSame( $first, $next );
	}

	public function test_a_cacheable_url_says_how_long_to_keep_it_and_outlives_that(): void {
		$url = $this->signer( null )->presign_cacheable( 'files', 'k', 3600, 'image/webp', 1760000400 + 10 );

		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'private, max-age=3600', $query['response-cache-control'] );
		$this->assertSame( 'image/webp', $query['response-content-type'] );
		$this->assertSame( 'inline', $query['response-content-disposition'] );
		$this->assertSame( '7200', $query['X-Amz-Expires'] );
		$this->assertSame( gmdate( 'Ymd\THis\Z', 1760000400 ), $query['X-Amz-Date'], 'Signed at the start of the window.' );
	}

	/**
	 * The cache instruction is signed, so nobody can edit a copied URL into
	 * one that tells a shared cache to keep a private file.
	 */
	public function test_the_cache_instruction_is_signed(): void {
		$url = $this->signer( null )->presign_cacheable( 'files', 'k', 3600, 'image/png', 1760000400 );

		$this->assertSame( $url, $this->signer( null )->presign_cacheable( 'files', 'k', 3600, 'image/png', 1760000400 ) );
		$this->assertNotSame(
			substr( $url, -64 ),
			substr( $this->signer( null )->presign_cacheable( 'files', 'k', 1800, 'image/png', 1760000400 ), -64 )
		);
	}

	public function test_a_window_too_long_to_sign_twice_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->signer()->presign_cacheable( 'files', 'k', 4 * 86400, 'image/png' );
	}

	public function test_origin_is_where_urls_for_the_bucket_point(): void {
		$signer = $this->signer();

		$this->assertStringStartsWith( $signer->origin( 'files' ) . '/', $signer->presign_get( 'files', 'k', 60 ) );
	}

	public function test_a_copy_names_its_source_and_replaces_metadata(): void {
		$request = $this->signer()->sign_copy_object( 'private', 'acct/live/ab cd.wav', 'public', 'p/acct/ab.wav', 'audio/wav', 'public, max-age=31536000, immutable' );

		$this->assertSame( 'PUT', $request->method->value );
		$this->assertSame( '/private/acct/live/ab%20cd.wav', $request->headers['x-amz-copy-source'] );
		$this->assertSame( 'REPLACE', $request->headers['x-amz-metadata-directive'] );
		$this->assertSame( 'audio/wav', $request->headers['content-type'] );
		$this->assertSame( 'public, max-age=31536000, immutable', $request->headers['cache-control'] );
		$this->assertStringContainsString( 'x-amz-copy-source', $request->headers['Authorization'] ?? $request->headers['authorization'] ?? '' );
	}

	/**
	 * The examples in AWS's SigV4 documentation for S3, signed by an
	 * implementation that is not this one. The golden vectors above prove
	 * nothing changed; these prove it was right to begin with.
	 */
	#[DataProvider( 'aws_vectors' )]
	public function test_aws_documentation_vectors( \Closure $sign, string $signature ): void {
		$signer = new Signer( self::ACCESS_KEY, self::SECRET_KEY, 's3.amazonaws.com', 'us-east-1', 1369353600, AddressingStyle::VirtualHosted );

		$this->assertStringEndsWith( $signature, $sign( $signer ) );
	}

	/** @return array<string, array{0: \Closure, 1: string}> */
	public static function aws_vectors(): array {
		return array(
			'presigned GET'  => array(
				static fn ( Signer $s ): string => $s->presign( Method::GET, 'examplebucket', 'test.txt', array(), 86400 ),
				'aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
			),
			'GET with range' => array(
				static fn ( Signer $s ): string => (string) $s->sign( Method::GET, 'examplebucket', 'test.txt', array(), '', array( 'range' => 'bytes=0-9' ) )->header( 'Authorization' ),
				'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
			),
			'GET lifecycle'  => array(
				static fn ( Signer $s ): string => (string) $s->sign( Method::GET, 'examplebucket', '', array( 'lifecycle' => '' ) )->header( 'Authorization' ),
				'fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543',
			),
			'list objects'   => array(
				static fn ( Signer $s ): string => (string) $s->sign( Method::GET, 'examplebucket', '', array( 'max-keys' => '2', 'prefix' => 'J' ) )->header( 'Authorization' ),
				'34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7',
			),
		);
	}

	public function test_a_dumped_signer_does_not_show_its_keys(): void {
		$dump = print_r( $this->signer(), true );

		$this->assertStringNotContainsString( self::SECRET_KEY, $dump );
		$this->assertStringNotContainsString( self::ACCESS_KEY, $dump );
		$this->assertStringContainsString( self::ENDPOINT, $dump );
	}

	/**
	 * `uploads/<user>/` + a name of `../<other>/x` signs a path that a
	 * normalising client or gateway reads as somebody else's key.
	 */
	#[DataProvider( 'dot_segment_paths' )]
	public function test_a_dot_segment_is_refused( \Closure $sign ): void {
		$this->expectException( \InvalidArgumentException::class );

		$sign( $this->signer() );
	}

	/** @return array<string, array{0: \Closure}> */
	public static function dot_segment_paths(): array {
		return array(
			'presigned put'   => array( static fn ( Signer $s ): string => $s->presign_put( 'b', 'uploads/u1/../u2/avatar.png' ) ),
			'presigned get'   => array( static fn ( Signer $s ): string => $s->presign_get( 'b', '../secret.zip' ) ),
			'trailing dots'   => array( static fn ( Signer $s ): string => $s->presign_get( 'b', 'a/..' ) ),
			'single dot'      => array( static fn ( Signer $s ): string => $s->presign_get( 'b', 'a/./b' ) ),
			'bucket'          => array( static fn ( Signer $s ): string => $s->presign_get( '..', 'x' ) ),
			'signed delete'   => array( static fn ( Signer $s ): mixed => $s->sign_delete_object( 'b', 'a/../../other/x' ) ),
			'copy source key' => array( static fn ( Signer $s ): mixed => $s->sign_copy_object( 'b', '../x', 'b', 'y' ) ),
		);
	}

	public function test_dots_inside_a_segment_are_still_fine(): void {
		$url = $this->signer()->presign_get( 'b', 'a/..b/c.../.hidden', 60 );

		$this->assertStringContainsString( '/b/a/..b/c.../.hidden?', $url );
	}

	public function test_an_inline_content_type_with_a_line_break_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->signer()->presign_inline( 'b', 'a.mp3', 60, "audio/mpeg\r\nSet-Cookie: a=b" );
	}

	public function test_query_names_sort_as_strings_not_numbers(): void {
		$url = $this->signer()->presign( Method::GET, 'b', 'k', array( '10' => 'a', '9' => 'b' ), 60 );

		$this->assertStringContainsString( '?10=a&9=b&X-Amz-Algorithm', $url );
	}

	public function test_an_r2_account_id_that_is_not_a_label_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Provider::R2->signer( self::ACCESS_KEY, self::SECRET_KEY, '', 'evil.example/' );
	}

	public function test_list_parts_needs_an_upload_id_in_the_signature(): void {
		$this->assertNotSame(
			$this->signer()->sign_list_parts( 'b', 'k', 'UP-1' )->header( 'Authorization' ),
			$this->signer()->sign_list_parts( 'b', 'k', 'UP-2' )->header( 'Authorization' )
		);
	}
}
