# Storage

Files and object storage for WordPress: signed links and uploads for any
S3-compatible bucket, a bucket browser in the admin, upload folders the web
server refuses to serve, and the small file helpers every plugin that sells
downloads ends up writing.

## What it does

A plugin that hands people files has the same jobs whether the files are on
disk or in R2: make a link that works for the buyer and nobody else, send a
large file without holding it in memory, check that a path out of a request
is really inside the folder it should be, and refuse an upload that is an
executable wearing a `.pdf`.

Each of those is easy to get almost right. SigV4 fails silently if one step is
off; S3 reports some failures inside a 200; an `.htaccess` deny rule does
nothing at all on nginx; `str_replace( '..', '', $path )` is defeated by
writing the attack twice. This does each of them once, properly, with no SDK.

## Features

* Sign presigned download, inline, upload and multipart links for R2, S3, B2, Wasabi, DigitalOcean, MinIO and a dozen more providers
* Send a file of any size to a bucket a part at a time, and resume a browser's multipart upload from what storage really kept
* Read a byte range, the real stored size or an object's current ETag without downloading it
* List a bucket a page at a time, delete a thousand keys a request, copy in place, and read where it is
* Read, write and explain a bucket's CORS rules, including whether a multipart upload will work
* Browse buckets and folders in the admin, upload straight to storage, and attach a file to a field
* Register an upload folder the server refuses, prove it over HTTP, and deliver from it with ranges and X-Sendfile
* Check a path is inside a directory after resolving `..` and symlinks
* Look up MIME types, decide inline or download, and file a type into a category
* Rename a file without letting it change type, and refuse names that hide their real extension
* Stream a ZIP to the browser without building it first
* Inspect an upload's bytes for executables, disguised types and zips hiding them
* Cache-or-compute that can store `false`, delete by prefix on uninstall, and cast stored values back

## Installation

```bash
composer require sugarcommerce/storage
```

It requires `sugarcommerce/plugin`: the browser's stylesheets, scripts and
icons are found and given URLs by `SugarCommerce\Plugin\Assets`, wherever
Composer or Strauss put this package, and `Files\Path::to_url()` maps
anything outside uploads through it too. This package has no asset resolver
of its own.

## Quick start

A sixty-second link to a private object, saved under a name of your choosing:

```php
use SugarCommerce\Storage\S3\Provider;

$signer = Provider::R2->signer( $access_key, $secret_key, account_id: $account_id );

$url = $signer->presign_get( 'my-bucket', 'masters/a1.wav', 60, 'Album Master.wav' );
```

A file on disk, only from inside the folder it belongs to:

```php
use SugarCommerce\Storage\Folders\Folders;

Folders::register( 'downloads' );

// From an endpoint that has already decided this person may have it.
Folders::deliver( 'downloads', $order->file_path, [ 'filename' => 'Album.zip' ] );
```

## Signing and links

`S3\Signer` does AWS Signature Version 4 for any S3-compatible provider and
never sends anything. `S3\Provider` knows each provider's endpoint, default
region and where the bucket goes, so a settings screen stores an enum value
rather than a hostname somebody typed wrong:

```php
use SugarCommerce\Storage\S3\Provider;

$signer = Provider::R2->signer( $access_key, $secret_key, account_id: $account_id );

// A sixty-second link, saved as "Album Master.wav" whatever the key is.
$url = $signer->presign_get( 'my-bucket', 'masters/a1.wav', 60, 'Album Master.wav' );

// Plays in an <audio> element instead of downloading.
$play = $signer->presign_inline( 'my-bucket', 'previews/a1.mp3', 3600, 'audio/mpeg' );

// The same URL for a whole hour, so the browser's cached copy is used.
$thumb = $signer->presign_cacheable( 'my-bucket', 'covers/a1.webp', 3600, 'image/webp' );

// For a Content-Security-Policy.
$origin = $signer->origin( 'my-bucket' );
```

The object stays private; the link works until it expires and then does not.
`presign_put()` and `presign_upload_part()` let a browser send a file straight
to storage, and the `sign_*()` methods sign the requests a server makes itself:
head, delete, list, copy, multipart start, complete, abort and ListParts, and
the bucket's CORS configuration.

A region or account id that is not one DNS label is refused before it is
spliced into a hostname, and a key with a `.` or `..` segment is refused before
it is signed: something between here and the bucket would collapse it, and
`uploads/u1/../u2/x` is somebody else's file. `var_dump()` of a signer shows
no keys.

## `s3://` addresses

A plugin that keeps some files in a bucket and some on disk stores the bucket
ones as `s3://bucket/key`. One parser, so every plugin agrees on the edge
cases:

```php
use SugarCommerce\Storage\S3\Address;

Address::is( $path );                         // is it ours to sign at all?
Address::parse( 's3://bucket//packs/a.zip' ); // [ 'bucket' => 'bucket', 'key' => 'packs/a.zip' ]
Address::parse( 'bucket/a.zip' );             // null: without the scheme it is a local path
Address::parse( ' bucket/a.zip', true );      // typed input: the scheme is optional
Address::to( 'bucket', 'packs/a.zip' );       // "s3://bucket/packs/a.zip"
Address::host( 'https://key@S3.example.com/x' ); // "s3.example.com"
```

