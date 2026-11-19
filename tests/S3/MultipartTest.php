<?php
/**
 * Resumable multipart uploads, against a bucket that keeps state.
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\S3;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Storage\S3\Bucket;
use SugarCommerce\Storage\S3\Multipart;
use SugarCommerce\Storage\S3\Signer;
use SugarCommerce\Storage\S3\Upload;
use SugarCommerce\Storage\Tests\Support\FakeHttp;
use SugarCommerce\Storage\Tests\Support\FakeS3;
use WP_Error;

/**
 * Multipart, Upload and the Bucket calls under them, driven against FakeS3.
 */
final class MultipartTest extends TestCase {

	/**
	 * One mebibyte.
	 */
	private const MB = 1048576;

	/**
	 * The bucket in memory.
	 *
	 * @var FakeS3
	 */
	private FakeS3 $s3;

	/**
	 * The bucket, through the real signer.
	 *
	 * @var Bucket
	 */
	private Bucket $bucket;

	/**
	 * Temporary files to remove.
	 *
	 * @var string[]
	 */
	private array $files = [];

	/**
	 * A fresh bucket.
	 */
	protected function setUp(): void {
		wp_test_reset();

		$this->s3     = FakeS3::install( 'bucket' );
		$this->bucket = new Bucket( new Signer( 'test-access', 'test-secret', 'storage.example.com', 'auto', 1700000000 ), 'bucket' );
	}

