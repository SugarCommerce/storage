# Storage

Files on disk for WordPress: upload folders the web server refuses to serve,
delivery from them with ranges and X-Sendfile, and the small file helpers
every plugin that sells downloads ends up writing -- safe paths, MIME types,
filenames, download names, streamed zips and upload inspection.

## What it does

A plugin that hands people files has the same jobs whatever the files are:
send a large file without holding it in memory, check that a path out of a
request is really inside the folder it should be, name the download so it
saves as what it is, and refuse an upload that is an executable wearing a
`.pdf`.

Each of those is easy to get almost right. An `.htaccess` deny rule does
nothing at all on nginx; `str_replace( '..', '', $path )` is defeated by
writing the attack twice; a filename with a right-to-left override saves as
something else. This does each of them once, properly.

Files in a bucket are `sugarcommerce/s3`; the admin bucket browser and the
"Choose from your bucket" picker are `sugarcommerce/storage-browser`. Both
build on the helpers here.

## Features

* Register an upload folder the server refuses, prove it over HTTP, and deliver from it with ranges and X-Sendfile
* Check a path is inside a directory after resolving `..` and symlinks
* Look up MIME types, decide inline or download, and file a type into a category
* Rename a file without letting it change type, and refuse names that hide their real extension
* Name a download in both RFC 6266 forms, so any script saves under its real name
* Stream a ZIP to the browser without building it first
* Inspect an upload's bytes for executables, disguised types and zips hiding them

## Installation

```bash
composer require sugarcommerce/storage
```

It requires `sugarcommerce/plugin`: `Files\Path::to_url()` maps anything
outside uploads through `SugarCommerce\Plugin\Assets`, and the hooks below
are named from the build by `SugarCommerce\Plugin\Build`.

## Quick start

A file on disk, only from inside the folder it belongs to:

```php
use SugarCommerce\Storage\Folders\Folders;

Folders::register( 'downloads' );

// From an endpoint that has already decided this person may have it.
Folders::deliver( 'downloads', $order->file_path, [ 'filename' => 'Album.zip' ] );
```

## Protected folders

Putting a paid file under `wp-content/uploads` means anyone who guesses the
URL has it. The usual fix — an `.htaccess` deny rule — is a file you write
once, never verify, and which does nothing at all on nginx.

`Folders` registers the folder, writes the rules the server understands, and
proves the protection works by asking the site for a test file over HTTP.
Delivery goes through a call that resolves the path inside the folder, so a
value out of a request cannot be made to name `wp-config.php`.

```php
use SugarCommerce\Storage\Folders\Folders;

add_action( 'init', function () {
	Folders::register( 'downloads', [
		'public_extensions' => [ 'jpg', 'png' ], // previews stay public
		'upload_for'        => [ 'product' ],    // product uploads land here
	] );
} );
```

Then, from whatever endpoint has already decided this person may have the file:

```php
Folders::deliver( 'downloads', $order->file_path, [
	'filename' => $product->name . '.zip', // any script; sent in both RFC 6266 forms
] );
// Returns false when the file is not in the folder, so you can say something better than a 404.
```

It does not decide **whether this person** may have the file. That is your
question, and it has to be answered before the call above.

Delivery sends ranges (so a download resumes and a video seeks), answers an
unsatisfiable range with a 416 rather than the whole file, sends
`X-Content-Type-Options: nosniff`, never renders HTML, SVG or script inline,
and hands the file to the web server when it can: LiteSpeed always, Apache
when core's `apache_mod_loaded( 'mod_xsendfile' )` says so (never guessed
under PHP-FPM, where Apache's modules cannot be seen), nginx -- Flywheel's
too, which core's `$is_nginx` misses -- once the `{prefix}_nginx_xsendfile`
filter says the internal location is in place.

