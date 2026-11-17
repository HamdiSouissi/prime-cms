<?php

declare(strict_types=1);

namespace Prime\Support;

use InvalidArgumentException;

/**
 * Dependency-free QR Code encoder (ISO/IEC 18004, byte mode, versions 1-10, error correction M)
 * used for the TOTP provisioning QR of the double-authentication setup screen.
 *
 * Pure PHP over arrays and integer arithmetic — no GD, no Imagick, no Composer package, no network.
 * The matrix layout, format-information BCH and mask selection follow the specification, so any
 * authenticator app can read it; `matrix()` returns the boolean grid (the admin theme renders it as
 * inline SVG) and `toSvg()` returns a standalone document.
 */
final class Barcode
{
    /**
     * Error-correction layout at level M, per version.
     *
     * @var array<int, array{total: int, ec: int, shortBlocks: int, shortData: int, longBlocks: int, longData: int}>
     */
    private const LAYOUT = [
        1 => ['total' => 26, 'ec' => 10, 'shortBlocks' => 1, 'shortData' => 16, 'longBlocks' => 0, 'longData' => 0],
        2 => ['total' => 44, 'ec' => 16, 'shortBlocks' => 1, 'shortData' => 28, 'longBlocks' => 0, 'longData' => 0],
        3 => ['total' => 70, 'ec' => 26, 'shortBlocks' => 1, 'shortData' => 44, 'longBlocks' => 0, 'longData' => 0],
        4 => ['total' => 100, 'ec' => 18, 'shortBlocks' => 2, 'shortData' => 32, 'longBlocks' => 0, 'longData' => 0],
        5 => ['total' => 134, 'ec' => 24, 'shortBlocks' => 2, 'shortData' => 43, 'longBlocks' => 0, 'longData' => 0],
        6 => ['total' => 172, 'ec' => 16, 'shortBlocks' => 4, 'shortData' => 27, 'longBlocks' => 0, 'longData' => 0],
        7 => ['total' => 196, 'ec' => 18, 'shortBlocks' => 4, 'shortData' => 31, 'longBlocks' => 0, 'longData' => 0],
        8 => ['total' => 242, 'ec' => 22, 'shortBlocks' => 2, 'shortData' => 38, 'longBlocks' => 2, 'longData' => 39],
        9 => ['total' => 292, 'ec' => 22, 'shortBlocks' => 3, 'shortData' => 36, 'longBlocks' => 2, 'longData' => 37],
        10 => ['total' => 346, 'ec' => 26, 'shortBlocks' => 4, 'shortData' => 43, 'longBlocks' => 1, 'longData' => 44],
    ];

    /**
     * Alignment pattern centre coordinates per supported version (ISO/IEC 18004 Table E.1).
     *
     * @var array<int, list<int>>
     */
    private const ALIGNMENT = [
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
    ];

    /**
     * Format information for EC level M (`00`) + mask pattern, BCH(15,5) with G(x)=0x537, XOR 0x5412.
     */
    private const FORMAT_POLY = 0x537;

    private const FORMAT_MASK = 0x5412;

    /**
     * @var list<int>|null
     */
    private static ?array $gfExp = null;

    /**
     * @var list<int>|null
     */
    private static ?array $gfLog = null;

    /**
     * Encode a payload into a QR module matrix.
     *
     * @return list<list<bool>>
     */
    public static function matrix(string $payload): array
    {
        return self::build($payload)['matrix'];
    }

