<?php
/**
 * Sending a file to storage, and asking about one.
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\S3;

use SugarCommerce\Storage\S3\AddressingStyle;
use SugarCommerce\Storage\S3\Signer;
use SugarCommerce\Storage\S3\Bucket;
use SugarCommerce\Storage\Tests\Support\FakeHttp;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Bucket::upload_file() and Bucket::head(), against a recorded HTTP API.
 */
final class BucketUploadTest extends TestCase {

	/**
	 * One mebibyte.
	 */
	private const MB = 1048576;

	/**
	 * Temporary files to remove.
	 *
	 * @var string[]
	 */
	private array $files = array();

	/**
	 * A signer with fixed, fake credentials and a fixed clock.
	 *
	 * @var Signer
	 */
	private Signer $signer;

	/**
	 * Fresh request log, empty queue.
	 */
	protected function setUp(): void {
		wp_test_reset();

		$this->signer = new Signer( 'test-access', 'test-secret', 'storage.example.com', 'auto', 1700000000 );
	}

	/**
	 * Remove the temporary files.
	 */
	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}

	/**
	 * A small file goes up in one PUT, and its address comes back.
	 */
	public function test_a_small_file_goes_in_one_put(): void {
		$file = $this->file( 'hello' );

		$result = ( new Bucket( $this->signer, 'bucket' ) )->upload_file( 'packs/a.zip', $file );

		$this->assertSame( 's3://bucket/packs/a.zip', $result );
		$this->assertCount( 1, $this->requests() );
		$this->assertSame( 'PUT', $this->requests()[0]['args']['method'] );
		$this->assertSame( 'hello', $this->requests()[0]['args']['body'] );
		$this->assertStringContainsString( '/bucket/packs/a.zip?', $this->requests()[0]['url'] );
		$this->assertSame( 300, $this->requests()[0]['args']['timeout'] );
	}

	/**
	 * A file exactly one part long is still one PUT.
	 */
	public function test_a_file_of_exactly_one_part_is_one_put(): void {
		$file = $this->file( str_repeat( 'a', 5 * self::MB ) );

		( new Bucket( $this->signer, 'bucket' ) )->upload_file( 'a.bin', $file, array( 'part_size' => 5 * self::MB ) );

		$this->assertCount( 1, $this->requests() );
		$this->assertSame( 'PUT', $this->requests()[0]['args']['method'] );
	}

	/**
	 * A large file goes in parts: start, each part in order, complete.
	 */
	public function test_a_large_file_goes_in_parts(): void {
		$file = $this->file( str_repeat( 'a', 5 * self::MB ) . str_repeat( 'b', 5 * self::MB ) . 'c' );

		$this->queue(
			$this->ok( '<InitiateMultipartUploadResult><UploadId>up-1</UploadId></InitiateMultipartUploadResult>' ),
			$this->ok( '', array( 'ETag' => '"e1"' ) ),
			$this->ok( '', array( 'etag' => '"e2"' ) ),
			$this->ok( '', array( 'ETag' => '"e3"' ) ),
			$this->ok( '<CompleteMultipartUploadResult/>' )
		);

		$result = ( new Bucket( $this->signer, 'bucket' ) )->upload_file( 'big.wav', $file, array(
				'part_size' => 1,
				'mime_type' => 'audio/wav',
			) );

		$requests = $this->requests();

		$this->assertSame( 's3://bucket/big.wav', $result );
		$this->assertCount( 5, $requests );

		// Start, with the content type the finished object keeps.
		$this->assertSame( 'POST', $requests[0]['args']['method'] );
		$this->assertStringContainsString( '?uploads', $requests[0]['url'] );
		$this->assertSame( 'audio/wav', $requests[0]['args']['headers']['content-type'] );

		// Parts at the 5 MB floor, in order, each its own slice of the file.
		$this->assertStringContainsString( 'partNumber=1', $requests[1]['url'] );
		$this->assertStringContainsString( 'uploadId=up-1', $requests[1]['url'] );
		$this->assertSame( str_repeat( 'a', 5 * self::MB ), $requests[1]['args']['body'] );
		$this->assertStringContainsString( 'partNumber=2', $requests[2]['url'] );
		$this->assertSame( str_repeat( 'b', 5 * self::MB ), $requests[2]['args']['body'] );
		$this->assertStringContainsString( 'partNumber=3', $requests[3]['url'] );
		$this->assertSame( 'c', $requests[3]['args']['body'] );

		// Complete, naming every part's ETag, whatever case its header came in.
		$this->assertSame( 'POST', $requests[4]['args']['method'] );
		$this->assertStringContainsString( 'uploadId=up-1', $requests[4]['url'] );
		$this->assertStringContainsString( '<PartNumber>1</PartNumber><ETag>&quot;e1&quot;</ETag>', $requests[4]['args']['body'] );
		$this->assertStringContainsString( '<PartNumber>2</PartNumber><ETag>&quot;e2&quot;</ETag>', $requests[4]['args']['body'] );
		$this->assertStringContainsString( '<PartNumber>3</PartNumber><ETag>&quot;e3&quot;</ETag>', $requests[4]['args']['body'] );
	}

	/**
	 * A part that fails abandons the upload, and nothing after it is sent.
	 */
	public function test_a_failed_part_aborts_the_upload(): void {
		$file = $this->file( str_repeat( 'a', 11 * self::MB ) );

		$this->queue(
			$this->ok( '<UploadId>up-2</UploadId>' ),
			$this->ok( '', array( 'ETag' => '"e1"' ) ),
			$this->answer( 403 )
		);

		$result = ( new Bucket( $this->signer, 'bucket' ) )->upload_file( 'big.bin', $file, array( 'part_size' => 5 * self::MB, 'error_code' => 'shop_upload_failed' ) );

		$requests = $this->requests();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'shop_upload_failed', $result->get_error_code() );
		$this->assertSame( 'Storage refused the file (403).', $result->get_error_message() );
		$this->assertCount( 4, $requests );
		$this->assertSame( 'DELETE', $requests[3]['args']['method'] );
		$this->assertStringContainsString( 'uploadId=up-2', $requests[3]['url'] );
	}

	/**
	 * A transport failure on a part aborts too, and is returned as it came.
	 */
	public function test_a_transport_failure_aborts_too(): void {
		$file = $this->file( str_repeat( 'a', 11 * self::MB ) );

		$this->queue( $this->ok( '<UploadId>up-3</UploadId>' ), new WP_Error( 'http_request_failed', 'timed out' ) );

		$result = ( new Bucket( $this->signer, 'bucket' ) )->upload_file( 'big.bin', $file, array( 'part_size' => 5 * self::MB ) );

		$this->assertSame( 'http_request_failed', $result->get_error_code() );
		$this->assertSame( 'DELETE', $this->requests()[2]['args']['method'] );
	}

	/**
	 * A provider that will not put the parts together gets the upload
	 * abandoned.
	 */
	public function test_a_refused_completion_aborts(): void {
		$file = $this->file( str_repeat( 'a', 6 * self::MB ) );

		$this->queue(
			$this->ok( '<UploadId>up-4</UploadId>' ),
			$this->ok( '', array( 'ETag' => '"e1"' ) ),
			$this->ok( '', array( 'ETag' => '"e2"' ) ),
			$this->answer( 400 )
		);

		$result = ( new Bucket( $this->signer, 'bucket' ) )->upload_file( 'big.bin', $file, array( 'part_size' => 5 * self::MB, 'messages' => array( 'not_finished' => 'Not finished.' ) ) );

		$this->assertSame( 'Not finished.', $result->get_error_message() );
		$this->assertCount( 5, $this->requests() );
		$this->assertSame( 'DELETE', $this->requests()[4]['args']['method'] );
	}

	/**
	 * A provider that will not start the upload: nothing more is sent, and
	 * there is nothing to abandon.
	 */
	public function test_an_upload_that_will_not_start(): void {
		$file = $this->file( str_repeat( 'a', 6 * self::MB ) );

		$this->queue( $this->answer( 403, '<Error><UploadId>not-really</UploadId></Error>' ) );

		$result = ( new Bucket( $this->signer, 'bucket' ) )->upload_file( 'big.bin', $file, array( 'part_size' => 5 * self::MB ) );

		$this->assertSame( 'Storage would not start the upload.', $result->get_error_message() );
		$this->assertCount( 1, $this->requests() );
	}

	/**
	 * A refused single PUT is an error carrying the status.
	 */
	public function test_a_refused_put_is_an_error(): void {
		$this->queue( $this->answer( 403 ) );

		$result = ( new Bucket( $this->signer, 'bucket' ) )->upload_file( 'a.zip', $this->file( 'x' ), array( 'messages' => array( 'refused' => 'Refused: %d' ) ) );

		$this->assertSame( 's3_upload_failed', $result->get_error_code() );
		$this->assertSame( 'Refused: 403', $result->get_error_message() );
	}

	/**
	 * A missing file is an error, never an empty object.
	 */
	public function test_a_missing_file_sends_nothing(): void {
		$result = ( new Bucket( $this->signer, 'bucket' ) )->upload_file( 'a.zip', sys_get_temp_dir() . '/no-such-file-' . uniqid() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'The file to upload cannot be read.', $result->get_error_message() );
		$this->assertSame( array(), $this->requests() );
	}

	/**
	 * A signer that throws is an error, not an exception.
	 */
	public function test_a_signer_exception_is_an_error(): void {
		$signer = new Signer( 'test-access', 'test-secret', 'storage.example.com', 'auto', 1700000000, AddressingStyle::VirtualHosted );
		$result = ( new Bucket( $signer, 'Not A Bucket!' ) )->upload_file( 'a.zip', $this->file( 'x' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 's3_upload_failed', $result->get_error_code() );
	}

	/**
	 * A part answered without an ETag cannot be assembled, so the upload is
	 * abandoned rather than finished with a blank in it.
	 */
	public function test_a_part_without_an_etag_aborts_the_upload(): void {
		$file = $this->file( str_repeat( 'a', 6 * self::MB ) );

		$this->queue(
			$this->ok( '<UploadId>up-5</UploadId>' ),
			$this->ok()
		);

		$result = ( new Bucket( $this->signer, 'bucket' ) )->upload_file( 'big.bin', $file, array( 'part_size' => 5 * self::MB ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertCount( 3, $this->requests() );
		$this->assertSame( 'DELETE', $this->requests()[2]['args']['method'] );
	}

	/**
	 * CompleteMultipartUpload can fail inside a 200.
	 */
	public function test_a_completion_refused_inside_a_200_aborts(): void {
		$file = $this->file( str_repeat( 'a', 6 * self::MB ) );

		$this->queue(
			$this->ok( '<UploadId>up-6</UploadId>' ),
			$this->ok( '', array( 'ETag' => '"e1"' ) ),
			$this->ok( '', array( 'ETag' => '"e2"' ) ),
			$this->ok( '<Error><Code>InternalError</Code></Error>' )
		);

		$result = ( new Bucket( $this->signer, 'bucket' ) )->upload_file( 'big.bin', $file, array( 'part_size' => 5 * self::MB ) );

		$this->assertSame( 'Storage would not finish the upload.', $result->get_error_message() );
		$this->assertSame( 'DELETE', $this->requests()[4]['args']['method'] );
	}

	/**
	 * A whole object sent in one PUT keeps its type.
	 */
	public function test_a_small_file_keeps_its_type(): void {
		( new Bucket( $this->signer, 'bucket' ) )->upload_file( 'a.wav', $this->file( 'x' ), array( 'mime_type' => 'audio/wav' ) );

		$this->assertSame( 'audio/wav', $this->requests()[0]['args']['headers']['content-type'] );
	}

	/**
	 * A huge file is not split into more parts than storage allows.
	 */
	public function test_the_part_size_rises_for_a_file_too_big_for_the_limit(): void {
		$this->assertGreaterThan( 5 * self::MB, Bucket::part_size( 9001 * 5 * self::MB, 5 * self::MB ) );
	}

	/**
	 * HEAD reads size and type from headers in any case.
	 */
	public function test_head_reads_size_and_type(): void {
		$this->queue(
			$this->ok(
				'',
				array(
					'Content-Length' => '245000',
					'CONTENT-TYPE'   => 'application/zip',
				)
			)
		);

		$this->assertSame(
			array(
				'size'      => 245000,
				'mime_type' => 'application/zip',
			),
			( new Bucket( $this->signer, 'bucket' ) )->head( 'a.zip' )
		);
		$this->assertSame( 'HEAD', $this->requests()[0]['args']['method'] );
		$this->assertSame( 5, $this->requests()[0]['args']['timeout'] );
	}

	/**
	 * A provider that does not say is zero and empty, not an error.
	 */
	public function test_head_without_headers_is_unknown(): void {
		$this->queue( $this->ok( '', array( 'content-length' => 'lots' ) ) );

		$this->assertSame(
			array(
				'size'      => 0,
				'mime_type' => '',
			),
			( new Bucket( $this->signer, 'bucket' ) )->head( 'a.zip', array( 'timeout' => 9 ) )
		);
		$this->assertSame( 9, $this->requests()[0]['args']['timeout'] );
	}

	/**
	 * A HEAD that is not a success is an error with the status.
	 */
	public function test_head_failure_is_an_error(): void {
		$this->queue( $this->answer( 404 ) );

		$result = ( new Bucket( $this->signer, 'bucket' ) )->head( 'a.zip', array( 'error_code' => 'shop_head_failed' ) );

		$this->assertSame( 'shop_head_failed', $result->get_error_code() );
		$this->assertSame( 'Storage answered 404.', $result->get_error_message() );
	}

	/**
	 * A temporary file with these contents.
	 *
	 * @param string $contents The bytes.
	 *
	 * @return string Its path.
	 */
	private function file( string $contents ): string {
		$file = (string) tempnam( sys_get_temp_dir(), 's3t' );

		file_put_contents( $file, $contents );

		$this->files[] = $file;

		return $file;
	}

	/**
	 * Queue answers.
	 *
	 * @param array|WP_Error ...$responses In order.
	 */
	private function queue( ...$responses ): void {
		foreach ( $responses as $response ) {
			if ( $response instanceof WP_Error ) {
				FakeHttp::queue_error( $response->get_error_code(), $response->get_error_message() );
			} else {
				FakeHttp::queue( $response['response']['code'], $response['body'], $response['headers'] );
			}
		}
	}

	/**
	 * Every request sent.
	 *
	 * @return array<int, array{url: string, args: array}>
	 */
	private function requests(): array {
		return FakeHttp::requests();
	}

	/**
	 * A 200.
	 *
	 * @param string $body    The body.
	 * @param array  $headers The headers.
	 *
	 * @return array
	 */
	private function ok( string $body = '', array $headers = array() ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => $headers,
			'body'     => $body,
		);
	}

	/**
	 * Any status.
	 *
	 * @param int    $code The status.
	 * @param string $body The body.
	 *
	 * @return array
	 */
	private function answer( int $code, string $body = '' ): array {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => array(),
			'body'     => $body,
		);
	}
}