## A bucket

`S3\Bucket` is one bucket on one signer, through WordPress's HTTP API. Failures
come back as a `WP_Error` carrying the status and storage's own error code
(`SignatureDoesNotMatch`, `NoSuchBucket`) in its data, because which one it was
decides the fix.

```php
use SugarCommerce\Storage\S3\Bucket;

$bucket = new Bucket( $signer, 'my-bucket' );

$address = $bucket->upload_file( 'packs/Drum Kit.zip', $temp_file, [
	'mime_type'  => 'application/zip',
	'error_code' => 'my_plugin_upload_failed',
	'messages'   => [
		/* translators: %d: an HTTP status code. */
		'refused' => __( 'Your storage refused the file (%d).', 'my-plugin' ),
	],
] );

$info = $bucket->head( 'packs/Drum Kit.zip' ); // [ 'size' => 256901120, 'mime_type' => 'application/zip' ]
```

A file no larger than one part (16 MB by default, `part_size` to change it)
goes in a single PUT. A larger one goes as a multipart upload read from disk a
part at a time, with the part size raised so it never needs more than 9,000
parts. A part refused, a part answered without an ETag, or an assembly refused
-- including inside a 200, which is how S3 reports some failures -- abandons
the upload at the provider, so half a file is not left costing storage. A
missing file is an error, never an empty object.

### Uploads the browser does

```php
$upload = $bucket->start_upload( $key, 'audio/wav' );          // the id, or a WP_Error
$urls   = $bucket->sign_parts( $key, $upload, range( 1, $parts ) ); // hand to the browser

if ( is_wp_error( $bucket->finish_upload( $key, $upload, $etags ) ) ) {
	$bucket->abort_upload( $key, $upload );
}
```

`parts()` asks storage which parts it really kept (ListParts, every page), for
resuming after a reload. Null means the upload is finished, aborted or gone:
start again, rather than "nothing sent yet".

### Reading, checking, copying

```php
$size = $bucket->size( $key );           // what really arrived, not what the browser said
$head = $bucket->read( $key, 0, 65535 ); // for an inspection; a WP_Error, never '', on failure
$now  = $bucket->stat( $key );           // [ 'etag', 'size', 'modified' ], or null

if ( null === $now || $now !== $checked ) {
	// somebody else's bucket changed under you: check it again
}

$public->copy_from( $private, $key, 'previews/' . $key, 'audio/mpeg', 'public, max-age=31536000, immutable' );
$bucket->put( 'previews/' . $sha . '.json', $peaks, 'application/json' );
$bucket->download_file( 'backups/site.sql', $path ); // streamed to disk, never over a file
```

`read()`, `size()` and `stat()` never take in more than they asked for: a
backwards range is refused before any request, and the answer is capped with
`limit_response_size`, so a server that ignores the Range header cannot pull a
six-gigabyte object into memory to answer a one-byte question.

### Listing and tidying

```php
$page = $bucket->list( 'packs/' );                    // one level: objects and the folders under it
$page = $bucket->list( 'packs/', '', $page['next_token'] ); // no delimiter: everything, a page at a time

// [ 'objects' => [ [ 'key', 'size', 'last_modified', 'etag', 'storage_class' ] ],
//   'prefixes' => [ 'packs/drums/' ], 'truncated' => true, 'next_token' => '…' ]

$done = $bucket->delete_many( $keys );                // [ 'deleted' => [...], 'errors' => [ [ 'key', 'code', 'message' ] ] ]
$bucket->copy( 'packs/old.zip', 'packs/new.zip' );    // in place, type and metadata kept
$bucket->region();                                    // 'eu-west-2'; S3's empty answer is 'us-east-1'
$bucket->delete_cors();

Bucket::all( $signer );                               // every bucket the key may list: [ [ 'name', 'created' ] ]
```

`delete_many()` sends DeleteObjects a thousand keys at a time and reports key
by key. A provider that does not implement it refuses the request whole --
sometimes inside a 200 -- and that is a `WP_Error`, so a caller can fall back
to `delete()`. A key scoped to one bucket cannot call ListBuckets, and the 403
`Bucket::all()` gets is how a scoped key is told from a wrong one.
`Account::signer( $access, $secret )` gives the signer for an `Account`.

## CORS

Uploading from the browser is a cross-origin request, so the bucket has to
allow it -- and has to expose `ETag`, or every part of a multipart upload
arrives and the upload then fails at assembly with an error that mentions
neither.

