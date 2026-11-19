/**
 * The browser's multipart uploader and its MD5, run under Node.
 *
 * assets/js/browser/md5.js is checked against Node's own MD5 at every
 * length that matters to the padding. assets/js/browser/upload.js is loaded
 * with just enough of a browser around it -- jQuery's ajax, XMLHttpRequest,
 * a File -- answered by a fake server and a fake bucket, and driven through
 * whole uploads: in parts, retried, interrupted and resumed, without
 * checksums when CORS refuses them, cancelled.
 *
 *     node tests/js/upload.js
 */
'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..', 'assets', 'js', 'browser');
const md5 = require(path.join(root, 'md5.js'));

let failures = 0;
let passed = 0;
const tests = [];

function test(name, fn) {
    tests.push([name, fn]);
}

/* ─── MD5 ────────────────────────────────────────────────────────────── */

test('md5 matches the RFC 1321 vectors', () => {
    const vectors = {
        '': 'd41d8cd98f00b204e9800998ecf8427e',
        'a': '0cc175b9c0f1b6a831c399e269772661',
        'abc': '900150983cd24fb0d6963f7d28e17f72',
        'message digest': 'f96b697d7cb7938d525a2f31aaf161d0',
        'abcdefghijklmnopqrstuvwxyz': 'c3fcd3d76192e4007dfb496cca67e13b',
        '12345678901234567890123456789012345678901234567890123456789012345678901234567890': '57edf4a22be3c955ac49da2e2107b67a'
    };

    for (const [text, hex] of Object.entries(vectors)) {
        assert.equal(md5.hex(Buffer.from(text)), hex, JSON.stringify(text));
    }
});

test('md5 agrees with Node at every padding boundary and beyond', () => {
    for (const length of [1, 54, 55, 56, 57, 63, 64, 65, 119, 120, 127, 128, 129, 1000, 65537, 1048576 + 3]) {
        const bytes = crypto.randomBytes(length);
        const expected = crypto.createHash('md5').update(bytes).digest('base64');

        assert.equal(md5.base64(new Uint8Array(bytes).buffer), expected, 'length ' + length);
    }
});

/* ─── A browser around upload.js ─────────────────────────────────────── */

const STRINGS = {
    uploadCancelled: 'Upload cancelled',
    networkError: 'Network error',
    uploadFailedStatus: 'Upload failed with status',
    failedPresignedUrl: 'Failed to get upload URL',
    resuming: 'Resuming: {done} of {total}',
    assembling: 'Assembling',
    retrying: 'Retrying {part}',
    noChecksums: 'No checksums',
    tooLarge: '{filename} too large',
    partFailed: 'Part {part} failed: {reason}',
    resumeHint: 'Drop it again.'
};

/**
 * A bucket and the WordPress routes in front of it.
 */
