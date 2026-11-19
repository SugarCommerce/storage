<?php
/**
 * Resumable multipart uploads, for files too large for one request.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\S3;

use Closure;
use SugarCommerce\Storage\Utils\Runtime;
use Throwable;
use WP_Error;

/**
 * Class Multipart
 *
 * One PUT carries at most 5 GB. A larger file -- a sample library, a video
 * course -- has to go as S3's multipart upload: started once, sent in up to
 * ten thousand parts, then assembled. Bucket has each of those calls. This
 * is what makes them survive the request they started in, which a
 * many-gigabyte upload never fits inside:
 *
 *   - The upload is remembered (an option per upload, not autoloaded): its
 *     key, storage's upload id, and how the file is cut into parts.
 *   - Parts are sent from this server a few at a time, inside a time limit
 *     (send(), run()), or by a browser to URLs signed here (sign()).
 *   - Every resume and every finish asks storage which parts it really kept
 *     (ListParts), because a request that died mid-part may or may not have
 *     delivered it, and a browser's memory is a hint.
 *   - Finishing checks that every part is there at its proper size before
 *     asking storage to assemble them, then that the object is as long as
 *     the file.
 *   - Abandoned uploads are found and aborted (prune()), since their parts
 *     are billed and nothing lists them.
 *
 * Each part carries a checksum storage verifies: from this server a SHA-256
 * signature over the bytes and a Content-MD5, from a browser a Content-MD5.
 *
 *     $multipart = new Multipart( $bucket );
 *     $upload    = $multipart->begin( 'packs/Library.zip', filesize( $path ), [ 'source' => $path ] );
 *
 *     // In a job, as often as it takes:
 *     $result = $multipart->run( $upload->id, 20 ); // Upload: more to send; string: the s3:// address
 *
 * @since 1.0.0
 */
final class Multipart {

	/**
	 * The smallest part a new upload uses (16 MiB). Raised for a file that
	 * would otherwise need more than Bucket::PART_LIMIT parts.
	 *
	 * @since 1.0.0
	 */
	public const PART_SIZE = 16 * 1048576;

	/**
	 * Option name prefix of a stored upload, after the build's own prefix.
	 */
	private const OPTION = 'upload_';

	/**
	 * Seconds a send() holds an upload against a second one, past its own
	 * time limit: long enough for one more part on a slow line, short enough
	 * that a request killed mid-part does not hold it for long.
	 */
	private const LOCK_GRACE = 600;

	/**
	 * What the clock reads, in seconds, for keeping inside a time limit.
	 *
	 * @var Closure(): float
	 */
	private Closure $clock;

	/**
	 * Uploads to one bucket.
	 *
	 * @since 1.0.0
	 *
	 * @param Bucket       $bucket The bucket.
	 * @param Closure|null $clock  Seconds on a monotonic clock. Default
	 *                             hrtime(); a test passes its own.
	 */
	public function __construct(
		private readonly Bucket $bucket,
		?Closure $clock = null
	) {
		$this->clock = $clock ?? static fn(): float => hrtime( true ) / 1e9;
	}

	/**
	 * The bucket it uploads to.
	 *
	 * @since 1.0.0
	 *
	 * @return Bucket
	 */
	public function bucket(): Bucket {
		return $this->bucket;
	}

	/* ─── Starting and finding ──────────────────────────────────────── */