```php
use SugarCommerce\Storage\S3\Cors\Analysis;
use SugarCommerce\Storage\S3\Cors\Origin;
use SugarCommerce\Storage\S3\Cors\Policy;
use SugarCommerce\Storage\S3\Cors\Rules;

$bucket->set_cors( Policy::for_browser_uploads( [ Origin::current() ] ) );

$policy = $bucket->cors()['policy'];               // an empty policy when none is set
$policy->supports_multipart_upload( Origin::current() ); // [ 'ok' => false, 'reason' => 'PUT is allowed but ETag is not exposed…' ]
$policy->to_console_json();                        // to paste into the R2 or S3 console

Rules::generate( 'mixed', [ Origin::current() ] ); // ready-made rules for a settings screen
Analysis::describe( $rules, Origin::current() );   // what they permit, in words
```

Setting CORS needs a key allowed to: one scoped to object read and write
cannot call PutBucketCors, and the AccessDenied it gets reads like a bucket
problem. `Origin::current()` reads the site's configured URL, never the Host
header, because the value is written into the bucket and stays there.

## The bucket browser

Files sold as downloads usually live in object storage rather than the media
library, and the moment they do, attaching one to a product means copying a
key out of another tab. `Browser` adds the bucket beside the media library:
buckets, folders, search, upload, rename, move, delete and a link back into
whatever field asked for it. Nothing is made public, and uploads go straight
from the browser to storage rather than through PHP.

```php
use SugarCommerce\Storage\Browser\Browser;
use SugarCommerce\Storage\S3\Account;

$browser = new Browser(
	Account::r2( $account_id ),
	$access_key,
	$secret_key,
	[ 'download' ],   // post types it appears for; [] means all
	'my-bucket',      // bucket it opens on
	'upload_files',   // capability required to use it
	'myshop'          // context: which plugin this instance serves
);

$browser->set_allowed_buckets( [ 'my-bucket' ] );
```

Confining it to one bucket is worth doing whenever you can: S3 keys usually
reach every bucket in the account, and the bucket arrives with each request.
Every REST route checks the capability, and every bucket route checks the
allow-list as well; a key with a `.` or `..` segment is refused before a
request is made.

`Account::aws( $region )`, `Account::regional( Provider::DigitalOcean, 'ams3' )`
and `Account::custom( 'minio.internal:9000' )` cover the other providers. Two
browsers on one site — two plugins, or two contexts — get separate media tabs,
REST routes, script handles and caches.

### The client

`Browser\Client` is what the routes and list tables ask, cached per bucket
and answered as an array or a `WP_Error` with storage's own code, its words
and a status a REST response can relay. Every request goes through
`S3\Bucket`; the browser has no S3 client of its own.

```php
$client = new SugarCommerce\Storage\Browser\Client( Account::r2( $account_id ), $access_key, $secret_key );

$client->get_buckets();                           // [ 'buckets' => [ [ 'name', 'created' ] ], 'scoped' => bool ]
$client->get_objects( 'my-bucket', 1000, 'packs/' ); // S3Object rows, folder prefixes, a continuation token
$client->object_exists( 'my-bucket', $key );      // [ 'exists', 'size', 'mime_type' ]
$client->rename_object( 'my-bucket', $from, $to );
$client->delete_folder( 'my-bucket', 'packs/old' ); // every page of it, DeleteObjects or one key at a time
$client->get_presigned_url( 'my-bucket', $key, 60 ); // minutes
$client->get_bucket_options();                    // [ name => name ] for a select
```

### Pages that are not post editors

A post editor is recognised by its post type. A screen of your own — a
settings page, a list, a panel — names its page instead and says where a
chosen file goes:

```php
add_action( 'admin_enqueue_scripts', function ( $hook ) use ( $browser ) {
	if ( 'toplevel_page_my-shop' === $hook ) {
		$browser->set_host_hook( $hook );
		wp_enqueue_media();
	}
}, 5 );
```

```js
window.S3BrowserHost = {
	insert: function ( files ) {
		// files: [ { fileName, bucket, key } ]
	}
};
```

The browser then appears as a tab in any media frame that page opens. The page
owns its fields, so it is asked rather than written into: throw from `insert`
and the frame stays open with the selection still in it. On the edit screens of
the `product` and `download` post types the browser fills the downloadable-files
table itself.

### Uploads need CORS

Uploading from the browser is a cross-origin request, so the bucket has to allow
it. The bucket's Details panel says whether it does for this site's origin and
offers to set the rule. Setting it needs an admin token: a key with Object Read
& Write cannot call `PutBucketCors`, and the error it gets back says
`AccessDenied`, which reads like a bucket problem rather than a key problem.

### Icons

Buckets, folders, file types and upload states are drawn as inline Heroicons
(outline, MIT, in `assets/icons/heroicons`), read through
`SugarCommerce\Plugin\Assets::contents()`. Every icon carries the class
`arraypress-icon`, so one stylesheet rule sizes them all.

### WP-CLI

A plugin that keeps a table of downloadable files implements `Cli\Files`
(find a product by reference, read and write its files) and registers the
commands under its own name:

```php
WP_CLI::add_command( 'myshop files', new SugarCommerce\Storage\Browser\Cli\Commands( $store, $browser_client ) );
```