`Folders::is_protected( 'downloads' )` asks the site over HTTP and caches the
answer for an hour. `Folders::server_rules( 'downloads' )` is the nginx text
for whoever administers the server, since nginx has no per-directory file.
`Delivery::send( $path, $options )` sends a file this code already chose,
with no containment check — use it only for a path that did not come from a
request.

### Object storage

A file in a bucket should be redirected to rather than proxied — streaming it
through PHP pays for the bytes twice. One filter, and the same call site
serves both:

```php
use SugarCommerce\Storage\Utils\Runtime;

add_filter( Runtime::hook( 'redirect_to' ), function ( $url, $path ) use ( $bucket ) {
	return $bucket->url( key_for( $path ), 60, basename( $path ) ); // a SugarCommerce\S3\Bucket
}, 10, 2 );
```

Return null and the file is read from disk as normal.

## Paths, types and names

`Files\Path::within()` is the check that matters for any endpoint that turns a
request into a file on disk. Both sides go through `realpath()`, so `..`,
symlinks, doubled slashes, a null byte and `phar://` are gone before the
comparison. A string match on the prefix is how a download endpoint ends up
reading `wp-config.php`.

```php
use SugarCommerce\Storage\Files\Filename;
use SugarCommerce\Storage\Files\Mime;
use SugarCommerce\Storage\Files\Path;

$file = Path::within( $downloads_dir, $requested ); // the resolved path, or null

if ( null === $file ) {
	wp_die( 'Not found', 404 );
}

$type = Mime::of( $file );            // core's answer first, then fonts, ebooks, 3D, installers
Mime::must_download( $type );          // anything a browser has no viewer for
Mime::is_dangerous_inline( $type );    // HTML, SVG, scripts: never inline, whatever was asked
Mime::category( $file );               // image, video, audio, document, archive or other
Mime::is_allowed( $file, 'downloads' ); // the site's allowed types, filtered per context
```

Core answers first in `Mime::of()`, so a file is the same type here as in the
media library, including anything a plugin added through the `mime_types`
filter. The package's own table holds only what core has no answer for.

Names:

```php
Filename::extension( 'image.jpg?v=2' );                 // 'jpg' — a '#' in a plain name is kept
Filename::rename( 'Final Mix', 'master.wav' );          // 'Final-Mix.wav' — for disk; the extension stays
Filename::relabel( 'master.wav', 'Final Mix.exe' );     // 'Final Mix.exe.wav' — for people to read
Filename::refusal( 'invoice.pdf.exe' );                 // 'Files of type .exe cannot be stored.'
Filename::clamp( $name, 200 );                          // to a byte budget, extension kept
Filename::to_ascii( 'Симфония №5.wav' );                // 'Simfoniia No5.wav'
```

`rename()` is for a file on disk and goes through `sanitize_file_name()`.
`relabel()` keeps spaces and capitals for a name somebody reads, and answers
`''` when nothing usable was typed. Both keep the original extension and both
take out direction overrides, which make `Invoice\u{202E}fdp.exe` display as
`Invoiceexe.pdf`. `refusal()` checks every part of a name, not just the last,
against `Filename::EXECUTABLE` or a list you pass.

`Path::from_url()` and `Path::to_url()` convert between uploads, content and
site URLs and paths -- `to_url()` hands anything outside uploads to
`SugarCommerce\Plugin\Assets::path_to_url()` -- and `Path::is_local()` says
whether a URL is a file on this site's disk. `Filename::basename()` is core's
`wp_basename()`, which splits on either separator and keeps a multibyte name
whole; `Filename::problem()` says what is wrong with a name typed for a
rename.

### Download names

`Files\ContentDisposition` writes the header a download is saved under, in
both RFC 6266 forms -- an ASCII `filename=` every client reads and a UTF-8
`filename*=` the rest use -- so "Naïve Café — Master.wav" saves as itself.
Protected-folder delivery uses it, and so do `sugarcommerce/s3`'s signed
links, so a file is named the same way from either place.

