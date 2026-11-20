<?php
/**
 * The ZIP format's own records: local headers, descriptors, the central directory and its end.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Traits\ZipStream;

use SugarCommerce\Storage\Models\ZipEntry;

/**
 * Trait Records
 *
 * The ZIP format's own records: local headers, descriptors, the central directory and its end.
 *
 * Byte for byte as APPNOTE says, with the ZIP64 forms past 4 GiB or 65,535
 * entries. ZipStream decides what goes in the archive; this is how it is
 * written down.
 *
 * @since 1.0.0
 */
trait Records {

	/**
	 * The header that precedes a member's bytes.
	 *
	 * Sizes and CRC are zero here and given afterwards in the descriptor,
	 * which is what bit 3 of the flags announces. It is the only way to
	 * write a member you have not read yet.
	 *
	 * @param string $name  Path inside the archive.
	 * @param int    $time  Modification time.
	 * @param bool   $zip64 Whether to reserve 64-bit fields.
	 *
	 * @return string
	 */
	private function local_header( string $name, int $time, bool $zip64 ): string {
		$extra = $zip64
			// Placeholders. The real numbers go in the descriptor and the
			// central directory; this only reserves the shape.
			? pack( 'vvPP', 0x0001, 16, 0, 0 )
			: '';

		return pack( 'V', 0x04034b50 )
			. pack( 'v', $zip64 ? 45 : 20 )     // Version needed.
			. pack( 'v', 0x0008 | 0x0800 )      // Data descriptor follows; name is UTF-8.
			. pack( 'v', 0 )                    // Stored.
			. self::dos_time( $time )
			. pack( 'VVV', 0, 0, 0 )            // CRC and both sizes: not yet known.
			. pack( 'v', strlen( $name ) )
			. pack( 'v', strlen( $extra ) )
			. $name
			. $extra;
	}

	/**
	 * The sizes and checksum, written after the member.
	 *
	 * @param int  $crc   CRC-32.
	 * @param int  $size  Bytes written.
	 * @param bool $zip64 Whether the fields are 64-bit.
	 *
	 * @return string
	 */
	private function descriptor( int $crc, int $size, bool $zip64 ): string {
		return pack( 'V', 0x08074b50 )
			. pack( 'V', $crc )
			. ( $zip64 ? pack( 'PP', $size, $size ) : pack( 'VV', $size, $size ) );
	}

	/**
	 * A member's entry in the central directory.
	 *
	 * @param ZipEntry $entry The written member.
	 *
	 * @return string
	 */
	private function central( ZipEntry $entry ): string {
		$big   = $entry->size >= self::LIMIT || $entry->offset >= self::LIMIT;
		$extra = '';

		if ( $big ) {
			// Order matters and is fixed by the spec: only the fields that
			// overflowed appear, in the order they appear in the record.
			$fields = '';

			if ( $entry->size >= self::LIMIT ) {
				$fields .= pack( 'PP', $entry->size, $entry->size );
			}

			if ( $entry->offset >= self::LIMIT ) {
				$fields .= pack( 'P', $entry->offset );
			}

			$extra = pack( 'vv', 0x0001, strlen( $fields ) ) . $fields;
		}

		return pack( 'V', 0x02014b50 )
			. pack( 'v', $big ? 45 : 20 )       // Version made by.
			. pack( 'v', $big ? 45 : 20 )       // Version needed.
			. pack( 'v', 0x0008 | 0x0800 )
			. pack( 'v', 0 )                    // Stored.
			. self::dos_time( $entry->time )
			. pack( 'V', $entry->crc )
			. pack( 'V', min( $entry->size, self::LIMIT ) )
			. pack( 'V', min( $entry->size, self::LIMIT ) )
			. pack( 'v', strlen( $entry->name ) )
			. pack( 'v', strlen( $extra ) )
			. pack( 'v', 0 )                    // Comment length.
			. pack( 'v', 0 )                    // Disk number.
			. pack( 'v', 0 )                    // Internal attributes.
			. pack( 'V', 0x81a40000 )           // External attributes: 0644.
			. pack( 'V', min( $entry->offset, self::LIMIT ) )
			. $entry->name
			. $extra;
	}

	/**
	 * The record a reader looks for first.
	 *
	 * It is at the end, which is why a ZIP cannot be read until it has been
	 * written — and why a download that dies halfway leaves nothing openable
	 * rather than a truncated archive that looks fine.
	 *
	 * @param int $start Where the central directory began.
	 * @param int $size  How long it is.
	 *
	 * @return string
	 */
	private function directory_end( int $start, int $size ): string {
		$count = count( $this->entries );
		$big   = $count >= 0xFFFF || $start >= self::LIMIT || $size >= self::LIMIT;
		$out   = '';

		if ( $big ) {
			// The 64-bit record, then a locator pointing at it. A reader that
			// understands neither still finds the 32-bit record after them
			// and sees the 0xFFFF markers telling it to look harder.
			$out .= pack( 'V', 0x06064b50 )
				. pack( 'P', 44 )               // Size of the rest of this record.
				. pack( 'vv', 45, 45 )
				. pack( 'VV', 0, 0 )            // This disk, and the one with the directory.
				. pack( 'PP', $count, $count )
				. pack( 'P', $size )
				. pack( 'P', $start );

			$out .= pack( 'V', 0x07064b50 )
				. pack( 'V', 0 )
				. pack( 'P', $start + $size )
				. pack( 'V', 1 );
		}

		return $out
			. pack( 'V', 0x06054b50 )
			. pack( 'vv', 0, 0 )
			. pack( 'v', min( $count, 0xFFFF ) )
			. pack( 'v', min( $count, 0xFFFF ) )
			. pack( 'V', min( $size, self::LIMIT ) )
			. pack( 'V', min( $start, self::LIMIT ) )
			. pack( 'v', 0 );                   // Comment length.
	}

	/**
	 * A timestamp in the format the archive uses.
	 *
	 * MS-DOS, from 1980 to 2107, in local time with two-second resolution.
	 * Not a choice — it is what the field is. A time outside that is
	 * clamped rather than overflowing into the next field.
	 *
	 * @param int $time Unix timestamp.
	 *
	 * @return string Four bytes: time then date.
	 */
	private static function dos_time( int $time ): string {
		$parts = getdate( min( max( $time, 315532800 ), self::LATEST ) ); // 1980-01-01, the epoch of the format.

		return pack(
			'vv',
			( $parts['hours'] << 11 ) | ( $parts['minutes'] << 5 ) | ( $parts['seconds'] >> 1 ),
			( ( $parts['year'] - 1980 ) << 9 ) | ( $parts['mon'] << 5 ) | $parts['mday']
		);
	}
}