function world(options) {
    const state = {
        partSize: options.partSize,
        uploads: {},
        objects: {},
        puts: [],
        ajax: [],
        faults: options.faults || [],
        corsRefusesMd5: !!options.corsRefusesMd5,
        serial: 0
    };

    function part(id, number) {
        return state.uploads[id] && state.uploads[id].parts[number];
    }

    // The REST routes, as Controller answers them.
    function route(method, url, body) {
        const match = /\/buckets\/([^/]+)\/uploads(?:\/([a-f0-9]+))?(?:\/(parts|complete))?$/.exec(url);
        const id = match[2];
        const action = match[3];

        state.ajax.push(method + ' ' + (action || (id ? 'upload' : 'start')));

        if (method === 'POST' && !id) {
            const existing = Object.keys(state.uploads).find((key) => state.uploads[key].fingerprint === body.fingerprint);
            const upload = existing ? state.uploads[existing] : {
                id: (++state.serial).toString(16).padStart(32, '0'),
                key: body.key,
                size: body.size,
                fingerprint: body.fingerprint,
                parts: {}
            };

            state.uploads[upload.id] = upload;

            return [existing ? 200 : 201, {
                id: upload.id,
                part_size: state.partSize,
                part_count: Math.ceil(upload.size / state.partSize),
                done: Object.keys(upload.parts).map(Number),
                resumed: !!existing,
                checksums: !state.corsRefusesMd5
            }];
        }

        const upload = state.uploads[id];

        if (!upload) {
            return [404, {code: 'rest_upload_not_found', message: 'gone'}];
        }

        if (method === 'POST' && action === 'parts') {
            const urls = {};

            body.parts.forEach((number) => {
                urls[number] = 'https://bucket.example/' + upload.key + '?partNumber=' + number + '&uploadId=' + id + '&sig=' + (++state.serial);
            });

            return [200, {urls: urls}];
        }

        if (method === 'POST' && action === 'complete') {
            const count = Math.ceil(upload.size / state.partSize);
            const missing = [];

            for (let n = 1; n <= count; n++) {
                const expected = Math.min(state.partSize, upload.size - (n - 1) * state.partSize);

                if (!upload.parts[n] || upload.parts[n].length !== expected) {
                    missing.push(n);
                }
            }

            if (missing.length) {
                return [409, {code: 's3_upload_incomplete', message: 'missing', data: {status: 409, missing: missing}}];
            }

            const chunks = [];

            for (let n = 1; n <= count; n++) {
                chunks.push(upload.parts[n]);
            }

            state.objects[upload.key] = Buffer.concat(chunks);
            delete state.uploads[id];

            return [200, {address: 's3://bucket/' + upload.key}];
        }

        if (method === 'DELETE') {
            delete state.uploads[id];

            return [200, {aborted: true}];
        }

        return [400, {message: 'unknown route'}];
    }

    const $ = function () {
        throw new Error('No DOM in these tests.');
    };

    $.extend = Object.assign;
    $.ajax = function (request) {
        const body = request.data ? JSON.parse(request.data) : undefined;

        setImmediate(() => {
            const [status, payload] = route(request.method, request.url, body);

            if (status >= 200 && status < 300) {
                request.success(payload);
            } else {
                request.error({status: status, responseJSON: payload});
            }
        });
    };

    class FakeXhr {
        constructor() {
            this.listeners = {};
            this.headers = {};
            this.upload = {addEventListener: (name, fn) => { this.uploadProgress = fn; }};
            this.status = 0;
            this.responseText = '';
        }

        addEventListener(name, fn) {
            this.listeners[name] = fn;
        }

        open(method, url) {
            this.method = method;
            this.url = new URL(url);
        }

        setRequestHeader(name, value) {
            this.headers[name.toLowerCase()] = value;
        }

        abort() {
            this.aborted = true;
            setImmediate(() => this.listeners.abort && this.listeners.abort());
        }

        send(bytes) {
            const id = this.url.searchParams.get('uploadId');
            const number = Number(this.url.searchParams.get('partNumber'));
            const body = Buffer.from(bytes);
            const record = {number: number, md5: this.headers['content-md5'] || '', size: body.length};

            state.puts.push(record);

            if (options.onPut) {
                options.onPut(state);
            }

            setImmediate(() => {
                if (this.aborted) {
                    return;
                }

                // The browser refuses the request itself when the preflight
                // does not allow a header: no status at all.
                if (state.corsRefusesMd5 && this.headers['content-md5']) {
                    this.listeners.error();
                    return;
                }

                const fault = state.faults.find((f) => f.part === number && f.times > 0);

                if (fault) {
                    fault.times--;

                    if (fault.keep) {
                        state.uploads[id].parts[number] = body;
                    }

                    if (fault.status === 0) {
                        this.listeners.error();
                    } else {
                        this.status = fault.status;
                        this.responseText = '<Error><Message>' + (fault.message || 'nope') + '</Message></Error>';
                        this.listeners.load();
                    }

                    return;
                }

                if (!state.uploads[id]) {
                    this.status = 404;
                    this.responseText = '<Error><Message>NoSuchUpload</Message></Error>';
                    this.listeners.load();
                    return;
                }

                if (record.md5 && record.md5 !== crypto.createHash('md5').update(body).digest('base64')) {
                    this.status = 400;
                    this.responseText = '<Error><Message>BadDigest</Message></Error>';
                    this.listeners.load();
                    return;
                }

                this.uploadProgress && this.uploadProgress({loaded: body.length, total: body.length});
                state.uploads[id].parts[number] = body;
                this.status = 200;
                this.listeners.load();
            });
        }
    }

    const sandbox = {
        window: {},
        jQuery: $,
        XMLHttpRequest: FakeXhr,
        Promise: Promise,
        Date: Date,
        Math: Math,
        Object: Object,
        Error: Error,
        Array: Array,
        // Backoff waits are real waits in a browser; here they are not.
        setTimeout: (fn) => setImmediate(fn),
        console: console
    };

    sandbox.window = sandbox;
    sandbox.window.S3Browser = {
        formatFileSize: (n) => String(n),
        escapeHtml: (t) => t,
        icon: () => ''
    };
    sandbox.window.S3BrowserMd5 = md5;
    sandbox.S3BrowserGlobalConfig = {
        restUrl: 'https://site.example/wp-json/s3-browser/v1/r2',
        restNonce: 'n',
        multipart: Object.assign({threshold: 10, concurrency: 3, signBatch: 4, retries: 3}, options.settings || {})
    };
    sandbox.s3BrowserConfig = {i18n: {upload: STRINGS}};

    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(path.join(root, 'upload.js'), 'utf8'), sandbox);

    return {state: state, browser: sandbox.window.S3Browser};
}

