<?php
/**
 * One S3-compatible bucket.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\S3;

use InvalidArgumentException;
use SugarCommerce\Storage\S3\Cors\Policy;
use Throwable;
use WP_Error;

/**
 * Class Bucket
 *
 * The Signer signs and deliberately does no I/O. This is the I/O, through
 * WordPress's HTTP API, for the handful of calls a plugin that stores files in
 * a bucket actually makes -- and the S3 behaviours each of them has to know
 * about:
 *
 *   - CompleteMultipartUpload, and a copy, can answer **200 with an error
 *     document**. Checking the status alone records an upload that failed.
 *   - An upload that is started and never finished keeps its parts, and is
 *     billed for them, until it is aborted.
 *   - A size a browser declared is a claim; size() asks the bucket.
 *   - A bucket with no CORS configuration answers 404, which is an answer.
 *
 * Failures come back as a WP_Error with storage's own error code in its data
 * (see StorageError), because why storage said no is what somebody has to
 * fix next. A byte range that is not one is a programming error and throws.
 *
 * Every answer that could be large and is not wanted whole is capped with
 * `limit_response_size`, so a server that ignores a Range header cannot pull
 * a six-gigabyte object into memory to answer a one-byte question.
 *
 *     $bucket = new Bucket( Provider::R2->signer( $key, $secret, account_id: $account ), 'files' );
 *
 *     $address = $bucket->upload_file( 'packs/Drum Kit.zip', $temp_file );
 *     $url     = $bucket->url( 'packs/Drum Kit.zip', 60, 'Drum Kit.zip' );
 *
 * @since 1.0.0
 */
final class Bucket {

	/**
	 * The default size of one part of a server-side multipart upload (16 MB).
	 *
	 * S3 wants at least 5 MB for every part but the last, and allows ten
	 * thousand parts, so sixteen covers a file of 160 GB while keeping one
	 * part's worth of memory small.
	 *
	 * @since 1.0.0
	 */
	public const PART_SIZE = 16 * 1048576;

	/**
	 * The smallest part S3 accepts, except for the last (5 MB).
	 *
	 * A smaller part size is raised to this rather than refused: the provider
	 * would reject every part but the last otherwise.
	 *
	 * @since 1.0.0
	 */
	public const MIN_PART_SIZE = 5 * 1048576;

	/**
	 * Parts per upload, with headroom under S3's 10,000 so a miscounted final
	 * part cannot push a large upload over.
	 *
	 * @since 1.0.0
	 */
	public const PART_LIMIT = 9000;

	/**
	 * The most parts S3, R2 and the rest accept in one upload.
	 *
	 * @since 1.0.0
	 */
	public const MAX_PARTS = 10000;

	/**
	 * The largest part S3 accepts (5 GiB).
	 *
	 * @since 1.0.0
	 */
	public const MAX_PART_SIZE = 5 * 1073741824;

	/**
	 * The largest object S3 and R2 store (5 TiB), and so the largest upload.
	 *
	 * Split into PART_LIMIT parts that is parts of about 583 MiB, well inside
	 * MAX_PART_SIZE.
	 *
	 * @since 1.0.0
	 */
	public const MAX_OBJECT_SIZE = 5 * 1099511627776;

	/**
	 * The largest object one PUT may carry (5 GiB). Past it, only a multipart
	 * upload will do.
	 *
	 * @since 1.0.0
	 */
	public const MAX_PUT_SIZE = 5 * 1073741824;

	/**
	 * Uploads ListMultipartUploads returns in one page, at most.
	 */
	private const UPLOADS_PAGE = 1000;

	/**
	 * Pages of unfinished uploads to follow before stopping: a bucket with
	 * more than fifty thousand of them has a problem a page of this will not
	 * fix, and a clean-up that runs daily gets the rest tomorrow.
	 */
	private const UPLOADS_PAGES = 50;

	/**
	 * Parts ListParts returns in one page, at most.
	 */
	private const PARTS_PAGE = 1000;

	/**
	 * Pages of parts to follow before giving up: S3 allows 10,000 parts,
	 * which is ten pages, and a listing still going after that is broken.
	 */
	private const PARTS_PAGES = 11;

	/**
	 * Most bytes stat() and size() read. One is asked for; a server that
	 * ignores the Range header is cut off here rather than read whole.
	 */
	private const STAT_BYTES = 1024;

	/**
	 * Wording for the upload and head failures that have no message of their
	 * own. A plugin passes its own, translated, through `messages`.
	 */
	private const MESSAGES = array(
		'unreadable'   => 'The file to upload cannot be read.',
		'refused'      => 'Storage refused the file (%d).',
		'not_started'  => 'Storage would not start the upload.',
		'not_finished' => 'Storage would not finish the upload.',
		'head'         => 'Storage answered %d.',
	);

	/**
	 * Point at a bucket.
	 *
	 * @since 1.0.0
	 *
	 * @param Signer $signer A signer for the provider.
	 * @param string $name   The bucket's name.
	 */
	public function __construct(
		private readonly Signer $signer,
		private readonly string $name
	) {}

	/**
	 * The part size for a file of a given size.
	 *
	 * Scales up so a very large file still fits inside PART_LIMIT, rather than
	 * failing at part 10,001 after an hour of uploading.
	 *
	 * @since 1.0.0
	 *
	 * @param int $bytes File size.
	 * @param int $floor Smallest part to use, when larger than S3's minimum --
	 *                   fewer round trips for an uploader that can afford to
	 *                   retry bigger parts. Never below MIN_PART_SIZE, and
	 *                   never above MAX_PART_SIZE.
	 *
	 * @return int
	 */
	public static function part_size( int $bytes, int $floor = self::MIN_PART_SIZE ): int {
		return min( self::MAX_PART_SIZE, max( self::MIN_PART_SIZE, $floor, (int) ceil( $bytes / self::PART_LIMIT ) ) );
	}

	/**
	 * The bucket's name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * The signer it was built with.
	 *
	 * @since 1.0.0
	 *
	 * @return Signer
	 */
	public function signer(): Signer {
		return $this->signer;
	}

	/* ─── From this server ──────────────────────────────────────────── */