    /**
     * Render a payload as a standalone SVG document (quiet zone included, no external assets).
     */
    public static function toSvg(string $payload, int $quietZone = 4, int $moduleSize = 4, string $dark = '#0f172a', string $light = '#ffffff'): string
    {
        $matrix = self::matrix($payload);
        $size = count($matrix);
        $total = ($size + 2 * $quietZone) * $moduleSize;

        $path = '';
        foreach ($matrix as $y => $row) {
            $x = 0;
            $runLength = 0;
            $runStart = 0;
            foreach ($row as $module) {
                if ($module) {
                    if ($runLength === 0) {
                        $runStart = $x;
                    }

                    ++$runLength;
                } elseif ($runLength > 0) {
                    $path .= self::svgRun($runStart, $y, $runLength, $quietZone, $moduleSize);
                    $runLength = 0;
                }

                ++$x;
            }

            if ($runLength > 0) {
                $path .= self::svgRun($runStart, $y, $runLength, $quietZone, $moduleSize);
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 %1$d %1$d" role="img" aria-label="QR Code" shape-rendering="crispEdges">'
            . '<rect width="%1$d" height="%1$d" fill="%2$s"/><path fill="%3$s" d="%4$s"/></svg>',
            $total,
            $light,
            $dark,
            $path,
        );
    }

    /**
     * Render the matrix as ASCII art — handy in the CLI (`prime user:totp-qr`).
     *
     * @param list<list<bool>> $matrix
     */
    public static function toText(array $matrix, string $on = '██', string $off = '  ', int $quietZone = 2): string
    {
        $size = count($matrix);
        $blank = str_repeat($off, $size + 4 * $quietZone);
        $lines = array_fill(0, $quietZone * 2, $blank);

        for ($y = 0; $y < $size; ++$y) {
            $line = str_repeat($off, $quietZone * 2);
            for ($x = 0; $x < $size; ++$x) {
                $line .= $matrix[$y][$x] ? $on : $off;
            }
            $line .= str_repeat($off, $quietZone * 2);
            $lines[] = $line;
            $lines[] = $line;
        }

        for ($i = 0; $i < $quietZone * 2; ++$i) {
            $lines[] = $blank;
        }

        return implode("\n", $lines);
    }

    /**
     * Encode and return both the matrix and the metadata a test or screen may need.
     *
     * @return array{matrix: list<list<bool>>, version: int, size: int, mask: int, capacity: int}
     */
    public static function build(string $payload): array
    {
        if ($payload === '') {
            throw new InvalidArgumentException('Le contenu du QR Code est vide.');
        }

        $version = self::pickVersion(strlen($payload));
        $layout = self::LAYOUT[$version];
        $size = 17 + 4 * $version;

        $functions = array_fill(0, $size, array_fill(0, $size, false));
        $base = array_fill(0, $size, array_fill(0, $size, false));

        self::drawFunctionPatterns($base, $functions, $size, $version);

        $bits = self::codewordBits($payload, $version);
        $data = array_fill(0, count($bits), 0);
        foreach ($bits as $index => $bit) {
            $data[intdiv($index, 8)] |= $bit << (7 - $index % 8);
        }

        $data = array_values($data);

        $best = null;
        $bestPenalty = PHP_INT_MAX;
        $chosenMask = 0;

        for ($mask = 0; $mask < 8; ++$mask) {
            $matrix = $base;
            self::drawCodewords($matrix, $functions, $size, $data);
            self::applyMask($matrix, $functions, $size, $mask);
            self::drawFormat($matrix, $size, $mask);

            $penalty = self::penalty($matrix, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $matrix;
                $chosenMask = $mask;
            }
        }

        /** @var list<list<bool>> $best */
        return [
            'matrix' => $best,
            'version' => $version,
            'size' => $size,
            'mask' => $chosenMask,
            'capacity' => $layout['shortData'] * $layout['shortBlocks'] + $layout['longData'] * $layout['longBlocks'],
        ];
    }

    private static function pickVersion(int $length): int
    {
        foreach (self::LAYOUT as $version => $layout) {
            $dataCodewords = $layout['shortData'] * $layout['shortBlocks'] + $layout['longData'] * $layout['longBlocks'];
            $needed = 4 + ($version >= 10 ? 16 : 8) + $length * 8;

            if ($needed <= $dataCodewords * 8) {
                return $version;
            }
        }

        throw new InvalidArgumentException('Contenu trop long pour un QR Code (maximum 213 octets dans cette implémentation).');
    }

    /**
     * Mode + length + payload + terminator + padding, then Reed-Solomon, then interleaving.
     *
     * @return list<int>
     */
    private static function codewordBits(string $payload, int $version): array
    {
        $layout = self::LAYOUT[$version];
        $dataCodewords = $layout['shortData'] * $layout['shortBlocks'] + $layout['longData'] * $layout['longBlocks'];

        $bits = [];
        $append = static function (int $value, int $count) use (&$bits): void {
            for ($i = $count - 1; $i >= 0; --$i) {
                $bits[] = ($value >> $i) & 1;
            }
        };

        $append(0b0100, 4);
        $append(strlen($payload), $version >= 10 ? 16 : 8);
        for ($i = 0, $max = strlen($payload); $i < $max; ++$i) {
            $append(ord($payload[$i]), 8);
        }

        $append(0, min(4, $dataCodewords * 8 - count($bits)));

        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $codewords = [];
        foreach (str_split(implode('', $bits), 8) as $byte) {
            $codewords[] = (int) bindec($byte);
        }

        $pad = true;
        while (count($codewords) < $dataCodewords) {
            $codewords[] = $pad ? 0xEC : 0x11;
            $pad = !$pad;
        }

        return self::toBits(self::interleave($codewords, $version));
    }

    /**
     * @param list<int> $codewords
     *
     * @return list<int>
     */
    private static function toBits(array $codewords): array
    {
        $bits = [];
        foreach ($codewords as $codeword) {
            for ($i = 7; $i >= 0; --$i) {
                $bits[] = ($codeword >> $i) & 1;
            }
        }

        return $bits;
    }

    /**
     * @param list<int> $codewords
     *
     * @return list<int>
     */
    private static function interleave(array $codewords, int $version): array
    {
        $layout = self::LAYOUT[$version];
        $blocks = [];
        $ecc = [];
        $offset = 0;
        $shortCount = $layout['shortBlocks'];
        $shortData = $layout['shortData'];
        $longCount = $layout['longBlocks'];
        $longData = $layout['longData'];

        for ($i = 0; $i < $shortCount; ++$i) {
            $data = array_slice($codewords, $offset, $shortData);
            $offset += $shortData;
            $blocks[] = $data;
            $ecc[] = self::reedSolomon($data, $layout['ec']);
        }

        for ($i = 0; $i < $longCount; ++$i) {
            $data = array_slice($codewords, $offset, $longData);
            $offset += $longData;
            $blocks[] = $data;
            $ecc[] = self::reedSolomon($data, $layout['ec']);
        }

        $result = [];
        $maxData = 0;
        foreach ($blocks as $block) {
            $maxData = max($maxData, count($block));
        }

        for ($i = 0; $i < $maxData; ++$i) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $layout['ec']; ++$i) {
            foreach ($ecc as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        // Remainder bits (spec table 7): the stream is simply zero-extended by the placer.
        return $result;
    }

    /**
     * @param list<int> $data
     *
     * @return list<int>
     */
    private static function reedSolomon(array $data, int $eccLength): array
    {
        self::initGf();

        $generator = self::generatorPolynomial($eccLength);
        $message = array_merge($data, array_fill(0, $eccLength, 0));
        $dataLength = count($data);

        for ($i = 0; $i < $dataLength; ++$i) {
            $factor = $message[$i];
            if ($factor === 0) {
                continue;
            }

            for ($j = 1, $max = count($generator); $j < $max; ++$j) {
                $message[$i + $j] ^= self::gfMul($generator[$j], $factor);
            }
        }

        return array_slice($message, $dataLength);
    }

    /**
     * @return list<int>
     */
    private static function generatorPolynomial(int $degree): array
    {
        self::initGf();

        $poly = [1];
        for ($i = 0; $i < $degree; ++$i) {
            $root = self::gfPow(2, $i);
            $next = array_fill(0, count($poly) + 1, 0);

            foreach ($poly as $j => $coefficient) {
                $next[$j] ^= $coefficient;
                $next[$j + 1] ^= self::gfMul($coefficient, $root);
            }

            $poly = $next;
        }

        return array_values($poly);
    }

    private static function gfPow(int $base, int $exponent): int
    {
        self::initGf();

        if ($exponent === 0) {
            return 1;
        }

        /** @var list<int> $exp */
        $exp = self::$gfExp;
        /** @var list<int> $log */
        $log = self::$gfLog;

        return $exp[($log[$base] * $exponent) % 255];
    }

    private static function gfMul(int $a, int $b): int
    {
        self::initGf();

        if ($a === 0 || $b === 0) {
            return 0;
        }

        /** @var list<int> $exp */
        $exp = self::$gfExp;
        /** @var list<int> $log */
        $log = self::$gfLog;

        // The exponent table repeats every 255 entries, so the sum is folded back into it: same value,
        // and an offset PHPStan can prove is inside the table.
        $sum = ($log[$a] ?? 0) + ($log[$b] ?? 0);

        return $exp[$sum % 255];
    }

    private static function initGf(): void
    {
        if (self::$gfExp !== null) {
            return;
        }

        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;

        for ($i = 0; $i < 255; ++$i) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;

            if (($x & 0x100) !== 0) {
                $x ^= 0x11D;
            }
        }

        for ($i = 255; $i < 512; ++$i) {
            $exp[$i] = $exp[$i - 255];
        }

        self::$gfExp = array_values($exp);
        self::$gfLog = array_values($log);
    }

    /**
     * @param list<list<bool>> $base
     * @param list<list<bool>> $functions
     */
    private static function drawFunctionPatterns(array &$base, array &$functions, int $size, int $version): void
    {
        // Timing patterns.
        for ($i = 0; $i < $size; ++$i) {
            self::set($base, $functions, $size, 6, $i, $i % 2 === 0);
            self::set($base, $functions, $size, $i, 6, $i % 2 === 0);
        }

        // Finder patterns + separators.
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$centerY, $centerX]) {
            for ($dy = -4; $dy <= 4; ++$dy) {
                for ($dx = -4; $dx <= 4; ++$dx) {
                    $dist = max(abs($dy), abs($dx));
                    self::set($base, $functions, $size, $centerX + $dx, $centerY + $dy, $dist !== 4 && $dist !== 2);
                }
            }
        }

