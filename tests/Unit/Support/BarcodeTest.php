<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prime\Support\Barcode;

/**
 * The QR encoder behind the double-authentication provisioning code. Everything asserted here is
 * fixed by ISO/IEC 18004, so a regression in any of it makes the code unreadable by authenticator
 * apps — which is exactly the failure a browser cannot see.
 */
final class BarcodeTest extends TestCase
{
    /**
     * Format information words for error-correction level M, indexed by mask pattern.
     */
    private const FORMAT_INFO = [
        0 => '101010000010010',
        1 => '101000100100101',
        2 => '101111001111100',
        3 => '101101101001011',
        4 => '100010111111001',
        5 => '100000011001110',
        6 => '100111110010111',
        7 => '100101010100000',
    ];

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function payloadProvider(): array
    {
        return [
            'one byte' => ['A', 1, 21],
            'fifteen bytes' => [str_repeat('a', 15), 2, 25],
            'twenty-eight bytes' => [str_repeat('b', 28), 3, 29],
            'short otpauth uri' => [
                'otpauth://totp/Prime%20CMS:admin@example.com?secret=JBSWY3DPEHPK3PXP&issuer=Prime+CMS&algorithm=SHA1&digits=6&period=30',
                7,
                45,
            ],
            'maximum length' => [str_repeat('c', 213), 10, 57],
        ];
    }

    #[DataProvider('payloadProvider')]
    public function testTheSmallestVersionHoldingThePayloadIsChosen(string $payload, int $version, int $size): void
    {
        $built = Barcode::build($payload);

        $this->assertSame($version, $built['version']);
        $this->assertSame($size, $built['size']);
        $this->assertCount($size, $built['matrix']);
        $this->assertSame($size, count($built['matrix'][0]));
        $this->assertGreaterThan(0, $built['capacity']);
    }