	/**
	 * Put a file from this server into the bucket.
	 *
	 * A file no larger than one part goes in a single PUT; a larger one as
	 * S3's multipart upload, read from disk one part at a time, so a file of
	 * many gigabytes is never in memory whole. A part refused, a part that
	 * came back without an ETag, or an assembly the provider will not finish
	 * -- including one it refuses inside a 200 -- abandons the upload at the
	 * provider, so half a file is not left costing storage.
	 *
	 * Arguments:
	 *
	 *   - `part_size`  Bytes a part (default PART_SIZE, never less than
	 *                  MIN_PART_SIZE, and raised by part_size() for a file
	 *                  that would otherwise need more than PART_LIMIT parts).
	 *   - `mime_type`  The finished object's content type (default
	 *                  `application/octet-stream`).
	 *   - `timeout`    Seconds to wait for each request (default 300).
	 *   - `error_code` The code on a returned WP_Error (default
	 *                  `s3_upload_failed`).
	 *   - `messages`   Wording for `unreadable`, `refused` (with `%d` for the
	 *                  HTTP status), `not_started` and `not_finished`.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $key  The object's key.
	 * @param string               $path The local file.
	 * @param array<string, mixed> $args See above.
	 *
	 * @return string|WP_Error The object's `s3://bucket/key` address.
	 */
	public function upload_file( string $key, string $path, array $args = array() ): string|WP_Error {
		$args = self::args(
			$args,
			array(
				'part_size'  => self::PART_SIZE,
				'mime_type'  => 'application/octet-stream',
				'timeout'    => 300,
				'error_code' => 's3_upload_failed',
			)
		);

		// Without this, a missing file reads as zero bytes and goes up as an
		// empty object that looks like a success.
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( $args['error_code'], $args['messages']['unreadable'] );
		}

		$size = (int) filesize( $path );
		$part = self::part_size( $size, (int) $args['part_size'] );

		try {
			$sent = $size <= $part
				? $this->put_part( $this->signer->presign_put( $this->name, $key ), $path, $size, 0, $args, (string) $args['mime_type'] )
				: $this->in_parts( $key, $path, $size, $part, $args );
		} catch ( Throwable $exception ) {
			$sent = new WP_Error( $args['error_code'], $exception->getMessage() );
		}