        // Alignment patterns — every centre pair except the three finder corners.
        $positions = self::alignmentPositions($size, $version);
        $count = count($positions);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = 0; $j < $count; ++$j) {
                $isFinderCorner = ($i === 0 && $j === 0)
                    || ($i === 0 && $j === $count - 1)
                    || ($i === $count - 1 && $j === 0);

                if ($isFinderCorner) {
                    continue;
                }

                for ($dy = -2; $dy <= 2; ++$dy) {
                    for ($dx = -2; $dx <= 2; ++$dx) {
                        self::set(
                            $base,
                            $functions,
                            $size,
                            $positions[$j] + $dx,
                            $positions[$i] + $dy,
                            max(abs($dy), abs($dx)) !== 1,
                        );
                    }
                }
            }
        }

        // Reserve the format areas (rewritten once the mask is known).
        for ($i = 0; $i < 9; ++$i) {
            self::reserve($functions, $size, 8, $i);
            self::reserve($functions, $size, $i, 8);
        }

        for ($i = 0; $i < 8; ++$i) {
            self::reserve($functions, $size, 8, $size - 1 - $i);
            self::reserve($functions, $size, $size - 1 - $i, 8);
        }

        // Version information (versions 7+).
        if ($version >= 7) {
            $bits = self::versionBits($version);
            for ($i = 0; $i < 18; ++$i) {
                $bit = (($bits >> $i) & 1) === 1;
                $row = intdiv($i, 3);
                $col = $i % 3;
                self::set($base, $functions, $size, $size - 11 + $col, $row, $bit);
                self::set($base, $functions, $size, $row, $size - 11 + $col, $bit);
            }
        }

        // Writes through a reference at computed offsets widen the rows from `list<bool>` to
        // `array<int, bool>`; re-indexing them keeps the promise every helper below makes.
        $base = self::asListMatrix($base);
        $functions = self::asListMatrix($functions);
    }

    /**
     * Row/column coordinates of the alignment patterns, exactly as ISO/IEC 18004 Table E.1 lists
     * them. The table is inlined rather than derived from the usual `step` formula, because that
     * formula only approximates the standard from version 7 upwards and a misplaced alignment
     * pattern makes the symbol unreadable (decoders take the coordinates from the same table).
     *
     * @return list<int>
     */
    private static function alignmentPositions(int $size, int $version): array
    {
        unset($size); // Derived from the version, which fixes the symbol size.

        return self::ALIGNMENT[$version] ?? [];
    }


    private static function versionBits(int $version): int
    {
        $rem = $version;
        for ($i = 0; $i < 12; ++$i) {
            $rem = ($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25);
        }

        return $version << 12 | $rem;
    }

    /**
     * @param list<list<bool>> $matrix
     * @param list<list<bool>> $functions
     * @param list<int>          $data
     */
    private static function drawCodewords(array &$matrix, array $functions, int $size, array $data): void
    {
        $bitCount = count($data) * 8;
        $i = 0;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                --$right;
            }

            for ($vert = 0; $vert < $size; ++$vert) {
                for ($j = 0; $j < 2; ++$j) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $size - 1 - $vert : $vert;

                    if ($functions[$y][$x] || $i >= $bitCount) {
                        continue;
                    }

                    $byte = $data[intdiv($i, 8)];
                    $matrix[$y][$x] = (($byte >> (7 - $i % 8)) & 1) === 1;
                    ++$i;
                }
            }
        }

        $matrix = self::asListMatrix($matrix);
    }

    /**
     * @param list<list<bool>> $matrix
     * @param list<list<bool>> $functions
     */
    private static function applyMask(array &$matrix, array $functions, int $size, int $mask): void
    {
        for ($y = 0; $y < $size; ++$y) {
            for ($x = 0; $x < $size; ++$x) {
                if ($functions[$y][$x]) {
                    continue;
                }

                if (self::maskHit($mask, $x, $y)) {
                    $matrix[$y][$x] = !$matrix[$y][$x];
                }
            }
        }

        $matrix = self::asListMatrix($matrix);
    }

    private static function maskHit(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => $x * $y % 2 + $x * $y % 3 === 0,
            6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
            default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
        };
    }

    /**
     * @param list<list<bool>> $matrix
     */
    private static function drawFormat(array &$matrix, int $size, int $mask): void
    {
        // Error-correction level M is encoded as `00`.
        $data = (0b00 << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; ++$i) {
            $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * self::FORMAT_POLY);
        }

        $bits = (($data << 10) | ($rem & 0x3FF)) ^ self::FORMAT_MASK;

        // ISO/IEC 18004 Figure 25: bit `0` is the least significant bit of the masked BCH word and
        // sits next to the separator; the two copies run in opposite directions around the symbol.
        for ($bit = 0; $bit < 15; ++$bit) {
            $on = (($bits >> $bit) & 1) === 1;

            if ($bit < 6) {
                $matrix[$bit][8] = $on;
            } elseif ($bit === 6) {
                $matrix[7][8] = $on;
            } elseif ($bit === 7) {
                $matrix[8][8] = $on;
            } elseif ($bit === 8) {
                $matrix[8][7] = $on;
            } else {
                $matrix[8][14 - $bit] = $on;
            }

            if ($bit <= 6) {
                $matrix[$size - 1 - $bit][8] = $on;
            } else {
                $matrix[8][$size - 15 + $bit] = $on;
            }
        }

        // Always-dark module below the top-right copy of the format information.
        $matrix[$size - 8][8] = true;

        $matrix = self::asListMatrix($matrix);
    }

    /**
     * @param list<list<bool>> $matrix
     */
    private static function penalty(array $matrix, int $size): int
    {
        $score = 0;

        for ($axis = 0; $axis < 2; ++$axis) {
            for ($index = 0; $index < $size; ++$index) {
                $runColor = false;
                $runLength = 0;
                $row = [];

                for ($other = 0; $other < $size; ++$other) {
                    $cell = $axis === 0 ? $matrix[$index][$other] : $matrix[$other][$index];
                    $row[] = $cell;

                    if ($cell === $runColor) {
                        ++$runLength;
                    } else {
                        if ($runLength >= 5) {
                            $score += 3 + ($runLength - 5);
                        }

                        $runColor = $cell;
                        $runLength = 1;
                    }
                }

                if ($runLength >= 5) {
                    $score += 3 + ($runLength - 5);
                }

                // Finder-like 1:1:3:1:1 preceded or followed by four light modules.
                $pattern = [true, false, false, false, false, true, false, true, true, true, false, true];
                for ($k = 0; $k + 12 <= $size; ++$k) {
                    $forward = true;
                    $backward = true;
                    for ($j = 0; $j < 12; ++$j) {
                        if ($row[$k + $j] !== $pattern[$j]) {
                            $forward = false;
                        }

                        if ($row[$k + 11 - $j] !== $pattern[$j]) {
                            $backward = false;
                        }
                    }

                    if ($forward || $backward) {
                        $score += 40;
                    }
                }
            }
        }

        // 2x2 blocks of a single colour.
        for ($y = 0; $y < $size - 1; ++$y) {
            for ($x = 0; $x < $size - 1; ++$x) {
                $cell = $matrix[$y][$x];
                if ($cell === $matrix[$y][$x + 1] && $cell === $matrix[$y + 1][$x] && $cell === $matrix[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // Deviation from a 50% dark ratio.
        $dark = 0;
        foreach ($matrix as $row) {
            foreach ($row as $cell) {
                if ($cell) {
                    ++$dark;
                }
            }
        }

        $total = $size * $size;
        $percent = $total === 0 ? 0 : intdiv($dark * 100, $total);
        $score += intdiv(abs($percent - 50), 5) * 10;

        return $score;
    }

    /**
     * @param list<list<bool>> $base
     * @param list<list<bool>> $functions
     */
    /**
     * PHPStan reads a write at a computed offset as `array<int, bool>` rather than `list<bool>`, which
     * a caller holding a `list<list<bool>>` may not be given back. Re-indexing the rows is a no-op on a
     * matrix that was dense to begin with — the size loop never leaves a hole — and it is what lets the
     * four drawing helpers keep taking their matrix by reference.
     *
     * @param array<int, array<int, bool>> $matrix
     *
     * @return list<list<bool>>
     */
    private static function asListMatrix(array $matrix): array
    {
        $rows = [];

        foreach ($matrix as $row) {
            $rows[] = array_values($row);
        }

        return $rows;
    }

    private static function set(array &$base, array &$functions, int $size, int $x, int $y, bool $on): void
    {
        if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) {
            return;
        }

        $base[$y][$x] = $on;
        $functions[$y][$x] = true;
    }

    /**
     * @param list<list<bool>> $functions
     */
    private static function reserve(array &$functions, int $size, int $x, int $y): void
    {
        if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) {
            return;
        }

        $functions[$y][$x] = true;
    }

    private static function svgRun(int $startX, int $y, int $length, int $quietZone, int $moduleSize): string
    {
        return sprintf(
            'M%d %dh%dv%dh%dz ',
            ($startX + $quietZone) * $moduleSize,
            ($y + $quietZone) * $moduleSize,
            $length * $moduleSize,
            $moduleSize,
            -$length * $moduleSize,
        );
    }
}