	/**
	 * Remove temporary files.
	 */
	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}

		FakeHttp::reset();
	}

	/* ─── Sizes and parts ───────────────────────────────────────────── */

	/**
	 * A 5 TB file fits inside the part limit, in parts no larger than 5 GiB.
	 */
	public function test_the_largest_object_fits_the_part_limit(): void {
		$part = Bucket::part_size( Bucket::MAX_OBJECT_SIZE, Multipart::PART_SIZE );

		$this->assertLessThanOrEqual( Bucket::PART_LIMIT, (int) ceil( Bucket::MAX_OBJECT_SIZE / $part ) );
		$this->assertLessThanOrEqual( Bucket::MAX_PART_SIZE, $part );
		$this->assertSame( Multipart::PART_SIZE, Bucket::part_size( 100 * self::MB, Multipart::PART_SIZE ) );
	}

	/**
	 * No part is larger than storage accepts, whatever floor is asked for.
	 */
	public function test_a_part_never_passes_five_gibibytes(): void {
		$this->assertSame( Bucket::MAX_PART_SIZE, Bucket::part_size( 10 * Bucket::MAX_PART_SIZE, 8 * Bucket::MAX_PART_SIZE ) );
	}

	/**
	 * Parts are cut evenly, and the last holds what is left.
	 */
	public function test_an_upload_cuts_its_parts(): void {
		$upload = new Upload( str_repeat( 'a', 32 ), 'bucket', 'k', 'u', 11 * self::MB + 3, 5 * self::MB );

		$this->assertSame( 3, $upload->part_count() );
		$this->assertSame( 5 * self::MB, $upload->part_length( 1 ) );
		$this->assertSame( 5 * self::MB, $upload->offset( 2 ) );
		$this->assertSame( self::MB + 3, $upload->part_length( 3 ) );
		$this->assertSame( 0, $upload->part_length( 4 ) );
		$this->assertSame( 0, $upload->part_length( 0 ) );
		$this->assertSame( [ 1, 2, 3 ], $upload->missing() );

		$upload->parts = [ 1 => 'e1', 3 => 'e3' ];

		$this->assertSame( [ 2 ], $upload->missing() );
		$this->assertSame( 6 * self::MB + 3, $upload->sent() );
		$this->assertSame( 2, $upload->progress()['parts'] );
		$this->assertSame( 3, $upload->progress()['of'] );
		$this->assertFalse( $upload->done() );
	}

	/**
	 * A stored record that is not an upload is not read as one.
	 */
	public function test_a_broken_record_is_not_an_upload(): void {
		$good = ( new Upload( str_repeat( 'a', 32 ), 'bucket', 'k', 'u', 10, 5 ) )->to_array();

		$this->assertInstanceOf( Upload::class, Upload::from_array( $good ) );
		$this->assertNull( Upload::from_array( 'nope' ) );
		$this->assertNull( Upload::from_array( [ 'size' => 0 ] + $good ) );
		$this->assertNull( Upload::from_array( [ 'part_size' => 0 ] + $good ) );
		$this->assertNull( Upload::from_array( [ 'upload_id' => '' ] + $good ) );
		$this->assertNull( Upload::from_array( [ 'size' => 10001, 'part_size' => 1 ] + $good ) );
		$this->assertSame( [ 2 => 'e' ], Upload::from_array( [ 'parts' => [ 2 => 'e', 'x' => 'y', 3 => '' ] ] + $good )->parts );
	}

	/* ─── Starting ──────────────────────────────────────────────────── */

	/**
	 * Starting asks storage for an upload and remembers it.
	 */
	public function test_begin_starts_and_remembers(): void {
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'packs/Library.zip', 40 * self::MB, [
			'mime_type'   => 'application/zip',
			'fingerprint' => 'Library.zip|41943040|1700000000',
			'owner'       => 7,
		] );

		$this->assertInstanceOf( Upload::class, $upload );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $upload->id );
		$this->assertSame( Multipart::PART_SIZE, $upload->part_size );
		$this->assertSame( 3, $upload->part_count() );
		$this->assertArrayHasKey( $upload->upload_id, $this->s3->uploads );
		$this->assertSame( 'application/zip', $this->s3->uploads[ $upload->upload_id ]['type'] );
		$this->assertEquals( $upload, $multipart->find( $upload->id ) );
		$this->assertCount( 1, $multipart->pending() );
	}

	/**
	 * What storage cannot take is refused before it is asked.
	 */
	public function test_begin_refuses_what_storage_cannot_take(): void {
		$multipart = new Multipart( $this->bucket );

		$this->assertSame( 's3_upload_size', $multipart->begin( 'a', 0 )->get_error_code() );
		$this->assertSame( 's3_upload_size', $multipart->begin( 'a', Bucket::MAX_OBJECT_SIZE + 1 )->get_error_code() );
		$this->assertSame( 's3_upload_key', $multipart->begin( 'a/../b', 10 )->get_error_code() );
		$this->assertSame( 's3_upload_key', $multipart->begin( '', 10 )->get_error_code() );
		$this->assertSame( 's3_upload_source', $multipart->begin( 'a', 10, [ 'source' => '/no/such/file' ] )->get_error_code() );
		$this->assertSame( 's3_upload_source', $multipart->begin( 'a', 10, [ 'source' => $this->file( 'short' ) ] )->get_error_code() );
		$this->assertSame( [], $this->s3->uploads );
	}

	/**
	 * The largest object is accepted, and its parts still fit.
	 */
	public function test_begin_takes_five_terabytes(): void {
		$upload = ( new Multipart( $this->bucket ) )->begin( 'huge.bin', Bucket::MAX_OBJECT_SIZE );

		$this->assertInstanceOf( Upload::class, $upload );
		$this->assertLessThanOrEqual( Bucket::PART_LIMIT, $upload->part_count() );
	}

	/**
	 * Storage refusing to start is its refusal, with its code.
	 */
	public function test_begin_passes_on_a_refusal(): void {
		$this->s3->fail( static fn( $method, $key, $query ) => isset( $query['uploads'] ), FakeS3::error( 403, 'AccessDenied', 'Access Denied' ) );

		$error = ( new Multipart( $this->bucket ) )->begin( 'a.zip', 10 * self::MB );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'AccessDenied', $error->get_error_data()['error_code'] );
		$this->assertSame( [], ( new Multipart( $this->bucket ) )->pending() );
	}

	/**
	 * The same file offered again finds its upload; anything else does not.
	 */
	public function test_match_finds_the_same_file_only(): void {
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'a.zip', 20 * self::MB, [ 'fingerprint' => 'a.zip|20|1', 'owner' => 3 ] );

		$this->assertSame( $upload->id, $multipart->match( 'a.zip', 20 * self::MB, 'a.zip|20|1', 3 )?->id );
		$this->assertNull( $multipart->match( 'a.zip', 20 * self::MB, 'a.zip|20|2', 3 ) );
		$this->assertNull( $multipart->match( 'a.zip', 20 * self::MB, 'a.zip|20|1', 4 ) );
		$this->assertNull( $multipart->match( 'b.zip', 20 * self::MB, 'a.zip|20|1', 3 ) );
		$this->assertNull( $multipart->match( 'a.zip', 21 * self::MB, 'a.zip|20|1', 3 ) );
		$this->assertNull( $multipart->match( 'a.zip', 20 * self::MB, '', 3 ) );
	}

	/**
	 * An upload is found only by its id, and only for its own bucket.
	 */
	public function test_find_is_by_id_and_bucket(): void {
		$upload = ( new Multipart( $this->bucket ) )->begin( 'a.zip', 20 * self::MB );
		$other  = new Multipart( new Bucket( $this->bucket->signer(), 'other' ) );

		$this->assertNull( $other->find( $upload->id ) );
		$this->assertSame( [], $other->pending() );
		$this->assertNull( ( new Multipart( $this->bucket ) )->find( 'not-an-id' ) );
		$this->assertNull( ( new Multipart( $this->bucket ) )->find( str_repeat( 'f', 32 ) ) );
	}

	/* ─── Browser parts ─────────────────────────────────────────────── */

	/**
	 * Signed URLs are for this upload's parts only.
	 */
	public function test_sign_is_for_real_parts_only(): void {
		$upload = ( new Multipart( $this->bucket ) )->begin( 'a.zip', 20 * self::MB );
		$urls   = ( new Multipart( $this->bucket ) )->sign( $upload, [ 0, 1, 2, 2, 9, -1 ] );

		$this->assertSame( [ 1, 2 ], array_keys( $urls ) );
		$this->assertStringContainsString( 'partNumber=2', $urls[2] );
		$this->assertStringContainsString( 'uploadId=' . rawurlencode( $upload->upload_id ), $urls[2] );
		$this->assertStringContainsString( 'X-Amz-Signature=', $urls[1] );
	}

	/**
	 * A browser's parts, PUT to the signed URLs with their Content-MD5, are
	 * assembled from what storage lists.
	 */
	public function test_a_browser_upload_finishes_from_storage_s_list(): void {
		$multipart = new Multipart( $this->bucket );
		$bytes     = $this->bytes( 11 * self::MB + 5 );
		$upload    = $multipart->begin( 'video.mp4', strlen( $bytes ), [ 'part_size' => 5 * self::MB ] );

		foreach ( $multipart->sign( $upload, $upload->missing() ) as $number => $url ) {
			$part = substr( $bytes, $upload->offset( $number ), $upload->part_length( $number ) );

			wp_remote_request( $url, [
				'method'  => 'PUT',
				'headers' => [ 'Content-MD5' => base64_encode( md5( $part, true ) ) ],
				'body'    => $part,
			] );
		}

		$this->assertSame( 's3://bucket/video.mp4', $multipart->finish( $upload ) );
		$this->assertSame( $bytes, $this->s3->objects['video.mp4'] );
		$this->assertSame( [], $this->s3->uploads );
		$this->assertNull( $multipart->find( $upload->id ) );
	}

	/**
	 * A part damaged on the way is refused by its checksum, and so is never
	 * assembled.
	 */
	public function test_a_damaged_browser_part_is_refused(): void {
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'a.bin', 6 * self::MB, [ 'part_size' => 5 * self::MB ] );
		$part      = $this->bytes( 5 * self::MB );

		$answer = wp_remote_request( $multipart->sign( $upload, [ 1 ] )[1], [
			'method'  => 'PUT',
			'headers' => [ 'Content-MD5' => base64_encode( md5( $part, true ) ) ],
			'body'    => substr_replace( $part, 'X', 100, 1 ),
		] );

		$this->assertSame( 400, $answer['response']['code'] );
		$this->assertStringContainsString( 'BadDigest', $answer['body'] );
		$this->assertSame( [], ( new Multipart( $this->bucket ) )->stored( $upload ) );
	}

	/**
	 * Finishing with parts still to send says which, and keeps the upload.
	 */
	public function test_finish_names_the_missing_parts(): void {
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'a.bin', 16 * self::MB, [ 'part_size' => 5 * self::MB ] );

		$this->put_part( $upload, 2, $this->bytes( 5 * self::MB ) );

		$error = $multipart->finish( $upload );

		$this->assertSame( 's3_upload_incomplete', $error->get_error_code() );
		$this->assertSame( [ 1, 3, 4 ], $error->get_error_data()['missing'] );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertArrayHasKey( $upload->upload_id, $this->s3->uploads );
		$this->assertSame( [ 2 ], array_keys( $multipart->find( $upload->id )->parts ) );
	}

	/**
	 * A part at the wrong size counts as missing, so it is sent again rather
	 * than assembled into a file of the wrong length.
	 */
	public function test_a_part_of_the_wrong_size_is_missing(): void {
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'a.bin', 11 * self::MB, [ 'part_size' => 5 * self::MB ] );

		$this->put_part( $upload, 1, $this->bytes( 5 * self::MB ) );
		$this->put_part( $upload, 2, $this->bytes( 5 * self::MB - 1 ) );
		$this->put_part( $upload, 3, $this->bytes( self::MB ) );

		$this->assertSame( [ 1, 3 ], array_keys( $multipart->stored( $upload ) ) );
		$this->assertSame( [ 2 ], $multipart->finish( $upload )->get_error_data()['missing'] );
	}

	/**
	 * A finish whose answer was lost is recognised the second time by the
	 * whole object being there.
	 */
	public function test_a_finish_asked_twice_is_still_a_finish(): void {
		$multipart = new Multipart( $this->bucket );
		$bytes     = $this->bytes( 6 * self::MB );
		$upload    = $multipart->begin( 'a.bin', strlen( $bytes ), [ 'part_size' => 5 * self::MB ] );

		$this->put_part( $upload, 1, substr( $bytes, 0, 5 * self::MB ) );
		$this->put_part( $upload, 2, substr( $bytes, 5 * self::MB ) );

		$this->s3->fail( static fn( $method, $key, $query ) => 'POST' === $method && isset( $query['uploadId'] ) );

		$this->assertInstanceOf( WP_Error::class, $multipart->finish( $upload ) );
		$this->assertSame( $bytes, $this->s3->objects['a.bin'] );
		$this->assertSame( 's3://bucket/a.bin', ( new Multipart( $this->bucket ) )->finish( $upload ) );
		$this->assertNull( $multipart->find( $upload->id ) );
	}

	/**
	 * An upload storage no longer has, and no object, is gone.
	 */
	public function test_finish_of_an_upload_storage_dropped(): void {
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'a.bin', 6 * self::MB );

		$this->s3->uploads = [];

		$this->assertSame( 's3_upload_gone', $multipart->finish( $upload )->get_error_code() );
		$this->assertNull( $multipart->find( $upload->id ) );
	}

	/**
	 * Assembly refused inside a 200 is a refusal, and the parts stay for
	 * another try.
	 */
	public function test_a_refused_assembly_keeps_the_parts(): void {
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'a.bin', 5 * self::MB );

		$this->put_part( $upload, 1, $this->bytes( 5 * self::MB ) );
		$this->s3->fail(
			static fn( $method, $key, $query ) => 'POST' === $method && isset( $query['uploadId'] ),
			FakeHttp::response( 200, '<Error><Code>InternalError</Code><Message>Try again</Message></Error>' )
		);

		$this->assertSame( 'InternalError', $multipart->finish( $upload )->get_error_data()['error_code'] );
		$this->assertArrayHasKey( $upload->upload_id, $this->s3->uploads );
		$this->assertNotNull( $multipart->find( $upload->id ) );
		$this->assertSame( 's3://bucket/a.bin', $multipart->finish( $upload ) );
	}

	/**
	 * An assembled object of the wrong length is reported, not passed off as
	 * the file.
	 */
	public function test_an_object_of_the_wrong_length_is_an_error(): void {
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'a.bin', 5 * self::MB );

		$this->put_part( $upload, 1, $this->bytes( 5 * self::MB ) );

		// Storage answers the size check with a different length.
		$this->s3->fail(
			static fn( $method, $key, $query, $headers ) => 'GET' === $method && isset( $headers['range'] ),
			FakeHttp::response( 206, 'x', [ 'Content-Range' => 'bytes 0-0/12' ] )
		);

		$error = $multipart->finish( $upload );

		$this->assertSame( 's3_upload_size', $error->get_error_code() );
		$this->assertSame( 12, $error->get_error_data()['stored'] );
	}

	/* ─── From this server ──────────────────────────────────────────── */

	/**
	 * A file on this server goes up part by part, each with both checksums,
	 * and is assembled.
	 */
	public function test_run_sends_a_file_from_this_server(): void {
		$bytes     = $this->bytes( 11 * self::MB + 7 );
		$path      = $this->file( $bytes );
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'packs/Library.zip', strlen( $bytes ), [ 'source' => $path, 'part_size' => 5 * self::MB ] );

		$this->assertSame( 's3://bucket/packs/Library.zip', $multipart->run( $upload->id, 0 ) );
		$this->assertSame( $bytes, $this->s3->objects['packs/Library.zip'] );

		$parts = $this->s3->requests( 'PUT', 'partNumber' );

		$this->assertCount( 3, $parts );

		foreach ( $parts as $part ) {
			$this->assertSame( base64_encode( md5( $part['body'], true ) ), $part['headers']['content-md5'] );
			$this->assertSame( hash( 'sha256', $part['body'] ), $part['headers']['x-amz-content-sha256'] );
		}

		$this->assertNull( $multipart->find( $upload->id ) );
	}

	/**
	 * A run stops before a part that would not finish in its time, and the
	 * next run carries on from there.
	 */
	public function test_run_keeps_inside_its_time(): void {
		$bytes     = $this->bytes( 20 * self::MB );
		$time      = 0.0;
		$clock     = static function () use ( &$time ): float {
			$time += 3.0;

			return $time;
		};
		$multipart = new Multipart( $this->bucket, $clock );
		$upload    = $multipart->begin( 'a.bin', strlen( $bytes ), [ 'source' => $this->file( $bytes ), 'part_size' => 5 * self::MB ] );

		// Every reading of the clock is three seconds on, so a part takes
		// three and sixteen seconds holds two of them.
		$first = $multipart->run( $upload->id, 16 );

		$this->assertInstanceOf( Upload::class, $first );
		$this->assertSame( [ 1, 2 ], array_keys( $first->parts ) );
		$this->assertSame( [ 1, 2 ], array_keys( $multipart->find( $upload->id )->parts ) );
		$this->assertSame( 50.0, $first->progress()['percent'] );

		$this->assertSame( 's3://bucket/a.bin', $multipart->run( $upload->id, 0 ) );
		$this->assertCount( 4, $this->s3->requests( 'PUT', 'partNumber' ) );
		$this->assertSame( $bytes, $this->s3->objects['a.bin'] );
	}

	/**
	 * However short the time, every run sends a part.
	 */
	public function test_every_run_sends_at_least_one_part(): void {
		$bytes     = $this->bytes( 11 * self::MB );
		$time      = 0.0;
		$multipart = new Multipart( $this->bucket, static function () use ( &$time ): float {
			return $time += 100.0;
		} );
		$upload    = $multipart->begin( 'a.bin', strlen( $bytes ), [ 'source' => $this->file( $bytes ), 'part_size' => 5 * self::MB ] );

		$this->assertSame( [ 1 ], array_keys( $multipart->run( $upload->id, 1 )->parts ) );
		$this->assertSame( [ 1, 2 ], array_keys( $multipart->run( $upload->id, 1 )->parts ) );
	}

	/**
	 * A request that timed out after storage kept the part does not send it
	 * again: the next run asks storage first.
	 */
	public function test_a_part_whose_answer_was_lost_is_not_sent_again(): void {
		$bytes     = $this->bytes( 16 * self::MB );
		$path      = $this->file( $bytes );
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'a.bin', strlen( $bytes ), [ 'source' => $path, 'part_size' => 5 * self::MB ] );

		$this->s3->fail( static fn( $method, $key, $query ) => 'PUT' === $method && '2' === ( $query['partNumber'] ?? '' ) );

		$error = $multipart->run( $upload->id, 0 );

		$this->assertSame( 's3_upload_part_failed', $error->get_error_code() );
		$this->assertSame( 0, $error->get_error_data()['status'] );
		$this->assertSame( [ 1 ], array_keys( $multipart->find( $upload->id )->parts ) );

		// A later request -- a new job run -- carries on.
		$this->assertSame( 's3://bucket/a.bin', ( new Multipart( $this->bucket ) )->run( $upload->id, 0 ) );
		$this->assertSame( $bytes, $this->s3->objects['a.bin'] );

		$sent = array_count_values( array_map( static fn( array $put ): string => $put['query']['partNumber'], $this->s3->requests( 'PUT', 'partNumber' ) ) );

		$this->assertSame( [ '1' => 1, '2' => 1, '3' => 1, '4' => 1 ], $sent );
	}

	/**
	 * A part storage never received is sent again on the next run.
	 */
	public function test_a_part_that_never_arrived_is_sent_again(): void {
		$bytes     = $this->bytes( 11 * self::MB );
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'a.bin', strlen( $bytes ), [ 'source' => $this->file( $bytes ), 'part_size' => 5 * self::MB ] );

		$this->s3->fail(
			static fn( $method, $key, $query ) => 'PUT' === $method && '2' === ( $query['partNumber'] ?? '' ),
			new WP_Error( 'http_request_failed', 'cURL error 56: Connection reset by peer' )
		);

		$this->assertInstanceOf( WP_Error::class, $multipart->run( $upload->id, 0 ) );
		$this->assertSame( 's3://bucket/a.bin', $multipart->run( $upload->id, 0 ) );
		$this->assertCount( 2, array_filter( $this->s3->requests( 'PUT', 'partNumber' ), static fn( array $put ): bool => '2' === $put['query']['partNumber'] ) );
		$this->assertSame( $bytes, $this->s3->objects['a.bin'] );
	}

	/**
	 * A part this side recorded but storage lost is sent again.
	 */
	public function test_resume_trusts_storage_over_the_record(): void {
		$bytes     = $this->bytes( 11 * self::MB );
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'a.bin', strlen( $bytes ), [ 'source' => $this->file( $bytes ), 'part_size' => 5 * self::MB ] );
		$upload    = $multipart->send( $upload, 0 );

		unset( $this->s3->uploads[ $upload->upload_id ]['parts'][2] );

		$this->assertSame( [ 1, 3 ], array_keys( $multipart->resume( $upload )->parts ) );
		$this->assertSame( 's3://bucket/a.bin', $multipart->run( $upload->id, 0 ) );
		$this->assertSame( $bytes, $this->s3->objects['a.bin'] );
	}

	/**
	 * A file that changed after the upload began is not sent half old and
	 * half new.
	 */
	public function test_a_changed_source_is_refused(): void {
		$path      = $this->file( $this->bytes( 6 * self::MB ) );
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'a.bin', 6 * self::MB, [ 'source' => $path, 'part_size' => 5 * self::MB ] );

		file_put_contents( $path, 'x', FILE_APPEND );

		$this->assertSame( 's3_upload_source', $multipart->send( $upload )->get_error_code() );
		$this->assertSame( [], $this->s3->requests( 'PUT', 'partNumber' ) );
	}

	/**
	 * A file replaced by another of the same size is caught by its modified
	 * time.
	 */
	public function test_a_replaced_source_of_the_same_size_is_refused(): void {
		$path      = $this->file( $this->bytes( 6 * self::MB ) );
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'a.bin', 6 * self::MB, [ 'source' => $path, 'part_size' => 5 * self::MB ] );

		file_put_contents( $path, str_repeat( 'z', 6 * self::MB ) );
		touch( $path, time() + 120 );

		$this->assertSame( 's3_upload_source', $multipart->send( $upload )->get_error_code() );
		$this->assertSame( [], $this->s3->requests( 'PUT', 'partNumber' ) );
	}

	/**
	 * A browser's upload has nothing on this server to send.
	 */
	public function test_send_needs_a_source(): void {
		$upload = ( new Multipart( $this->bucket ) )->begin( 'a.bin', 6 * self::MB );

		$this->assertSame( 's3_upload_source', ( new Multipart( $this->bucket ) )->send( $upload )->get_error_code() );
	}

	/**
	 * Two runs at once do not both send.
	 */
	public function test_one_run_at_a_time(): void {
		$bytes     = $this->bytes( 6 * self::MB );
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'a.bin', strlen( $bytes ), [ 'source' => $this->file( $bytes ), 'part_size' => 5 * self::MB ] );
		$lock      = \SugarCommerce\Storage\Utils\Runtime::key( 'upload-lock_' . $upload->id );

		add_option( $lock, time() + 60 );

		$this->assertSame( 's3_upload_busy', $multipart->send( $upload )->get_error_code() );

		// A hold whose time has passed is from a request that died.
		update_option( $lock, time() - 1 );

		$this->assertInstanceOf( Upload::class, $multipart->send( $upload, 0 ) );
		$this->assertFalse( get_option( $lock ) );
	}

	/**
	 * A run for an id with no upload says so.
	 */
	public function test_run_of_an_unknown_upload(): void {
		$this->assertSame( 's3_upload_unknown', ( new Multipart( $this->bucket ) )->run( str_repeat( 'a', 32 ) )->get_error_code() );
	}

	/* ─── Giving up and clearing up ─────────────────────────────────── */

	/**
	 * Aborting frees the parts at storage and forgets the upload.
	 */
	public function test_abort_frees_and_forgets(): void {
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'a.bin', 6 * self::MB );

		$this->put_part( $upload, 1, $this->bytes( 5 * self::MB ) );

		$this->assertTrue( $multipart->abort( $upload ) );
		$this->assertSame( [], $this->s3->uploads );
		$this->assertNull( $multipart->find( $upload->id ) );

		// Already gone at storage: still forgotten.
		$again = $multipart->begin( 'b.bin', 6 * self::MB );
		$this->s3->uploads = [];

		$this->assertTrue( $multipart->abort( $again ) );
		$this->assertNull( $multipart->find( $again->id ) );
	}

	/**
	 * An abort storage refuses keeps the upload remembered, to try again.
	 */
	public function test_a_refused_abort_is_remembered(): void {
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'a.bin', 6 * self::MB );

		$this->s3->fail( static fn( $method, $key, $query ) => 'DELETE' === $method, FakeS3::error( 403, 'AccessDenied', 'Access Denied' ) );

		$this->assertFalse( $multipart->abort( $upload ) );
		$this->assertNotNull( $multipart->find( $upload->id ) );
	}

	/**
	 * Every unfinished upload in the bucket older than the limit is aborted,
	 * page after page; younger ones are left.
	 */
	public function test_prune_aborts_old_uploads_across_pages(): void {
		$multipart              = new Multipart( $this->bucket );
		$this->s3->uploads_page = 2;
		$now                    = time();

		$this->s3->now = $now - 9 * 86400;
		$old           = [];

		for ( $i = 0; $i < 5; $i++ ) {
			$old[] = $multipart->begin( 'old/' . $i . '.bin', 6 * self::MB );
		}

		$this->s3->now = $now - 86400;
		$young         = $multipart->begin( 'young.bin', 6 * self::MB );

		$result = $multipart->prune( 7, $now );

		$this->assertSame( [ 'aborted' => 5, 'failed' => 0, 'forgotten' => 5 ], $result );
		$this->assertSame( [ $young->upload_id ], array_keys( $this->s3->uploads ) );
		$this->assertNotNull( $multipart->find( $young->id ) );
		$this->assertNull( $multipart->find( $old[0]->id ) );
		$this->assertGreaterThanOrEqual( 3, count( $this->s3->requests( 'GET', 'uploads' ) ) );
	}

	/**
	 * An upload remembered here that has not moved in the limit is forgotten,
	 * even when storage already dropped it.
	 */
	public function test_prune_forgets_still_records(): void {
		$multipart = new Multipart( $this->bucket );
		$upload    = $multipart->begin( 'a.bin', 6 * self::MB );
		$record    = $upload->to_array();

		$record['started'] = $record['updated'] = time() - 30 * 86400;
		update_option( \SugarCommerce\Storage\Utils\Runtime::key( 'upload_' . $upload->id ), $record );
		$this->s3->uploads = [];

		$this->assertSame( 1, $multipart->prune( 7 )['forgotten'] );
		$this->assertSame( [], $multipart->pending() );
	}

	/**
	 * A bucket whose uploads cannot be listed is an error, and nothing is
	 * forgotten.
	 */
	public function test_prune_needs_the_listing(): void {
		$multipart = new Multipart( $this->bucket );
		$multipart->begin( 'a.bin', 6 * self::MB );

		$this->s3->fail( static fn( $method, $key, $query ) => 'GET' === $method && isset( $query['uploads'] ), FakeS3::error( 403, 'AccessDenied', 'Access Denied' ) );

		$this->assertSame( 's3_uploads_failed', $multipart->prune( 7 )->get_error_code() );
		$this->assertCount( 1, $multipart->pending() );
	}

	/**
	 * A listing that is not one -- an error inside a 200 -- is an error.
	 */
	public function test_uploads_refuses_a_non_listing(): void {
		$this->s3->fail( static fn() => true, FakeHttp::response( 200, '<Error><Code>InternalError</Code></Error>' ) );

		$this->assertInstanceOf( WP_Error::class, $this->bucket->uploads() );
	}

	/**
	 * Uploads come back with their key, id and start time, by prefix.
	 */
	public function test_uploads_lists_by_prefix(): void {
		$multipart = new Multipart( $this->bucket );
		$a         = $multipart->begin( 'packs/a.bin', 6 * self::MB );
		$multipart->begin( 'other/b.bin', 6 * self::MB );

		$listed = $this->bucket->uploads( 'packs/' );

		$this->assertSame( [ [ 'key' => 'packs/a.bin', 'upload_id' => $a->upload_id, 'initiated' => gmdate( 'Y-m-d\TH:i:s.000\Z', $this->s3->now ) ] ], $listed );
	}

	/**
	 * The ListMultipartUploads query: an upload id marker only beside a key
	 * marker.
	 */
	public function test_the_list_uploads_request(): void {
		$signer = $this->bucket->signer();

		$this->assertStringContainsString( 'max-uploads=1000&prefix=p%2F&uploads=', $signer->sign_list_multipart_uploads( 'bucket', 'p/' )->url );
		$this->assertStringNotContainsString( 'upload-id-marker', $signer->sign_list_multipart_uploads( 'bucket', '', '', 'u1' )->url );
		$this->assertStringContainsString( 'key-marker=k&max-uploads=5&upload-id-marker=u1', $signer->sign_list_multipart_uploads( 'bucket', '', 'k', 'u1', 5 )->url );
		$this->assertStringContainsString( 'max-uploads=1&', $signer->sign_list_multipart_uploads( 'bucket', '', '', '', 0 )->url );
	}

	/**
	 * A part sent from this server is refused for a number storage would
	 * refuse, before any request.
	 */
	public function test_upload_part_refuses_a_bad_number(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->bucket->upload_part( 'a', 'u', 10001, 'x' );
	}

	/**
	 * A part answered without an ETag is not a part.
	 */
	public function test_upload_part_needs_an_etag(): void {
		$this->s3->fail( static fn() => true, FakeHttp::response( 200 ) );

		$this->assertSame( 's3_upload_part_failed', $this->bucket->upload_part( 'a', 'u', 1, 'x' )->get_error_code() );
	}

	/**
	 * upload_file() now carries a Content-MD5 on every request, which a
	 * bucket checks.
	 */
	public function test_upload_file_sends_checksums(): void {
		$bytes = $this->bytes( 11 * self::MB );

		$this->assertSame( 's3://bucket/big.bin', $this->bucket->upload_file( 'big.bin', $this->file( $bytes ), [ 'part_size' => 5 * self::MB ] ) );
		$this->assertSame( 's3://bucket/small.txt', $this->bucket->upload_file( 'small.txt', $this->file( 'hello' ) ) );
		$this->assertSame( $bytes, $this->s3->objects['big.bin'] );

		foreach ( $this->s3->requests( 'PUT' ) as $put ) {
			$this->assertSame( base64_encode( md5( $put['body'], true ) ), $put['headers']['content-md5'] );
		}
	}

	/* ─── Helpers ───────────────────────────────────────────────────── */

	/**
	 * PUT one part the way a browser does: to its signed URL, with its MD5.
	 *
	 * @param Upload $upload The upload.
	 * @param int    $number Part number.
	 * @param string $bytes  The part.
	 *
	 * @return void
	 */
	private function put_part( Upload $upload, int $number, string $bytes ): void {
		wp_remote_request( ( new Multipart( $this->bucket ) )->sign( $upload, [ $number ] )[ $number ], [
			'method'  => 'PUT',
			'headers' => [ 'Content-MD5' => base64_encode( md5( $bytes, true ) ) ],
			'body'    => $bytes,
		] );
	}

	/**
	 * Bytes that differ from part to part, so a part out of place shows.
	 *
	 * @param int $length How many.
	 *
	 * @return string
	 */
	private function bytes( int $length ): string {
		$block = '';

		for ( $i = 0; strlen( $block ) < 65536; $i++ ) {
			$block .= md5( (string) $i, true );
		}

		$bytes = '';

		for ( $i = 0; strlen( $bytes ) < $length; $i++ ) {
			$bytes .= pack( 'N', $i ) . substr( $block, 4 );
		}

		return substr( $bytes, 0, $length );
	}

	/**
	 * A temporary file.
	 *
	 * @param string $contents What it holds.
	 *
	 * @return string Its path.
	 */
	private function file( string $contents ): string {
		$path          = (string) tempnam( sys_get_temp_dir(), 'multipart' );
		$this->files[] = $path;

		file_put_contents( $path, $contents );

		return $path;
	}
}