```php
use SugarCommerce\Storage\Files\ContentDisposition;

header( 'Content-Disposition: ' . ContentDisposition::attachment( 'Naïve Café — Master.wav' ) );
```

Direction overrides and control characters go, the name is clamped to a
safe length with its extension kept, and a name with nothing printable left
falls back to its extension rather than to an empty name.

## Streamed zips

A "download all" or a bundle has to reach the buyer as one file, and
building it on disk first means a forty-gigabyte temporary file and a request
that times out before the first byte is sent. `ZipStream` writes the archive
as it goes: each member is read through one 256 KiB buffer and sent on, so
the first byte reaches the browser before the last file has been opened.

```php
use SugarCommerce\Storage\Files\ZipStream;

header( 'Content-Type: application/zip' );
header( 'Content-Disposition: attachment; filename="order-1042.zip"' );

$zip = new ZipStream( static fn( string $bytes ) => print $bytes );

foreach ( $files as $file ) {
	$zip->add_file( $file['path'], $file['name'] );   // read a chunk at a time
}

$zip->add_string( 'licence.txt', $licence );
$zip->finish();
```

`add( $name, $handle )` takes any open stream, and `begin()`, `write()` and
`end()` take a member that arrives in pieces. One member is open at a time:
beginning a second, writing or ending with none, and finishing with one open
all throw a `LogicException`. `add()` is the other three in a row, so both
ways write the same bytes.

Members are stored, not compressed. What is sold this way -- audio, video,
images, a plugin's zip -- is compressed already, and deflating it costs time
to make it bigger. It also means no `Content-Length` can be promised: send
none and the browser shows progress without a total.

Sizes and checksums go in a data descriptor after each member, since neither
is known until it has been read. ZIP64 turns on by itself, per member past
4 GiB and for the archive past 65,534 members or 4 GiB of directory; pass
`expect:` with a size you know and the member's header is made big enough
from the start. Being wrong about it costs nothing.

Names cannot escape the archive. `..` segments, leading slashes, drives
(`C:\`), backslashes and segments made only of dots and spaces (which Windows
reads as `..`) are removed, invalid UTF-8 is repaired, control characters and
direction overrides go, and a name with nothing left is `unnamed`. A name
already used gets a number before its extension -- `readme (2).txt` --
compared without case, because macOS and Windows extract to file systems
where `Readme.txt` and `readme.txt` are the same file. Times are stored as
the format's local MS-DOS time and clamped to 1980–2107.

### A file in a bucket

`fopen()` on a URL cannot be told which address to connect to, so a remote
file goes in from a copy fetched properly. Use a presigned URL (`sugarcommerce/s3`'s
`Signer::presign_get()`) and the WordPress HTTP API's `stream` option, then
add the copy:

```php
$temp     = wp_tempnam( 'zip-member' );
$response = wp_safe_remote_get( $signer->presign_get( $bucket, $key, 300 ), [
	'stream'   => true,
	'filename' => $temp,
	'timeout'  => 300,
] );

if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
	$zip->add_file( $temp, 'Masters/' . basename( $key ) );
}

wp_delete_file( $temp );
```

## Inspecting uploads

An extension check before an upload is a statement of intent; anybody can
rename a file. `Inspector` looks at the bytes that arrived -- usually the
first and last 64 KiB, two ranged reads of an object -- and answers one of
three things: `ok`, `flagged` for a person to look at, or `blocked`.

```php
use SugarCommerce\Storage\Files\Filename;
use SugarCommerce\Storage\Files\Inspector;

$refused = Filename::refusal( $submitted_name );   // before the upload: '' when acceptable

$inspector = new Inspector( suspect: [ 'keygen', 'cracked' ] );
$size      = (int) $bucket->size( $key );   // what arrived, not what the browser said
$head      = $bucket->read( $key, 0, min( $size, Inspector::HEAD ) - 1 );
$tail      = $size > Inspector::HEAD ? $bucket->read( $key, $size - Inspector::HEAD, $size - 1 ) : '';

