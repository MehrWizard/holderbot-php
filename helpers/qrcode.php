<?php
declare(strict_types=1);

/**
 * HolderBot PHP - Zero-Dependency QR Code Generator
 *
 * Pure-PHP QR Code encoder (byte mode, ECC level M) + a hand-rolled PNG writer
 * built on zlib (gzcompress/crc32), matching the original Python bot's fully
 * offline generation. No third-party API calls, no GD dependency.
 */

final class QrEncoder {
    // GF(256) tables for Reed-Solomon, primitive polynomial 0x11D
    private static array $expTable = [];
    private static array $logTable = [];

    // Capacity table: [version] => [ 'L'=>[totalCw, ec1blocks,ec1cw,ec2blocks,ec2cw,eccPerBlock], 'M'=>..., 'Q'=>..., 'H'=>... ]
    // Values: totalDataCodewords (sum of group1+group2 data codewords), then block layout.
    // Format per level: [eccCodewordsPerBlock, numBlocksGroup1, dataCwGroup1, numBlocksGroup2, dataCwGroup2]
    private static array $blockTable = [
        1 => ['L'=>[7,1,19,0,0], 'M'=>[10,1,16,0,0], 'Q'=>[13,1,13,0,0], 'H'=>[17,1,9,0,0]],
        2 => ['L'=>[10,1,34,0,0], 'M'=>[16,1,28,0,0], 'Q'=>[22,1,22,0,0], 'H'=>[28,1,16,0,0]],
        3 => ['L'=>[15,1,55,0,0], 'M'=>[26,1,44,0,0], 'Q'=>[18,2,17,0,0], 'H'=>[22,2,13,0,0]],
        4 => ['L'=>[20,1,80,0,0], 'M'=>[18,2,32,0,0], 'Q'=>[26,2,24,0,0], 'H'=>[16,4,9,0,0]],
        5 => ['L'=>[26,1,108,0,0], 'M'=>[24,2,43,0,0], 'Q'=>[18,2,15,2,16], 'H'=>[22,2,11,2,12]],
        6 => ['L'=>[18,2,68,0,0], 'M'=>[16,4,27,0,0], 'Q'=>[24,4,19,0,0], 'H'=>[28,4,15,0,0]],
        7 => ['L'=>[20,2,78,0,0], 'M'=>[18,4,31,0,0], 'Q'=>[18,2,14,4,15], 'H'=>[26,4,13,1,14]],
        8 => ['L'=>[24,2,97,0,0], 'M'=>[22,2,38,2,39], 'Q'=>[22,4,18,2,19], 'H'=>[26,4,14,2,15]],
        9 => ['L'=>[30,2,116,0,0], 'M'=>[22,3,36,2,37], 'Q'=>[20,4,16,4,17], 'H'=>[24,4,12,4,13]],
        10 => ['L'=>[18,2,68,2,69], 'M'=>[26,4,43,1,44], 'Q'=>[24,6,19,2,20], 'H'=>[28,6,15,2,16]],
        11 => ['L'=>[20,4,81,0,0], 'M'=>[30,1,50,4,51], 'Q'=>[28,4,22,4,23], 'H'=>[24,3,12,8,13]],
        12 => ['L'=>[24,2,92,2,93], 'M'=>[22,6,36,2,37], 'Q'=>[26,4,20,6,21], 'H'=>[28,7,14,4,15]],
        13 => ['L'=>[26,4,107,0,0], 'M'=>[22,8,37,1,38], 'Q'=>[24,8,20,4,21], 'H'=>[22,12,11,4,12]],
        14 => ['L'=>[30,3,115,1,116], 'M'=>[24,4,40,5,41], 'Q'=>[20,11,16,5,17], 'H'=>[24,11,12,5,13]],
        15 => ['L'=>[22,5,87,1,88], 'M'=>[24,5,41,5,42], 'Q'=>[30,5,24,7,25], 'H'=>[24,11,12,7,13]],
        16 => ['L'=>[24,5,98,1,99], 'M'=>[28,7,45,3,46], 'Q'=>[24,15,19,2,20], 'H'=>[30,3,15,13,16]],
        17 => ['L'=>[28,1,107,5,108], 'M'=>[28,10,46,1,47], 'Q'=>[28,1,22,15,23], 'H'=>[28,2,14,17,15]],
        18 => ['L'=>[30,5,120,1,121], 'M'=>[26,9,43,4,44], 'Q'=>[28,17,22,1,23], 'H'=>[28,2,14,19,15]],
        19 => ['L'=>[28,3,113,4,114], 'M'=>[26,3,44,11,45], 'Q'=>[26,17,21,4,22], 'H'=>[26,9,13,16,14]],
        20 => ['L'=>[28,3,107,5,108], 'M'=>[26,3,41,13,42], 'Q'=>[30,15,24,5,25], 'H'=>[28,15,15,10,16]],
        21 => ['L'=>[28,4,116,4,117], 'M'=>[26,17,42,0,0], 'Q'=>[28,17,22,6,23], 'H'=>[30,19,16,6,17]],
        22 => ['L'=>[28,2,111,7,112], 'M'=>[28,17,46,0,0], 'Q'=>[30,7,24,16,25], 'H'=>[24,34,13,0,0]],
        23 => ['L'=>[30,4,121,5,122], 'M'=>[28,4,47,14,48], 'Q'=>[30,11,24,14,25], 'H'=>[30,16,15,14,16]],
        24 => ['L'=>[30,6,117,4,118], 'M'=>[28,6,45,14,46], 'Q'=>[30,11,24,16,25], 'H'=>[30,30,16,2,17]],
        25 => ['L'=>[26,8,106,4,107], 'M'=>[28,8,47,13,48], 'Q'=>[30,7,24,22,25], 'H'=>[30,22,15,13,16]],
        26 => ['L'=>[28,10,114,2,115], 'M'=>[28,19,46,4,47], 'Q'=>[28,28,22,6,23], 'H'=>[30,33,16,4,17]],
        27 => ['L'=>[30,8,122,4,123], 'M'=>[28,22,45,3,46], 'Q'=>[30,8,23,26,24], 'H'=>[30,12,15,28,16]],
        28 => ['L'=>[30,3,117,10,118], 'M'=>[28,3,45,23,46], 'Q'=>[30,4,24,31,25], 'H'=>[30,11,15,31,16]],
        29 => ['L'=>[30,7,116,7,117], 'M'=>[28,21,45,7,46], 'Q'=>[30,1,23,37,24], 'H'=>[30,19,15,26,16]],
        30 => ['L'=>[30,5,115,10,116], 'M'=>[28,19,47,10,48], 'Q'=>[30,15,24,25,25], 'H'=>[30,23,15,25,16]],
        31 => ['L'=>[30,13,115,3,116], 'M'=>[28,2,46,29,47], 'Q'=>[30,42,24,1,25], 'H'=>[30,23,15,28,16]],
        32 => ['L'=>[30,17,115,0,0], 'M'=>[28,10,46,23,47], 'Q'=>[30,10,24,35,25], 'H'=>[30,19,15,35,16]],
        33 => ['L'=>[30,17,115,1,116], 'M'=>[28,14,46,21,47], 'Q'=>[30,29,24,19,25], 'H'=>[30,11,15,46,16]],
        34 => ['L'=>[30,13,115,6,116], 'M'=>[28,14,46,23,47], 'Q'=>[30,44,24,7,25], 'H'=>[30,59,16,1,17]],
        35 => ['L'=>[30,12,121,7,122], 'M'=>[28,12,47,26,48], 'Q'=>[30,39,24,14,25], 'H'=>[30,22,15,41,16]],
        36 => ['L'=>[30,6,121,14,122], 'M'=>[28,6,47,34,48], 'Q'=>[30,46,24,10,25], 'H'=>[30,2,15,64,16]],
        37 => ['L'=>[30,17,122,4,123], 'M'=>[28,29,46,14,47], 'Q'=>[30,49,24,10,25], 'H'=>[30,24,15,46,16]],
        38 => ['L'=>[30,4,122,18,123], 'M'=>[28,13,46,32,47], 'Q'=>[30,48,24,14,25], 'H'=>[30,42,15,32,16]],
        39 => ['L'=>[30,20,117,4,118], 'M'=>[28,40,47,7,48], 'Q'=>[30,43,24,22,25], 'H'=>[30,10,15,67,16]],
        40 => ['L'=>[30,19,118,6,119], 'M'=>[28,18,47,31,48], 'Q'=>[30,34,24,34,25], 'H'=>[30,20,15,61,16]],
    ];