```bash
wp myshop files verify                     # every referenced object is really in the bucket
wp myshop files manifest --all > files.txt # every referenced path, for rclone --files-from
wp myshop files assign files.csv           # dry run: what would change
wp myshop files assign files.csv --mode=sync --execute
```

`assign` reads a CSV of `file_name`, `file_path` and a reference column (`sku`,
`id`, `product`, `download` or `reference`). Modes are `append` (the default),
`sync`, which matches rows by name so a download keeps its id, and `replace`.

### Hooks

Every hook is named from the build's prefix, so two plugins bundling their own
copy never answer each other's filters: unprefixed it is
`sugarcommerce_storage_{name}`, in a Strauss build prefixed `MyPlugin\` it is
`myplugin_sugarcommerce_storage_{name}`. The ones marked *+context* run a second
time as `{name}_{context}`, so a plugin can change its own browser without
changing every browser on the site.

| Hook | Kind | |
|---|---|---|
| `browser_allowed_buckets` | filter | Buckets this instance may address (`$buckets, $provider_id, $context`) |
| `browser_config`, `browser_global_config` | filter, +context | The configuration handed to the scripts |
| `browser_translations` | filter, +context | The scripts' strings |
| `browser_insert_label` | filter, +context | The "Insert Selected" button |
| `browser_auto_load` | filter | Open the listing straight away |
| `allowed_mime_types` | filter, +context | What may be uploaded (from core's allowed types) |
| `s3_object_hidden_files` | filter | Names never listed (`.DS_Store`, `Thumbs.db`, `__MACOSX` and the like) |
| `s3_known_buckets_fallback` | filter | Buckets to show when the key cannot list them |
| `s3_connection_test_fallback_bucket` | filter | A bucket the connection test may read instead |
| `s3_object_references` | filter | Where an object is used, shown before it is renamed or deleted |
| `s3_object_renamed` | action | After a rename or move (`$bucket, $old_key, $new_key`) |

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
	return $bucket->url( key_for( $path ), 60, basename( $path ) );
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
file goes in from a copy fetched properly. Use a presigned URL and the
WordPress HTTP API's `stream` option, then add the copy:

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

## Cache, cleanup and casts

`get_transient()` returns `false` both when nothing is cached and when the
cached value *is* `false`, so the cache-or-compute pattern gets written by
hand, subtly wrong, in every plugin. `Cache::remember()` wraps the stored
value so the two are distinguishable.

```php
use SugarCommerce\Storage\Cache;
use SugarCommerce\Storage\Cast;
use SugarCommerce\Storage\Cleanup;

// Computed once an hour, and it can legitimately return false.
$eligible = Cache::remember( 'myplugin_eligible_' . $user_id, fn() => check( $user_id ) );

Cache::has( $key );    // true even when what is stored is false
Cache::get( $key, 'none' ); // what is kept -- false included -- or the default
Cache::set( $key, false );  // kept as false, not as nothing
Cache::forget( $key );

