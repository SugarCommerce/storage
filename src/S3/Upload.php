<?php
/**
 * One resumable multipart upload, as it stands.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\S3;

/**
 * Class Upload
 *
 * What has to outlive the request that started an upload: which bucket and
 * key, storage's upload id, how the file is cut into parts, and which parts
 * have been sent. Multipart keeps one of these per upload, so a PHP request
 * that timed out, a job that ran out of time or a browser that was closed
 * carries on from the last part rather than the first.
 *
 * Every part but the last is `part_size` bytes. R2 refuses an upload whose
 * parts are not all the same size, and a fixed size is also what lets a part
 * number be turned back into a byte range on any later request.
 *
 * `parts` is this side's record, part number to ETag. It is a hint: what
 * storage lists (Multipart::stored()) is the answer, and every resume and
 * every finish asks.
 *
 * @since 1.0.0
 */
final class Upload {

	/**
	 * Describe an upload.
	 *
	 * @since 1.0.0
	 *
	 * @param string             $id          This side's id for it: random,
	 *                                        and what a browser or a job is
	 *                                        handed, never storage's id.
	 * @param string             $bucket      Bucket name.
	 * @param string             $key         Object key.
	 * @param string             $upload_id   Storage's multipart upload id.
	 * @param int                $size        Bytes in the whole file.
	 * @param int                $part_size   Bytes in every part but the last.
	 * @param string             $mime_type   The finished object's type.
	 * @param string             $source      A file on this server to send it
	 *                                        from, or '' when a browser sends
	 *                                        the parts.
	 * @param string             $fingerprint What the sender says identifies
	 *                                        the file -- a browser's name,
	 *                                        size and modified time -- so the
	 *                                        same file dropped again resumes.
	 * @param int                $owner       The user who started it, 0 for
	 *                                        none.
	 * @param int                $started     When it was started.
	 * @param array<int, string> $parts       Part number => ETag, as sent.
	 * @param int                $updated     When it last changed.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $bucket,
		public readonly string $key,
		public readonly string $upload_id,
		public readonly int $size,
		public readonly int $part_size,
		public readonly string $mime_type = 'application/octet-stream',
		public readonly string $source = '',
		public readonly string $fingerprint = '',
		public readonly int $owner = 0,
		public readonly int $started = 0,
		public array $parts = array(),
		public int $updated = 0,
	) {}

	/**
	 * How many parts the file is cut into.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function part_count(): int {
		return max( 1, (int) ceil( $this->size / max( 1, $this->part_size ) ) );
	}

	/**
	 * Where a part starts in the file.
	 *
	 * @since 1.0.0
	 *
	 * @param int $number Part number, 1-based.
	 *
	 * @return int
	 */
	public function offset( int $number ): int {
		return ( $number - 1 ) * $this->part_size;
	}

	/**
	 * How many bytes a part holds: `part_size`, except the last.
	 *
	 * @since 1.0.0
	 *
	 * @param int $number Part number, 1-based.
	 *
	 * @return int 0 for a number that is not one of this upload's parts.
	 */
	public function part_length( int $number ): int {
		if ( $number < 1 || $number > $this->part_count() ) {
			return 0;
		}

		return min( $this->part_size, $this->size - $this->offset( $number ) );
	}

	/**
	 * The parts not yet sent, in order.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, int> Part numbers.
	 */
	public function missing(): array {
		$missing = array();

		for ( $number = 1, $count = $this->part_count(); $number <= $count; $number++ ) {
			if ( ! isset( $this->parts[ $number ] ) ) {
				$missing[] = $number;
			}
		}

		return $missing;
	}

	/**
	 * Bytes in the parts sent.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function sent(): int {
		$bytes = 0;

		foreach ( array_keys( $this->parts ) as $number ) {
			$bytes += $this->part_length( (int) $number );
		}

		return $bytes;
	}

	/**
	 * Whether every part has been sent.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function done(): bool {
		return array() === $this->missing();
	}

	/**
	 * How far along it is, for a progress bar.
	 *
	 * @since 1.0.0
	 *
	 * @return array{bytes: int, total: int, parts: int, of: int, percent: float}
	 */
	public function progress(): array {
		$bytes = $this->sent();

		return array(
			'bytes'   => $bytes,
			'total'   => $this->size,
			'parts'   => count( $this->parts ),
			'of'      => $this->part_count(),
			'percent' => $this->size > 0 ? round( 100 * $bytes / $this->size, 1 ) : 0.0,
		);
	}

	/**
	 * The upload as it is stored.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'          => $this->id,
			'bucket'      => $this->bucket,
			'key'         => $this->key,
			'upload_id'   => $this->upload_id,
			'size'        => $this->size,
			'part_size'   => $this->part_size,
			'mime_type'   => $this->mime_type,
			'source'      => $this->source,
			'fingerprint' => $this->fingerprint,
			'owner'       => $this->owner,
			'started'     => $this->started,
			'parts'       => $this->parts,
			'updated'     => $this->updated,
		);
	}

	/**
	 * An upload as to_array() stored it.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data What was stored.
	 *
	 * @return self|null Null when it is not one: a missing field, a size or
	 *                   part size that cannot be, or more parts than storage
	 *                   allows.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$text = static fn( string $name ): string => is_scalar( $data[ $name ] ?? null ) ? (string) $data[ $name ] : '';
		$int  = static fn( string $name ): int => is_numeric( $data[ $name ] ?? null ) ? (int) $data[ $name ] : 0;

		$size      = $int( 'size' );
		$part_size = $int( 'part_size' );

		if ( '' === $text( 'id' ) || '' === $text( 'bucket' ) || '' === $text( 'key' ) || '' === $text( 'upload_id' ) || $size < 1 || $part_size < 1 || ceil( $size / $part_size ) > Bucket::MAX_PARTS ) {
			return null;
		}

		$parts = array();

		foreach ( (array) ( $data['parts'] ?? array() ) as $number => $etag ) {
			if ( is_int( $number ) && $number >= 1 && is_string( $etag ) && '' !== $etag ) {
				$parts[ $number ] = $etag;
			}
		}

		ksort( $parts );

		return new self(
			$text( 'id' ),
			$text( 'bucket' ),
			$text( 'key' ),
			$text( 'upload_id' ),
			$size,
			$part_size,
			'' === $text( 'mime_type' ) ? 'application/octet-stream' : $text( 'mime_type' ),
			$text( 'source' ),
			$text( 'fingerprint' ),
			$int( 'owner' ),
			$int( 'started' ),
			$parts,
			$int( 'updated' )
		);
	}
}