    private static function initTables(): void {
        if (!empty(self::$expTable)) return;
        $exp = [];
        $log = [];
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        self::$expTable = $exp;
        self::$logTable = $log;
    }

    private static function gfMul(int $a, int $b): int {
        if ($a === 0 || $b === 0) return 0;
        return self::$expTable[self::$logTable[$a] + self::$logTable[$b]];
    }

    /** Compute RS generator polynomial coefficients (highest degree first, leading coeff implicit 1) */
    private static function rsGeneratorPoly(int $degree): array {
        self::initTables();
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $newPoly = array_fill(0, count($poly) + 1, 0);
            for ($j = 0; $j < count($poly); $j++) {
                $newPoly[$j] ^= self::gfMul($poly[$j], 1);
                $newPoly[$j + 1] ^= self::gfMul($poly[$j], self::$expTable[$i]);
            }
            $poly = $newPoly;
        }
        return $poly;
    }

    private static function rsEncode(array $data, int $eccLen): array {
        self::initTables();
        $gen = self::rsGeneratorPoly($eccLen);
        $result = array_fill(0, $eccLen, 0);
        foreach ($data as $b) {
            $factor = $b ^ $result[0];
            array_shift($result);
            $result[] = 0;
            if ($factor !== 0) {
                for ($i = 0; $i < $eccLen; $i++) {
                    $result[$i] ^= self::gfMul($gen[$i + 1], $factor);
                }
            }
        }
        return $result;
    }

    /**
     * Pick smallest supported version (1-40 in this table) fitting $len bytes in byte mode at given level.
     * Character count indicator is 8 bits for versions 1-9, 16 bits from version 10 up (byte mode).
     */
    private static function pickVersion(int $len, string $level): ?array {
        foreach (self::$blockTable as $version => $levels) {
            $cfg = $levels[$level];
            [$eccPerBlock, $b1, $d1, $b2, $d2] = $cfg;
            $totalData = $b1 * $d1 + $b2 * $d2;
            $ccBits = ($version <= 9) ? 8 : 16;
            $headerBits = 4 + $ccBits;
            $capacityBits = $totalData * 8;
            $availableForData = intdiv($capacityBits - $headerBits, 8);
            if ($availableForData >= $len) {
                return ['version' => $version, 'cfg' => $cfg, 'totalData' => $totalData, 'ccBits' => $ccBits];
            }
        }
        return null;
    }

    private static function bitPush(array &$bits, int $value, int $len): void {
        for ($i = $len - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /**
     * Encode $data (byte mode only) into a boolean matrix. Returns null if data too large (>version 40 capacity).
     */
    public static function encode(string $data, string $level = 'M'): ?array {
        self::initTables();
        $bytes = array_values(unpack('C*', $data));
        $len = count($bytes);

        $picked = self::pickVersion($len, $level);
        if ($picked === null) {
            return null;
        }
        $version = $picked['version'];
        [$eccPerBlock, $b1, $d1, $b2, $d2] = $picked['cfg'];
        $totalData = $picked['totalData'];
        $ccBits = $picked['ccBits'];

        // Build bit stream
        $bits = [];
        self::bitPush($bits, 0b0100, 4); // byte mode
        self::bitPush($bits, $len, $ccBits);
        foreach ($bytes as $b) {
            self::bitPush($bits, $b, 8);
        }

        // Terminator (up to 4 bits)
        $capacityBits = $totalData * 8;
        $term = min(4, $capacityBits - count($bits));
        for ($i = 0; $i < $term; $i++) $bits[] = 0;

        // Pad to byte boundary
        while (count($bits) % 8 !== 0) $bits[] = 0;

        // Convert to codewords
        $codewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) $byte = ($byte << 1) | $bits[$i + $j];
            $codewords[] = $byte;
        }

        // Pad codewords with 0xEC/0x11 alternating
        $padBytes = [0xEC, 0x11];
        $pi = 0;
        while (count($codewords) < $totalData) {
            $codewords[] = $padBytes[$pi % 2];
            $pi++;
        }

        // Split into blocks
        $blocks = [];
        $eccBlocks = [];
        $offset = 0;
        $allBlockDefs = [];
        for ($i = 0; $i < $b1; $i++) $allBlockDefs[] = $d1;
        for ($i = 0; $i < $b2; $i++) $allBlockDefs[] = $d2;
        foreach ($allBlockDefs as $dcount) {
            $block = array_slice($codewords, $offset, $dcount);
            $offset += $dcount;
            $blocks[] = $block;
            $eccBlocks[] = self::rsEncode($block, $eccPerBlock);
        }

        // Interleave data codewords
        $finalData = [];
        $maxData = max($d1, $d2 ?: $d1);
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) $finalData[] = $block[$i];
            }
        }
        // Interleave ECC codewords
        $finalEcc = [];
        for ($i = 0; $i < $eccPerBlock; $i++) {
            foreach ($eccBlocks as $eb) {
                $finalEcc[] = $eb[$i];
            }
        }
        $finalCodewords = array_merge($finalData, $finalEcc);

        // Convert final codewords back to a bit array
        $finalBits = [];
        foreach ($finalCodewords as $cw) {
            self::bitPush($finalBits, $cw, 8);
        }
        // Remainder bits for all QR versions.
        $remainderBits = match (true) {
            $version === 1 => 0,
            $version >= 2 && $version <= 6 => 7,
            $version >= 7 && $version <= 13 => 0,
            $version <= 20 => 3,
            $version <= 27 => 4,
            $version <= 34 => 3,
            default => 0,
        };
        for ($i = 0; $i < $remainderBits; $i++) $finalBits[] = 0;

        return self::buildMatrix($version, $level, $finalBits);
    }

    private static function buildMatrix(int $version, string $level, array $dataBits): array {
        $size = $version * 4 + 17;
        $matrix = array_fill(0, $size, array_fill(0, $size, null)); // null = not yet set (function pattern check)
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        $setModule = function (&$matrix, &$reserved, $r, $c, $val) use ($size) {
            if ($r < 0 || $r >= $size || $c < 0 || $c >= $size) return;
            $matrix[$r][$c] = $val;
            $reserved[$r][$c] = true;
        };

        // Finder patterns (3 corners) + separators
        $placeFinder = function (&$matrix, &$reserved, $row, $col) use ($setModule, $size) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $rr = $row + $r;
                    $cc = $col + $c;
                    if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) continue;
                    if ($r >= 0 && $r <= 6 && $c >= 0 && $c <= 6) {
                        $isBorder = ($r === 0 || $r === 6 || $c === 0 || $c === 6);
                        $isCenter = ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                        $val = ($isBorder || $isCenter) ? 1 : 0;
                    } else {
                        $val = 0; // separator
                    }
                    $setModule($matrix, $reserved, $rr, $cc, $val);
                }
            }
        };
        $placeFinder($matrix, $reserved, 0, 0);
        $placeFinder($matrix, $reserved, 0, $size - 7);
        $placeFinder($matrix, $reserved, $size - 7, 0);

        // Timing patterns
        for ($i = 8; $i < $size - 8; $i++) {
            $val = ($i % 2 === 0) ? 1 : 0;
            $setModule($matrix, $reserved, 6, $i, $val);
            $setModule($matrix, $reserved, $i, 6, $val);
        }

        // Dark module
        $setModule($matrix, $reserved, 4 * $version + 9, 8, 1);

        // Alignment patterns (version-dependent center coordinates)
        $alignCoords = self::alignmentCoords($version);
        foreach ($alignCoords as $ar) {
            foreach ($alignCoords as $ac) {
                // Skip if overlapping a finder pattern corner
                if (($ar <= 8 && $ac <= 8) || ($ar <= 8 && $ac >= $size - 9) || ($ar >= $size - 9 && $ac <= 8)) {
                    continue;
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $isBorder = (abs($r) === 2 || abs($c) === 2);
                        $isCenter = ($r === 0 && $c === 0);
                        $val = ($isBorder || $isCenter) ? 1 : 0;
                        $setModule($matrix, $reserved, $ar + $r, $ac + $c, $val);
                    }
                }
            }
        }

        // Reserve format info areas (values filled later, after mask chosen)
        for ($i = 0; $i <= 8; $i++) {
            if (!$reserved[8][$i]) { $matrix[8][$i] = 0; $reserved[8][$i] = true; }
            if (!$reserved[$i][8]) { $matrix[$i][8] = 0; $reserved[$i][8] = true; }
        }
        for ($i = 0; $i < 8; $i++) {
            $r = $size - 1 - $i;
            if (!$reserved[$r][8]) { $matrix[$r][8] = 0; $reserved[$r][8] = true; }
            $c = $size - 1 - $i;
            if (!$reserved[8][$c]) { $matrix[8][$c] = 0; $reserved[8][$c] = true; }
        }
        $setModule($matrix, $reserved, 8, 8, 0);

        // Version information for version 7 and above.
        if ($version >= 7) {
            // 6x3 blocks near bottom-left and top-right corners
            $verBits = self::versionInfoBits($version);
            $bitIdx = 0;
            for ($c = 0; $c < 6; $c++) {
                for ($r = 0; $r < 3; $r++) {
                    $bit = $verBits[17 - $bitIdx];
                    $setModule($matrix, $reserved, $size - 11 + $r, $c, $bit);
                    $setModule($matrix, $reserved, $c, $size - 11 + $r, $bit);
                    $bitIdx++;
                }
            }
        }

        // Place data bits in zigzag pattern (skipping reserved modules)
        $bitIndex = 0;
        $upward = true;
        $col = $size - 1;
        while ($col > 0) {
            if ($col === 6) $col--; // skip timing column
            for ($i = 0; $i < $size; $i++) {
                $row = $upward ? ($size - 1 - $i) : $i;
                for ($dc = 0; $dc < 2; $dc++) {
                    $c = $col - $dc;
                    if (!$reserved[$row][$c]) {
                        $bit = $bitIndex < count($dataBits) ? $dataBits[$bitIndex] : 0;
                        $matrix[$row][$c] = $bit;
                        $bitIndex++;
                    }
                }
            }
            $upward = !$upward;
            $col -= 2;
        }

        // Try all 8 masks, pick lowest penalty
        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        $bestMatrix = null;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = self::applyMask($matrix, $reserved, $mask, $size);
            self::writeFormatInfo($candidate, $level, $mask, $size);
            $penalty = self::penaltyScore($candidate, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
                $bestMatrix = $candidate;
            }
        }

        return $bestMatrix;
    }

    private static function alignmentCoords(int $version): array {
        $table = [
            1 => [],
            2 => [6, 18],
            3 => [6, 22],
            4 => [6, 26],
            5 => [6, 30],
            6 => [6, 34],
            7 => [6, 22, 38],
            8 => [6, 24, 42],
            9 => [6, 26, 46],
            10 => [6, 28, 50],
            11 => [6, 30, 54],
            12 => [6, 32, 58],
            13 => [6, 34, 62],
            14 => [6, 26, 46, 66],
            15 => [6, 26, 48, 70],
            16 => [6, 26, 50, 74],
            17 => [6, 30, 54, 78],
            18 => [6, 30, 56, 82],
            19 => [6, 30, 58, 86],
            20 => [6, 34, 62, 90],
            21 => [6, 28, 50, 72, 94],
            22 => [6, 26, 50, 74, 98],
            23 => [6, 30, 54, 78, 102],
            24 => [6, 28, 54, 80, 106],
            25 => [6, 32, 58, 84, 110],
            26 => [6, 30, 58, 86, 114],
            27 => [6, 34, 62, 90, 118],
            28 => [6, 26, 50, 74, 98, 122],
            29 => [6, 30, 54, 78, 102, 126],
            30 => [6, 26, 52, 78, 104, 130],
            31 => [6, 30, 56, 82, 108, 134],
            32 => [6, 34, 60, 86, 112, 138],
            33 => [6, 30, 58, 86, 114, 142],
            34 => [6, 34, 62, 90, 118, 146],
            35 => [6, 30, 54, 78, 102, 126, 150],
            36 => [6, 24, 50, 76, 102, 128, 154],
            37 => [6, 28, 54, 80, 106, 132, 158],
            38 => [6, 32, 58, 84, 110, 136, 162],
            39 => [6, 26, 54, 82, 110, 138, 166],
            40 => [6, 30, 58, 86, 114, 142, 170],
        ];
        return $table[$version] ?? [];
    }

    private static function versionInfoBits(int $version): array {
        // BCH(18,6) encoding of version number, generator poly 0x1F25
        $g = 0x1F25;
        $data = $version << 12;
        $val = $data;
        for ($i = 17; $i >= 12; $i--) {
            if ($val & (1 << $i)) {
                $val ^= $g << ($i - 12);
            }
        }
        $full = $data | $val;
        $bits = [];
        for ($i = 17; $i >= 0; $i--) {
            $bits[] = ($full >> $i) & 1;
        }
        return $bits; // index 0 = bit17 ... index17 = bit0; we index via [17-bitIdx] above so bits[0] is MSB
    }

    private static function applyMask(array $matrix, array $reserved, int $maskId, int $size): array {
        $out = $matrix;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($reserved[$r][$c]) continue;
                $invert = match ($maskId) {
                    0 => (($r + $c) % 2) === 0,
                    1 => ($r % 2) === 0,
                    2 => ($c % 3) === 0,
                    3 => (($r + $c) % 3) === 0,
                    4 => ((intdiv($r, 2) + intdiv($c, 3)) % 2) === 0,
                    5 => ((($r * $c) % 2) + (($r * $c) % 3)) === 0,
                    6 => (((($r * $c) % 2) + (($r * $c) % 3)) % 2) === 0,
                    7 => (((($r + $c) % 2) + (($r * $c) % 3)) % 2) === 0,
                    default => false,
                };
                if ($invert) {
                    $out[$r][$c] = $matrix[$r][$c] ? 0 : 1;
                }
            }
        }
        return $out;
    }

    private static function writeFormatInfo(array &$matrix, string $level, int $maskId, int $size): void {
        $levelBits = ['L' => 0b01, 'M' => 0b00, 'Q' => 0b11, 'H' => 0b10][$level];
        $data = ($levelBits << 3) | $maskId;
        // BCH(15,5) with generator 0x537, then XOR with mask 0x5412
        $g = 0x537;
        $val = $data << 10;
        $rem = $val;
        for ($i = 14; $i >= 10; $i--) {
            if ($rem & (1 << $i)) {
                $rem ^= $g << ($i - 10);
            }
        }
        $full = (($data << 10) | $rem) ^ 0x5412;
        $bits = [];
        for ($i = 14; $i >= 0; $i--) $bits[] = ($full >> $i) & 1;

        // Positions per spec (around top-left finder + split top-right/bottom-left)
        $positions1 = [[8,0],[8,1],[8,2],[8,3],[8,4],[8,5],[8,7],[8,8],[7,8],[5,8],[4,8],[3,8],[2,8],[1,8],[0,8]];
        for ($i = 0; $i < 15; $i++) {
            [$r, $c] = $positions1[$i];
            $matrix[$r][$c] = $bits[$i];
        }
        // Second copy: top-right (row 8, cols size-1..size-8) and bottom-left (rows size-1..size-7, col 8)
        for ($i = 0; $i < 8; $i++) {
            $matrix[8][$size - 1 - $i] = $bits[14 - $i];
        }
        for ($i = 0; $i < 7; $i++) {
            $matrix[$size - 1 - $i][8] = $bits[$i];
        }
        $matrix[$size - 8][8] = 1; // dark module always set (redundant safety)
    }

    private static function penaltyScore(array $matrix, int $size): int {
        $penalty = 0;
        // Rule 1: runs of 5+ same color in row/col
        for ($r = 0; $r < $size; $r++) {
            $run = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($matrix[$r][$c] === $matrix[$r][$c - 1]) {
                    $run++;
                } else {
                    if ($run >= 5) $penalty += 3 + ($run - 5);
                    $run = 1;
                }
            }
            if ($run >= 5) $penalty += 3 + ($run - 5);
        }
        for ($c = 0; $c < $size; $c++) {
            $run = 1;
            for ($r = 1; $r < $size; $r++) {
                if ($matrix[$r][$c] === $matrix[$r - 1][$c]) {
                    $run++;
                } else {
                    if ($run >= 5) $penalty += 3 + ($run - 5);
                    $run = 1;
                }
            }
            if ($run >= 5) $penalty += 3 + ($run - 5);
        }
        // Rule 2: 2x2 blocks same color
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $v = $matrix[$r][$c];
                if ($v === $matrix[$r][$c+1] && $v === $matrix[$r+1][$c] && $v === $matrix[$r+1][$c+1]) {
                    $penalty += 3;
                }
            }
        }
        // Rule 3: finder-like patterns 1:1:3:1:1 with 4 light either side
        $pattern = [1,0,1,1,1,0,1,0,0,0,0];
        $patternRev = array_reverse($pattern);
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c <= $size - 11; $c++) {
                $seg = array_slice($matrix[$r], $c, 11);
                if ($seg === $pattern || $seg === $patternRev) $penalty += 40;
            }
        }
        for ($c = 0; $c < $size; $c++) {
            for ($r = 0; $r <= $size - 11; $r++) {
                $seg = [];
                for ($i = 0; $i < 11; $i++) $seg[] = $matrix[$r + $i][$c];
                if ($seg === $pattern || $seg === $patternRev) $penalty += 40;
            }
        }
        // Rule 4: overall dark proportion
        $dark = 0;
        foreach ($matrix as $row) $dark += array_sum($row);
        $total = $size * $size;
        $percent = ($dark / $total) * 100;
        $prevMultiple = (int)(floor($percent / 5) * 5);
        $nextMultiple = $prevMultiple + 5;
        $penalty += min(abs($prevMultiple - 50), abs($nextMultiple - 50)) * 2;

        return $penalty;
    }
}