		return $sent instanceof WP_Error ? $sent : Address::to( $this->name, $key );
	}

	/**
	 * How large an object is and what it says it is, without fetching it.
	 *
	 * One signed HEAD request, with a short timeout by default: the usual
	 * caller is somebody waiting on a Save button, and the answer is only a
	 * nicety.
	 *
	 * Arguments: `timeout` (default 5), `error_code` (default
	 * `s3_head_failed`) and `messages.head` (with `%d` for the HTTP status).
	 *
	 * @since 1.0.0
	 *
	 * @param string               $key  The object's key.
	 * @param array<string, mixed> $args See above.
	 *
	 * @return array{size: int, mime_type: string}|WP_Error Zero and an empty
	 *                                                      string are "the
	 *                                                      provider did not
	 *                                                      say".
	 */
	public function head( string $key, array $args = array() ): array|WP_Error {
		$args = self::args(
			$args,
			array(
				'timeout'    => 5,
				'error_code' => 's3_head_failed',
			)
		);

		try {
			$response = Transport::send( $this->signer->sign_head_object( $this->name, $key ), array( 'timeout' => (int) $args['timeout'] ) );
		} catch ( Throwable $exception ) {
			return new WP_Error( $args['error_code'], $exception->getMessage() );
		}

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		// The status goes in the data: a 404 is "not there", which is an
		// answer, and anything else is a refusal.
		if ( ! Transport::is_success( $response['status'] ) ) {
			return new WP_Error( $args['error_code'], sprintf( $args['messages']['head'], $response['status'] ), array( 'status' => $response['status'] ) );
		}

		// Header names arrive in whatever case the provider sent them.
		$headers = array_change_key_case( $response['headers'], CASE_LOWER );
		$length  = $headers['content-length'] ?? '';
		$type    = $headers['content-type'] ?? '';

		return array(
			'size'      => is_scalar( $length ) && ctype_digit( (string) $length ) ? (int) $length : 0,
			'mime_type' => is_scalar( $type ) ? (string) $type : '',
		);
	}

	/**
	 * Write a small object from memory.
	 *
	 * The small case, deliberately. A real upload goes browser to bucket so
	 * the bytes never enter PHP, or a file goes up with upload_file(); this is
	 * for what is already in memory -- a JSON sidecar, a generated preview.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key           Object key.
	 * @param string $body          Contents.
	 * @param string $mime_type     Content-Type to store.
	 * @param string $cache_control Cache-Control to store, or ''. For an
	 *                              object served straight from a public
	 *                              bucket, where this is the only caching
	 *                              anybody gets to set.
	 *
	 * @return true|WP_Error
	 */
	public function put( string $key, string $body, string $mime_type = 'application/octet-stream', string $cache_control = '' ): true|WP_Error {
		$headers = array( 'content-type' => $mime_type );

		if ( '' !== $cache_control ) {
			$headers['cache-control'] = $cache_control;
		}

		$request  = $this->signer->sign( Method::PUT, $this->name, $key, array(), $body, $headers );
		$response = Transport::request( 'PUT', $request->url, $request->headers, $body );

		return Transport::is_success( $response['status'] ) ? true : self::refusal( 'put', $response );
	}

	/**
	 * Read an object into a file on disk, as it arrives.
	 *
	 * The way back for upload_file(): a backup, an export, too big to hold, so
	 * WordPress streams the body to the file and it is never in memory whole.
	 * The file is created, never replaced, so a download cannot land on top
	 * of the file it was meant to sit beside. Anything short of the whole
	 * object -- a refusal, a transfer cut off, fewer bytes than the
	 * Content-Length promised -- removes what was written, rather than
	 * leaving a file that looks like a download.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Object key.
	 * @param string $path    Where to write it. Must not exist.
	 * @param int    $timeout Seconds to allow. Default 300.
	 *
	 * @return true|WP_Error `s3_download_exists` when the file is already
	 *                       there, `s3_download_failed` otherwise.
	 */
	public function download_file( string $key, string $path, int $timeout = 300 ): true|WP_Error {
		if ( file_exists( $path ) || is_link( $path ) ) {
			return new WP_Error( 's3_download_exists', 'A download will not overwrite ' . $path );
		}

		// Created first, exclusively, so two downloads cannot both claim it.
		$handle = @fopen( $path, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the false return is the answer.

		if ( false === $handle ) {
			return new WP_Error( 's3_download_failed', 'Cannot create ' . $path );
		}

		fclose( $handle );

		$response = Transport::request(
			'GET',
			$this->url( $key, max( 60, $timeout ) ),
			array(),
			null,
			array(
				'timeout'  => $timeout,
				'stream'   => true,
				'filename' => $path,
			)
		);

		clearstatcache( true, $path );

		$length = Transport::header( $response, 'content-length' );
		$whole  = 200 === $response['status'] && is_file( $path )
			&& ( '' === $length || ! ctype_digit( $length ) || (int) $length === (int) filesize( $path ) );

		if ( $whole ) {
			return true;
		}

		wp_delete_file( $path );

		return self::refusal( 'download', $response );
	}

	/* ─── Browser-direct multipart ──────────────────────────────────── */

	/**
	 * Open a multipart upload and return its id.
	 *
	 * Server-side, because the id only exists once storage has answered and
	 * every part needs it. Parsed with a pattern rather than an XML parser:
	 * one field, from a contract that has not changed in fifteen years.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key       Object key.
	 * @param string $mime_type Content-Type to store.
	 *
	 * @return string|WP_Error The upload id, or why storage refused: a rolled
	 *                         secret, a revoked key and a missing bucket are
	 *                         three different fixes.
	 */
	public function start_upload( string $key, string $mime_type = 'application/octet-stream' ): string|WP_Error {
		$request  = $this->signer->sign_create_multipart_upload( $this->name, $key, $mime_type );
		$response = Transport::request( 'POST', $request->url, $request->headers, '' );
		$upload   = Transport::is_success( $response['status'] ) ? self::field( $response['body'], 'UploadId' ) : '';

		return '' === $upload ? self::refusal( 'start_upload', $response ) : $upload;
	}

	/**
	 * Presigned URLs for a batch of parts, for the browser to PUT to.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $key       Object key.
	 * @param string          $upload_id Multipart upload id.
	 * @param array<int, int> $parts     Part numbers, 1-based.
	 * @param int             $expires   Seconds each URL works for.
	 *
	 * @return array<int, string> Part number => URL. Numbers out of range are
	 *                            left out.
	 */
	public function sign_parts( string $key, string $upload_id, array $parts, int $expires = 3600 ): array {
		$urls = array();

		foreach ( $parts as $number ) {
			$number = (int) $number;

			if ( $number >= 1 && $number <= 10000 ) {
				$urls[ $number ] = $this->signer->presign_upload_part( $this->name, $key, $upload_id, $number, $expires );
			}
		}

		return $urls;
	}

	/**
	 * Finish a multipart upload.
	 *
	 * @since 1.0.0
	 *
	 * @param string                                           $key       Object key.
	 * @param string                                           $upload_id Multipart upload id.
	 * @param array<int, array{PartNumber: int, ETag: string}> $parts     The parts, with their ETags.
	 *
	 * @return true|WP_Error An error on any refusal -- including a 200 carrying
	 *                       an error document, which is how S3 reports a
	 *                       failure it found after it had started answering.
	 *                       Abort the upload then, or its parts stay and are
	 *                       billed.
	 */
	public function finish_upload( string $key, string $upload_id, array $parts ): true|WP_Error {
		if ( array() === $parts ) {
			return new WP_Error( 's3_finish_upload_failed', 'A multipart upload cannot be finished with no parts.' );
		}

		$request  = $this->signer->sign_complete_multipart_upload( $this->name, $key, $upload_id, $parts );
		$response = Transport::request( 'POST', $request->url, $request->headers, $request->body );

		return self::confirmed( $response ) ? true : self::refusal( 'finish_upload', $response );
	}

	/**
	 * Abandon a multipart upload and free its parts.
	 *
	 * An upload started and never finished keeps every part it received, and
	 * they are billed, until this is called or a lifecycle rule clears them.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Multipart upload id.
	 *
	 * @return bool False means the parts are still in storage, still billed.
	 */
	public function abort_upload( string $key, string $upload_id ): bool {
		$request = $this->signer->sign_abort_multipart_upload( $this->name, $key, $upload_id );

		return Transport::is_success( Transport::request( 'DELETE', $request->url, $request->headers )['status'] );
	}

	/**
	 * Every part storage holds for an unfinished upload, in order.
	 *
	 * What resuming needs. A browser that reloads halfway through a 6 GB
	 * upload remembers which parts it sent, but storage decides what counts:
	 * a part the browser saw finish may not have been kept, and one it never
	 * heard back about may have been. So the browser's list is a hint and this
	 * is the answer. Followed page by page, a thousand parts at a time.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Multipart upload id.
	 *
	 * @return array<int, array{PartNumber: int, ETag: string, Size: int}>|null
	 *         Null when storage would not say -- most often because the upload
	 *         was finished or aborted and no longer exists, which a caller
	 *         should treat as "start again", not as "nothing sent yet".
	 */
	public function parts( string $key, string $upload_id ): ?array {
		$parts  = array();
		$marker = 0;

		for ( $page = 0; $page < self::PARTS_PAGES; $page++ ) {
			$request  = $this->signer->sign_list_parts( $this->name, $key, $upload_id, self::PARTS_PAGE, $marker );
			$response = Transport::request( 'GET', $request->url, $request->headers );

			// The 200-with-an-error-document case again: an answer is only a
			// listing if it says it is one.
			if ( ! Transport::is_success( $response['status'] ) || ! str_contains( $response['body'], '<ListPartsResult' ) ) {
				return null;
			}

			$listing = self::parse_list_parts( $response['body'] );
			$parts   = array_merge( $parts, $listing['parts'] );

			if ( ! $listing['more'] || $listing['next'] <= $marker ) {
				return $parts;
			}

			$marker = $listing['next'];
		}

		return null;
	}

	/**
	 * Read a ListPartsResult document.
	 *
	 * Patterns rather than an XML parser, the way an upload id is read: a
	 * handful of fields, from a contract that has not changed in fifteen
	 * years, and no entity expansion to think about. ETags come back without
	 * their quotes, ready to hand to finish_upload().
	 *
	 * @since 1.0.0
	 *
	 * @param string $xml Response body.
	 *
	 * @return array{parts: array<int, array{PartNumber: int, ETag: string, Size: int}>, more: bool, next: int}
	 */
	public static function parse_list_parts( string $xml ): array {
		$parts = array();

		preg_match_all( '#<Part>(.*?)</Part>#s', $xml, $blocks );

		foreach ( $blocks[1] as $block ) {
			$number = (int) self::field( $block, 'PartNumber' );
			$etag   = trim( html_entity_decode( self::field( $block, 'ETag' ), ENT_QUOTES | ENT_XML1 ), '"' );

			if ( $number >= 1 && '' !== $etag ) {
				$parts[] = array(
					'PartNumber' => $number,
					'ETag'       => $etag,
					'Size'       => (int) self::field( $block, 'Size' ),
				);
			}
		}

		usort( $parts, static fn( array $a, array $b ): int => $a['PartNumber'] <=> $b['PartNumber'] );

		return array(
			'parts' => $parts,
			'more'  => 'true' === strtolower( self::field( $xml, 'IsTruncated' ) ),
			'next'  => (int) self::field( $xml, 'NextPartNumberMarker' ),
		);
	}

	/**
	 * Send one part of a multipart upload from this server.
	 *
	 * Signed over the part's own SHA-256, with its Content-MD5 alongside, so
	 * storage checks the bytes twice and refuses a part that changed on the
	 * way (XAmzContentSHA256Mismatch, BadDigest) rather than keeping it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Multipart upload id.
	 * @param int    $number    Part number, 1 to MAX_PARTS.
	 * @param string $bytes     The part.
	 * @param int    $timeout   Seconds to allow. Default 300.
	 *
	 * @return string|WP_Error The part's ETag, unquoted. `s3_upload_part_failed`
	 *                         on a refusal, or a part answered without one.
	 *
	 * @throws InvalidArgumentException On a part number S3 would refuse.
	 */
	public function upload_part( string $key, string $upload_id, int $number, string $bytes, int $timeout = 300 ): string|WP_Error {
		if ( $number < 1 || $number > self::MAX_PARTS ) {
			throw new InvalidArgumentException( sprintf( 'Not a part number: %d.', $number ) );
		}

		$request  = $this->signer->sign(
			Method::PUT,
			$this->name,
			$key,
			array(
				'partNumber' => (string) $number,
				'uploadId'   => $upload_id,
			),
			$bytes,
			array( 'content-md5' => self::md5( $bytes ) )
		);
		$response = Transport::request( 'PUT', $request->url, $request->headers, $bytes, array( 'timeout' => $timeout ) );
		$etag     = trim( Transport::header( $response, 'etag' ), "\" \t" );

		if ( ! Transport::is_success( $response['status'] ) ) {
			return self::refusal( 'upload_part', $response );
		}

		// A part without an ETag cannot be assembled.
		return '' === $etag
			? new WP_Error( 's3_upload_part_failed', 'Storage kept the part but did not say its ETag.', array( 'status' => $response['status'] ) )
			: $etag;
	}

	/**
	 * Every multipart upload started in the bucket and neither finished nor
	 * aborted, oldest page first.
	 *
	 * ListMultipartUploads, followed page by page. Their parts are billed
	 * and invisible to an object listing, so this is the only way to find
	 * them.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Only uploads of keys starting with this.
	 *
	 * @return array<int, array{key: string, upload_id: string, initiated: string}>|WP_Error
	 *         `s3_uploads_failed` with storage's code in its data.
	 */
	public function uploads( string $prefix = '' ): array|WP_Error {
		$uploads   = array();
		$key       = '';
		$upload_id = '';

		for ( $page = 0; $page < self::UPLOADS_PAGES; $page++ ) {
			$request  = $this->signer->sign_list_multipart_uploads( $this->name, $prefix, $key, $upload_id, self::UPLOADS_PAGE );
			$response = Transport::request( 'GET', $request->url, $request->headers, null, array( 'limit_response_size' => 8388608 ) );
			$document = Transport::is_success( $response['status'] ) ? Xml::load( $response['body'] ) : null;

			// An answer is only a listing if it says it is one.
			if ( null === $document || 'ListMultipartUploadsResult' !== $document->getName() ) {
				return self::refusal( 'uploads', $response );
			}

			foreach ( Xml::children( $document, 'Upload' ) as $node ) {
				$entry = array(
					'key'       => Xml::text( $node, 'Key' ),
					'upload_id' => Xml::text( $node, 'UploadId' ),
					'initiated' => Xml::text( $node, 'Initiated' ),
				);

				if ( '' !== $entry['key'] && '' !== $entry['upload_id'] ) {
					$uploads[] = $entry;
				}
			}

			$next_key = Xml::text( $document, 'NextKeyMarker' );
			$next_id  = Xml::text( $document, 'NextUploadIdMarker' );

			// A page that names no way on, or the same way as last time,
			// would only be asked for again.
			if ( 'true' !== strtolower( Xml::text( $document, 'IsTruncated' ) ) || '' === $next_key || ( $next_key === $key && $next_id === $upload_id ) ) {
				break;
			}

			$key       = $next_key;
			$upload_id = $next_id;
		}

		return $uploads;
	}

	/**
	 * Abort every unfinished multipart upload older than some days.
	 *
	 * What a provider's AbortIncompleteMultipartUpload lifecycle rule does,
	 * for a bucket that has none -- or keys that may not set one. An upload
	 * a browser or a job is still sending is days younger than this, so the
	 * age is the whole test.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $days   Abort uploads started more than this many days
	 *                       ago. At least 1.
	 * @param string $prefix Only uploads of keys starting with this.
	 * @param int    $now    The time to measure from. Default now.
	 *
	 * @return array{aborted: array<int, array{key: string, upload_id: string, initiated: string}>, failed: array<int, array{key: string, upload_id: string, initiated: string}>}|WP_Error
	 *         An error when the uploads could not be listed.
	 */
	public function abort_stale( int $days, string $prefix = '', ?int $now = null ): array|WP_Error {
		$uploads = $this->uploads( $prefix );

		if ( $uploads instanceof WP_Error ) {
			return $uploads;
		}

		$before = ( $now ?? time() ) - max( 1, $days ) * 86400;
		$result = array(
			'aborted' => array(),
			'failed'  => array(),
		);

		foreach ( $uploads as $upload ) {
			$started = strtotime( $upload['initiated'] );

			// A time that does not parse is not old enough to throw away.
			if ( false === $started || $started > $before ) {
				continue;
			}

			$result[ $this->abort_upload( $upload['key'], $upload['upload_id'] ) ? 'aborted' : 'failed' ][] = $upload;
		}

		return $result;
	}

	/* ─── Reading ───────────────────────────────────────────────────── */

	/**
	 * What the bucket says about an object now: its ETag, whole length and
	 * last modification.
	 *
	 * A one-byte ranged read of a presigned URL -- the URL is signed for GET,
	 * so a HEAD would not verify -- whose headers carry all three. For telling
	 * whether an object somebody else can write to is still the one you
	 * checked: an overwrite changes the length or the time whatever it does
	 * to the ETag, so compare all three.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Object key.
	 *
	 * @return array{etag: string, size: int, modified: string}|null Null when
	 *         storage would not answer, or did not say all of it: without a
	 *         Content-Range there is no length, and without an ETag nothing to
	 *         compare.
	 */
	public function stat( string $key ): ?array {
		$response = $this->first_byte( $key );

		if ( ! Transport::is_success( $response['status'] ) || 1 !== preg_match( '#/(\d+)$#', Transport::header( $response, 'content-range' ), $total ) ) {
			return null;
		}

		$etag = trim( Transport::header( $response, 'etag' ), "\" \t" );

		if ( '' === $etag ) {
			return null;
		}

		return array(
			'etag'     => $etag,
			'size'     => (int) $total[1],
			'modified' => Transport::header( $response, 'last-modified' ),
		);
	}

	/**
	 * How many bytes the bucket holds for an object.
	 *
	 * A one-byte ranged read, whose Content-Range carries the total.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Object key.
	 *
	 * @return int|null Bytes, or null when storage would not say -- including
	 *                  when it ignored the range for an object bigger than
	 *                  STAT_BYTES, which is cut off rather than read whole.
	 */
	public function size( string $key ): ?int {
		$response = $this->first_byte( $key );

		if ( ! Transport::is_success( $response['status'] ) ) {
			return null;
		}

		if ( 1 === preg_match( '#/(\d+)$#', Transport::header( $response, 'content-range' ), $matches ) ) {
			return (int) $matches[1];
		}

		// A 200 means the range was ignored and the whole object came back --
		// believable only when it was all there was.
		if ( 200 !== $response['status'] ) {
			return null;
		}

		$length = Transport::header( $response, 'content-length' );

		return '' === $length || (int) $length === strlen( $response['body'] ) ? strlen( $response['body'] ) : null;
	}

	/**
	 * Some bytes of an object.
	 *
	 * Never takes in more than it asked for. A backwards or negative range is
	 * refused before any request -- storage answers a range it cannot parse
	 * with the whole object -- and the answer is cut off in transit past the
	 * range length. A server that ignores the range and sends the whole
	 * object is only believed when the whole object fit under that cap.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key  Object key.
	 * @param int    $from First byte.
	 * @param int    $to   Last byte, inclusive.
	 *
	 * @return string|WP_Error The bytes. An error rather than '' when storage
	 *                         did not answer, because an empty read and an
	 *                         empty file look the same afterwards.
	 *
	 * @throws InvalidArgumentException On a range that is not one.
	 */
	public function read( string $key, int $from, int $to ): string|WP_Error {
		if ( $from < 0 || $to < $from || PHP_INT_MAX === $to - $from ) {
			throw new InvalidArgumentException( sprintf( 'Not a byte range: %d-%d.', $from, $to ) );
		}

		$length   = $to - $from + 1;
		$response = Transport::request(
			'GET',
			$this->url( $key, 60 ),
			array( 'Range' => sprintf( 'bytes=%d-%d', $from, $to ) ),
			null,
			array( 'limit_response_size' => $length )
		);

		if ( 206 === $response['status'] ) {
			return $response['body'];
		}

		if ( 200 === $response['status'] ) {
			// The range was ignored. Cut from the whole object only when the
			// whole object is what arrived; otherwise the cap cut it off, and
			// the bytes asked for are not here.
			$whole = Transport::header( $response, 'content-length' );

			if ( 0 === $from || ( '' !== $whole && (int) $whole === strlen( $response['body'] ) ) ) {
				return substr( $response['body'], $from, $length );
			}

			return new WP_Error( 's3_read_failed', 'Storage ignored the byte range and sent the whole object.', array( 'status' => 200 ) );
		}

		return self::refusal( 'read', $response );
	}

	/* ─── Listing ───────────────────────────────────────────────────── */

	/**
	 * Every bucket these credentials can list.
	 *
	 * Asks the service, not a bucket, so it is static: a key scoped to one
	 * bucket is refused (403 AccessDenied) even though it works, and that
	 * refusal is how a caller tells a scoped key from a wrong one.
	 *
	 * @since 1.0.0
	 *
	 * @param Signer $signer A signer for the provider.
	 *
	 * @return array<int, array{name: string, created: string}>|WP_Error
	 *         `s3_buckets_failed` with storage's code in its data.
	 */
	public static function all( Signer $signer ): array|WP_Error {
		$request  = $signer->sign( Method::GET, '' );
		$response = Transport::send( $request, array( 'limit_response_size' => 1048576 ) );

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		if ( ! Transport::is_success( $response['status'] ) ) {
			return self::refusal( 'buckets', $response );
		}

		$document = Xml::load( $response['body'] );

		if ( null === $document ) {
			return self::refusal( 'buckets', $response );
		}

		$buckets = array();

		foreach ( Xml::children( $document, 'Buckets' ) as $list ) {
			foreach ( Xml::children( $list, 'Bucket' ) as $bucket ) {
				$name = Xml::text( $bucket, 'Name' );

				if ( '' !== $name ) {
					$buckets[] = array(
						'name'    => $name,
						'created' => Xml::text( $bucket, 'CreationDate' ),
					);
				}
			}
		}

		return $buckets;
	}

	/**
	 * One page of what is in the bucket.
	 *
	 * ListObjectsV2. With a delimiter, keys below the next one are rolled up
	 * into `prefixes` -- the folders -- so a listing is one level of the
	 * tree; with none, everything under the prefix comes back, a page at a
	 * time, which is what deleting a folder needs.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix    Only keys starting with this.
	 * @param string $delimiter Roll keys up at this character; '' for none.
	 * @param string $token     The previous page's `next_token`, or ''.
	 * @param int    $max_keys  Keys a page, 1 to 1000.
	 *
	 * @return array{objects: array<int, array{key: string, size: int, last_modified: string, etag: string, storage_class: string}>, prefixes: array<int, string>, truncated: bool, next_token: string}|WP_Error
	 *         `s3_list_failed` with storage's code in its data.
	 */
	public function list( string $prefix = '', string $delimiter = '/', string $token = '', int $max_keys = 1000 ): array|WP_Error {
		$request  = $this->signer->sign_list_objects( $this->name, $max_keys, $prefix, $delimiter, $token );
		$response = Transport::send( $request, array( 'limit_response_size' => 8388608 ) );

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$document = Transport::is_success( $response['status'] ) ? Xml::load( $response['body'] ) : null;

		if ( null === $document ) {
			return self::refusal( 'list', $response );
		}

		$objects = array();

		foreach ( Xml::children( $document, 'Contents' ) as $node ) {
			$key = Xml::text( $node, 'Key' );

			if ( '' === $key ) {
				continue;
			}

			$class     = Xml::text( $node, 'StorageClass' );
			$objects[] = array(
				'key'           => $key,
				'size'          => (int) Xml::text( $node, 'Size' ),
				'last_modified' => Xml::text( $node, 'LastModified' ),
				'etag'          => trim( Xml::text( $node, 'ETag' ), '"' ),
				'storage_class' => '' === $class ? 'STANDARD' : $class,
			);
		}

		$prefixes = array();

		foreach ( Xml::children( $document, 'CommonPrefixes' ) as $node ) {
			$folder = Xml::text( $node, 'Prefix' );

			if ( '' !== $folder ) {
				$prefixes[] = $folder;
			}
		}

		$truncated = 'true' === strtolower( Xml::text( $document, 'IsTruncated' ) );

		return array(
			'objects'    => $objects,
			'prefixes'   => $prefixes,
			'truncated'  => $truncated,
			'next_token' => $truncated ? Xml::text( $document, 'NextContinuationToken' ) : '',
		);
	}

	/* ─── Links ─────────────────────────────────────────────────────── */

	/**
	 * A presigned URL to download an object.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key      Object key.
	 * @param int    $ttl      Seconds it works for.
	 * @param string $filename What the browser should save it as, or ''.
	 *
	 * @return string
	 */
	public function url( string $key, int $ttl = 300, string $filename = '' ): string {
		return $this->signer->presign_get( $this->name, $key, $ttl, $filename );
	}

	/**
	 * A presigned URL that plays or shows in the browser, rather than
	 * downloading -- for an `<audio>` or `<video>` element.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key          Object key.
	 * @param int    $ttl          Seconds it works for.
	 * @param string $content_type What the browser should treat it as.
	 *
	 * @return string
	 */
	public function stream_url( string $key, int $ttl, string $content_type ): string {
		return $this->signer->presign_inline( $this->name, $key, $ttl, $content_type );
	}

	/**
	 * A URL that shows an object in the browser and stays the same for a
	 * window, so a page shown twice in that window is fetched once.
	 *
	 * For thumbnails and previews somebody may look at again and again: every
	 * fetch from storage is a billable read.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key          Object key.
	 * @param string $content_type What the browser should treat it as.
	 * @param int    $window       Seconds the URL stays the same. Default an hour.
	 *
	 * @return string
	 */
	public function view_url( string $key, string $content_type, int $window = 3600 ): string {
		return $this->signer->presign_cacheable( $this->name, $key, $window, $content_type );
	}

	/**
	 * Where this bucket's URLs point, for a Content-Security-Policy.
	 *
	 * @since 1.0.0
	 *
	 * @return string e.g. `https://acct.r2.cloudflarestorage.com`.
	 */
	public function origin(): string {
		return $this->signer->origin( $this->name );
	}

	/* ─── Changing ──────────────────────────────────────────────────── */

	/**
	 * Copy an object from another bucket into this one, inside storage.
	 *
	 * Nothing passes through PHP, whatever the size. Both buckets must be
	 * reachable with these credentials. The copy gets its own type and
	 * caching, which is the point of copying something private into a public
	 * bucket.
	 *
	 * @since 1.0.0
	 *
	 * @param Bucket $source        Where it is now.
	 * @param string $source_key    Its key there.
	 * @param string $key           Its key here.
	 * @param string $content_type  Content-Type for the copy.
	 * @param string $cache_control Cache-Control for the copy, or ''.
	 *
	 * @return true|WP_Error
	 */
	public function copy_from( Bucket $source, string $source_key, string $key, string $content_type, string $cache_control = '' ): true|WP_Error {
		$request  = $this->signer->sign_copy_object( $source->name, $source_key, $this->name, $key, $content_type, $cache_control );
		$response = Transport::request( 'PUT', $request->url, $request->headers, '' );

		// A copy, like a multipart completion, can fail inside a 200.
		return self::confirmed( $response ) ? true : self::refusal( 'copy', $response );
	}

	/**
	 * Delete an object.
	 *
	 * S3 answers 204 whether or not the key existed, so success is not proof
	 * the object was there -- only that it is not there now.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Object key.
	 *
	 * @return true|WP_Error
	 */
	public function delete( string $key ): true|WP_Error {
		$request  = $this->signer->sign_delete_object( $this->name, $key );
		$response = Transport::request( 'DELETE', $request->url, $request->headers );

		return Transport::is_success( $response['status'] ) ? true : self::refusal( 'delete', $response );
	}

	/**
	 * Copy an object to another key in this bucket, keeping its type and
	 * metadata. Half of a rename; the other half is delete().
	 *
	 * @since 1.0.0
	 *
	 * @param string $from The key it has.
	 * @param string $to   The key the copy gets.
	 *
	 * @return true|WP_Error A copy refused inside a 200 is refused.
	 *
	 * @throws InvalidArgumentException When either key has a "." or ".." segment.
	 */
	public function copy( string $from, string $to ): true|WP_Error {
		if ( ! Validate::segments( $from ) ) {
			throw new InvalidArgumentException( 'An S3 key cannot contain a "." or ".." path segment.' );
		}

		$request  = $this->signer->sign(
			Method::PUT,
			$this->name,
			$to,
			array(),
			'',
			// Signed, as every x-amz-* header must be: AWS refuses one added
			// afterwards. Each segment encoded, the slashes kept.
			array( 'x-amz-copy-source' => '/' . rawurlencode( $this->name ) . '/' . Endpoint::encode_key( $from ) )
		);
		$response = Transport::request( 'PUT', $request->url, $request->headers, '' );

		return self::confirmed( $response ) ? true : self::refusal( 'copy', $response );
	}

	/**
	 * Delete many objects, a thousand to a request.
	 *
	 * DeleteObjects answers 200 and lists, key by key, what it deleted and
	 * what it would not. A provider that does not implement it at all --
	 * some S3-compatible stores do not -- refuses the whole request, and
	 * that comes back as a WP_Error so the caller can fall back to delete()
	 * one key at a time.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $keys Object keys.
	 *
	 * @return array{deleted: array<int, string>, errors: array<int, array{key: string, code: string, message: string}>}|WP_Error
	 *         `s3_delete_many_failed` when a request was refused whole.
	 */
	public function delete_many( array $keys ): array|WP_Error {
		$result = array(
			'deleted' => array(),
			'errors'  => array(),
		);

		foreach ( array_chunk( array_values( array_unique( array_map( 'strval', $keys ) ) ), 1000 ) as $chunk ) {
			$body = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<Delete><Quiet>false</Quiet>';

			foreach ( $chunk as $key ) {
				$body .= '<Object><Key>' . htmlspecialchars( $key, ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</Key></Object>';
			}

			$body    .= '</Delete>';
			$request  = $this->signer->sign(
				Method::POST,
				$this->name,
				'',
				array( 'delete' => '' ),
				$body,
				array(
					'content-type' => 'application/xml',
					'content-md5'  => base64_encode( (string) hash( 'md5', $body, true ) ),
				)
			);
			$response = Transport::send( $request, array( 'timeout' => 60 ) );

			if ( $response instanceof WP_Error ) {
				return $response;
			}

			$document = Transport::is_success( $response['status'] ) ? Xml::load( $response['body'] ) : null;

			if ( null === $document || 'Error' === $document->getName() ) {
				return self::refusal( 'delete_many', $response );
			}

			foreach ( Xml::children( $document, 'Deleted' ) as $node ) {
				$result['deleted'][] = Xml::text( $node, 'Key' );
			}

			foreach ( Xml::children( $document, 'Error' ) as $node ) {
				$result['errors'][] = array(
					'key'     => Xml::text( $node, 'Key' ),
					'code'    => Xml::text( $node, 'Code' ),
					'message' => Xml::text( $node, 'Message' ),
				);
			}
		}

		return $result;
	}

	/* ─── The bucket itself ─────────────────────────────────────────── */

	/**
	 * Whether the bucket answers these credentials at all.
	 *
	 * A one-key listing: the cheapest call that proves the endpoint, the
	 * bucket name and the keys are all right, for a health check.
	 *
	 * @since 1.0.0
	 *
	 * @return array{ok: bool, error: string, status?: int, code?: string, reason?: string}
	 *         A refusal also carries its status and storage's code and words.
	 */
	public function reachable(): array {
		$request  = $this->signer->sign_list_objects( $this->name, 1 );
		$response = Transport::request( 'GET', $request->url, $request->headers, null, array( 'limit_response_size' => 65536 ) );

		return Transport::is_success( $response['status'] )
			? array(
				'ok'    => true,
				'error' => '',
			)
			: self::failed( $response );
	}

	/**
	 * The bucket's CORS policy.
	 *
	 * @since 1.0.0
	 *
	 * @return array{ok: bool, policy: Policy|null, error: string, status?: int, code?: string, reason?: string} A bucket
	 *         with no configuration is ok, with an empty policy.
	 */
	public function cors(): array {
		$request  = $this->signer->sign_get_bucket_cors( $this->name );
		$response = Transport::request( 'GET', $request->url, $request->headers, null, array( 'limit_response_size' => 262144 ) );

		// No configuration at all answers 404 -- an answer, not a failure.
		if ( 404 === $response['status'] && ! str_contains( $response['body'], 'NoSuchBucket' ) ) {
			return array(
				'ok'     => true,
				'policy' => new Policy(),
				'error'  => '',
			);
		}

		if ( 200 !== $response['status'] ) {
			return array( 'policy' => null ) + self::failed( $response );
		}

		try {
			return array(
				'ok'     => true,
				'policy' => Policy::from_xml( $response['body'] ),
				'error'  => '',
			);
		} catch ( \RuntimeException | InvalidArgumentException $e ) {
			return array(
				'ok'     => false,
				'policy' => null,
				'error'  => $e->getMessage(),
			);
		}
	}

	/**
	 * Replace the bucket's CORS policy. There is no way to add one rule.
	 *
	 * Setting it needs a key allowed to: one scoped to object read and write
	 * cannot call PutBucketCors, and the AccessDenied it gets reads like a
	 * bucket problem rather than a key one.
	 *
	 * @since 1.0.0
	 *
	 * @param Policy $policy The policy to store.
	 *
	 * @return array{ok: bool, error: string, status?: int, code?: string, reason?: string}
	 *         A refusal also carries its status and storage's code and words.
	 */
	public function set_cors( Policy $policy ): array {
		$request  = $this->signer->sign_put_bucket_cors( $this->name, $policy );
		$response = Transport::request( 'PUT', $request->url, $request->headers, $request->body );

		return Transport::is_success( $response['status'] )
			? array(
				'ok'    => true,
				'error' => '',
			)
			: self::failed( $response );
	}

	/**
	 * Remove the bucket's CORS policy altogether.
	 *
	 * @since 1.0.0
	 *
	 * @return array{ok: bool, error: string, status?: int, code?: string, reason?: string}
	 *         A refusal also carries its status and storage's code and words.
	 */
	public function delete_cors(): array {
		$request  = $this->signer->sign_delete_bucket_cors( $this->name );
		$response = Transport::request( 'DELETE', $request->url, $request->headers );

		return Transport::is_success( $response['status'] )
			? array(
				'ok'    => true,
				'error' => '',
			)
			: self::failed( $response );
	}

	/**
	 * Where the bucket is, as the provider names it.
	 *
	 * GetBucketLocation. S3 answers an empty LocationConstraint for
	 * us-east-1 rather than naming it, so an empty one is that region.
	 *
	 * @since 1.0.0
	 *
	 * @return string|WP_Error `s3_location_failed` with storage's code in its data.
	 */
	public function region(): string|WP_Error {
		$request  = $this->signer->sign( Method::GET, $this->name, '', array( 'location' => '' ) );
		$response = Transport::send( $request, array( 'limit_response_size' => 65536 ) );

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$document = Transport::is_success( $response['status'] ) ? Xml::load( $response['body'] ) : null;

		if ( null === $document ) {
			return self::refusal( 'location', $response );
		}

		$region = trim( (string) $document );

		return '' === $region ? 'us-east-1' : $region;
	}

	/* ─── Internals ─────────────────────────────────────────────────── */

	/**
	 * One-byte ranged read, capped, for stat() and size().
	 *
	 * @param string $key Object key.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string, error: string}
	 */
	private function first_byte( string $key ): array {
		return Transport::request(
			'GET',
			$this->url( $key, 60 ),
			array( 'Range' => 'bytes=0-0' ),
			null,
			array( 'limit_response_size' => self::STAT_BYTES )
		);
	}

	/**
	 * One PUT of part or all of a file, to a presigned address.
	 *
	 * @param string $url       The presigned address.
	 * @param string $path      The file.
	 * @param int    $length    How many bytes.
	 * @param int    $offset    From where.
	 * @param array  $args      The upload's arguments.
	 * @param string $mime_type Content-Type to send, for a whole object; ''
	 *                          for a part, which has none of its own.
	 *
	 * @return string|WP_Error The part's ETag.
	 */
	private function put_part( string $url, string $path, int $length, int $offset, array $args, string $mime_type = '' ): string|WP_Error {
		// One part's worth, never more: this is the only place the bytes are
		// in memory, and the part size is what bounds it.
		$body = (string) file_get_contents( $path, false, null, $offset, max( 0, $length ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a byte range of a local file.

		// Content-Type is not among a presigned URL's signed headers, so it
		// may be sent alongside, and storage keeps it. So may Content-MD5,
		// which storage checks the bytes against and refuses (BadDigest)
		// when they were changed on the way.
		$headers = array( 'content-md5' => self::md5( $body ) );

		if ( '' !== $mime_type ) {
			$headers['content-type'] = $mime_type;
		}

		$response = Transport::send(
			new SignedRequest( Method::PUT, $url, $headers, $body ),
			array( 'timeout' => (int) $args['timeout'] )
		);

		unset( $body );

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$etag = array_change_key_case( $response['headers'], CASE_LOWER )['etag'] ?? '';
		$etag = is_scalar( $etag ) ? (string) $etag : '';

		// A part without an ETag cannot be assembled, so it counts as refused.
		if ( ! Transport::is_success( $response['status'] ) || ( '' === $mime_type && '' === trim( $etag, '" ' ) ) ) {
			return new WP_Error( $args['error_code'], sprintf( $args['messages']['refused'], $response['status'] ) );
		}

		return $etag;
	}

	/**
	 * A multipart upload from disk: start it, send each part, put them
	 * together. Abandoned at the provider if any of that fails.
	 *
	 * @param string $key  The object's key.
	 * @param string $path The file.
	 * @param int    $size Its size in bytes.
	 * @param int    $part Bytes a part.
	 * @param array  $args The upload's arguments.
	 *
	 * @return true|WP_Error
	 */
	private function in_parts( string $key, string $path, int $size, int $part, array $args ): true|WP_Error {
		$started = Transport::send( $this->signer->sign_create_multipart_upload( $this->name, $key, (string) $args['mime_type'] ), array( 'timeout' => (int) $args['timeout'] ) );

		if ( $started instanceof WP_Error ) {
			return $started;
		}

		$upload = Transport::is_success( $started['status'] ) ? self::field( $started['body'], 'UploadId' ) : '';

		if ( '' === $upload ) {
			return new WP_Error( $args['error_code'], $args['messages']['not_started'] );
		}

		$parts = array();

		for ( $number = 1, $offset = 0; $offset < $size; $number++, $offset += $part ) {
			$etag = $this->put_part(
				$this->signer->presign_upload_part( $this->name, $key, $upload, $number ),
				$path,
				min( $part, $size - $offset ),
				$offset,
				$args
			);

			if ( $etag instanceof WP_Error ) {
				$this->abort_upload( $key, $upload );

				return $etag;
			}

			$parts[] = array(
				'PartNumber' => $number,
				'ETag'       => $etag,
			);
		}

		$request = $this->signer->sign_complete_multipart_upload( $this->name, $key, $upload, $parts );
		$done    = Transport::send( $request, array( 'timeout' => (int) $args['timeout'] ) );

		if ( $done instanceof WP_Error || ! self::confirmed( $done ) ) {
			$this->abort_upload( $key, $upload );

			return $done instanceof WP_Error ? $done : new WP_Error( $args['error_code'], $args['messages']['not_finished'] );
		}

		return true;
	}

	/**
	 * The Content-MD5 of some bytes: their MD5, base64 encoded.
	 *
	 * @param string $bytes The bytes.
	 *
	 * @return string
	 */
	private static function md5( string $bytes ): string {
		return base64_encode( (string) hash( 'md5', $bytes, true ) );
	}

	/**
	 * Whether an answer is a success all the way through: a 2xx that is not
	 * an error document. CompleteMultipartUpload and CopyObject both answer
	 * 200 and then report a failure in the body.
	 *
	 * @param array{status: int, body: string} $response An answer.
	 *
	 * @return bool
	 */
	private static function confirmed( array $response ): bool {
		return Transport::is_success( $response['status'] ) && ! str_contains( $response['body'], '<Error>' );
	}

	/**
	 * A refusal as a WP_Error, with storage's own code in its data.
	 *
	 * @param string $operation What was being done.
	 * @param array  $response  What Transport::request() returned.
	 *
	 * @return WP_Error
	 */
	private static function refusal( string $operation, array $response ): WP_Error {
		$body = '' !== ( $response['body'] ?? '' ) ? (string) $response['body'] : (string) ( $response['error'] ?? '' );

		return StorageError::refused( $operation, (int) $response['status'], $body )->to_wp_error();
	}

	/**
	 * A refusal, for the calls that answer with `ok` rather than a WP_Error:
	 * the line, the status, and storage's own code and words for it.
	 *
	 * @param array $response What Transport::request() returned.
	 *
	 * @return array{ok: false, error: string, status: int, code: string, reason: string}
	 */
	private static function failed( array $response ): array {
		$refusal = StorageError::refused( 'request', (int) $response['status'], '' !== $response['body'] ? $response['body'] : $response['error'] );

		return array(
			'ok'     => false,
			'error'  => self::describe( $response ),
			'status' => $refusal->status,
			'code'   => $refusal->error_code,
			'reason' => $refusal->reason,
		);
	}

	/**
	 * A refusal, as one line.
	 *
	 * @param array $response What Transport::request() returned.
	 *
	 * @return string
	 */
	private static function describe( array $response ): string {
		$body = '' !== $response['body'] ? $response['body'] : $response['error'];

		return 'HTTP ' . (int) $response['status'] . ': ' . substr( trim( strip_tags( (string) $body ) ), 0, 160 );
	}

	/**
	 * The text of the first element with this name, or ''.
	 *
	 * @param string $xml  Document or fragment.
	 * @param string $name Element name.
	 *
	 * @return string
	 */
	private static function field( string $xml, string $name ): string {
		return 1 === preg_match( '#<' . $name . '>([^<]*)</' . $name . '>#', $xml, $match ) ? $match[1] : '';
	}

	/**
	 * Arguments merged over defaults, with the wording merged a level down.
	 *
	 * @param array $args     What the caller passed.
	 * @param array $defaults What this call defaults to.
	 *
	 * @return array
	 */
	private static function args( array $args, array $defaults ): array {
		$merged             = array_merge( $defaults, $args );
		$merged['messages'] = array_merge( self::MESSAGES, (array) ( $args['messages'] ?? array() ) );

		return $merged;
	}
}
