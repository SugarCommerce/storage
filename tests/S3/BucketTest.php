<?php
/**
 * The bucket client: multipart from the browser, reading, copies and CORS.
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\S3;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Storage\S3\Bucket;
use SugarCommerce\Storage\S3\Cors\Policy;
use SugarCommerce\Storage\S3\Cors\Rule;
use SugarCommerce\Storage\S3\Provider;
use SugarCommerce\Storage\S3\StorageError;
use SugarCommerce\Storage\Tests\Support\FakeHttp;
use WP_Error;

/**
 * Bucket, against FakeHttp.
 */
final class BucketTest extends TestCase {

	/**
	 * Files to remove.
	 *
	 * @var string[]
	 */
	private array $files = array();

	protected function setUp(): void {
		wp_test_reset();
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}
	}

	private function bucket( string $name = 'files' ): Bucket {
		return new Bucket( Provider::R2->signer( 'key', 'secret', 'auto', 'acct1234' ), $name );
	}

	/**
	 * @param string $method Method.
	 *
	 * @return array<int, array{url: string, args: array}>
	 */
	private static function sent( string $method ): array {
		return array_values( array_filter( FakeHttp::requests(), static fn( array $request ): bool => $method === $request['args']['method'] ) );
	}

	/* ─── Starting, signing, finishing, aborting ────────────────────── */

	public function test_starting_an_upload_reads_back_its_id(): void {
		FakeHttp::queue( 200, '<InitiateMultipartUploadResult><UploadId>abc-123</UploadId></InitiateMultipartUploadResult>' );

		$this->assertSame( 'abc-123', $this->bucket()->start_upload( 'a/b', 'audio/wav' ) );
		$this->assertSame( 'POST', FakeHttp::last()['args']['method'] );
		$this->assertStringContainsString( '?uploads', FakeHttp::last()['url'] );
		$this->assertSame( 'audio/wav', FakeHttp::last()['args']['headers']['content-type'] );
	}

	public function test_a_refused_start_says_why(): void {
		FakeHttp::queue( 403, '<?xml version="1.0" encoding="UTF-8"?><Error><Code>SignatureDoesNotMatch</Code><Message>The request signature we calculated does not match the signature you provided.</Message></Error>' );

		$error = $this->bucket()->start_upload( 'a/b' );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 's3_start_upload_failed', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
		$this->assertSame( 'SignatureDoesNotMatch', $error->get_error_data()['error_code'] );
		$this->assertStringContainsString( 'SignatureDoesNotMatch: The request signature', $error->get_error_message() );
	}

	public function test_a_start_answered_without_an_id_failed(): void {
		FakeHttp::queue( 200, '<InitiateMultipartUploadResult/>' );

		$this->assertInstanceOf( WP_Error::class, $this->bucket()->start_upload( 'a/b' ) );
	}

	public function test_a_start_that_never_arrived_is_status_zero(): void {
		FakeHttp::queue_error();

		$this->assertSame( 0, $this->bucket()->start_upload( 'a/b' )->get_error_data()['status'] );
	}

	public function test_part_numbers_out_of_range_are_not_signed(): void {
		$urls = $this->bucket()->sign_parts( 'a/b', 'u', array( 0, 1, 2, 10001 ) );

		$this->assertSame( array( 1, 2 ), array_keys( $urls ) );
		$this->assertStringContainsString( 'partNumber=2', $urls[2] );
		$this->assertStringContainsString( 'uploadId=u', $urls[2] );
	}

	public function test_a_completion_is_a_post_of_every_etag(): void {
		FakeHttp::queue( 200, '<CompleteMultipartUploadResult/>' );

		$this->assertTrue( $this->bucket()->finish_upload( 'a/b', 'u', array( array( 'PartNumber' => 1, 'ETag' => '"x"' ) ) ) );
		$this->assertStringContainsString( '<ETag>&quot;x&quot;</ETag>', FakeHttp::last()['args']['body'] );
	}

	/**
	 * S3 reports some failures inside a 200.
	 */
	public function test_a_completion_answered_with_an_error_document_failed(): void {
		FakeHttp::queue( 200, '<Error><Code>InvalidPart</Code></Error>' );

		$error = $this->bucket()->finish_upload( 'a/b', 'u', array( array( 'PartNumber' => 1, 'ETag' => '"x"' ) ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'InvalidPart', $error->get_error_data()['error_code'] );
	}

	public function test_a_completion_with_no_parts_is_refused_without_asking(): void {
		$this->assertInstanceOf( WP_Error::class, $this->bucket()->finish_upload( 'a/b', 'u', array() ) );
		$this->assertSame( array(), FakeHttp::requests() );
	}

	public function test_aborting_deletes_the_upload(): void {
		FakeHttp::queue( 204 );

		$this->assertTrue( $this->bucket()->abort_upload( 'a/b', 'u' ) );
		$this->assertSame( 'DELETE', FakeHttp::last()['args']['method'] );
		$this->assertStringContainsString( 'uploadId=u', FakeHttp::last()['url'] );
	}

	public function test_a_refused_abort_says_so(): void {
		FakeHttp::queue( 403 );

		$this->assertFalse( $this->bucket()->abort_upload( 'a/b', 'u' ) );
	}

	/* ─── Resuming ──────────────────────────────────────────────────── */

	public function test_a_part_listing_is_read_in_order_with_quotes_off(): void {
		$listing = Bucket::parse_list_parts(
			'<ListPartsResult><IsTruncated>false</IsTruncated>'
			. '<Part><PartNumber>2</PartNumber><ETag>&quot;bbb&quot;</ETag><Size>5</Size></Part>'
			. '<Part><PartNumber>1</PartNumber><ETag>"aaa"</ETag><Size>8</Size></Part>'
			. '<Part><PartNumber>0</PartNumber><ETag>"nonsense"</ETag><Size>1</Size></Part>'
			. '</ListPartsResult>'
		);

		$this->assertSame(
			array(
				array( 'PartNumber' => 1, 'ETag' => 'aaa', 'Size' => 8 ),
				array( 'PartNumber' => 2, 'ETag' => 'bbb', 'Size' => 5 ),
			),
			$listing['parts']
		);
		$this->assertFalse( $listing['more'] );
	}

	public function test_a_long_listing_is_followed_to_the_end(): void {
		FakeHttp::respond_with(
			static fn( string $url ) => str_contains( $url, 'part-number-marker=1' )
				? FakeHttp::response( 200, '<ListPartsResult><IsTruncated>false</IsTruncated><Part><PartNumber>2</PartNumber><ETag>"b"</ETag><Size>1</Size></Part></ListPartsResult>' )
				: FakeHttp::response( 200, '<ListPartsResult><IsTruncated>true</IsTruncated><NextPartNumberMarker>1</NextPartNumberMarker><Part><PartNumber>1</PartNumber><ETag>"a"</ETag><Size>1</Size></Part></ListPartsResult>' )
		);

		$parts = $this->bucket()->parts( 'k', 'u' );

		$this->assertSame( array( 1, 2 ), array_column( (array) $parts, 'PartNumber' ) );
		$this->assertCount( 2, self::sent( 'GET' ) );
	}

	#[DataProvider( 'not_listings' )]
	public function test_an_upload_storage_does_not_have_lists_as_null( int $status, string $body ): void {
		FakeHttp::queue( $status, $body );

		$this->assertNull( $this->bucket()->parts( 'k', 'u' ) );
	}

	public static function not_listings(): array {
		return array(
			'no such upload'      => array( 404, '<Error><Code>NoSuchUpload</Code></Error>' ),
			'an error inside 200' => array( 200, '<Error><Code>InternalError</Code></Error>' ),
		);
	}

	public function test_a_listing_that_never_ends_is_given_up_on(): void {
		FakeHttp::respond_with(
			static fn( string $url ) => FakeHttp::response(
				200,
				sprintf(
					'<ListPartsResult><IsTruncated>true</IsTruncated><NextPartNumberMarker>%d</NextPartNumberMarker></ListPartsResult>',
					1 + (int) ( preg_match( '/part-number-marker=(\d+)/', $url, $m ) ? $m[1] : 0 )
				)
			)
		);

		$this->assertNull( $this->bucket()->parts( 'k', 'u' ) );
		$this->assertCount( 11, self::sent( 'GET' ) );
	}

	public function test_the_listing_is_a_signed_request_for_that_upload(): void {
		FakeHttp::queue( 200, '<ListPartsResult></ListPartsResult>' );

		$this->assertSame( array(), $this->bucket()->parts( 'acct_X/live/abc', 'up-9' ) );
		$this->assertStringContainsString( 'uploadId=up-9', FakeHttp::last()['url'] );
		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256', FakeHttp::last()['args']['headers']['Authorization'] );
	}

	/* ─── Part sizes ────────────────────────────────────────────────── */

	public function test_part_size_scales_to_stay_inside_the_limit(): void {
		$this->assertSame( Bucket::MIN_PART_SIZE, Bucket::part_size( 1000 ) );
		$this->assertLessThanOrEqual( Bucket::PART_LIMIT, (int) ceil( 5e12 / Bucket::part_size( 5000000000000 ) ) );
	}

	public function test_part_size_can_have_a_larger_floor_but_never_a_smaller_one(): void {
		$this->assertSame( 8 * 1048576, Bucket::part_size( 1000, 8 * 1048576 ) );
		$this->assertSame( Bucket::MIN_PART_SIZE, Bucket::part_size( 1000, 1 ) );
		$this->assertSame( Bucket::part_size( 5000000000000 ), Bucket::part_size( 5000000000000, 8 * 1048576 ), 'The limit still wins for a huge file.' );
	}

	public function test_a_bucket_knows_its_name(): void {
		$this->assertSame( 'files', $this->bucket()->name() );
	}

	/* ─── Reading ───────────────────────────────────────────────────── */

	public function test_a_read_that_storage_refused_is_an_error(): void {
		FakeHttp::queue( 500 );

		$this->assertInstanceOf( WP_Error::class, $this->bucket()->read( 'a/b', 0, 9 ) );
	}

	public function test_a_read_asks_for_its_range_and_no_more(): void {
		FakeHttp::queue( 206, 'abc' );

		$this->assertSame( 'abc', $this->bucket()->read( 'a/b', 10, 12 ) );
		$this->assertSame( 'bytes=10-12', FakeHttp::last()['args']['headers']['Range'] );
		$this->assertSame( 3, FakeHttp::last()['args']['limit_response_size'] );
	}

	#[DataProvider( 'not_ranges' )]
	public function test_a_range_that_is_not_one_is_refused_before_asking( int $from, int $to ): void {
		try {
			$this->bucket()->read( 'a/b', $from, $to );
			$this->fail( 'A backwards or negative range was read.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( array(), FakeHttp::requests() );
		}
	}

	public static function not_ranges(): array {
		return array(
			'backwards' => array( 10, 5 ),
			'negative'  => array( -1, 5 ),
			'overflows' => array( 0, PHP_INT_MAX ),
		);
	}

	public function test_a_read_whose_range_was_ignored_is_cut_to_the_range(): void {
		FakeHttp::queue( 200, 'abcdefgh', array( 'Content-Length' => '8' ) );

		$this->assertSame( 'cde', $this->bucket()->read( 'a/b', 2, 4 ) );
	}

	/**
	 * The cap cut a whole-object answer short, so the bytes asked for from
	 * further in are not there: an error, not the wrong bytes.
	 */
	public function test_an_ignored_range_cut_short_by_the_cap_is_an_error(): void {
		FakeHttp::queue( 200, 'abc', array( 'Content-Length' => '6000000' ) );

		$this->assertInstanceOf( WP_Error::class, $this->bucket()->read( 'a/b', 10, 12 ) );
	}

	public function test_the_size_comes_from_the_content_range(): void {
		FakeHttp::queue( 206, 'x', array( 'Content-Range' => 'bytes 0-0/5000000' ) );

		$this->assertSame( 5000000, $this->bucket()->size( 'a/b' ) );
		$this->assertSame( 'bytes=0-0', FakeHttp::last()['args']['headers']['Range'] );
		$this->assertSame( 1024, FakeHttp::last()['args']['limit_response_size'] );
	}

	public function test_a_whole_object_answer_is_its_own_size(): void {
		FakeHttp::queue( 200, 'abc' );

		$this->assertSame( 3, $this->bucket()->size( 'a/b' ) );
	}

	public function test_a_whole_object_cut_off_is_no_size(): void {
		FakeHttp::queue( 200, str_repeat( 'a', 1024 ), array( 'Content-Length' => '9999' ) );

		$this->assertNull( $this->bucket()->size( 'a/b' ) );
	}

	public function test_an_unanswered_size_is_unknown(): void {
		FakeHttp::queue( 404 );

		$this->assertNull( $this->bucket()->size( 'a/b' ) );
	}

	public function test_stat_reads_the_etag_length_and_time_from_a_ranged_read(): void {
		FakeHttp::queue(
			206,
			'x',
			array(
				'Content-Range' => 'bytes 0-0/1234',
				'ETag'          => '"abc"',
				'Last-Modified' => 'Mon, 05 Oct 2026 09:00:00 GMT',
			)
		);

		$this->assertSame(
			array(
				'etag'     => 'abc',
				'size'     => 1234,
				'modified' => 'Mon, 05 Oct 2026 09:00:00 GMT',
			),
			$this->bucket()->stat( 'a/b' )
		);
		$this->assertSame( 'bytes=0-0', FakeHttp::last()['args']['headers']['Range'] );
		$this->assertSame( 1024, FakeHttp::last()['args']['limit_response_size'] );
	}

	#[DataProvider( 'unsaid' )]
	public function test_stat_without_all_of_it_is_unknown( int $status, string $body, array $headers ): void {
		FakeHttp::queue( $status, $body, $headers );

		$this->assertNull( $this->bucket()->stat( 'a/b' ) );
	}

	public static function unsaid(): array {
		return array(
			'refused'          => array( 403, '', array() ),
			'no content-range' => array( 200, 'whole', array( 'etag' => '"e"' ) ),
			'no etag'          => array( 206, 'x', array( 'content-range' => 'bytes 0-0/9' ) ),
		);
	}

	/* ─── Links ─────────────────────────────────────────────────────── */

	public function test_a_download_url_is_presigned(): void {
		$this->assertStringContainsString( 'X-Amz-Signature', $this->bucket()->url( 'a/b', 60, 'Master.wav' ) );
	}

	public function test_a_view_url_is_stable_and_cacheable(): void {
		$url = $this->bucket()->view_url( 'a/b.png', 'image/png', 600 );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'private, max-age=600', $query['response-cache-control'] );
		$this->assertSame( 'image/png', $query['response-content-type'] );
		$this->assertSame( 0, strtotime( $query['X-Amz-Date'] ) % 600, 'Signed on a window boundary.' );
	}

	public function test_a_stream_url_plays_rather_than_downloads(): void {
		$url = $this->bucket()->stream_url( 'a/b', 3600, 'audio/mpeg' );

		$this->assertStringContainsString( 'response-content-type=audio%2Fmpeg', $url );
		$this->assertStringContainsString( 'response-content-disposition=inline', $url );
		$this->assertStringStartsWith( $this->bucket()->origin(), $url );
	}

	/* ─── Writing, copying, deleting ────────────────────────────────── */

	public function test_put_can_set_caching(): void {
		FakeHttp::queue( 200 );

		$this->assertTrue( $this->bucket()->put( 'p/a.json', '[1]', 'application/json', 'public, max-age=60' ) );
		$this->assertSame( 'public, max-age=60', FakeHttp::last()['args']['headers']['cache-control'] );
		$this->assertSame( 'application/json', FakeHttp::last()['args']['headers']['content-type'] );
		$this->assertSame( '[1]', FakeHttp::last()['args']['body'] );
	}

	public function test_a_refused_put_is_an_error(): void {
		FakeHttp::queue( 403, '<Error><Code>AccessDenied</Code></Error>' );

		$this->assertSame( 'AccessDenied', $this->bucket()->put( 'p', 'x' )->get_error_data()['error_code'] );
	}

	public function test_a_copy_is_one_put_naming_the_source(): void {
		FakeHttp::queue( 200, '<CopyObjectResult/>' );

		$this->assertTrue( $this->bucket()->copy_from( $this->bucket( 'private' ), 'a/b.wav', 'p/b.wav', 'audio/wav', 'public, max-age=31536000' ) );
		$this->assertSame( 'PUT', FakeHttp::last()['args']['method'] );
		$this->assertSame( '/private/a/b.wav', FakeHttp::last()['args']['headers']['x-amz-copy-source'] );
	}

	public function test_a_copy_that_failed_inside_a_200_failed(): void {
		FakeHttp::queue( 200, '<Error><Code>AccessDenied</Code></Error>' );

		$this->assertInstanceOf( WP_Error::class, $this->bucket()->copy_from( $this->bucket( 'private' ), 'a', 'b', 'audio/wav' ) );
	}

	public function test_delete_is_a_signed_delete(): void {
		FakeHttp::queue( 204 );

		$this->assertTrue( $this->bucket()->delete( 'a/b' ) );
		$this->assertSame( 'DELETE', FakeHttp::last()['args']['method'] );
		$this->assertStringContainsString( '/files/a/b', FakeHttp::last()['url'] );
	}

	/* ─── The bucket itself ─────────────────────────────────────────── */

	public function test_reachable_says_why_not(): void {
		FakeHttp::queue( 200 );
		FakeHttp::queue( 403, '<Error>AccessDenied</Error>' );

		$this->assertTrue( $this->bucket()->reachable()['ok'] );

		$refused = $this->bucket()->reachable();

		$this->assertFalse( $refused['ok'] );
		$this->assertStringContainsString( '403', $refused['error'] );
	}

	public function test_a_bucket_with_no_cors_has_an_empty_policy(): void {
		FakeHttp::queue( 404, '<Error><Code>NoSuchCORSConfiguration</Code></Error>' );

		$result = $this->bucket()->cors();

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 0, $result['policy'] );
	}

	public function test_a_missing_bucket_is_not_a_missing_policy(): void {
		FakeHttp::queue( 404, '<Error><Code>NoSuchBucket</Code></Error>' );

		$this->assertFalse( $this->bucket()->cors()['ok'] );
	}

	public function test_a_stored_policy_is_read_back(): void {
		FakeHttp::queue( 200, Policy::for_browser_uploads( array( 'https://shop.example' ) )->to_xml() );

		$policy = $this->bucket()->cors()['policy'];

		$this->assertTrue( $policy->supports_multipart_upload( 'https://shop.example' )['ok'] );
	}

	public function test_setting_cors_puts_the_whole_policy(): void {
		FakeHttp::queue( 200 );

		$this->assertTrue( $this->bucket()->set_cors( new Policy( array( Rule::browser_upload( array( 'https://shop.example' ) ) ) ) )['ok'] );
		$this->assertStringContainsString( '?cors', FakeHttp::last()['url'] );
		$this->assertStringContainsString( '<ExposeHeader>ETag</ExposeHeader>', FakeHttp::last()['args']['body'] );
		$this->assertArrayHasKey( 'content-md5', FakeHttp::last()['args']['headers'] );
	}

	/* ─── To disk ───────────────────────────────────────────────────── */

	private function target(): string {
		$path          = sys_get_temp_dir() . '/sc-download-' . bin2hex( random_bytes( 4 ) );
		$this->files[] = $path;

		return $path;
	}

	/**
	 * Answer a streamed GET by writing the body where WordPress would.
	 */
	private static function streams( int $status, string $body, array $headers = array() ): void {
		FakeHttp::respond_with(
			static function ( string $url, array $args ) use ( $status, $body, $headers ) {
				if ( ! empty( $args['stream'] ) ) {
					file_put_contents( $args['filename'], $body );
				}

				return FakeHttp::response( $status, '', $headers );
			}
		);
	}

	public function test_a_download_is_written_to_the_file(): void {
		$path = $this->target();

		self::streams( 200, str_repeat( 'd', 70000 ), array( 'Content-Length' => '70000' ) );

		$this->assertTrue( $this->bucket()->download_file( 'backups/a.db', $path ) );
		$this->assertSame( 70000, filesize( $path ) );
		$this->assertStringContainsString( '/files/backups/a.db?', FakeHttp::last()['url'] );
		$this->assertTrue( FakeHttp::last()['args']['stream'] );
	}

	#[DataProvider( 'short_downloads' )]
	public function test_a_download_that_is_not_the_whole_object_leaves_nothing( int $status, string $body, array $headers ): void {
		$path = $this->target();

		self::streams( $status, $body, $headers );

		$this->assertInstanceOf( WP_Error::class, $this->bucket()->download_file( 'backups/a.db', $path ) );
		$this->assertFileDoesNotExist( $path );
	}

	public static function short_downloads(): array {
		return array(
			'refused'   => array( 404, '<Error><Code>NoSuchKey</Code></Error>', array() ),
			'cut short' => array( 200, 'abc', array( 'Content-Length' => '10' ) ),
		);
	}

	public function test_a_download_will_not_overwrite_a_file(): void {
		$path = $this->target();

		file_put_contents( $path, 'already here' );

		$this->assertSame( 's3_download_exists', $this->bucket()->download_file( 'backups/a.db', $path )->get_error_code() );
		$this->assertSame( 'already here', file_get_contents( $path ) );
		$this->assertSame( array(), FakeHttp::requests() );
	}

	/* ─── Errors ────────────────────────────────────────────────────── */

	public function test_a_storage_error_reads_the_error_document(): void {
		$error = StorageError::refused( 'read', 403, '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>' );

		$this->assertSame( 'AccessDenied', $error->error_code );
		$this->assertSame( 'Storage read failed (403): AccessDenied: Access Denied', $error->getMessage() );
		$this->assertSame( 's3_read_failed', $error->to_wp_error()->get_error_code() );
		$this->assertSame( 'mine', $error->to_wp_error( 'mine' )->get_error_code() );
	}
}
