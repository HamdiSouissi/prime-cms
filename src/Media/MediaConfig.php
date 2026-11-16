<?php

declare(strict_types=1);

namespace Prime\Media;

use Prime\Hooks\Hooks;
use Prime\Kernel\Application;

/**
 * Typed access to `config/media.php` with hook extensibility (`media.allowed_types`,
 * `media.image_sizes`) and PHP ini upload-limit awareness.
 */
final class MediaConfig
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly string $basePath,
        private readonly array $config = [],
        private readonly ?Hooks $hooks = null,
    ) {
    }

    public static function fromApplication(Application $app): self
    {
        $raw = $app->config()->get('media', []);
        $hooks = $app->container()->has(Hooks::class) ? $app->get(Hooks::class) : null;

        return new self(
            basePath: $app->basePath(),
            config: is_array($raw) ? $raw : [],
            hooks: $hooks instanceof Hooks ? $hooks : null,
        );
    }

    public function uploadsAbsolutePath(): string
    {
        $rel = is_string($this->config['uploads_dir'] ?? null)
            ? trim($this->config['uploads_dir'], '/')
            : 'content/uploads';

        return rtrim($this->basePath, '/') . '/' . $rel;
    }

    public function publicLinkAbsolutePath(): string
    {
        $rel = is_string($this->config['public_link'] ?? null)
            ? trim($this->config['public_link'], '/')
            : 'public/uploads';

        return rtrim($this->basePath, '/') . '/' . $rel;
    }

    public function urlPrefix(): string
    {
        $prefix = is_string($this->config['url_prefix'] ?? null)
            ? trim($this->config['url_prefix'])
            : '/uploads';

        return '/' . trim($prefix, '/');
    }

    public function configuredMaxUploadMb(): int
    {
        return max(1, (int) ($this->config['max_upload_mb'] ?? 20));
    }

    public function configuredMaxUploadBytes(): int
    {
        return $this->configuredMaxUploadMb() * 1024 * 1024;
    }

    /**
     * Effective maximum upload size in bytes, factoring in PHP `upload_max_filesize`
     * and `post_max_size`, plus whether PHP limits are lower than the CMS setting.
     *
     * @return array{bytes: int, megabytes: float, phpLimited: bool, phpLimitBytes: ?int, configuredBytes: int}
     */
    public function effectiveUploadLimit(?string $uploadMaxIni = null, ?string $postMaxIni = null): array
    {
        $configured = $this->configuredMaxUploadBytes();
        $uploadRaw = $uploadMaxIni ?? (string) ini_get('upload_max_filesize');
        $postRaw = $postMaxIni ?? (string) ini_get('post_max_size');

        $phpLimits = [];
        $uBytes = self::parseIniBytes($uploadRaw);
        if ($uBytes > 0) {
            $phpLimits[] = $uBytes;
        }
        $pBytes = self::parseIniBytes($postRaw);
        if ($pBytes > 0) {
            $phpLimits[] = $pBytes;
        }

        $phpLimitBytes = $phpLimits !== [] ? min($phpLimits) : null;
        $effective = $phpLimitBytes !== null ? min($configured, $phpLimitBytes) : $configured;

        return [
            'bytes' => $effective,
            'megabytes' => round($effective / (1024 * 1024), 2),
            'phpLimited' => $phpLimitBytes !== null && $phpLimitBytes < $configured,
            'phpLimitBytes' => $phpLimitBytes,
            'configuredBytes' => $configured,
        ];
    }

    public function maxMegapixels(): int
    {
        return max(1, (int) ($this->config['max_megapixels'] ?? 40));
    }

    public function maxPixels(): int
    {
        return $this->maxMegapixels() * 1_000_000;
    }

    public function maxDimension(): int
    {
        return max(320, (int) ($this->config['max_dimension'] ?? 2560));
    }

    public function webpQuality(): int
    {
        return max(1, min(100, (int) ($this->config['webp_quality'] ?? 82)));
    }

    public function jpegQuality(): int
    {
        return max(1, min(100, (int) ($this->config['jpeg_quality'] ?? 85)));
    }

    public function supportsWebp(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatefromwebp');
    }

    public function supportsAvif(): bool
    {
        if (!function_exists('imageavif') || !function_exists('imagecreatefromavif')) {
            return false;
        }
        if (function_exists('gd_info')) {
            $info = gd_info();
            if (array_key_exists('AVIF Support', $info) && !$info['AVIF Support']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Allowed file types grouped by logical kind => [ext => list<mime>],
     * after filtering out unsupported AVIF and applying the `media.allowed_types` filter.
     *
     * @return array<string, array<string, list<string>>>
     */
    public function allowedTypes(): array
    {
        $raw = is_array($this->config['allowed_types'] ?? null)
            ? $this->config['allowed_types']
            : self::defaultAllowedTypes();

        $normalized = $this->normalizeAllowedTypes($raw);

        // Automatically disable AVIF when host GD does not support it
        if (!$this->supportsAvif() && isset($normalized[Media::KIND_IMAGE]['avif'])) {
            unset($normalized[Media::KIND_IMAGE]['avif']);
        }

        if ($this->hooks !== null) {
            $filtered = $this->hooks->apply('media.allowed_types', $normalized);
            if (is_array($filtered)) {
                $normalized = $this->normalizeAllowedTypes($filtered);
            }
        }

        return $normalized;
    }

    /**
     * Flat lookup map of allowed extension => ['kind' => string, 'mimes' => list<string>].
     *
     * @return array<string, array{kind: string, mimes: list<string>}>
     */
    public function allowedExtensionMap(): array
    {
        $map = [];
        foreach ($this->allowedTypes() as $kind => $extensions) {
            foreach ($extensions as $ext => $mimes) {
                $map[$ext] = [
                    'kind' => $kind,
                    'mimes' => $mimes,
                ];
            }
        }

        return $map;
    }

    /**
     * Configured image sizes after applying the `media.image_sizes` filter.
     *
     * @return array<string, array{width: int, height: ?int, crop: bool}>
     */
    public function imageSizes(): array
    {
        $raw = is_array($this->config['image_sizes'] ?? null)
            ? $this->config['image_sizes']
            : self::defaultImageSizes();

        $normalized = $this->normalizeImageSizes($raw);

        if ($this->hooks !== null) {
            $filtered = $this->hooks->apply('media.image_sizes', $normalized);
            if (is_array($filtered)) {
                $normalized = $this->normalizeImageSizes($filtered);
            }
        }

        return $normalized;
    }

    public static function parseIniBytes(string $value): int
    {
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '-1') {
            return 0;
        }

        if (preg_match('/^(\d+)\s*([KMG])?B?$/i', $trimmed, $m) !== 1) {
            return max(0, (int) $trimmed);
        }

        $num = (int) $m[1];
        $unit = strtoupper($m[2] ?? '');

        return match ($unit) {
            'G' => $num * 1024 * 1024 * 1024,
            'M' => $num * 1024 * 1024,
            'K' => $num * 1024,
            default => $num,
        };
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    public static function defaultAllowedTypes(): array
    {
        return [
            Media::KIND_IMAGE => [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'gif' => ['image/gif'],
                'webp' => ['image/webp'],
                'avif' => ['image/avif'],
            ],
            Media::KIND_DOCUMENT => [
                'pdf' => ['application/pdf'],
                'docx' => [
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/zip',
                ],
                'xlsx' => [
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/zip',
                ],
                'pptx' => [
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'application/zip',
                ],
                'odt' => ['application/vnd.oasis.opendocument.text'],
                'ods' => ['application/vnd.oasis.opendocument.spreadsheet'],
                'txt' => ['text/plain'],
                'csv' => ['text/csv', 'text/plain', 'application/csv'],
            ],
            Media::KIND_AUDIO => [
                'mp3' => ['audio/mpeg', 'audio/mp3'],
                'ogg' => ['audio/ogg', 'application/ogg'],
                'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave'],
                'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
            ],
            Media::KIND_VIDEO => [
                'mp4' => ['video/mp4'],
                'webm' => ['video/webm', 'audio/webm'],
            ],
            Media::KIND_ARCHIVE => [
                'zip' => ['application/zip', 'application/x-zip-compressed'],
            ],
        ];
    }

    /**
     * @return array<string, array{width: int, height: ?int, crop: bool}>
     */
    public static function defaultImageSizes(): array
    {
        return [
            'thumbnail' => ['width' => 150, 'height' => 150, 'crop' => true],
            'small' => ['width' => 480, 'height' => null, 'crop' => false],
            'medium' => ['width' => 960, 'height' => null, 'crop' => false],
            'large' => ['width' => 1600, 'height' => null, 'crop' => false],
        ];
    }

    /**
     * @param array<mixed, mixed> $raw
     * @return array<string, array<string, list<string>>>
     */
    private function normalizeAllowedTypes(array $raw): array
    {
        $result = [];
        foreach ($raw as $kind => $extensions) {
            if (!is_string($kind) || !is_array($extensions)) {
                continue;
            }
            $cleanKind = in_array($kind, Media::ALL_KINDS, true) ? $kind : Media::KIND_OTHER;
            foreach ($extensions as $ext => $mimes) {
                if (!is_string($ext)) {
                    continue;
                }
                $cleanExt = strtolower(ltrim(trim($ext), '.'));
                if ($cleanExt === '' || preg_match('/^[a-z0-9]{1,16}$/', $cleanExt) !== 1) {
                    continue;
                }
                $mimeList = is_array($mimes) ? $mimes : (is_string($mimes) ? [$mimes] : []);
                $cleanMimes = [];
                foreach ($mimeList as $m) {
                    if (is_string($m) && trim($m) !== '') {
                        $cleanMimes[] = strtolower(trim($m));
                    }
                }
                if ($cleanMimes !== []) {
                    $result[$cleanKind][$cleanExt] = array_values(array_unique($cleanMimes));
                }
            }
        }

        return $result;
    }

    /**
     * @param array<mixed, mixed> $raw
     * @return array<string, array{width: int, height: ?int, crop: bool}>
     */
    private function normalizeImageSizes(array $raw): array
    {
        $result = [];
        foreach ($raw as $name => $def) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_-]{0,40}$/', $name) !== 1 || !is_array($def)) {
                continue;
            }
            $width = is_numeric($def['width'] ?? null) ? (int) $def['width'] : 0;
            if ($width < 16 || $width > 8000) {
                continue;
            }
            $height = isset($def['height']) && is_numeric($def['height']) ? (int) $def['height'] : null;
            if ($height !== null && ($height < 16 || $height > 8000)) {
                $height = null;
            }
            $crop = !empty($def['crop']) && $height !== null;
            $result[$name] = [
                'width' => $width,
                'height' => $height,
                'crop' => $crop,
            ];
        }

        return $result;
    }
}