// On uninstall, take everything with you.
Cleanup::options( 'myplugin_' );
Cleanup::transients( 'myplugin_' );    // both halves, so nothing is orphaned
Cleanup::meta( '_myplugin_', 'post' );
```

The prefix goes through `esc_like()`, so the underscore in `myplugin_` is a
literal one rather than SQL's single-character wildcard.

Anything out of the options table is a string. `Cast` reads it back as what it
was meant to be:

```php
Cast::bool( 'false' );                 // false — PHP says true
Cast::int( get_option( 'limit' ), 5 );
Cast::ids( '[5,"7",0,5]' );            // [5, 7] from a JSON column
Cast::ids( '5, 7' );                   // [5, 7] from typed text
Cast::strings( $row->features, 'sanitize_text_field' );
```

| Method | Keeps | Drops |
|---|---|---|
| `Cast::array( $value )` | every entry, re-indexed | nothing (`null`, `''` and `false` are an empty list) |
| `Cast::ids( $value )` | positive integers (`absint`) | zero, duplicates, nested arrays |
| `Cast::strings( $value, $clean = null )` | trimmed, cleaned scalars | blanks, duplicates, nested arrays |

A string that opens with `[` or `{` is read as JSON. If that JSON is broken
the list is empty rather than a comma split of the brackets.

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

The bucket browser's own hooks are listed in its section above.

## Moving from arraypress/wp-*

Five libraries became this one, under `SugarCommerce\Storage\`, with the text domain `sugarcommerce`. Anything not listed keeps its name.

### From `arraypress/wp-s3-signer` (`ArrayPress\S3Signer`)

Everything else in `S3\` is new.

| Old | New |
|---|---|
| `ArrayPress\S3Signer\Signer` (every method, `MAX_EXPIRES`) | `SugarCommerce\Storage\S3\Signer`, same methods |
| `Enums\Provider` (cases, `label()`, `needs_account_id()`, `needs_endpoint()`, `default_region()`, `regions()`, `has_regions()`, `knows_region()`, `addressing()`, `endpoint()`, `signer()`, `options()`) | `S3\Provider`, same; `endpoint()` and `signer()` now refuse a region or account id that is not one DNS label |
| `Enums\AddressingStyle` (`Path`, `VirtualHosted`, `is_dns_compatible()`) | `S3\AddressingStyle` |
| `Enums\Method` (cases, `has_body()`) | `S3\Method` |
| `Models\SignedRequest` (`method`, `url`, `headers`, `body`, `curl_headers()`, `header()`) | `S3\SignedRequest` |
| `Support\Headers::canonicalize()`, `block()`, `names()` | `S3\Headers::…` |
| `Support\Endpoint::normalize()`, `host()`, `canonical_uri()`, `encode_key()`, `canonical_query()` | `S3\Endpoint::…`; `canonical_uri()` refuses `.`/`..` segments, `canonical_query()` sorts names as strings |
| `Support\Validate::bucket()`, `dns_label()`, `region()`, `host()`, `header_name()`, `header_value()`, `credential()` | `S3\Validate::…` (plus `segments()`) |
| `Support\ContentDisposition::attachment()`, `sanitize()`, `ascii_fallback()` | `S3\ContentDisposition::…` |
| `Support\Filename::basename()`, `extension()`, `stem()`, `clamp()`, `to_ascii()` | `Files\Filename::…` |
| `Support\Url::SCHEME` | `S3\Address::SCHEME` |
| `Support\Url::host( $url )` | `S3\Address::host( $url )` |
| `Support\Url::is_s3( $path )` | `S3\Address::is( $path )` |
| `Support\Url::parse_s3( $path, $optional )` | `S3\Address::parse( $path, $optional )` |
| `Support\Url::s3( $bucket, $key )` | `S3\Address::to( $bucket, $key )` |
| `Support\Objects::upload( $signer, $file, $bucket, $key, $args )` | `( new S3\Bucket( $signer, $bucket ) )->upload_file( $key, $file, $args )` -- same arguments and wording keys |
| `Support\Objects::head( $signer, $bucket, $key, $args )` | `( new S3\Bucket( $signer, $bucket ) )->head( $key, $args )` |
| `Support\Objects::PART_SIZE`, `MIN_PART_SIZE` | `S3\Bucket::PART_SIZE`, `MIN_PART_SIZE` |
| `Support\Transport::send()`, `is_success()` | `S3\Transport::…` (plus `request()` and `header()`) |
| `Data\Regions::for( $provider )` | `S3\Regions::for( $provider )` |

Behaviour that changed on the way: a single-PUT upload now stores its
`mime_type`; a part answered without an ETag, or an assembly refused inside a
200, now aborts the upload; a 404 `NoSuchBucket` from `Bucket::cors()` is an
error rather than "no policy".

### From `arraypress/wp-s3-browser` (`ArrayPress\S3`)

Everything not listed keeps its name under `SugarCommerce\Storage\Browser`
(`ArrayPress\S3\Client` is `SugarCommerce\Storage\Browser\Client`, and so on for
`Browser`, `Cache`, `Permissions`, `Admin\*`, `Rest\*`, `Tables\*`, `Cli\*`,
`Models\S3Object`, `Traits\Shared\Context`, `Traits\Shared\Debug`,
`Utils\Admin`, `Utils\Directory`, `Utils\Sanitize`, `Utils\Timestamp`,
`Utils\Validate`). The browser's second S3 client is gone; see
[Removed in the tidy-up](#removed-in-the-tidy-up). Its REST routes, their
payloads, `window.S3BrowserHost`, the scripts and the option keys are
unchanged.

| Old | New |
|---|---|
| `ArrayPress\S3\Provider` | `SugarCommerce\Storage\S3\Account` (same constructor and methods) |
| `ArrayPress\S3\Provider::r2()` / `aws()` / `regional()` / `custom()` | `Account::r2()` / `aws()` / `regional()` / `custom()` |
| `ArrayPress\S3Signer\Enums\Provider` (the `$type` argument) | `SugarCommerce\Storage\S3\Provider` |
| `new Browser( Provider $provider, … )` | `new Browser( Account $account, … )` |
| `Client::get_provider()` | `Client::get_account()` |
| `ArrayPress\S3\Cors\Analysis`, `Rules`, `Origin` | `SugarCommerce\Storage\S3\Cors\Analysis`, `Rules`, `Origin` |
| `Utils\Transport::is_transport_error()` / `explain()` | `SugarCommerce\Storage\S3\Transport::is_transport_error()` / `explain()` |
| `Utils\Encode::object_key( $key )` | `SugarCommerce\Storage\S3\Endpoint::request_key( $key )` |
| `Utils\Detect::is_s3_path( $path )` | `Browser\Utils\Parse::is_file( $path )` |
| `Utils\Parse::path( $path )` | `Browser\Utils\Parse::path( $path )` — now also reads `s3://` paths, which it refused before (so `verify` checked none of them) |
| `Utils\Shortcodes::has( $path )` | `Browser\Utils\Parse::has_shortcode( $path )` |
| `Utils\File::extension( $name )` | `SugarCommerce\Storage\Files\Filename::extension( $name )` |
| `Utils\File::name( $key )` | `Files\Filename::basename( $key )` |
| `Utils\File::mime_type( $name )` | `Files\Mime::of( $name )` |
| `Utils\File::category( $name )` | `Files\Mime::category( $name )` |
| `Utils\Mime::get_allowed_types( $context )` | `Files\Mime::allowed_types( $context )` |
| `Utils\Mime::get_allowed_extensions( $context )` | `Files\Mime::allowed_extensions( $context )` |
| `Utils\Mime::is_allowed_extension( $name, $context )` | `Files\Mime::is_allowed( $name, $context )` |
| `Admin\Icon::render()` / `file()` / `for_scripts()` | unchanged; always Heroicons, no wp-field-kit, no Dashicons fallback |
| Breadcrumbs through `arraypress/wp-breadcrumbs` | drawn by `Admin\MediaLibrary`, same markup |
| `arraypress_enqueue_composer_*()` (wp-composer-assets) | `SugarCommerce\Plugin\Assets::enqueue_style( $handle, __FILE__, $file )` / `enqueue_script()` / `url()` (sugarcommerce/plugin) |
| filter `s3_browser_allowed_buckets` | `{prefix}_browser_allowed_buckets` |
| filter `s3_browser_global_config` (+ `_{context}`) | `{prefix}_browser_global_config` (+ `_{context}`) |
| filter `s3_browser_config` (+ `_{context}`) | `{prefix}_browser_config` (+ `_{context}`) |
| filter `s3_browser_translations` (+ `_{context}`) | `{prefix}_browser_translations` (+ `_{context}`) |
| filter `s3_browser_insert_label` (+ `_{context}`) | `{prefix}_browser_insert_label` (+ `_{context}`) |
| filter `s3_browser_auto_load` | `{prefix}_browser_auto_load` |
| filter `s3_browser_allowed_mime_types_{context}` | `{prefix}_allowed_mime_types` and `{prefix}_allowed_mime_types_{context}` |
| filter `s3_object_hidden_files` | `{prefix}_s3_object_hidden_files` |
| filter `arraypress_s3_known_buckets_fallback` | `{prefix}_s3_known_buckets_fallback` |
| filter `arraypress_s3_connection_test_fallback_bucket` | `{prefix}_s3_connection_test_fallback_bucket` |
| filter `arraypress_s3_object_references` | `{prefix}_s3_object_references` |
| action `arraypress_s3_object_renamed` | `{prefix}_s3_object_renamed` |
| every other `arraypress_s3_{name}` (+ `_{context}`) | `{prefix}_s3_{name}` (+ `_{context}`) |
| handles `s3-browser-core`, `s3-browser-modals`, `s3-browser-files`, `s3-browser-folders`, `s3-browser-integrations`, `s3-browser-cors`, `s3-upload-script` | `{handle}-browser-core`, `-browser-modals`, `-browser-files`, `-browser-folders`, `-browser-integrations`, `-browser-cors`, `-browser-upload` |
| handles `s3-browser-global-config`, `s3-browser-host`, `s3-browser-wc`, `s3-browser-edd`, `s3-connection-test` | `{handle}-browser-config`, `-browser-host`, `-browser-wc`, `-browser-edd`, `-connection` |
| styles `s3-admin-components`, `s3-browser-style`, `s3-upload-style` | `{handle}-admin`, `-browser`, `-upload` |
| REST namespace `s3-browser/v1` (prefixed `{prefix}-s3-browser/v1`) | unchanged |
| JS `window.S3BrowserHost`, `S3BrowserGlobalConfig`, `s3BrowserConfig` | unchanged |
| text domain `arraypress` | `sugarcommerce` |

`{prefix}` is `sugarcommerce_storage` unprefixed (`myplugin_sugarcommerce_storage`
in a build prefixed `MyPlugin\`); `{handle}` is the same with hyphens.

### From `arraypress/wp-file-utils` (`ArrayPress\FileUtils`)

| Old | New |
|---|---|
| `Path::within( $directory, $candidate )` | `Files\Path::within( $directory, $candidate )` |
| `Path::is_absolute()`, `normalize()`, `from_url()`, `is_local()` | `Files\Path::` the same |
| `Path::to_url()` | `Files\Path::to_url()` -- uploads from `wp_upload_dir()`, everything else through `SugarCommerce\Plugin\Assets::path_to_url()`: the WordPress root maps to `site_url()` rather than `home_url()`, a symlinked plugin maps back, and a folder that merely starts with the uploads folder's name is no longer taken for it |
| `MIME::of()`, `extension_for()`, `must_download()`, `is_dangerous_inline()`, `is_media()`, `all()`, `extras()` | `Files\Mime::` the same (the class is `Mime`) |
| `Filename::with_extension()` | `Files\Filename::with_extension()` |
| `Filename::rename( $name, $original, $lower )` | `Files\Filename::rename()` — direction overrides are now removed too |
| `Filename::extension( $name )` | `Files\Filename::extension()` — letters and digits only, at most ten; a `#` in a plain filename is kept |

### From `arraypress/wp-storage-utils` (`ArrayPress\StorageUtils`)

| Old | New |
|---|---|
| `Cache::remember()`, `has()`, `forget()` | `SugarCommerce\Storage\Cache::` the same; values already cached are still read |
| `Cast::bool()`, `int()`, `float()`, `array()`, `ids()`, `strings()` | `SugarCommerce\Storage\Cast::` the same |
| `Cleanup::options()`, `transients()`, `meta()` | `SugarCommerce\Storage\Cleanup::` the same |

### From `arraypress/wp-protected-folders` (`ArrayPress\ProtectedFolders`)

| Old | New |
|---|---|
| `register_protected_folder( $id, $config )` | `Folders\Folders::register( $id, $config )` |
| `get_protected_folder( $id )` | `Folders\Folders::get( $id )` |
| `deliver_protected_file( $folder, $relative, $options )` | `Folders\Folders::deliver( $folder, $relative, $options )` |
| `deliver_file_at_path( $path, $options )` | `Folders\Delivery::send( $path, $options )` |
| `is_folder_protected( $id, $force )` | `Folders\Folders::is_protected( $id, $force )` |
| `get_protected_folder_server_rules( $id )` | `Folders\Folders::server_rules( $id )` |
| `Folder`, `Folders`, `Guard`, `Rules`, `Delivery` | `SugarCommerce\Storage\Folders\*`, same methods |
| `ArrayPress\ServerUtils\Server::has_xsendfile()` (soft dependency) | `Folders\Delivery::server_sends_files()` |
| filter `protected_folders_redirect_to` | `Runtime::hook( 'redirect_to' )` |
| filter `protected_folders_htaccess` | `Runtime::hook( 'htaccess' )` |
| filter `protected_folders_nginx_internal_location` | `Runtime::hook( 'nginx_internal_location' )` |
| filter `server_nginx_xsendfile` | `Runtime::hook( 'nginx_xsendfile' )` |
| transient `protected_folders_protected_{id}` | `sugarcommerce_storage_protected_{id}` (asked again once) |
| `Rules::MARKER` (`# Protected Folders`) | unchanged, so `.htaccess` files the old library wrote are recognised and kept current |

### Removed in the tidy-up

Nothing in SugarCart, its Lemon Squeezy add-on, edd-registrars, the EDD
plugins or the other SugarCommerce packages used these, by old or new name.

| Removed | Instead |
|---|---|
| `Images\{Generator, Size, Fit, Format, Variant, Source, EdgeUrl}` | removed: unused. `wp_get_image_editor()` for resizing, which has an Imagick fallback and EXIF rotation |
| `Scan\{Panel, Scanner, State, Subject, Verdict, Clamd, VirusTotal}` | removed: unused. `Files\Inspector` still checks what an upload is |
| `S3\Backups` | removed: unused. `S3\Bucket::upload_file()`, `list()`, `delete()` and `download_file()` |
| `S3\Connection` | removed: unused. `S3\Account` and `S3\Provider` for a bucket the site configures |
| `S3\Probe`, `ProbeCheck`, `ProbeFault`, `ProbeStep` | removed: unused. `Browser\Permissions::check()` (read, write, delete) and `Browser\Client::cors_allows_upload()` |
| `Utils\Assets` | `SugarCommerce\Plugin\Assets` (`enqueue_style( $handle, __FILE__, $file )`, `enqueue_script()`, `url()`, `contents()`, `path_to_url()`) |
| `Browser\Api`, `Interfaces\Api`, `Traits\Api\*`, `Traits\Shared\Timeouts` | `S3\Bucket` through `Client::bucket( $name )`, and `Client::signer()` (was `Client::api()`) |
| `Browser\Xml\{Parser, Builder, Extract, Response}` | `S3\Xml`, and Bucket's own readers |
| `Browser\Responses\*`, `Interfaces\Response`, `Abstracts\Response` | every `Client` method returns an array (or `true`, or a string) or a `WP_Error` with storage's code, its words and a REST `status` |
| `Models\S3Bucket` | `[ 'name', 'created' ]` from `Client::get_buckets()` / `S3\Bucket::all()` |
| `Models\S3Prefix` | the prefix string; `Utils\Directory::name( $prefix )` for its name |
| `Models\S3Object::get_dashicon_class()`, `to_array()` | removed: unused (`Admin\Icon::file()` draws the icon). It is now built from an `S3\Bucket::list()` row |
| `Traits\Client\*` | `Browser\Client`, one class |
| `Client::get_object_models()`, `get_objects()` | `Client::get_objects()`: `S3Object` rows (hidden files left out), prefix strings, `truncated`, `continuation_token` |
| `Client::get_bucket_models()`, `get_buckets( $max, $prefix, $marker )` | `Client::get_buckets( $use_cache )`: every bucket, no marker paging |
| `Client::put_object()`, `head_object()` | `Client::bucket( $name )->upload_file()` / `put()` / `head()` |
| `Client::copy_object()` | `Client::rename_object()`, or `S3\Bucket::copy()` |
| `Client::batch_delete_objects()`, `delete_folder_batch()`, `delete_folder( …, $recursive, $force )` | `Client::delete_folder( $bucket, $path )`: every page of keys, DeleteObjects, one key at a time where that is refused |
| `Client::get_cors_configuration()` | `Client::get_cors_rules()` |
| `Client::set_cors_configuration()`, `generate_cors_rules()` | `Client::set_cors_scenario()`, or `S3\Bucket::set_cors( S3\Cors\Rules::policy( … ) )` |
| `Client::get_bucket_location()` (a response) | the same, a string or a `WP_Error` |
| `Utils\Validate::filename()` | `Files\Filename::problem( $name )`: the reason, or `''` |
| `Utils\Validate::cors_rules()` | `S3\Cors\Policy::from_array()`, which refuses a bad rule |
| `Traits\Shared\Context::apply_contextual_filters()` and the hooks it fired: `s3_{operation}_params` / `_response` around every client call, `s3_cors_analysis_result`, `s3_cors_upload_check_result`, `s3_create_folder_params`, `s3_folder_created`, `s3_folder_placeholder_content`, `s3_delete_folder_params`, `s3_delete_folder_object`, `s3_folder_deleted` | removed: no consumer, and their values were the removed response objects |
| `Account::format_url()`, `build_url_with_encoded_key()`, `build_url_with_query()` | the signer's: `Account::signer( $access, $secret )->sign( … )->url` |

Behaviour that changed with it, all of it fixes: a copy refused inside a 200
no longer counts as a rename (the original stays); a bucket's region is read
(it always came back as the provider's default); a fallback bucket that does
not exist no longer passes the connection test or appears in the bucket list;
deleting a folder takes every page of keys, not the first thousand, in one
DeleteObjects per thousand; `Debug` reports storage's refusals rather than
raw requests; `Bucket::head()` puts the status in its error's data; and
`StorageError` keeps storage's own words as `reason`. One a caller has to
know: `new Client()` -- and so `new Browser()` -- throws an
`InvalidArgumentException` for an empty access key or secret, where it used
to build and then fail every request.

## From the retired SaaS packages

| Old | New |
|---|---|
| `SugarCommerce\ZipStream\Archive` | `SugarCommerce\Storage\Files\ZipStream` (plus `add_file( $path, $name, $time )`) |
| `SugarCommerce\ZipStream\Entry` | `SugarCommerce\Storage\Files\ZipEntry` |
| `SugarCommerce\FileInspect\Inspector` | `SugarCommerce\Storage\Files\Inspector` -- reasons translated |
| `SugarCommerce\FileInspect\Verdict` | `SugarCommerce\Storage\Files\Inspection` |
| `SugarCommerce\FileInspect\ZipDirectory` | `SugarCommerce\Storage\Files\ZipDirectory` |
| `SugarCommerce\FileInspect\Filename::refusal()` / `EXECUTABLE` | `Files\Filename::refusal()` / `Filename::EXECUTABLE` |
| `SugarCommerce\FileInspect\Filename::rename( $current, $typed, $max )` | `Files\Filename::relabel( $current, $typed, $max )` |
| `SugarCommerce\VirusScan\*`, `SugarCommerce\ImageVariants\*` | removed in the tidy-up: unused (see below) |

Left out:

* The zip-stream README's `$http->stream()` push example: it relied on
  http-client's connection pinning, which WordPress's HTTP API has no
  equivalent of. The WordPress way is above -- `stream` to a temporary file,
  then `add_file()`.
* From `s3-signer` and `object-storage`: the
  `sugarcommerce/http-client` dependency (its SSRF guard, connection pinning and
  byte caps -- WordPress's HTTP API does the requests, `limit_response_size`
  does the caps, and a bucket endpoint is operator configuration or a
  provider's own host); the `Signer::r2()`, `aws()`, `backblaze()`,
  `digitalocean()` and `minio()` factories (`Provider::X->signer()` is the one
  way); `AddressingStyle::is_valid_bucket()` (`Validate::bucket()` is the same
  rule); `#[\NoDiscard]` and its test; the benchmark scripts. Bucket answers
  with `WP_Error` where the SaaS one threw `StorageError` or returned `false`;
  `StorageError` is kept, and `to_wp_error()` is how Bucket builds them.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later
* `sugarcommerce/plugin`, for asset URLs
* Credentials for an S3-compatible provider, for anything in `S3\` or the browser

## License

GPL-2.0-or-later

The Heroicons in `assets/icons/heroicons` are MIT, © Tailwind Labs; their licence is beside them.