function file(size, name) {
    const bytes = crypto.randomBytes(size);

    return {bytes: bytes, file: new File([bytes], name || 'pack.zip', {lastModified: 1700000000000})};
}

/* ─── Uploads ────────────────────────────────────────────────────────── */

test('a file over the threshold goes in parts, each with its Content-MD5', async () => {
    const {state, browser} = world({partSize: 1000});
    const {bytes, file: f} = file(24500);

    assert.equal(browser.usesParts(f), true);
    assert.equal(browser.usesParts(new File(['tiny'], 't.txt')), false);

    const result = await browser.uploadInParts(f, 'files', 'packs/pack.zip', null, 'row1');

    assert.equal(result.address, 's3://bucket/packs/pack.zip');
    assert.deepEqual(state.objects['packs/pack.zip'], bytes);
    assert.equal(state.puts.length, 25);
    state.puts.forEach((put) => assert.notEqual(put.md5, ''));
    // Signed in batches, not part by part.
    assert.ok(state.ajax.filter((a) => a === 'POST parts').length < 25);
});

test('a file past one PUT\'s limit goes in parts whatever the threshold', () => {
    const {browser} = world({partSize: 1000, settings: {threshold: 1e15, singlePutMax: 50}});

    assert.equal(browser.usesParts(new File([Buffer.alloc(51)], 'a.bin')), true);
    assert.equal(browser.usesParts(new File([Buffer.alloc(50)], 'a.bin')), false);
});

test('a part that fails for a moment is sent again', async () => {
    const {state, browser} = world({partSize: 1000, faults: [{part: 3, times: 1, status: 503}, {part: 5, times: 2, status: 0}]});
    const {bytes, file: f} = file(8000);

    await browser.uploadInParts(f, 'files', 'a.zip', null, 'row');

    assert.deepEqual(state.objects['a.zip'], bytes);
    assert.equal(state.puts.filter((p) => p.number === 3).length, 2);
    assert.equal(state.puts.filter((p) => p.number === 5).length, 3);
});

test('an expired URL is signed again', async () => {
    const {state, browser} = world({partSize: 1000, faults: [{part: 2, times: 1, status: 403, message: 'Request has expired'}]});
    const {bytes, file: f} = file(4000);

    await browser.uploadInParts(f, 'files', 'a.zip', null, 'row');

    assert.deepEqual(state.objects['a.zip'], bytes);
    assert.ok(state.ajax.filter((a) => a === 'POST parts').length >= 2);
});

test('an interrupted upload resumes from the parts storage kept', async () => {
    const {state, browser} = world({partSize: 1000, settings: {concurrency: 1}, faults: [{part: 4, times: 3, status: 0}]});
    const {bytes, file: f} = file(10000);

    await assert.rejects(browser.uploadInParts(f, 'files', 'big.zip', null, 'row'), /Part 4 failed/);
    assert.equal(state.objects['big.zip'], undefined);

    const kept = Object.keys(Object.values(state.uploads)[0].parts).map(Number);
    assert.deepEqual(kept, [1, 2, 3]);

    state.puts.length = 0;

    // The same file dropped again.
    const again = await browser.uploadInParts(f, 'files', 'big.zip', null, 'row2');

    assert.equal(again.address, 's3://bucket/big.zip');
    assert.deepEqual(state.objects['big.zip'], bytes);
    assert.deepEqual(state.puts.map((p) => p.number).sort((a, b) => a - b), [4, 5, 6, 7, 8, 9, 10]);
});