final class PngWriter {
    public static function fromMatrix(array $matrix, int $moduleSize = 8, int $quietZone = 2): string {
        $n = count($matrix);
        $imgSize = ($n + $quietZone * 2) * $moduleSize;

        // Build raw scanlines (8-bit grayscale, filter type 0 per line)
        $raw = '';
        for ($y = 0; $y < $imgSize; $y++) {
            $raw .= "\x00"; // filter type: None
            $moduleRow = intdiv($y, $moduleSize) - $quietZone;
            for ($x = 0; $x < $imgSize; $x++) {
                $moduleCol = intdiv($x, $moduleSize) - $quietZone;
                $isDark = false;
                if ($moduleRow >= 0 && $moduleRow < $n && $moduleCol >= 0 && $moduleCol < $n) {
                    $isDark = (bool)$matrix[$moduleRow][$moduleCol];
                }
                $raw .= $isDark ? "\x00" : "\xFF";
            }
        }

        $compressed = gzcompress($raw, 9);

        $png = "\x89PNG\r\n\x1a\n";
        $png .= self::chunk('IHDR', pack('NNCCCCC', $imgSize, $imgSize, 8, 0, 0, 0, 0));
        $png .= self::chunk('IDAT', $compressed);
        $png .= self::chunk('IEND', '');

        return $png;
    }