	/**
	 * Start an upload and remember it.
	 *
	 * Arguments:
	 *
	 *   - `mime_type`   The finished object's type (default
	 *                   `application/octet-stream`).
	 *   - `part_size`   The smallest part to use (default PART_SIZE, never
	 *                   below Bucket::MIN_PART_SIZE). Raised by
	 *                   Bucket::part_size() so a 5 TB file still fits.
	 *   - `source`      A file on this server to send from, for send() and
	 *                   run(). It must be `$size` bytes.
	 *   - `fingerprint` What identifies the file to its sender, so the same
	 *                   file offered again resumes (match()). For a `source`
	 *                   it defaults to its size and modified time, and a file
	 *                   that changes after that is refused rather than sent
	 *                   half old and half new.
	 *   - `owner`       The user starting it (default 0).
	 *
	 * @since 1.0.0
	 *
	 * @param string               $key  Object key.
	 * @param int                  $size Bytes in the file.
	 * @param array<string, mixed> $args See above.
	 *
	 * @return Upload|WP_Error `s3_upload_size` for a size storage cannot take,
	 *                         `s3_upload_source` for a source that is not the
	 *                         file described, `s3_upload_key` for a key with
	 *                         a `.` or `..` segment, or storage's refusal.
	 */
	public function begin( string $key, int $size, array $args = array() ): Upload|WP_Error {
		$args = array_merge(
			array(
				'mime_type'   => 'application/octet-stream',
				'part_size'   => self::PART_SIZE,
				'source'      => '',
				'fingerprint' => '',
				'owner'       => 0,
			),
			$args
		);

		if ( $size < 1 || $size > Bucket::MAX_OBJECT_SIZE ) {
			return new WP_Error(
				's3_upload_size',
				$size < 1
					? __( 'An empty file cannot be sent in parts; upload it in one request.', 'sugarcommerce' )
					: __( 'The file is larger than the 5 TB storage keeps in one object.', 'sugarcommerce' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $key || ! Validate::segments( $key ) ) {
			return new WP_Error( 's3_upload_key', __( 'That file name cannot be stored: a folder in it is "." or "..".', 'sugarcommerce' ), array( 'status' => 400 ) );
		}

		$source      = (string) $args['source'];
		$fingerprint = (string) $args['fingerprint'];

		if ( '' !== $source ) {
			if ( ! is_file( $source ) || ! is_readable( $source ) || filesize( $source ) !== $size ) {
				return new WP_Error( 's3_upload_source', __( 'The file to upload cannot be read, or is not the size given.', 'sugarcommerce' ) );
			}

			$fingerprint = '' === $fingerprint ? self::fingerprint( $source ) : $fingerprint;
		}

		$upload_id = $this->bucket->start_upload( $key, (string) $args['mime_type'] );

		if ( $upload_id instanceof WP_Error ) {
			return $upload_id;
		}

		$now    = time();
		$upload = new Upload(
			bin2hex( random_bytes( 16 ) ),
			$this->bucket->name(),
			$key,
			$upload_id,
			$size,
			Bucket::part_size( $size, (int) $args['part_size'] ),
			(string) $args['mime_type'],
			$source,
			substr( $fingerprint, 0, 255 ),
			(int) $args['owner'],
			$now,
			array(),
			$now
		);

		$this->save( $upload );

		return $upload;
	}

	/**
	 * A stored upload to this bucket, by its id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id What begin() gave it.
	 *
	 * @return Upload|null Null when there is none -- finished, aborted,
	 *                     pruned, never begun, or begun for another bucket.
	 */
	public function find( string $id ): ?Upload {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			return null;
		}

		$upload = Upload::from_array( get_option( self::option( $id ) ) );

		return null !== $upload && $upload->id === $id && $upload->bucket === $this->bucket->name() ? $upload : null;
	}

	/**
	 * Every stored upload to this bucket, oldest first.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, Upload>
	 */
	public function pending(): array {
		global $wpdb;

		// Finding options by name has no API; nothing is cached, because the
		// answer changes with every part.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names   = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::option( '' ) ) . '%' ) );
		$uploads = array();

		foreach ( $names as $name ) {
			$upload = $this->find( substr( (string) $name, strlen( self::option( '' ) ) ) );

			if ( null !== $upload ) {
				$uploads[] = $upload;
			}
		}

		usort( $uploads, static fn( Upload $a, Upload $b ): int => $a->started <=> $b->started );

		return $uploads;
	}

	/**
	 * The unfinished upload of this same file, if there is one, to resume.
	 *
	 * Same key, same size, same fingerprint and the same owner: a file
	 * dropped into the browser again after a reload, or the same file handed
	 * to a job twice. An empty fingerprint never matches, since then there
	 * is nothing to say it is the same file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key         Object key.
	 * @param int    $size        Bytes in the file.
	 * @param string $fingerprint What identifies the file to its sender.
	 * @param int    $owner       The user asking.
	 *
	 * @return Upload|null The newest match.
	 */
	public function match( string $key, int $size, string $fingerprint, int $owner = 0 ): ?Upload {
		if ( '' === $fingerprint ) {
			return null;
		}

		$found = null;

		foreach ( $this->pending() as $upload ) {
			if ( $upload->key === $key && $upload->size === $size && $upload->fingerprint === substr( $fingerprint, 0, 255 ) && $upload->owner === $owner ) {
				$found = $upload;
			}
		}

		return $found;
	}

	/* ─── Sending ───────────────────────────────────────────────────── */

	/**
	 * Presigned URLs a browser PUTs parts to.
	 *
	 * The browser may send a Content-MD5 header with each, which is not
	 * signed and which storage checks the part against.
	 *
	 * @since 1.0.0
	 *
	 * @param Upload          $upload  The upload.
	 * @param array<int, int> $numbers Part numbers.
	 * @param int             $expires Seconds each URL works for.
	 *
	 * @return array<int, string> Part number => URL. Numbers that are not
	 *                            this upload's parts are left out.
	 */
	public function sign( Upload $upload, array $numbers, int $expires = 3600 ): array {
		$count = $upload->part_count();
		$wanted = array_filter( array_map( 'intval', $numbers ), static fn( int $number ): bool => $number >= 1 && $number <= $count );

		return $this->bucket->sign_parts( $upload->key, $upload->upload_id, array_values( array_unique( $wanted ) ), $expires );
	}

	/**
	 * The parts storage holds for an upload, at their proper size.
	 *
	 * ListParts, every page. A part storage holds at another size than this
	 * upload cuts -- left from a sender that cut differently -- is not
	 * counted, so it is sent again.
	 *
	 * @since 1.0.0
	 *
	 * @param Upload $upload The upload.
	 *
	 * @return array<int, array{etag: string, size: int}>|WP_Error Part number
	 *         => its ETag and size. `s3_upload_gone` when storage no longer
	 *         has the upload: finished, aborted or expired.
	 */
	public function stored( Upload $upload ): array|WP_Error {
		$listed = $this->bucket->parts( $upload->key, $upload->upload_id );

		if ( null === $listed ) {
			return new WP_Error( 's3_upload_gone', __( 'Storage no longer has this upload. It was finished, cancelled or expired; start it again.', 'sugarcommerce' ), array( 'status' => 410 ) );
		}

		$parts = array();

		foreach ( $listed as $part ) {
			if ( $part['Size'] === $upload->part_length( $part['PartNumber'] ) ) {
				$parts[ $part['PartNumber'] ] = array(
					'etag' => $part['ETag'],
					'size' => $part['Size'],
				);
			}
		}

		return $parts;
	}

	/**
	 * Bring this side's record into line with what storage holds.
	 *
	 * For carrying on after an interruption: a part this side recorded but
	 * storage does not have is sent again, and one storage has that this
	 * side never heard back about is not.
	 *
	 * @since 1.0.0
	 *
	 * @param Upload $upload The upload.
	 *
	 * @return Upload|WP_Error The same upload, its parts storage's. When
	 *                         storage no longer has it, it is forgotten here
	 *                         too.
	 */
	public function resume( Upload $upload ): Upload|WP_Error {
		$stored = $this->stored( $upload );

		if ( $stored instanceof WP_Error ) {
			$this->forget( $upload );

			return $stored;
		}

		$upload->parts   = array_map( static fn( array $part ): string => $part['etag'], $stored );
		$upload->updated = time();

		$this->save( $upload );

		return $upload;
	}

	/**
	 * Send the parts that are missing from the file on this server, until
	 * they are all sent or the time is up.
	 *
	 * Starts by asking storage what it holds, so it carries on where any
	 * earlier run stopped, however that run ended. Each part is remembered
	 * as soon as storage takes it. At least one part is sent each call, and
	 * no new part is started that would not finish inside the time at the
	 * pace of the last. One run at a time: a second, while one is going,
	 * is turned away.
	 *
	 * @since 1.0.0
	 *
	 * @param Upload $upload  The upload, begun with a `source`.
	 * @param int    $seconds Time to spend. 0 sends everything left.
	 * @param int    $timeout Seconds to allow one part. Default 300.
	 *
	 * @return Upload|WP_Error The upload, with what was sent. An error for
	 *                         a part refused or not answered -- what was sent
	 *                         before it is kept, so calling again carries on
	 *                         -- or `s3_upload_busy`, `s3_upload_source` and
	 *                         `s3_upload_gone`.
	 */
	public function send( Upload $upload, int $seconds = 20, int $timeout = 300 ): Upload|WP_Error {
		if ( '' === $upload->source ) {
			return new WP_Error( 's3_upload_source', __( 'This upload is sent by a browser, not from this server.', 'sugarcommerce' ) );
		}

		clearstatcache( true, $upload->source );

		// A file replaced or changed since the upload began would go up half
		// one and half the other.
		if ( ! is_file( $upload->source ) || ! is_readable( $upload->source ) || filesize( $upload->source ) !== $upload->size || ( '' !== $upload->fingerprint && self::fingerprint( $upload->source ) !== $upload->fingerprint ) ) {
			return new WP_Error( 's3_upload_source', __( 'The file being uploaded has changed or gone since the upload began.', 'sugarcommerce' ) );
		}

		if ( ! $this->lock( $upload, $seconds ) ) {
			return new WP_Error( 's3_upload_busy', __( 'This upload is already being sent.', 'sugarcommerce' ), array( 'status' => 409 ) );
		}

		try {
			return $this->send_parts( $upload, $seconds, $timeout );
		} finally {
			$this->unlock( $upload );
		}
	}

	/**
	 * Assemble the upload, once every part is there.
	 *
	 * Storage's own list of parts is what is checked and what is sent: each
	 * part must be there, at its proper size, before assembly is asked for,
	 * and the object must then be as long as the file. A finish that was
	 * asked for before but not heard back from is recognised by the object
	 * being there, whole, and the upload gone.
	 *
	 * Nothing is aborted on a refusal: the parts stay, so a finish refused
	 * for a moment can be asked again. abort() is for giving up.
	 *
	 * @since 1.0.0
	 *
	 * @param Upload $upload The upload.
	 *
	 * @return string|WP_Error The object's `s3://bucket/key` address.
	 *                         `s3_upload_incomplete` (with `missing` part
	 *                         numbers in its data) while parts are still to
	 *                         send, `s3_upload_gone`, `s3_upload_size` when
	 *                         the assembled object is not the file's length,
	 *                         or storage's refusal.
	 */
	public function finish( Upload $upload ): string|WP_Error {
		$stored = $this->stored( $upload );

		if ( $stored instanceof WP_Error ) {
			// Finished already, by an earlier call whose answer was lost?
			if ( $this->bucket->size( $upload->key ) === $upload->size ) {
				$this->forget( $upload );

				return Address::to( $upload->bucket, $upload->key );
			}

			$this->forget( $upload );

			return $stored;
		}

		$missing = array_values( array_diff( range( 1, $upload->part_count() ), array_keys( $stored ) ) );

		if ( array() !== $missing ) {
			$upload->parts = array_map( static fn( array $part ): string => $part['etag'], $stored );
			$this->save( $upload );

			return new WP_Error(
				's3_upload_incomplete',
				sprintf(
					/* translators: 1: parts still to send, 2: parts in the upload. */
					_n( '%1$d part of %2$d has not arrived yet.', '%1$d parts of %2$d have not arrived yet.', count( $missing ), 'sugarcommerce' ),
					count( $missing ),
					$upload->part_count()
				),
				array(
					'status'  => 409,
					'missing' => $missing,
				)
			);
		}

		$parts = array();

		foreach ( $stored as $number => $part ) {
			$parts[] = array(
				'PartNumber' => $number,
				'ETag'       => $part['etag'],
			);
		}

		$done = $this->bucket->finish_upload( $upload->key, $upload->upload_id, $parts );

		if ( $done instanceof WP_Error ) {
			return $done;
		}

		$this->forget( $upload );

		$size = $this->bucket->size( $upload->key );

		if ( null !== $size && $size !== $upload->size ) {
			return new WP_Error(
				's3_upload_size',
				sprintf(
					/* translators: 1: bytes stored, 2: bytes in the file. */
					__( 'Storage assembled %1$s bytes, but the file is %2$s bytes.', 'sugarcommerce' ),
					number_format_i18n( $size ),
					number_format_i18n( $upload->size )
				),
				array(
					'status' => 502,
					'stored' => $size,
				)
			);
		}

		return Address::to( $upload->bucket, $upload->key );
	}

	/**
	 * Send what is left and finish, as one step of a job.
	 *
	 * What a job calls until the answer is not an Upload: send() for as long
	 * as it is given, then finish() once every part is there.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id      The upload's id.
	 * @param int    $seconds Time to spend sending.
	 *
	 * @return Upload|string|WP_Error The upload when there is more to send,
	 *                                its address once finished, or why not.
	 *                                `s3_upload_unknown` for an id with no
	 *                                upload.
	 */
	public function run( string $id, int $seconds = 20 ): Upload|string|WP_Error {
		$upload = $this->find( $id );

		if ( null === $upload ) {
			return new WP_Error( 's3_upload_unknown', __( 'There is no such upload.', 'sugarcommerce' ), array( 'status' => 404 ) );
		}

		$sent = $this->send( $upload, $seconds );

		if ( $sent instanceof WP_Error || ! $sent->done() ) {
			return $sent;
		}

		return $this->finish( $sent );
	}

	/**
	 * Give an upload up: storage frees its parts and it is forgotten here.
	 *
	 * @since 1.0.0
	 *
	 * @param Upload $upload The upload.
	 *
	 * @return bool False when storage still holds it -- its parts still
	 *              billed -- and it is still remembered, to try again.
	 */
	public function abort( Upload $upload ): bool {
		if ( $this->bucket->abort_upload( $upload->key, $upload->upload_id ) || null === $this->bucket->parts( $upload->key, $upload->upload_id ) ) {
			$this->forget( $upload );

			return true;
		}

		return false;
	}

	/**
	 * Clear away abandoned uploads.
	 *
	 * Aborts every unfinished upload in the bucket started more than `$days`
	 * ago -- whoever started it, since storage cannot say -- and forgets
	 * each upload remembered here that was aborted or has not moved in that
	 * long. Run it daily: R2 does the first half by itself after seven days,
	 * most other providers only with a lifecycle rule.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $days Days an upload may sit unfinished. At least 1.
	 * @param int|null $now  The time to measure from. Default now.
	 *
	 * @return array{aborted: int, failed: int, forgotten: int}|WP_Error An
	 *         error when the bucket's uploads could not be listed; nothing
	 *         is forgotten then.
	 */
	public function prune( int $days = 7, ?int $now = null ): array|WP_Error {
		$now   = $now ?? time();
		$stale = $this->bucket->abort_stale( $days, '', $now );

		if ( $stale instanceof WP_Error ) {
			return $stale;
		}

		// By key, then asking storage, not by upload id: R2 lists an upload
		// under a different id from the one it gave when the upload began.
		$aborted   = array_column( $stale['aborted'], 'key' );
		$before    = $now - max( 1, $days ) * 86400;
		$forgotten = 0;

		foreach ( $this->pending() as $upload ) {
			$gone = in_array( $upload->key, $aborted, true ) && null === $this->bucket->parts( $upload->key, $upload->upload_id );

			if ( $gone || max( $upload->started, $upload->updated ) < $before ) {
				$this->forget( $upload );
				++$forgotten;
			}
		}

		return array(
			'aborted'   => count( $stale['aborted'] ),
			'failed'    => count( $stale['failed'] ),
			'forgotten' => $forgotten,
		);
	}

	/* ─── Internals ─────────────────────────────────────────────────── */

	/**
	 * The sending loop, with the upload held.
	 *
	 * @param Upload $upload  The upload.
	 * @param int    $seconds Time to spend; 0 for no limit.
	 * @param int    $timeout Seconds to allow one part.
	 *
	 * @return Upload|WP_Error
	 */
	private function send_parts( Upload $upload, int $seconds, int $timeout ): Upload|WP_Error {
		$upload = $this->resume( $upload );

		if ( $upload instanceof WP_Error ) {
			return $upload;
		}

		$began = ( $this->clock )();
		$pace  = 0.0;

		foreach ( $upload->missing() as $number ) {
			$elapsed = ( $this->clock )() - $began;

			// Never start a part that would run past the time at the pace of
			// the last, but always send one, so every call gets somewhere.
			if ( $seconds > 0 && $pace > 0.0 && $elapsed + $pace > $seconds ) {
				break;
			}

			$started = ( $this->clock )();
			$bytes   = $this->read( $upload, $number );

			if ( $bytes instanceof WP_Error ) {
				return $bytes;
			}

			try {
				$etag = $this->bucket->upload_part( $upload->key, $upload->upload_id, $number, $bytes, $timeout );
			} catch ( Throwable $exception ) {
				$etag = new WP_Error( 's3_upload_part_failed', $exception->getMessage() );
			}

			unset( $bytes );

			if ( $etag instanceof WP_Error ) {
				return $etag;
			}

			$upload->parts[ $number ] = $etag;
			$upload->updated          = time();
			ksort( $upload->parts );
			$this->save( $upload );

			$pace = ( $this->clock )() - $started;
		}

		return $upload;
	}

	/**
	 * One part's bytes, from the file.
	 *
	 * @param Upload $upload The upload.
	 * @param int    $number Part number.
	 *
	 * @return string|WP_Error
	 */
	private function read( Upload $upload, int $number ): string|WP_Error {
		$length = $upload->part_length( $number );
		$bytes  = file_get_contents( $upload->source, false, null, $upload->offset( $number ), $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a byte range of a local file.

		if ( false === $bytes || strlen( $bytes ) !== $length ) {
			return new WP_Error( 's3_upload_source', __( 'The file being uploaded could not be read.', 'sugarcommerce' ) );
		}

		return $bytes;
	}

	/**
	 * Remember an upload.
	 *
	 * @param Upload $upload The upload.
	 *
	 * @return void
	 */
	private function save( Upload $upload ): void {
		update_option( self::option( $upload->id ), $upload->to_array(), false );
	}

	/**
	 * Forget an upload, and any hold on it.
	 *
	 * @param Upload $upload The upload.
	 *
	 * @return void
	 */
	private function forget( Upload $upload ): void {
		delete_option( self::option( $upload->id ) );
	}

	/**
	 * Hold an upload for one send(), unless another holds it.
	 *
	 * The insert is the test: add_option() fails when the row is there. A
	 * hold past its time is from a request that died, and is taken over.
	 *
	 * @param Upload $upload  The upload.
	 * @param int    $seconds The send's time limit.
	 *
	 * @return bool
	 */
	private function lock( Upload $upload, int $seconds ): bool {
		$name  = self::lock_option( $upload->id );
		$until = time() + max( 0, $seconds ) + self::LOCK_GRACE;

		if ( add_option( $name, $until, '', false ) ) {
			return true;
		}

		if ( (int) get_option( $name, 0 ) >= time() ) {
			return false;
		}

		delete_option( $name );

		return add_option( $name, $until, '', false );
	}

	/**
	 * Let go of an upload.
	 *
	 * @param Upload $upload The upload.
	 *
	 * @return void
	 */
	private function unlock( Upload $upload ): void {
		delete_option( self::lock_option( $upload->id ) );
	}

	/**
	 * Where an upload is stored.
	 *
	 * @param string $id Its id.
	 *
	 * @return string
	 */
	private static function option( string $id ): string {
		return Runtime::key( self::OPTION . $id );
	}

	/**
	 * Where an upload's hold is stored. Not under option(), so pending()
	 * does not read it as an upload.
	 *
	 * @param string $id Its id.
	 *
	 * @return string
	 */
	private static function lock_option( string $id ): string {
		return Runtime::key( 'upload-lock_' . $id );
	}

	/**
	 * What identifies a file on this server: its size and modified time.
	 *
	 * @param string $path The file.
	 *
	 * @return string
	 */
	private static function fingerprint( string $path ): string {
		clearstatcache( true, $path );

		return filesize( $path ) . ':' . filemtime( $path );
	}
}
