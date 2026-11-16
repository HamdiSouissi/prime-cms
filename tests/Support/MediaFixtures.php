<?php

declare(strict_types=1);

namespace Prime\Tests\Support;

use RuntimeException;
use ZipArchive;

/**
 * Deterministic generator for real binary media test fixtures (JPEG, EXIF-rotated JPEG,
 * transparent PNG, GIF, WebP, PDF, ZIP, polyglot JPEG+PHP, fake-MIME PHP/SVG, and PNG pixel-bomb).
 */
final class MediaFixtures
{
    /**
     * Create a valid RGB JPEG image of given dimensions (left half red, right half blue for focal-crop testing).
     */
    public static function createJpeg(string $path, int $width = 800, int $height = 600): string
    {
        $im = imagecreatetruecolor($width, $height);
        if ($im === false) {
            throw new RuntimeException('GD failed to allocate JPEG canvas.');
        }

        $red = (int) imagecolorallocate($im, 210, 40, 40);
        $blue = (int) imagecolorallocate($im, 30, 80, 210);
        imagefilledrectangle($im, 0, 0, (int) floor($width / 2) - 1, $height - 1, $red);
        imagefilledrectangle($im, (int) floor($width / 2), 0, $width - 1, $height - 1, $blue);

        imagejpeg($im, $path, 90);
        imagedestroy($im);

        return $path;
    }

    /**
     * Create a valid JPEG containing a crafted TIFF/EXIF APP1 header with Orientation = 6 (90° CW)
     * plus an EXIF Make tag ("PrimeCamera") so tests can verify both auto-rotation and EXIF stripping.
     */
    public static function createJpegWithExifOrientation6(string $path, int $width = 120, int $height = 80): string
    {
        self::createJpeg($path, $width, $height);
        $raw = (string) file_get_contents($path);
        // Must begin with SOI 0xFF 0xD8
        if (!str_starts_with($raw, "\xFF\xD8")) {
            throw new RuntimeException('Generated JPEG missing SOI marker.');
        }

        // Little-endian TIFF header + IFD0 with 2 entries: Make (0x010F) & Orientation (0x0112 = 6)
        $makeStr = "PrimeCamera\0";
        $ifdOffset = 8;
        $numEntries = 2;
        $dataOffset = $ifdOffset + 2 + ($numEntries * 12) + 4; // 8 + 2 + 24 + 4 = 38

        $tiff = 'II' . pack('v', 42) . pack('V', $ifdOffset);
        $tiff .= pack('v', $numEntries);
        // Entry 1: Tag 0x010F (Make), Type 2 (ASCII), Count 12, Offset = $dataOffset
        $tiff .= pack('vvVV', 0x010F, 2, strlen($makeStr), $dataOffset);
        // Entry 2: Tag 0x0112 (Orientation), Type 3 (SHORT), Count 1, Value = 6
        $tiff .= pack('vvVvv', 0x0112, 3, 1, 6, 0);
        // Next IFD = 0
        $tiff .= pack('V', 0);
        $tiff .= $makeStr;

        $app1Payload = "Exif\0\0" . $tiff;
        $app1Segment = "\xFF\xE1" . pack('n', strlen($app1Payload) + 2) . $app1Payload;

        $withExif = "\xFF\xD8" . $app1Segment . substr($raw, 2);
        file_put_contents($path, $withExif);

        return $path;
    }

    /**
     * Create a valid RGBA PNG with transparent top-left corner and opaque bottom-right quadrant.
     */
    public static function createTransparentPng(string $path, int $width = 640, int $height = 480): string
    {
        $im = imagecreatetruecolor($width, $height);
        if ($im === false) {
            throw new RuntimeException('GD failed to allocate PNG canvas.');
        }

        imagealphablending($im, false);
        imagesavealpha($im, true);

        $transparent = (int) imagecolorallocatealpha($im, 0, 0, 0, 127);
        $emerald = (int) imagecolorallocatealpha($im, 16, 185, 129, 0);
        imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, $transparent);
        imagefilledrectangle($im, (int) ($width / 2), (int) ($height / 2), $width - 1, $height - 1, $emerald);

        imagepng($im, $path, 6);
        imagedestroy($im);