if ( is_wp_error( $head ) || is_wp_error( $tail ) ) {
	return; // not read is not inspected: try again later
}

$found = $inspector->scan( $name, $head, $tail );

if ( ! $found->allowed() ) {
	$bucket->delete( $key );   // blocked: the bytes do not stay
}

$record->review_state = $found->state;   // ok | flagged | blocked
$record->review_note  = $found->reason;  // translated; escape it where it is shown
```

* Windows, Linux and macOS executables and shell scripts are blocked
  whatever they are called.
* A file whose contents disagree with its extension -- a `.wav` that is a
  PDF -- is flagged: usually a mistake, occasionally not.
* HTML or SVG a browser would sniff and run, in a `.txt` or a file with no
  extension, is flagged.
* Anything that is a zip, whatever it is called, has the names in its
  central directory checked the way an upload's name is, so `setup.exe` or
  `invoice.pdf.exe` inside is flagged. An `.epub` is a zip of pages and is
  left alone.
* Phrases in the filename the caller lists are flagged.

The banned extensions default to `Filename::EXECUTABLE` and are a constructor
argument, as are the phrases. `ZipDirectory::names( $bytes )` lists what a
fragment of an archive contains without parsing the archive. It is not a
virus scanner, does not open nested archives, and a clean answer does not
promise a file is safe.

## Hooks

Every hook this package fires is named from its build, so two plugins bundling
their own prefixed copies never steer each other. `Runtime::hook( 'name' )`
gives the name: `sugarcommerce_storage_name` unprefixed, and
`myplugin_sugarcommerce_storage_name` in a build Strauss prefixed `MyPlugin\`.
A prefix that itself starts `SugarCommerce\` keeps all its segments --
`SugarCommerce\Playground\Vendor\` gives
`sugarcommerce_playground_vendor_sugarcommerce_storage_name` -- so a plugin of
the family is never taken for an unprefixed copy.

| Hook | Kind | What it does |
|---|---|---|
| `redirect_to` | filter | A URL to send a protected download to instead of reading it from disk |
| `htaccess` | filter | The `.htaccess` a protected folder is given |
| `nginx_internal_location` | filter | The internal location X-Accel-Redirect uses (`/protected-files/`) |
| `nginx_xsendfile` | filter | Whether nginx has that location, so files can be handed to it |
| `allowed_mime_types` | filter | The types `Mime::allowed_types()` starts from, with the context |
| `allowed_mime_types_{context}` | filter | The same, for one context only |

## How it is laid out

* `Files/` -- paths, MIME types, filenames, download names, upload inspection and streamed zips
* `Models/` -- what an inspection found (`Inspection`) and a zip member written (`ZipEntry`)
* `Folders/` -- protected upload folders: registering, guard files and server rules, and delivery
* `Traits/` -- `ZipStream`'s format records, kept apart from deciding what goes in the archive
* `Utils/` -- the build's prefix, for hook names

## Security

* Resolves a path, `..` and symlinks included, before saying it is inside a folder, and refuses null bytes and stream wrappers such as `phar://`
* Maps a local URL to a file only inside the folder its address names, so `../../wp-config.php` on the end of an uploads URL is nothing
* Never shows inline a type a browser would run (HTML, SVG, JavaScript and the rest), and sends `nosniff` so the bytes cannot argue otherwise
* Names a download in both RFC 6266 forms with control characters, direction overrides and path separators gone, so a name cannot spoof its extension or break the header
* Writes zip entry names that cannot escape the archive when extracted -- no `..`, backslashes, drive letters or Windows dot-segments
* Answers a range it cannot satisfy with 416, rather than with the whole file
* Refuses an upload whose bytes are an executable, a disguised type, or a zip with one inside, whatever its extension says

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later
* `sugarcommerce/plugin`

## License

GPL-2.0-or-later
