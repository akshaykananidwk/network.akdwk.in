/* ---------------------------------------------------------------------------
 * Minimal QR encoder — byte mode, error-correction level M, versions 1–10.
 *
 * Exists because the Content-Security-Policy forbids third-party scripts, and
 * because sending a TOTP secret to an external QR service to be turned into an
 * image would be indefensible. Versions 1–10 cover ~200 bytes at level M; an
 * otpauth:// URI is ~100–150, comfortably inside that.
 *
 * Implements ISO/IEC 18004: data encoding, Reed–Solomon error correction,
 * block interleaving, the eight mask patterns with the standard penalty
 * scoring, and format/version information.
 * --------------------------------------------------------------------------- */

(function () {
    'use strict';

    /* --------------------------------------------- Galois field GF(256) */

    var EXP = new Uint8Array(512);
    var LOG = new Uint8Array(256);

    (function initGaloisField() {
        var x = 1;
        for (var i = 0; i < 255; i++) {
            EXP[i] = x;
            LOG[x] = i;
            x <<= 1;
            if (x & 0x100) { x ^= 0x11D; } // the QR generator polynomial
        }
        for (var j = 255; j < 512; j++) { EXP[j] = EXP[j - 255]; }
    })();

    function gfMul(a, b) {
        if (a === 0 || b === 0) { return 0; }
        return EXP[LOG[a] + LOG[b]];
    }

    /** Generator polynomial for `degree` error-correction codewords. */
    function rsGenerator(degree) {
        var poly = [1];
        for (var i = 0; i < degree; i++) {
            var next = new Array(poly.length + 1).fill(0);
            for (var j = 0; j < poly.length; j++) {
                next[j] ^= gfMul(poly[j], 1);
                next[j + 1] ^= gfMul(poly[j], EXP[i]);
            }
            poly = next;
        }
        return poly;
    }

    function rsEncode(data, eccCount) {
        var generator = rsGenerator(eccCount);
        var remainder = new Array(eccCount).fill(0);

        for (var i = 0; i < data.length; i++) {
            var factor = data[i] ^ remainder[0];
            remainder.shift();
            remainder.push(0);
            for (var j = 0; j < eccCount; j++) {
                remainder[j] ^= gfMul(generator[j + 1], factor);
            }
        }
        return remainder;
    }

    /* ------------------------------------------------ version parameters */

    /*
     * Per version (1–10) at ECC level M:
     *   [total data codewords, ecc codewords per block, group1 blocks,
     *    group1 data codewords, group2 blocks, group2 data codewords]
     * Values are from ISO/IEC 18004 Table 9.
     */
    var VERSIONS_M = {
        1:  [16,  10, 1, 16,  0, 0],
        2:  [28,  16, 1, 28,  0, 0],
        3:  [44,  26, 1, 44,  0, 0],
        4:  [64,  18, 2, 32,  0, 0],
        5:  [86,  24, 2, 43,  0, 0],
        6:  [108, 16, 4, 27,  0, 0],
        7:  [124, 18, 4, 31,  0, 0],
        8:  [154, 22, 2, 38,  2, 39],
        9:  [182, 22, 3, 36,  2, 37],
        10: [216, 26, 4, 43,  1, 44]
    };

    /* Alignment-pattern centre coordinates per version. */
    var ALIGNMENT = {
        1: [], 2: [6, 18], 3: [6, 22], 4: [6, 26], 5: [6, 30],
        6: [6, 34], 7: [6, 22, 38], 8: [6, 24, 42], 9: [6, 26, 46], 10: [6, 28, 50]
    };

    function sizeForVersion(version) { return version * 4 + 17; }

    /* ------------------------------------------------------ data encoding */

    function toUtf8Bytes(text) {
        var encoded = unescape(encodeURIComponent(text));
        var bytes = new Array(encoded.length);
        for (var i = 0; i < encoded.length; i++) { bytes[i] = encoded.charCodeAt(i); }
        return bytes;
    }

    function chooseVersion(byteLength) {
        for (var version = 1; version <= 10; version++) {
            var capacity = VERSIONS_M[version][0];
            // 4 bits mode + 8 or 16 bits length + data
            var headerBits = 4 + (version < 10 ? 8 : 16);
            if (Math.ceil((headerBits + byteLength * 8) / 8) <= capacity) {
                return version;
            }
        }
        throw new Error('Too much data for a version 10 QR code.');
    }

    function buildDataCodewords(bytes, version) {
        var spec = VERSIONS_M[version];
        var totalData = spec[0];

        var bits = [];
        function push(value, length) {
            for (var i = length - 1; i >= 0; i--) { bits.push((value >>> i) & 1); }
        }

        push(0x4, 4);                                   // byte mode
        push(bytes.length, version < 10 ? 8 : 16);      // character count
        bytes.forEach(function (byte) { push(byte, 8); });

        // Terminator, then pad to a byte boundary.
        var capacityBits = totalData * 8;
        for (var t = 0; t < 4 && bits.length < capacityBits; t++) { bits.push(0); }
        while (bits.length % 8 !== 0) { bits.push(0); }

        var codewords = [];
        for (var i = 0; i < bits.length; i += 8) {
            var byte = 0;
            for (var b = 0; b < 8; b++) { byte = (byte << 1) | bits[i + b]; }
            codewords.push(byte);
        }

        // Alternating pad bytes, as the standard specifies.
        var pad = [0xEC, 0x11];
        var padIndex = 0;
        while (codewords.length < totalData) {
            codewords.push(pad[padIndex++ % 2]);
        }

        return codewords;
    }

    /** Split into blocks, add ECC, and interleave as the standard requires. */
    function interleave(codewords, version) {
        var spec = VERSIONS_M[version];
        var eccPerBlock = spec[1];
        var group1Blocks = spec[2], group1Size = spec[3];
        var group2Blocks = spec[4], group2Size = spec[5];

        var blocks = [];
        var offset = 0;

        for (var i = 0; i < group1Blocks; i++) {
            blocks.push(codewords.slice(offset, offset + group1Size));
            offset += group1Size;
        }
        for (var j = 0; j < group2Blocks; j++) {
            blocks.push(codewords.slice(offset, offset + group2Size));
            offset += group2Size;
        }

        var eccBlocks = blocks.map(function (block) { return rsEncode(block, eccPerBlock); });

        var result = [];
        var maxDataLength = Math.max(group1Size, group2Size || 0);

        for (var c = 0; c < maxDataLength; c++) {
            for (var b = 0; b < blocks.length; b++) {
                if (c < blocks[b].length) { result.push(blocks[b][c]); }
            }
        }
        for (var e = 0; e < eccPerBlock; e++) {
            for (var eb = 0; eb < eccBlocks.length; eb++) {
                result.push(eccBlocks[eb][e]);
            }
        }

        return result;
    }

    /* ------------------------------------------------------ matrix layout */

    function createMatrix(version) {
        var size = sizeForVersion(version);
        var modules = [];
        var reserved = [];

        for (var i = 0; i < size; i++) {
            modules.push(new Array(size).fill(0));
            reserved.push(new Array(size).fill(false));
        }

        function placeFinder(row, col) {
            for (var r = -1; r <= 7; r++) {
                for (var c = -1; c <= 7; c++) {
                    var rr = row + r, cc = col + c;
                    if (rr < 0 || rr >= size || cc < 0 || cc >= size) { continue; }
                    var isDark = (r >= 0 && r <= 6 && (c === 0 || c === 6))
                        || (c >= 0 && c <= 6 && (r === 0 || r === 6))
                        || (r >= 2 && r <= 4 && c >= 2 && c <= 4);
                    modules[rr][cc] = isDark ? 1 : 0;
                    reserved[rr][cc] = true;
                }
            }
        }

        placeFinder(0, 0);
        placeFinder(0, size - 7);
        placeFinder(size - 7, 0);

        // Timing patterns.
        for (var t = 8; t < size - 8; t++) {
            modules[6][t] = t % 2 === 0 ? 1 : 0;
            modules[t][6] = t % 2 === 0 ? 1 : 0;
            reserved[6][t] = true;
            reserved[t][6] = true;
        }

        /*
         * Alignment patterns sit at every combination of the version's centre
         * coordinates, except the three that would land on a finder.
         *
         * The test has to be that explicit: a pattern centred on row 6 or
         * column 6 legitimately overlaps the timing pattern (their modules
         * agree there by construction), so "is this cell already reserved?"
         * wrongly skips those and produces a symbol that will not scan.
         */
        var centres = ALIGNMENT[version];
        if (centres.length > 0) {
            var first = centres[0];
            var last = centres[centres.length - 1];

            centres.forEach(function (row) {
                centres.forEach(function (col) {
                    var onFinder = (row === first && col === first)
                        || (row === first && col === last)
                        || (row === last && col === first);
                    if (onFinder) { return; }

                    for (var r = -2; r <= 2; r++) {
                        for (var c = -2; c <= 2; c++) {
                            modules[row + r][col + c] =
                                (Math.abs(r) === 2 || Math.abs(c) === 2 || (r === 0 && c === 0)) ? 1 : 0;
                            reserved[row + r][col + c] = true;
                        }
                    }
                });
            });
        }

        // The dark module, always set.
        modules[size - 8][8] = 1;
        reserved[size - 8][8] = true;

        // Reserve the format-information areas.
        for (var f = 0; f < 9; f++) {
            if (!reserved[8][f]) { reserved[8][f] = true; }
            if (!reserved[f][8]) { reserved[f][8] = true; }
        }
        for (var g = 0; g < 8; g++) {
            reserved[8][size - 1 - g] = true;
            reserved[size - 1 - g][8] = true;
        }

        // Versions 7 and up carry version information in two 3x6 blocks;
        // without reserving them, data would be written where they belong.
        if (version >= 7) {
            for (var v = 0; v < 18; v++) {
                var vr = Math.floor(v / 3);
                var vc = v % 3;
                reserved[vr][size - 11 + vc] = true;
                reserved[size - 11 + vc][vr] = true;
            }
        }

        return { modules: modules, reserved: reserved, size: size };
    }

    /** Zig-zag placement, two columns at a time, skipping the timing column. */
    function placeData(matrix, codewords) {
        var size = matrix.size;
        var bitIndex = 0;
        var totalBits = codewords.length * 8;
        var upward = true;

        for (var right = size - 1; right >= 1; right -= 2) {
            if (right === 6) { right = 5; } // skip the vertical timing pattern

            for (var vert = 0; vert < size; vert++) {
                var row = upward ? size - 1 - vert : vert;

                for (var col = 0; col < 2; col++) {
                    var c = right - col;
                    if (matrix.reserved[row][c]) { continue; }

                    var bit = 0;
                    if (bitIndex < totalBits) {
                        bit = (codewords[bitIndex >> 3] >>> (7 - (bitIndex & 7))) & 1;
                        bitIndex++;
                    }
                    matrix.modules[row][c] = bit;
                }
            }
            upward = !upward;
        }
    }

    var MASKS = [
        function (r, c) { return (r + c) % 2 === 0; },
        function (r) { return r % 2 === 0; },
        function (r, c) { return c % 3 === 0; },
        function (r, c) { return (r + c) % 3 === 0; },
        function (r, c) { return (Math.floor(r / 2) + Math.floor(c / 3)) % 2 === 0; },
        function (r, c) { return ((r * c) % 2) + ((r * c) % 3) === 0; },
        function (r, c) { return (((r * c) % 2) + ((r * c) % 3)) % 2 === 0; },
        function (r, c) { return (((r + c) % 2) + ((r * c) % 3)) % 2 === 0; }
    ];

    function applyMask(matrix, maskIndex) {
        var masked = matrix.modules.map(function (row) { return row.slice(); });
        for (var r = 0; r < matrix.size; r++) {
            for (var c = 0; c < matrix.size; c++) {
                if (!matrix.reserved[r][c] && MASKS[maskIndex](r, c)) {
                    masked[r][c] ^= 1;
                }
            }
        }
        return masked;
    }

    /** The four penalty rules from the standard. */
    function penalty(modules, size) {
        var score = 0;
        var r, c, run, i;

        // Rule 1: runs of five or more same-coloured modules.
        for (r = 0; r < size; r++) {
            run = 1;
            for (c = 1; c < size; c++) {
                if (modules[r][c] === modules[r][c - 1]) {
                    run++;
                } else {
                    if (run >= 5) { score += 3 + (run - 5); }
                    run = 1;
                }
            }
            if (run >= 5) { score += 3 + (run - 5); }
        }
        for (c = 0; c < size; c++) {
            run = 1;
            for (r = 1; r < size; r++) {
                if (modules[r][c] === modules[r - 1][c]) {
                    run++;
                } else {
                    if (run >= 5) { score += 3 + (run - 5); }
                    run = 1;
                }
            }
            if (run >= 5) { score += 3 + (run - 5); }
        }

        // Rule 2: 2x2 blocks of one colour.
        for (r = 0; r < size - 1; r++) {
            for (c = 0; c < size - 1; c++) {
                var v = modules[r][c];
                if (v === modules[r][c + 1] && v === modules[r + 1][c] && v === modules[r + 1][c + 1]) {
                    score += 3;
                }
            }
        }

        // Rule 3: finder-like patterns.
        var pattern1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        var pattern2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];

        function matches(line, start, pattern) {
            for (var k = 0; k < pattern.length; k++) {
                if (line[start + k] !== pattern[k]) { return false; }
            }
            return true;
        }

        for (r = 0; r < size; r++) {
            for (c = 0; c <= size - 11; c++) {
                if (matches(modules[r], c, pattern1) || matches(modules[r], c, pattern2)) { score += 40; }
            }
        }
        for (c = 0; c < size; c++) {
            var column = [];
            for (r = 0; r < size; r++) { column.push(modules[r][c]); }
            for (i = 0; i <= size - 11; i++) {
                if (matches(column, i, pattern1) || matches(column, i, pattern2)) { score += 40; }
            }
        }

        // Rule 4: deviation from 50% dark.
        var dark = 0;
        for (r = 0; r < size; r++) {
            for (c = 0; c < size; c++) { dark += modules[r][c]; }
        }
        var percent = (dark * 100) / (size * size);
        score += Math.floor(Math.abs(percent - 50) / 5) * 10;

        return score;
    }

    /** Format information: ECC level M (0b00) + mask, BCH(15,5) with XOR mask. */
    function formatBits(maskIndex) {
        var data = (0x00 << 3) | maskIndex; // 00 = level M
        var value = data << 10;

        for (var i = 4; i >= 0; i--) {
            if (value & (1 << (i + 10))) {
                value ^= 0x537 << i; // generator 10100110111
            }
        }

        return ((data << 10) | value) ^ 0x5412; // standard XOR mask
    }

    /**
     * Write both copies of the format information.
     *
     * Bit 14 (the MSB) goes at (8,0) and the string descends from there;
     * getting this backwards produces a symbol that looks right and does not
     * scan, so the positions are listed explicitly rather than derived.
     */
    function placeFormat(modules, size, maskIndex) {
        var bits = formatBits(maskIndex);

        // Index 0 of each list holds bit 14, index 14 holds bit 0.
        var copy1 = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]
        ];
        var copy2 = [
            [size - 1, 8], [size - 2, 8], [size - 3, 8], [size - 4, 8],
            [size - 5, 8], [size - 6, 8], [size - 7, 8],
            [8, size - 8], [8, size - 7], [8, size - 6], [8, size - 5],
            [8, size - 4], [8, size - 3], [8, size - 2], [8, size - 1]
        ];

        for (var i = 0; i < 15; i++) {
            var bit = (bits >>> (14 - i)) & 1;
            modules[copy1[i][0]][copy1[i][1]] = bit;
            modules[copy2[i][0]][copy2[i][1]] = bit;
        }
    }

    /**
     * Version information, required from version 7 upwards.
     *
     * 18 bits: 6 data + 12 BCH, mirrored into two 3x6 blocks beside the
     * top-right and bottom-left finders.
     */
    function versionBits(version) {
        var value = version << 12;
        for (var i = 5; i >= 0; i--) {
            if (value & (1 << (i + 12))) {
                value ^= 0x1F25 << i; // generator 1111100100101
            }
        }

        return (version << 12) | value;
    }

    function placeVersion(modules, size, version) {
        if (version < 7) {
            return;
        }

        var bits = versionBits(version);

        for (var i = 0; i < 18; i++) {
            var bit = (bits >>> i) & 1;
            var row = Math.floor(i / 3);
            var col = i % 3;

            modules[row][size - 11 + col] = bit;   // top-right block
            modules[size - 11 + col][row] = bit;   // bottom-left block
        }
    }

    /* ----------------------------------------------------------- rendering */

    function encode(text) {
        var bytes = toUtf8Bytes(text);
        var version = chooseVersion(bytes.length);

        var codewords = interleave(buildDataCodewords(bytes, version), version);

        var matrix = createMatrix(version);
        placeData(matrix, codewords);

        // Pick the mask with the lowest penalty, as the standard requires.
        var best = null;
        var bestScore = Infinity;

        for (var maskIndex = 0; maskIndex < 8; maskIndex++) {
            var candidate = applyMask(matrix, maskIndex);
            placeFormat(candidate, matrix.size, maskIndex);
            placeVersion(candidate, matrix.size, version);
            var score = penalty(candidate, matrix.size);
            if (score < bestScore) {
                bestScore = score;
                best = candidate;
            }
        }

        return { modules: best, size: matrix.size, version: version };
    }

    /**
     * Render as an inline SVG.
     *
     * A four-module quiet zone is mandatory; scanners fail without it.
     */
    function renderQrSvg(text, pixelSize) {
        var qr = encode(text);
        var quiet = 4;
        var total = qr.size + quiet * 2;
        var scale = pixelSize || 5;
        var dimension = total * scale;

        var path = '';
        for (var r = 0; r < qr.size; r++) {
            for (var c = 0; c < qr.size; c++) {
                if (qr.modules[r][c]) {
                    path += 'M' + ((c + quiet) * scale) + ',' + ((r + quiet) * scale)
                        + 'h' + scale + 'v' + scale + 'h-' + scale + 'z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' + dimension + '" height="' + dimension + '" '
            + 'viewBox="0 0 ' + dimension + ' ' + dimension + '" role="img" '
            + 'aria-label="Two-factor authentication QR code">'
            + '<rect width="' + dimension + '" height="' + dimension + '" fill="#ffffff"/>'
            + '<path d="' + path + '" fill="#000000"/>'
            + '</svg>';
    }

    window.renderQrSvg = renderQrSvg;
    window.qrEncode = encode;
})();