    public function testThePayloadIsRejectedWhenNoSupportedVersionCanHoldIt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Barcode::build(str_repeat('x', 214));
    }

    public function testAnEmptyPayloadIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Barcode::matrix('');
    }

    #[DataProvider('payloadProvider')]
    public function testFinderSeparatorsAndTimingPatternsAreInPlace(string $payload, int $version, int $size): void
    {
        $matrix = Barcode::matrix($payload);

        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$top, $left]) {
            for ($dy = 0; $dy < 7; ++$dy) {
                for ($dx = 0; $dx < 7; ++$dx) {
                    $ring = max(abs($dy - 3), abs($dx - 3));
                    $this->assertSame(
                        $ring !== 2,
                        $matrix[$top + $dy][$left + $dx],
                        sprintf('finder module (%d, %d) of the symbol', $top + $dy, $left + $dx),
                    );
                }
            }

            // The one-module light separator around the 7x7 finder.
            $separatorRow = $top === 0 ? 7 : $top - 1;
            $separatorCol = $left === 0 ? 7 : $left - 1;
            $this->assertFalse($matrix[$separatorRow][$left]);
            $this->assertFalse($matrix[$top][$separatorCol]);
        }

        for ($i = 8; $i < $size - 8; ++$i) {
            $this->assertSame($i % 2 === 0, $matrix[6][$i], 'horizontal timing pattern');
            $this->assertSame($i % 2 === 0, $matrix[$i][6], 'vertical timing pattern');
        }

        $this->assertTrue($matrix[$size - 8][8], 'the module below the top-right format copy is always dark');
    }

    /**
     * @return array<string, array{0: int, 1: list<int>}>
     */
    public static function alignmentProvider(): array
    {
        return [
            'version 1 has none' => [1, []],
            'version 3' => [3, [6, 22]],
            'version 6' => [6, [6, 34]],
            'version 7' => [7, [6, 22, 38]],
            'version 10' => [10, [6, 28, 50]],
        ];
    }

    #[DataProvider('alignmentProvider')]
    /**
     * @param list<int> $positions
     */
    public function testAlignmentPatternsSitOnTheCoordinatesOfTheStandard(int $version, array $positions): void
    {
        $payload = str_repeat('a', self::firstByteLengthFor($version));
        $built = Barcode::build($payload);
        $this->assertSame($version, $built['version'], 'the fixture must land on the version under test');

        $matrix = $built['matrix'];
        $size = $built['size'];
        $drawn = [];

        foreach ($positions as $y) {
            foreach ($positions as $x) {
                $overlapsFinder = ($y === 6 && $x === 6) || ($y === 6 && $x === $size - 7) || ($y === $size - 7 && $x === 6);
                $isPattern = $y >= 2 && $y <= $size - 3 && $x >= 2 && $x <= $size - 3 && self::isAlignmentPattern($matrix, $y, $x);

                if (!$overlapsFinder) {
                    $this->assertTrue($isPattern, sprintf('alignment pattern expected at (%d, %d)', $y, $x));
                    $drawn[] = [$y, $x];
                } else {
                    $this->assertFalse($isPattern, sprintf('no alignment pattern over the finder at (%d, %d)', $y, $x));
                }
            }
        }

        $this->assertCount(count($positions) ** 2 - (count($positions) === 0 ? 0 : 3), $drawn);
    }

    #[DataProvider('payloadProvider')]
    public function testTheFormatInformationAdvertisesTheMaskThatWasApplied(string $payload, int $version, int $size): void
    {
        $built = Barcode::build($payload);
        $matrix = $built['matrix'];

        $read = '';
        for ($bit = 14; $bit >= 0; --$bit) {
            $position = match (true) {
                $bit <= 5 => [$bit, 8],
                $bit === 6 => [7, 8],
                $bit === 7 => [8, 8],
                $bit === 8 => [8, 7],
                default => [8, 14 - $bit],
            };
            $read .= $matrix[$position[0]][$position[1]] ? '1' : '0';
        }

        $this->assertSame(self::FORMAT_INFO[$built['mask']], $read, 'copy 1 of the format information');

        $second = '';
        for ($bit = 14; $bit >= 0; --$bit) {
            $position = $bit >= 7 ? [8, $size - 15 + $bit] : [$size - 1 - $bit, 8];
            $second .= $matrix[$position[0]][$position[1]] ? '1' : '0';
        }

        $this->assertSame($read, $second, 'both copies of the format information must agree');
    }

    public function testTheSvgDocumentDrawsExactlyTheModuleMatrix(): void
    {
        $payload = 'otpauth://totp/Prime%20CMS:admin@example.com?secret=JBSWY3DPEHPK3PXP&issuer=Prime+CMS';
        $matrix = Barcode::matrix($payload);
        $size = count($matrix);
        $module = 3;
        $quiet = 4;

        $svg = Barcode::toSvg($payload, $quiet, $module);

        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertStringEndsWith('</svg>', $svg);
        $this->assertStringNotContainsString('<?xml', $svg, 'the admin screen inlines this markup');
        $this->assertStringNotContainsString('<script', $svg);

        $total = ($size + 2 * $quiet) * $module;
        $this->assertStringContainsString(sprintf('viewBox="0 0 %d %d"', $total, $total), $svg);

        $rebuilt = array_fill(0, $size, array_fill(0, $size, false));
        preg_match_all('/M(\d+) (\d+)h(\d+)v\d+h(-\d+)z/', $svg, $runs, PREG_SET_ORDER);
        $this->assertGreaterThan(0, count($runs));

        foreach ($runs as $run) {
            $y = intdiv((int) $run[2], $module) - $quiet;
            $x = intdiv((int) $run[1], $module) - $quiet;
            $length = (int) $run[3] / $module;

            $this->assertSame($length, -(int) $run[4] / $module, 'each run closes on itself');

            for ($offset = 0; $offset < $length; ++$offset) {
                $rebuilt[$y][$x + $offset] = true;
            }
        }

        $this->assertSame($matrix, $rebuilt, 'the rendered path must be the matrix, module for module');
    }

    public function testTheTerminalRenderingIsSquareAndPadded(): void
    {
        $matrix = Barcode::matrix('prime');
        $size = count($matrix);
        $quietZone = 1;
        $text = Barcode::toText($matrix, '#', '.', $quietZone);
        $lines = explode("\n", rtrim($text, "\n"));

        $this->assertCount($size * 2 + 4 * $quietZone, $lines, 'every module is two characters tall, one quiet row above and below');
        foreach ($lines as $line) {
            $this->assertSame(strlen($lines[0]), strlen($line), 'the quiet zone keeps every line the same width');
        }

        $this->assertStringStartsWith('.', $lines[1]);
    }

    private static function isAlignmentPattern(array $matrix, int $y, int $x): bool
    {
        for ($dy = -2; $dy <= 2; ++$dy) {
            for ($dx = -2; $dx <= 2; ++$dx) {
                if ((max(abs($dy), abs($dx)) !== 1) !== $matrix[$y + $dy][$x + $dx]) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function firstByteLengthFor(int $version): int
    {
        return [1 => 1, 3 => 28, 6 => 106, 7 => 122, 10 => 213][$version];
    }
}
