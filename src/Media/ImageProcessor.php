<?php

declare(strict_types=1);

namespace Prime\Media;

use GdImage;
use Prime\Media\Exception\MediaException;
use Throwable;

/**
 * Native GD image processor for Prime CMS:
 * - Applies EXIF orientation (all 8 transforms) before re-encoding.
 * - Always re-encodes raster originals (stripping EXIF/GPS metadata & neutralizing polyglots).
 * - Preserves animated GIF originals while generating static resized variants.
 * - Downscales originals exceeding `max_dimension` (default 2560 px longest side).
 * - Generates responsive variants (`thumbnail` focal-point crop, `small`, `medium`, `large`,
 *   plus plugin sizes via `media.image_sizes`), never upscaling, in original format + WebP.
 */
final class ImageProcessor
{
    public function __construct(
        private readonly MediaConfig $config,
    ) {
    }

    /**
     * Process an uploaded image from `$sourcePath` into `$destRelativePath` (relative to uploads dir)
     * and generate all configured responsive + WebP variants.
     *
     * @return array{
     *   width: int,
     *   height: int,
     *   size_bytes: int,
     *   variants: array<string, array{path: string, webp?: ?string, width: int, height: int, size_bytes: int}>,
     *   animated_gif: bool
     * }
     */
    public function processUpload(
        string $sourcePath,
        string $destRelativePath,
        string $extension,
        float $focalX = 0.5,
        float $focalY = 0.5,
    ): array {
        $ext = strtolower($extension);
        $uploadsRoot = $this->config->uploadsAbsolutePath();
        $destAbsPath = $uploadsRoot . '/' . ltrim($destRelativePath, '/');
        $destDir = dirname($destAbsPath);

        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            throw new MediaException(sprintf('Impossible de créer le dossier [%s].', $destDir));
        }

        $createdFiles = [];
        $image = null;