test('a part whose answer was lost is not sent again on resume', async () => {
    const {state, browser} = world({partSize: 1000, settings: {concurrency: 1, retries: 1}, faults: [{part: 2, times: 1, status: 0, keep: true}]});
    const {bytes, file: f} = file(3000);

    await assert.rejects(browser.uploadInParts(f, 'files', 'k.zip', null, 'row'));

    state.puts.length = 0;
    await browser.uploadInParts(f, 'files', 'k.zip', null, 'row2');

    assert.deepEqual(state.objects['k.zip'], bytes);
    assert.deepEqual(state.puts.map((p) => p.number), [3]);
});

test('parts storage lost before assembly are sent again, once', async () => {
    const {state, browser} = world({partSize: 1000});
    const {bytes, file: f} = file(3500);
    const complete = browser.completeParts.bind(browser);
    let dropped = false;

    browser.completeParts = function (job, mayRetry) {
        if (!dropped) {
            dropped = true;
            delete state.uploads[job.id].parts[2];
        }

        return complete(job, mayRetry);
    };

    await browser.uploadInParts(f, 'files', 'l.zip', null, 'row');

    assert.deepEqual(state.objects['l.zip'], bytes);
    assert.equal(state.puts.filter((p) => p.number === 2).length, 2);
});

test('a bucket whose CORS refuses Content-MD5 still gets the file, without checksums', async () => {
    const {state, browser} = world({partSize: 1000, corsRefusesMd5: true});
    const {bytes, file: f} = file(5000);

    await browser.uploadInParts(f, 'files', 'm.zip', null, 'row');

    assert.deepEqual(state.objects['m.zip'], bytes);
    assert.equal(state.puts.length, 5);
    assert.ok(state.puts.every((p) => p.md5 === ''));
});

test('a damaged part is refused by its checksum and not kept', async () => {
    const {state, browser} = world({partSize: 1000, settings: {retries: 1}});
    const {file: f} = file(2000);
    const original = md5.base64;

    // A part changed on its way: the header says one thing, the bytes another.
    browser.partSettings = () => ({threshold: 10, singlePutMax: 1e12, maxSize: 1e15, concurrency: 1, signBatch: 4, retries: 1});
    md5.base64 = () => crypto.createHash('md5').update('something else').digest('base64');

    try {
        await assert.rejects(browser.uploadInParts(f, 'files', 'd.zip', null, 'row'), /BadDigest/);
    } finally {
        md5.base64 = original;
    }

    assert.deepEqual(Object.keys(Object.values(state.uploads)[0].parts), []);
});

test('cancelling stops the parts and frees the upload in storage', async () => {
    const held = {};
    const {state, browser} = world({
        partSize: 1000,
        settings: {concurrency: 1},
        // The person presses Cancel while the third part is on its way.
        onPut: (s) => s.puts.length === 3 && held.browser.activeUploads.row.abort()
    });
    const {file: f} = file(50000);

    held.browser = browser;

    await assert.rejects(browser.uploadInParts(f, 'files', 'c.zip', null, 'row'), /Upload cancelled/);
    await new Promise((resolve) => setTimeout(resolve, 5));

    assert.ok(state.ajax.includes('DELETE upload'));
    assert.deepEqual(state.uploads, {});
    assert.equal(state.puts.length, 3);
});

test('a file larger than storage keeps is refused before anything is sent', async () => {
    const {state, browser} = world({partSize: 1000, settings: {maxSize: 100}});
    const {file: f} = file(101, 'huge.bin');

    await assert.rejects(browser.uploadInParts(f, 'files', 'h.bin', null, 'row'), /huge\.bin too large/);
    assert.deepEqual(state.ajax, []);
});

(async () => {
    for (const [name, fn] of tests) {
        try {
            await fn();
            passed++;
            console.log('ok - ' + name);
        } catch (error) {
            failures++;
            console.log('not ok - ' + name);
            console.log(error && error.stack ? error.stack : error);
        }
    }

    console.log('\n' + passed + ' passed, ' + failures + ' failed');
    process.exit(failures ? 1 : 0);
})();