        return $path;
    }

    /**
     * Create a valid GIF image.
     */
    public static function createGif(string $path, int $width = 200, int $height = 150): string
    {
        $im = imagecreate($width, $height);
        if ($im === false) {
            throw new RuntimeException('GD failed to allocate GIF canvas.');
        }

        imagecolorallocate($im, 99, 102, 241);
        imagegif($im, $path);
        imagedestroy($im);

        return $path;
    }

    /**
     * Create a valid WebP image.
     */
    public static function createWebp(string $path, int $width = 600, int $height = 400): string
    {
        $im = imagecreatetruecolor($width, $height);
        if ($im === false) {
            throw new RuntimeException('GD failed to allocate WebP canvas.');
        }

        $indigo = (int) imagecolorallocate($im, 79, 70, 229);
        imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, $indigo);
        imagewebp($im, $path, 82);
        imagedestroy($im);

        return $path;
    }

    /**
     * Create a valid JPEG with an embedded PHP comment payload in the header (polyglot attack).
     */
    public static function createPolyglotJpegWithPhp(string $path): string
    {
        self::createJpeg($path, 400, 300);
        $raw = (string) file_get_contents($path);
        $payload = '<?php echo "PRIME_POLYGLOT_MARKER"; system($_GET["c"]); ?>';
        $comSegment = "\xFF\xFE" . pack('n', strlen($payload) + 2) . $payload;
        file_put_contents($path, "\xFF\xD8" . $comSegment . substr($raw, 2) . $payload);

        return $path;
    }

    /**
     * Create a valid JPEG (> 20 KB) with a trailing PHP payload appended past EOF/header scan
     * so tests verify GD re-encoding destroys even deep trailing bytes.
     */
    public static function createJpegWithDeepTrailingPhp(string $path): string
    {
        $im = imagecreatetruecolor(600, 600);
        if ($im === false) {
            throw new RuntimeException('GD failed to allocate noisy JPEG canvas.');
        }
        // High-frequency pattern ensures JPEG exceeds 20 KB cleanly
        for ($y = 0; $y < 600; $y += 4) {
            for ($x = 0; $x < 600; $x += 4) {
                $col = (int) imagecolorallocate($im, ($x * 7 + $y * 13) % 256, ($x * 11 + $y * 3) % 256, ($x * 5 + $y * 17) % 256);
                imagefilledrectangle($im, $x, $y, $x + 3, $y + 3, $col);
            }
        }
        imagejpeg($im, $path, 92);
        imagedestroy($im);

        file_put_contents($path, '<?php echo "PRIME_TRAILING_MARKER"; ?>', FILE_APPEND);

        return $path;
    }

    /**
     * Create a tiny PNG file whose IHDR claims 10000×10000 pixels (100 MP > 40 MP DoS limit).
     */
    public static function createPixelBombPng(string $path, int $claimedWidth = 10000, int $claimedHeight = 10000): string
    {
        // Minimal PNG signature + IHDR chunk with huge width/height
        $sig = "\x89PNG\r\n\x1A\n";
        $ihdrData = pack('NNCCCCC', $claimedWidth, $claimedHeight, 8, 2, 0, 0, 0);
        $ihdrChunk = pack('N', 13) . 'IHDR' . $ihdrData . pack('N', crc32('IHDR' . $ihdrData));
        $iendChunk = pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
        file_put_contents($path, $sig . $ihdrChunk . $iendChunk);

        return $path;
    }

    /**
     * Create a minimal valid PDF 1.4 document.
     */
    public static function createPdf(string $path): string
    {
        $pdf = "%PDF-1.4\n1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n"
            . "2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj\n"
            . "3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>endobj\n"
            . "xref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n"
            . "trailer<< /Size 4 /Root 1 0 R >>\nstartxref\n190\n%%EOF\n";
        file_put_contents($path, $pdf);

        return $path;
    }

    /**
     * Create a valid ZIP archive containing a harmless readme.txt.
     */
    public static function createZip(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Failed to create ZIP fixture.');
        }
        $zip->addFromString('readme.txt', "Prime CMS Phase 7 Archive Fixture\n");
        $zip->close();

        return $path;
    }

    /**
     * Build a PHP `$_FILES`-compatible associative array for a local fixture file.
     *
     * @return array{name: string, tmp_name: string, error: int, size: int, type: string}
     */
    public static function uploadedFile(string $tmpPath, string $clientName, string $clientMime = 'application/octet-stream'): array
    {
        return [
            'name' => $clientName,
            'tmp_name' => $tmpPath,
            'error' => UPLOAD_ERR_OK,
            'size' => is_file($tmpPath) ? (int) filesize($tmpPath) : 0,
            'type' => $clientMime,
        ];
    }
}