        try {
            $animatedGif = $ext === 'gif' && self::isAnimatedGif($sourcePath);
            $orientation = in_array($ext, ['jpg', 'jpeg'], true) ? self::readExifOrientation($sourcePath) : 1;

            $image = $this->loadGdImage($sourcePath, $ext);
            if ($orientation > 1) {
                $image = $this->applyExifOrientation($image, $orientation);
            }

            $origW = imagesx($image);
            $origH = imagesy($image);
            $maxDim = $this->config->maxDimension();

            // Downscale original if longest side exceeds max_dimension (animated GIF kept intact to preserve animation)
            if (!$animatedGif && max($origW, $origH) > $maxDim) {
                [$scaledW, $scaledH] = self::fitDimensions($origW, $origH, $maxDim, $maxDim);
                $resized = $this->resample($image, 0, 0, $origW, $origH, $scaledW, $scaledH, $ext);
                imagedestroy($image);
                $image = $resized;
                $origW = $scaledW;
                $origH = $scaledH;
            }

            // Persist final original
            if ($animatedGif) {
                if (!@copy($sourcePath, $destAbsPath)) {
                    throw new MediaException('Impossible d\'enregistrer le fichier GIF animé.');
                }
            } else {
                $this->saveGdImage($image, $destAbsPath, $ext);
            }
            $createdFiles[] = $destAbsPath;

            $finalSize = filesize($destAbsPath);
            $sizeBytes = $finalSize !== false ? $finalSize : 0;

            $variants = [];

            // WebP companion of original (non-animated, non-webp)
            if (!$animatedGif && $ext !== 'webp' && $this->config->supportsWebp()) {
                $webpRel = self::replaceExtension($destRelativePath, 'webp');
                $webpAbs = $uploadsRoot . '/' . ltrim($webpRel, '/');
                $this->saveGdImage($image, $webpAbs, 'webp');
                $createdFiles[] = $webpAbs;
                $webpBytes = filesize($webpAbs);
                $variants['_original_webp'] = [
                    'path' => $webpRel,
                    'width' => $origW,
                    'height' => $origH,
                    'size_bytes' => $webpBytes !== false ? $webpBytes : 0,
                ];
            }

            // Generate all configured sizes
            $generated = $this->generateVariantsFromImage(
                $image,
                $destRelativePath,
                $ext,
                $focalX,
                $focalY,
                onlySize: null,
                createdFiles: $createdFiles,
            );
            $variants = array_merge($variants, $generated);

            return [
                'width' => $origW,
                'height' => $origH,
                'size_bytes' => $sizeBytes,
                'variants' => $variants,
                'animated_gif' => $animatedGif,
            ];
        } catch (Throwable $e) {
            foreach ($createdFiles as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }

            if ($e instanceof MediaException) {
                throw $e;
            }

            throw new MediaException('Erreur lors du traitement de l\'image : ' . $e->getMessage(), 0, $e);
        } finally {
            if ($image instanceof GdImage) {
                imagedestroy($image);
            }
        }
    }

    /**
     * Regenerate image variants for an existing Media record using its current focal point.
     *
     * @return array<string, array{path: string, webp?: ?string, width?: int, height?: int, size_bytes?: int}>
     */
    public function regenerate(Media $media, ?string $onlySize = null): array
    {
        if (!$media->isImage()) {
            return $media->variants;
        }

        $uploadsRoot = $this->config->uploadsAbsolutePath();
        $sourceAbs = $uploadsRoot . '/' . ltrim($media->diskPath, '/');
        if (!is_file($sourceAbs)) {
            throw new MediaException(sprintf('Le fichier source [%s] est introuvable sur le disque.', $media->diskPath));
        }

        $ext = strtolower($media->extension);
        $animatedGif = $ext === 'gif' && self::isAnimatedGif($sourceAbs);
        $image = $this->loadGdImage($sourceAbs, $ext);
        $createdFiles = [];

        try {
            $origW = imagesx($image);
            $origH = imagesy($image);

            $nextVariants = $onlySize !== null ? $media->variants : [];

            if ($onlySize === null && !$animatedGif && $ext !== 'webp' && $this->config->supportsWebp()) {
                $webpRel = self::replaceExtension($media->diskPath, 'webp');
                $webpAbs = $uploadsRoot . '/' . ltrim($webpRel, '/');
                $this->saveGdImage($image, $webpAbs, 'webp');
                $createdFiles[] = $webpAbs;
                $webpBytes = filesize($webpAbs);
                $nextVariants['_original_webp'] = [
                    'path' => $webpRel,
                    'width' => $origW,
                    'height' => $origH,
                    'size_bytes' => $webpBytes !== false ? $webpBytes : 0,
                ];
            }

            $generated = $this->generateVariantsFromImage(
                $image,
                $media->diskPath,
                $ext,
                $media->focalX,
                $media->focalY,
                onlySize: $onlySize,
                createdFiles: $createdFiles,
            );

            if ($onlySize !== null) {
                if (isset($generated[$onlySize])) {
                    $nextVariants[$onlySize] = $generated[$onlySize];
                } else {
                    unset($nextVariants[$onlySize]);
                }
            } else {
                $nextVariants = array_merge($nextVariants, $generated);
            }

            // Clean up obsolete variant files that are no longer referenced (never deleting original)
            $newPaths = [$media->diskPath => true];
            foreach ($nextVariants as $info) {
                $newPaths[$info['path']] = true;
                if (isset($info['webp']) && $info['webp'] !== '') {
                    $newPaths[$info['webp']] = true;
                }
            }

            foreach ($media->variants as $oldInfo) {
                foreach ([$oldInfo['path'], $oldInfo['webp'] ?? null] as $oldRel) {
                    if (is_string($oldRel) && $oldRel !== '' && !isset($newPaths[$oldRel])) {
                        $oldAbs = $uploadsRoot . '/' . ltrim($oldRel, '/');
                        if (is_file($oldAbs)) {
                            @unlink($oldAbs);
                        }
                    }
                }
            }

            return $nextVariants;
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * Detect whether a GIF file contains multiple animation frames (2+ Graphic Control Extensions).
     */
    public static function isAnimatedGif(string $filePath): bool
    {
        $fp = @fopen($filePath, 'rb');
        if ($fp === false) {
            return false;
        }

        $frames = 0;
        $tail = '';
        try {
            while (!feof($fp) && $frames < 2) {
                $chunk = fread($fp, 1024 * 64);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $buffer = $tail . $chunk;
                $matches = preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $buffer);
                if ($matches !== false) {
                    $frames += $matches;
                }
                $tail = substr($buffer, -16);
            }
        } finally {
            fclose($fp);
        }

        return $frames >= 2;
    }

    /**
     * Read JPEG EXIF Orientation (1-8) using `exif_read_data()` when available,
     * with a pure-PHP APP1/TIFF fallback when `ext-exif` is not installed.
     */
    public static function readExifOrientation(string $filePath): int
    {
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($filePath, 'IFD0', false, false);
            if (is_array($exif) && isset($exif['Orientation']) && is_numeric($exif['Orientation'])) {
                $val = (int) $exif['Orientation'];
                if ($val >= 1 && $val <= 8) {
                    return $val;
                }
            }
        }

        return self::readExifOrientationPurePhp($filePath);
    }

    /**
     * Calculate proportional target dimensions fitting within `($maxW, $maxH)` without upscaling.
     *
     * @return array{0: int, 1: int}
     */
    public static function fitDimensions(int $srcW, int $srcH, int $maxW, ?int $maxH): array
    {
        $ratioW = $maxW / max(1, $srcW);
        $ratioH = $maxH !== null ? ($maxH / max(1, $srcH)) : $ratioW;
        $scale = min(1.0, $ratioW, $ratioH);

        return [
            max(1, (int) round($srcW * $scale)),
            max(1, (int) round($srcH * $scale)),
        ];
    }

    /**
     * Calculate source crop rectangle `(srcX, srcY, srcW, srcH)` centered on `(focalX, focalY)`.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    public static function focalCropRect(
        int $origW,
        int $origH,
        int $targetW,
        int $targetH,
        float $focalX,
        float $focalY,
    ): array {
        $fx = max(0.0, min(1.0, $focalX));
        $fy = max(0.0, min(1.0, $focalY));

        $scale = max($targetW / max(1, $origW), $targetH / max(1, $origH));
        $cropW = min($origW, max(1, (int) round($targetW / $scale)));
        $cropH = min($origH, max(1, (int) round($targetH / $scale)));

        $centerX = $fx * $origW;
        $centerY = $fy * $origH;

        $srcX = (int) round($centerX - ($cropW / 2));
        $srcY = (int) round($centerY - ($cropH / 2));

        $srcX = max(0, min($origW - $cropW, $srcX));
        $srcY = max(0, min($origH - $cropH, $srcY));

        return [
            'x' => $srcX,
            'y' => $srcY,
            'width' => $cropW,
            'height' => $cropH,
        ];
    }

    /**
     * @param list<string> $createdFiles
     * @return array<string, array{path: string, webp?: ?string, width: int, height: int, size_bytes: int}>
     */
    private function generateVariantsFromImage(
        GdImage $source,
        string $baseRelPath,
        string $ext,
        float $focalX,
        float $focalY,
        ?string $onlySize,
        array &$createdFiles,
    ): array {
        $origW = imagesx($source);
        $origH = imagesy($source);
        $uploadsRoot = $this->config->uploadsAbsolutePath();
        $sizes = $this->config->imageSizes();
        $variants = [];

        foreach ($sizes as $name => $spec) {
            if ($onlySize !== null && $name !== $onlySize) {
                continue;
            }

            $targetW = $spec['width'];
            $targetH = $spec['height'];
            $crop = $spec['crop'];

            if ($crop && $targetH !== null) {
                // Never upscale cropped variants
                if ($origW < $targetW || $origH < $targetH) {
                    continue;
                }
                $rect = self::focalCropRect($origW, $origH, $targetW, $targetH, $focalX, $focalY);
                $dstW = $targetW;
                $dstH = $targetH;
                $srcX = $rect['x'];
                $srcY = $rect['y'];
                $srcW = $rect['width'];
                $srcH = $rect['height'];
            } else {
                // Never upscale proportional variants
                if ($origW <= $targetW && ($targetH === null || $origH <= $targetH)) {
                    continue;
                }
                [$dstW, $dstH] = self::fitDimensions($origW, $origH, $targetW, $targetH);
                if ($dstW >= $origW && $dstH >= $origH) {
                    continue;
                }
                $srcX = 0;
                $srcY = 0;
                $srcW = $origW;
                $srcH = $origH;
            }

            $variantImg = $this->resample($source, $srcX, $srcY, $srcW, $srcH, $dstW, $dstH, $ext);

            try {
                $variantRel = self::appendSizeSuffix($baseRelPath, $dstW, $dstH, $ext);
                $variantAbs = $uploadsRoot . '/' . ltrim($variantRel, '/');
                $this->saveGdImage($variantImg, $variantAbs, $ext);
                $createdFiles[] = $variantAbs;

                $varBytes = filesize($variantAbs);
                $entry = [
                    'path' => $variantRel,
                    'width' => $dstW,
                    'height' => $dstH,
                    'size_bytes' => $varBytes !== false ? $varBytes : 0,
                ];

                if ($ext !== 'webp' && $this->config->supportsWebp()) {
                    $webpRel = self::appendSizeSuffix($baseRelPath, $dstW, $dstH, 'webp');
                    $webpAbs = $uploadsRoot . '/' . ltrim($webpRel, '/');
                    $this->saveGdImage($variantImg, $webpAbs, 'webp');
                    $createdFiles[] = $webpAbs;
                    $entry['webp'] = $webpRel;
                    $wBytes = filesize($webpAbs);
                    if ($wBytes !== false) {
                        $entry['size_bytes'] += $wBytes;
                    }
                } elseif ($ext === 'webp') {
                    $entry['webp'] = $variantRel;
                }

                $variants[$name] = $entry;
            } finally {
                imagedestroy($variantImg);
            }
        }

        return $variants;
    }

    private function loadGdImage(string $path, string $ext): GdImage
    {
        $img = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($path),
            'png' => @imagecreatefrompng($path),
            'gif' => @imagecreatefromgif($path),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : false,
            default => false,
        };

        if (!$img instanceof GdImage) {
            throw new MediaException(sprintf('Impossible de décoder l\'image (%s) via GD.', strtoupper($ext)));
        }

        if (in_array($ext, ['png', 'gif', 'webp', 'avif'], true)) {
            if (!imageistruecolor($img)) {
                imagepalettetotruecolor($img);
            }
            imagealphablending($img, true);
            imagesavealpha($img, true);
        }

        return $img;
    }

    private function resample(
        GdImage $source,
        int $srcX,
        int $srcY,
        int $srcW,
        int $srcH,
        int $dstW,
        int $dstH,
        string $ext,
    ): GdImage {
        $dst = imagecreatetruecolor(max(1, $dstW), max(1, $dstH));
        if (!$dst instanceof GdImage) {
            throw new MediaException('Impossible d\'allouer la surface image GD.');
        }

        if (in_array($ext, ['png', 'gif', 'webp', 'avif'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $transparent);
            }
        }

        imagecopyresampled($dst, $source, 0, 0, $srcX, $srcY, $dstW, $dstH, $srcW, $srcH);

        return $dst;
    }

    private function applyExifOrientation(GdImage $image, int $orientation): GdImage
    {
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $image;
            case 3:
                return $this->rotateClean($image, 180);
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);

                return $image;
            case 5:
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $this->rotateClean($image, 90);
            case 6:
                return $this->rotateClean($image, -90);
            case 7:
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $this->rotateClean($image, -90);
            case 8:
                return $this->rotateClean($image, 90);
            default:
                return $image;
        }
    }

    private function rotateClean(GdImage $image, float $angle): GdImage
    {
        $rotated = imagerotate($image, $angle, 0);
        if (!$rotated instanceof GdImage) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }

    private function saveGdImage(GdImage $image, string $path, string $ext): void
    {
        $ok = match ($ext) {
            'jpg', 'jpeg' => @imagejpeg($image, $path, $this->config->jpegQuality()),
            'png' => $this->savePng($image, $path),
            'gif' => @imagegif($image, $path),
            'webp' => $this->saveWebp($image, $path),
            'avif' => $this->saveAvif($image, $path),
            default => false,
        };

        if (!$ok) {
            throw new MediaException(sprintf('Impossible d\'enregistrer l\'image au format %s.', strtoupper($ext)));
        }
    }

    private function savePng(GdImage $image, string $path): bool
    {
        imagesavealpha($image, true);

        return @imagepng($image, $path, 6);
    }

    private function saveWebp(GdImage $image, string $path): bool
    {
        if (!function_exists('imagewebp')) {
            return false;
        }
        imagesavealpha($image, true);

        return @imagewebp($image, $path, $this->config->webpQuality());
    }

    private function saveAvif(GdImage $image, string $path): bool
    {
        if (!function_exists('imageavif')) {
            return false;
        }
        imagesavealpha($image, true);

        return @imageavif($image, $path, $this->config->webpQuality());
    }

    private static function appendSizeSuffix(string $relPath, int $width, int $height, string $ext): string
    {
        $dir = dirname($relPath);
        $stem = pathinfo($relPath, PATHINFO_FILENAME);
        $file = sprintf('%s-%dx%d.%s', $stem, $width, $height, strtolower($ext));

        return $dir !== '.' && $dir !== '' ? $dir . '/' . $file : $file;
    }

    private static function replaceExtension(string $relPath, string $newExt): string
    {
        $dir = dirname($relPath);
        $stem = pathinfo($relPath, PATHINFO_FILENAME);
        $file = $stem . '.' . strtolower($newExt);

        return $dir !== '.' && $dir !== '' ? $dir . '/' . $file : $file;
    }

    /**
     * Pure-PHP reader for JPEG APP1 EXIF Orientation tag (0x0112) when ext-exif is unavailable.
     */
    private static function readExifOrientationPurePhp(string $filePath): int
    {
        $data = @file_get_contents($filePath, false, null, 0, 65536);
        if (!is_string($data) || strlen($data) < 16 || substr($data, 0, 2) !== "\xFF\xD8") {
            return 1;
        }

        $offset = 2;
        $len = strlen($data);
        while ($offset + 4 < $len) {
            if (ord($data[$offset]) !== 0xFF) {
                break;
            }
            $marker = ord($data[$offset + 1]);
            $segLen = (ord($data[$offset + 2]) << 8) | ord($data[$offset + 3]);
            if ($segLen < 2) {
                break;
            }

            if ($marker === 0xE1 && substr($data, $offset + 4, 6) === "Exif\x00\x00") {
                $tiffStart = $offset + 10;
                if ($tiffStart + 8 > $len) {
                    return 1;
                }
                $byteOrder = substr($data, $tiffStart, 2);
                $le = $byteOrder === 'II';
                if (!$le && $byteOrder !== 'MM') {
                    return 1;
                }

                $u16 = static fn (int $pos): int => $le
                    ? (ord($data[$pos]) | (ord($data[$pos + 1]) << 8))
                    : ((ord($data[$pos]) << 8) | ord($data[$pos + 1]));
                $u32 = static fn (int $pos): int => $le
                    ? (ord($data[$pos]) | (ord($data[$pos + 1]) << 8) | (ord($data[$pos + 2]) << 16) | (ord($data[$pos + 3]) << 24))
                    : ((ord($data[$pos]) << 24) | (ord($data[$pos + 1]) << 16) | (ord($data[$pos + 2]) << 8) | ord($data[$pos + 3]));

                $ifdOffset = $tiffStart + $u32($tiffStart + 4);
                if ($ifdOffset + 2 > $len) {
                    return 1;
                }
                $entries = $u16($ifdOffset);
                for ($i = 0; $i < $entries; $i++) {
                    $entryPos = $ifdOffset + 2 + ($i * 12);
                    if ($entryPos + 12 > $len) {
                        break;
                    }
                    if ($u16($entryPos) === 0x0112) {
                        $val = $u16($entryPos + 8);

                        return ($val >= 1 && $val <= 8) ? $val : 1;
                    }
                }

                return 1;
            }

            $offset += 2 + $segLen;
        }

        return 1;
    }
}
