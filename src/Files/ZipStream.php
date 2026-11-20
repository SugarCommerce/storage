<?php
/**
 * Zip Stream
 *
 * @package     SugarCommerce\Storage
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Files;

use SugarCommerce\Storage\Models\ZipEntry;
use SugarCommerce\Storage\Traits\ZipStream\Records;

/**
 * A ZIP written as it is sent.
 *
 * Builds the archive in the order a reader expects it, and sends each part as
 * soon as it exists. Nothing is held: a forty-gigabyte download costs one
 * buffer rather than forty gigabytes of disk, and the first byte reaches the
 * client before the last member has been read.
 *
 * Members are stored, not deflated. Everything anybody sells this way — audio,
 * video, images, a zip of a plugin — is already compressed, so deflating costs
 * CPU to make the file fractionally larger. It also means the bytes written
 * are the bytes read, which is what makes streaming honest.
 *
 * Sizes and checksums are not known until a member has been read, so each one
 * is followed by a data descriptor — the part of the format that exists for
 * exactly this. ZIP64 turns on per member when one exceeds 4 GiB, and for the
 * archive when the totals do.
 *
 * ```php
 * $zip = new ZipStream( static fn( string $bytes ) => print $bytes );
 *
 * $zip->add( 'master.wav', $handle );
 * $zip->finish();
 * ```
 *
 * @since 1.0.0
 */
final class ZipStream {

	use Records;

	/**
	 * The point at which a 32-bit field can no longer hold the answer.
	 */
	private const int LIMIT = 0xFFFFFFFF;

	/**
	 * How much to move at a time.
	 *
	 * Large enough that the syscall overhead disappears, small enough that a
	 * worker holding several of these at once is not the reason a box runs
	 * out of memory.
	 */
	private const int CHUNK = 262144; // 256 KiB.

	/**
	 * The longest name written, in bytes.
	 *
	 * The field that holds a name's length is sixteen bits. A longer name
	 * would not just be cut off — its excess would be read as the bytes that
	 * follow it, which is a way to plant headers of one's own. The margin
	 * leaves room for the number unused() may append.
	 */
	private const int NAME_BYTES = 0xFFFF - 32;

	/**
	 * The latest time the format can hold: the last day of 2107, with a day
	 * to spare for any time zone. Later years overflow the seven-bit field.
	 */
	private const int LATEST = 4354732800;

	/**
	 * What a member is called when nothing is left of its name.
	 */
	private const string UNNAMED = 'unnamed';

	/**
	 * Members written so far.
	 *
	 * @var ZipEntry[]
	 */
	private array $entries = array();

	/**
	 * Names already written, lower-cased, as keys.
	 *
	 * @var array<string, true>
	 */
	private array $names = array();

	/**
	 * Bytes sent, which is also the offset of whatever comes next.
	 */
	private int $offset = 0;

	/**
	 * Whether finish() has run.
	 */
	private bool $finished = false;

	/**
	 * The member being written, between begin() and end().
	 *
	 * @var array{name: string, time: int, zip64: bool, start: int, crc: \HashContext, size: int}|null
	 */
	private ?array $open = null;

	/**
	 * An archive that sends itself to a sink as it is built.
	 *
	 * @since 1.0.0
	 *
	 * @param \Closure $sink Receives each chunk of the archive. Printing it
	 *                       is the common case; a test collects it instead.
	 */
	public function __construct( private readonly \Closure $sink ) {}