    private static function chunk(string $type, string $data): string {
        $len = pack('N', strlen($data));
        $typeData = $type . $data;
        $crc = pack('N', crc32($typeData));
        return $len . $typeData . $crc;
    }
}

/**
 * Public entry point used by the bot: generates a QR code locally and sends it
 * as a Telegram photo. Never calls any external service.
 */
class QrGenerator {
    /**
     * Render $data as a QR code PNG (bytes). Returns null if the data is too
     * large to encode within the supported version range.
     */
    public static function generatePng(string $data): ?string {
        $matrix = QrEncoder::encode($data, 'M');
        if ($matrix === null) {
            return null;
        }
        $png = PngWriter::fromMatrix($matrix, 8, 2);
        global $config;
        $backgroundPath = $config['qr_background'] ?? (getenv('QR_BACKGROUND') ?: '');
        if (!$backgroundPath || !is_file($backgroundPath)) return $png;
        if (!function_exists('imagecreatefromstring')) throw new RuntimeException('QR backgrounds require the PHP GD extension');
        $background = @imagecreatefromstring(file_get_contents($backgroundPath));
        if (!$background) return $png;
        $width = imagesx($background); $height = imagesy($background);
        if (max($width, $height) > 1000) {
            $scale = 1000 / max($width, $height);
            $width = (int)round($width * $scale); $height = (int)round($height * $scale);
            $background = imagescale($background, $width, $height, IMG_BICUBIC_FIXED);
        }
        $qr = imagecreatefromstring($png);
        $size = (int)(0.6 * min($width, $height));
        imagecopyresampled($background, $qr, intdiv($width - $size, 2), intdiv($height - $size, 2), 0, 0, $size, $size, imagesx($qr), imagesy($qr));
        ob_start(); imagepng($background, null, 6); $result = ob_get_clean();
        return $result;
    }

    /**
     * Generate a QR code for $data locally and send it as a photo to $chatId.
     * Falls back to a plain text message (never a fake/unreadable QR) if the
     * data is too large to encode.
     */
    public static function sendQrPhoto(int|string $chatId, string $data, string $caption): ?array {
        $png = self::generatePng($data);
        if ($png === null) {
            return tg_send_message($chatId, $caption . "\n\n⚠️ <i>This link is too long to render as a QR code; use the text link above.</i>");
        }

        $tmpFile = sys_get_temp_dir() . '/hb_qr_' . bin2hex(random_bytes(8)) . '.png';
        file_put_contents($tmpFile, $png);
        try { return tg_send_photo($chatId, $tmpFile, $caption); }
        finally { @unlink($tmpFile); }
    }
}

