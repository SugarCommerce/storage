/**
 * MD5, for the Content-MD5 of an upload part.
 *
 * The browser's own crypto has SHA but no MD5, and Content-MD5 is the
 * checksum every S3-compatible store checks a part against, refusing one
 * whose bytes changed on the way (BadDigest). Not for anything secret: MD5
 * is a checksum here, which is all it is still good for.
 *
 * window.S3BrowserMd5.base64( arrayBuffer ) -> the Content-MD5 header value
 * window.S3BrowserMd5.hex( arrayBuffer )    -> the familiar 32 hex digits
 *
 * Also loads under Node, for its tests.
 */
(function (root) {
    'use strict';

    // Per-round shift amounts.
    var S = [
        7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22,
        5, 9, 14, 20, 5, 9, 14, 20, 5, 9, 14, 20, 5, 9, 14, 20,
        4, 11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23,
        6, 10, 15, 21, 6, 10, 15, 21, 6, 10, 15, 21, 6, 10, 15, 21
    ];

    // The sine table: floor(abs(sin(i + 1)) * 2^32).
    var K = new Int32Array(64);

    for (var i = 0; i < 64; i++) {
        K[i] = Math.floor(Math.abs(Math.sin(i + 1)) * 4294967296) | 0;
    }

    /**
     * Read one 64-byte block as sixteen little-endian words.
     */
    function load(bytes, offset, words) {
        for (var j = 0; j < 16; j++) {
            var o = offset + j * 4;
            words[j] = bytes[o] | (bytes[o + 1] << 8) | (bytes[o + 2] << 16) | (bytes[o + 3] << 24);
        }
    }

    /**
     * Fold one block into the state.
     */
    function cycle(state, words) {
        var a = state[0], b = state[1], c = state[2], d = state[3];
        var f, g, x, t;

        for (var j = 0; j < 64; j++) {
            if (j < 16) {
                f = (b & c) | (~b & d);
                g = j;
            } else if (j < 32) {
                f = (d & b) | (~d & c);
                g = (5 * j + 1) & 15;
            } else if (j < 48) {
                f = b ^ c ^ d;
                g = (3 * j + 5) & 15;
            } else {
                f = c ^ (b | ~d);
                g = (7 * j) & 15;
            }

            t = d;
            d = c;
            c = b;
            x = (a + f + K[j] + words[g]) | 0;
            b = (b + ((x << S[j]) | (x >>> (32 - S[j])))) | 0;
            a = t;
        }

        state[0] = (state[0] + a) | 0;
        state[1] = (state[1] + b) | 0;
        state[2] = (state[2] + c) | 0;
        state[3] = (state[3] + d) | 0;
    }

    /**
     * The sixteen-byte digest of some bytes.
     *
     * @param {ArrayBuffer|Uint8Array} input The bytes.
     * @return {Uint8Array}
     */
    function digest(input) {
        var bytes = input instanceof Uint8Array ? input : new Uint8Array(input);
        var length = bytes.length;
        var state = new Int32Array([0x67452301, 0xefcdab89 | 0, 0x98badcfe | 0, 0x10325476]);
        var words = new Int32Array(16);
        var whole = length - (length % 64);
        var offset;

        for (offset = 0; offset < whole; offset += 64) {
            load(bytes, offset, words);
            cycle(state, words);
        }

        // What is left, a 1 bit, zeros, and the length in bits as a 64-bit
        // little-endian number: one block, or two when the length will not
        // fit after what is left.
        var rest = length - whole;
        var tail = new Uint8Array(rest < 56 ? 64 : 128);
        var low = (length % 0x20000000) * 8;
        var high = Math.floor(length / 0x20000000);
        var end = tail.length - 8;

        tail.set(bytes.subarray(whole));
        tail[rest] = 0x80;

        for (var k = 0; k < 4; k++) {
            tail[end + k] = (low >>> (8 * k)) & 255;
            tail[end + 4 + k] = (high >>> (8 * k)) & 255;
        }

        for (offset = 0; offset < tail.length; offset += 64) {
            load(tail, offset, words);
            cycle(state, words);
        }

        var out = new Uint8Array(16);

        for (var n = 0; n < 4; n++) {
            out[n * 4] = state[n] & 255;
            out[n * 4 + 1] = (state[n] >>> 8) & 255;
            out[n * 4 + 2] = (state[n] >>> 16) & 255;
            out[n * 4 + 3] = (state[n] >>> 24) & 255;
        }

        return out;
    }

    var api = {
        digest: digest,

        /**
         * The Content-MD5 header value: the digest, base64 encoded.
         */
        base64: function (input) {
            var out = digest(input);
            var text = '';

            for (var b = 0; b < out.length; b++) {
                text += String.fromCharCode(out[b]);
            }

            return typeof btoa === 'function' ? btoa(text) : Buffer.from(text, 'binary').toString('base64');
        },

        /**
         * The digest as 32 hex digits.
         */
        hex: function (input) {
            var out = digest(input);
            var text = '';

            for (var b = 0; b < out.length; b++) {
                text += (out[b] < 16 ? '0' : '') + out[b].toString(16);
            }

            return text;
        }
    };

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.S3BrowserMd5 = api;
    }
})(typeof window !== 'undefined' ? window : this);