	/**
	 * Add a member, reading it to the end.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $name   Path inside the archive. Directories are made
	 *                         by putting a slash in it. A name already used
	 *                         gets a number — see unused().
	 * @param resource $handle Anything fread() accepts. Closed by the caller.
	 * @param int|null $time   Modification time. Null uses the clock.
	 * @param int      $expect Size if it is known, which only decides whether
	 *                         the member is written with 64-bit fields. Being
	 *                         wrong costs nothing but a larger header.
	 *
	 * @return ZipEntry What ended up in the archive.
	 *
	 * @throws \InvalidArgumentException When $handle is not an open stream;
	 *                                   nothing has been written.
	 * @throws \LogicException           When the archive has already been
	 *                                   finished.
	 */
	public function add( string $name, $handle, ?int $time = null, int $expect = 0 ): ZipEntry {
		// Checked before the header goes out, so a bad handle cannot leave a
		// member begun that nothing will end.
		if ( ! is_resource( $handle ) || 'stream' !== get_resource_type( $handle ) ) {
			throw new \InvalidArgumentException( 'A member is read from an open stream.' );
		}

		$this->begin( $name, $time, $expect );

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, self::CHUNK );

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$this->write( $chunk );
		}

		return $this->end();
	}

	/**
	 * Start a member whose bytes will be pushed in with write().
	 *
	 * For a member that arrives rather than being read: the body of an HTTP
	 * response handed over chunk by chunk, where there is no handle to give
	 * add(). Wiring such a body to a handle means a stream wrapper, and PHP's
	 * cannot be told which address to connect to — so the bytes are pushed
	 * here from whatever fetched them properly instead.
	 *
	 * The header goes out now. Nothing else may be added until end().
	 *
	 * @since 1.2.0
	 *
	 * @param string   $name   Path inside the archive. Cleaned and, if
	 *                         already used, numbered — as for add().
	 * @param int|null $time   Modification time. Null uses the clock.
	 * @param int      $expect Size if it is known; see add().
	 *
	 * @return string The name the member was written under.
	 *
	 * @throws \LogicException When the archive has been finished, or another
	 *                         member is still open.
	 */
	public function begin( string $name, ?int $time = null, int $expect = 0 ): string {
		if ( $this->finished ) {
			throw new \LogicException( 'Cannot add to an archive that has been finished.' );
		}

		if ( null !== $this->open ) {
			throw new \LogicException( 'Cannot begin "' . $name . '" while "' . $this->open['name'] . '" is still open: end() it first.' );
		}

		$name  = $this->unused( self::clean( $name ) );
		$time ??= time();

		// Decided before the header, because it changes the header. A member
		// declared 32-bit that turns out not to be cannot be rewritten: it
		// has already been sent.
		$zip64 = $expect >= self::LIMIT;

		$this->open = array(
			'name'  => $name,
			'time'  => $time,
			'zip64' => $zip64,
			'start' => $this->offset,
			'crc'   => hash_init( 'crc32b' ),
			'size'  => 0,
		);

		$this->emit( $this->local_header( $name, $time, $zip64 ) );

		return $name;
	}

	/**
	 * Push some of the open member's bytes.
	 *
	 * @since 1.2.0
	 *
	 * @param string $bytes The next bytes, in order. Any length; empty is
	 *                      allowed and changes nothing.
	 *
	 * @return void
	 *
	 * @throws \LogicException When no member is open.
	 */
	public function write( string $bytes ): void {
		if ( null === $this->open ) {
			throw new \LogicException( 'No member is open: begin() one first.' );
		}

		if ( '' === $bytes ) {
			return;
		}

		hash_update( $this->open['crc'], $bytes );

		$this->open['size'] += strlen( $bytes );

		$this->emit( $bytes );
	}

	/**
	 * Close the open member: its sizes and checksum go out after it.
	 *
	 * A member whose source failed partway is still closed properly — the
	 * archive stays readable, and the member is as long as what arrived.
	 * Whether that is acceptable is the caller's decision, made with the
	 * ZipEntry's size in hand.
	 *
	 * @since 1.2.0
	 *
	 * @return ZipEntry What ended up in the archive.
	 *
	 * @throws \LogicException When no member is open.
	 */
	public function end(): ZipEntry {
		if ( null === $this->open ) {
			throw new \LogicException( 'No member is open to end.' );
		}

		[ 'name' => $name, 'time' => $time, 'zip64' => $zip64, 'start' => $start, 'crc' => $crc, 'size' => $size ] = $this->open;

		$this->open = null;
		$checksum   = (int) hexdec( hash_final( $crc ) );

		// A member that outgrew its header. The descriptor can carry the
		// larger numbers even when the header did not, and the central
		// directory is what readers actually trust.
		$zip64 = $zip64 || $size >= self::LIMIT;

		$this->emit( $this->descriptor( $checksum, $size, $zip64 ) );

		$entry           = new ZipEntry( $name, $checksum, $size, $start, $time, $zip64 );
		$this->entries[] = $entry;

		return $entry;
	}

	/**
	 * Whether a member is open between begin() and end().
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_open(): bool {
		return null !== $this->open;
	}

	/**
	 * Add a member from a string.
	 *
	 * For the small things that belong in a download beside the files — a
	 * readme, a licence, a manifest — where opening a stream to hold four
	 * hundred bytes is ceremony.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $name     Path inside the archive.
	 * @param string   $contents The bytes.
	 * @param int|null $time     Modification time. Null uses the clock.
	 *
	 * @return ZipEntry
	 *
	 * @throws \RuntimeException When a memory stream cannot be opened.
	 * @throws \LogicException   When the archive has already been finished.
	 */
	public function add_string( string $name, string $contents, ?int $time = null ): ZipEntry {
		$handle = fopen( 'php://memory', 'r+' );

		if ( false === $handle ) {
			throw new \RuntimeException( 'A memory stream could not be opened.' );
		}

		fwrite( $handle, $contents );
		rewind( $handle );

		try {
			return $this->add( $name, $handle, $time, strlen( $contents ) );
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Add a member from a file on this server.
	 *
	 * The file is read a chunk at a time, so a large one never sits in
	 * memory. The name inside the archive is the file's own unless one is
	 * given. Only for a path this code chose: nothing here checks that the
	 * path is inside a folder a request was allowed to name -- that is
	 * Path::within()'s job, before this is called.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $path Absolute path to the file.
	 * @param string|null $name Path inside the archive. Null uses the
	 *                          file's own name.
	 * @param int|null    $time Modification time. Null uses the file's.
	 *
	 * @return ZipEntry
	 *
	 * @throws \RuntimeException When the file cannot be opened.
	 * @throws \LogicException   When the archive has already been finished.
	 */
	public function add_file( string $path, ?string $name = null, ?int $time = null ): ZipEntry {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file that cannot be opened is reported by the exception below.
		$handle = is_file( $path ) ? @fopen( $path, 'rb' ) : false;

		if ( false === $handle ) {
			throw new \RuntimeException( 'The file could not be opened: ' . $path );
		}

		$size = filesize( $path );
		$when = filemtime( $path );

		try {
			return $this->add(
				$name ?? Filename::basename( $path ),
				$handle,
				$time ?? ( false === $when ? null : $when ),
				false === $size ? 0 : $size
			);
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Write the central directory and close the archive.
	 *
	 * Nothing can be added afterwards, and nothing before this point is a
	 * readable ZIP — the directory at the end is how a reader finds anything.
	 *
	 * @since 1.0.0
	 *
	 * @return int Total bytes written.
	 *
	 * @throws \LogicException When a member begun with begin() was not ended:
	 *                         its descriptor would be missing, and a reader
	 *                         would find a directory pointing at half a file.
	 */
	public function finish(): int {
		if ( $this->finished ) {
			return $this->offset;
		}

		if ( null !== $this->open ) {
			throw new \LogicException( 'Cannot finish while "' . $this->open['name'] . '" is still open: end() it first.' );
		}

		$this->finished = true;
		$start          = $this->offset;

		foreach ( $this->entries as $entry ) {
			$this->emit( $this->central( $entry ) );
		}

		$this->emit( $this->directory_end( $start, $this->offset - $start ) );

		return $this->offset;
	}

	/**
	 * Bytes written so far.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function written(): int {
		return $this->offset;
	}

	/**
	 * Send a chunk, and remember how far along we are.
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return void
	 */
	private function emit( string $bytes ): void {
		$this->offset += strlen( $bytes );

		( $this->sink )( $bytes );
	}

	/**
	 * The name, or the first numbered variant of it not yet written.
	 *
	 * Two members with one name make an archive that loses a file on
	 * extraction — the second overwrites the first, or the extractor stops
	 * to ask. Compared without case, because macOS and Windows extract to
	 * file systems where `Readme.txt` and `readme.txt` are the same file.
	 *
	 * The number goes before the extension, the way a desktop names a
	 * second copy: `readme (2).txt`, `stems/kick (3).wav`.
	 *
	 * @param string $name Cleaned name.
	 *
	 * @return string
	 */
	private function unused( string $name ): string {
		$slash     = strrpos( $name, '/' );
		$directory = false === $slash ? '' : substr( $name, 0, $slash + 1 );
		$file      = false === $slash ? $name : substr( $name, $slash + 1 );

		// After the first character, so a dotfile is all name and no
		// extension: `.env (2)`, not ` (2).env`.
		$dot       = strrpos( $file, '.', 1 );
		$base      = false === $dot || 0 === $dot ? $file : substr( $file, 0, $dot );
		$extension = false === $dot || 0 === $dot ? '' : substr( $file, $dot );

		$candidate = $name;

		for ( $copy = 2; isset( $this->names[ mb_strtolower( $candidate ) ] ); $copy++ ) {
			$candidate = $directory . $base . ' (' . $copy . ')' . $extension;
		}

		$this->names[ mb_strtolower( $candidate ) ] = true;

		return $candidate;
	}

	/**
	 * A name that cannot escape the archive.
	 *
	 * An entry called `../../etc/cron.d/x` is a real attack on anything that
	 * extracts without checking, and plenty does. Backslashes go too: a
	 * Windows reader treats them as separators, so they are a second way to
	 * write the same escape. So does a drive (`C:/`, `C:`), and any segment
	 * made only of dots and spaces — Windows drops trailing dots and spaces
	 * from each part of a path, so `.. ` and `...` are `..` once extracted
	 * there.
	 *
	 * The name is also made safe to show: valid UTF-8 (the archive says it
	 * is), with no control characters and no direction overrides, which
	 * make `txt.exe` display as `exe.txt`. It is cut to a length the
	 * format can record, and a name with nothing left becomes `unnamed`.
	 *
	 * @param string $name Submitted name.
	 *
	 * @return string
	 */
	private static function clean( string $name ): string {
		$name = (string) preg_replace( '/[\x00-\x1F\x7F]|[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', mb_scrub( $name, 'UTF-8' ) );
		$name = str_replace( '\\', '/', $name );
		$name = (string) preg_replace( '#^[A-Za-z]:#', '', $name );

		$parts = array_filter(
			explode( '/', $name ),
			static fn( string $part ): bool => '' !== trim( $part, ' .' )
		);

		$name = rtrim( mb_strcut( implode( '/', $parts ), 0, self::NAME_BYTES, 'UTF-8' ), '/' );

		return '' === $name ? self::UNNAMED : $name;
	}
}
