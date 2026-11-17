<?php

declare(strict_types=1);

namespace Prime\Media;

/**
 * Immutable value object representing a media asset stored in `pc_media`.
 */
final readonly class Media
{
    public const KIND_IMAGE = 'image';
    public const KIND_DOCUMENT = 'document';
    public const KIND_AUDIO = 'audio';
    public const KIND_VIDEO = 'video';
    public const KIND_ARCHIVE = 'archive';
    public const KIND_OTHER = 'other';

    /**
     * @var list<string>
     */
    public const ALL_KINDS = [
        self::KIND_IMAGE,
        self::KIND_DOCUMENT,
        self::KIND_AUDIO,
        self::KIND_VIDEO,
        self::KIND_ARCHIVE,
        self::KIND_OTHER,
    ];

    /**
     * @var array<string, string>
     */
    public const KIND_LABELS = [
        self::KIND_IMAGE => 'Image',
        self::KIND_DOCUMENT => 'Document',
        self::KIND_AUDIO => 'Audio',
        self::KIND_VIDEO => 'Vidéo',
        self::KIND_ARCHIVE => 'Archive',
        self::KIND_OTHER => 'Fichier',
    ];

    /**
     * @param array<string, array{path: string, webp?: ?string, width?: int, height?: int, size_bytes?: int}> $variants
     */
    public function __construct(
        public int $id,
        public ?int $folderId,
        public ?int $authorId,
        public string $diskPath,
        public string $filename,
        public string $originalName,
        public string $extension,
        public string $mimeType,
        public string $kind,
        public int $sizeBytes,
        public ?int $width,
        public ?int $height,
        public string $checksum,
        public string $alt,
        public string $title,
        public ?string $caption,
        public ?string $description,
        public float $focalX,
        public float $focalY,
        public array $variants,
        public string $createdAt,
        public string $updatedAt,
        public ?string $authorName = null,
        public int $usageCount = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row, ?string $authorName = null, int $usageCount = 0): self
    {
        $rawVariants = $row['variants'] ?? null;
        $variants = [];
        if (is_string($rawVariants) && $rawVariants !== '') {
            $decoded = json_decode($rawVariants, true);
            if (is_array($decoded)) {
                $variants = self::normalizeVariants($decoded);
            }
        } elseif (is_array($rawVariants)) {
            $variants = self::normalizeVariants($rawVariants);
        }

        $focalX = is_numeric($row['focal_x'] ?? null) ? (float) $row['focal_x'] : 0.5;
        $focalY = is_numeric($row['focal_y'] ?? null) ? (float) $row['focal_y'] : 0.5;

        return new self(
            id: (int) ($row['id'] ?? 0),
            folderId: isset($row['folder_id']) && $row['folder_id'] !== '' ? (int) $row['folder_id'] : null,
            authorId: isset($row['author_id']) && $row['author_id'] !== '' ? (int) $row['author_id'] : null,
            diskPath: (string) ($row['disk_path'] ?? ''),
            filename: (string) ($row['filename'] ?? ''),
            originalName: (string) ($row['original_name'] ?? ''),
            extension: strtolower((string) ($row['extension'] ?? '')),
            mimeType: (string) ($row['mime_type'] ?? 'application/octet-stream'),
            kind: (string) ($row['kind'] ?? self::KIND_OTHER),
            sizeBytes: max(0, (int) ($row['size_bytes'] ?? 0)),
            width: isset($row['width']) && $row['width'] !== '' ? (int) $row['width'] : null,
            height: isset($row['height']) && $row['height'] !== '' ? (int) $row['height'] : null,
            checksum: (string) ($row['checksum'] ?? ''),
            alt: (string) ($row['alt'] ?? ''),
            title: (string) ($row['title'] ?? ''),
            caption: isset($row['caption']) && $row['caption'] !== '' ? (string) $row['caption'] : null,
            description: isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
            focalX: max(0.0, min(1.0, $focalX)),
            focalY: max(0.0, min(1.0, $focalY)),
            variants: $variants,
            createdAt: (string) ($row['created_at'] ?? ''),
            updatedAt: (string) ($row['updated_at'] ?? ''),
            authorName: $authorName ?? (isset($row['author_name']) ? (string) $row['author_name'] : null),
            usageCount: isset($row['usage_count']) ? max(0, (int) $row['usage_count']) : $usageCount,
        );
    }

    public function url(string $urlPrefix = '/uploads'): string
    {
        return rtrim($urlPrefix, '/') . '/' . ltrim($this->diskPath, '/');
    }

    /**
     * Resolve the public URL of a generated variant, falling back cleanly to the original URL.
     */
    public function variantUrl(?string $size = null, bool $webp = false, string $urlPrefix = '/uploads'): string
    {
        $prefix = rtrim($urlPrefix, '/');

        if ($size === null || $size === '' || $size === 'full' || $size === 'original') {
            if ($webp && isset($this->variants['_original_webp']['path'])) {
                return $prefix . '/' . ltrim($this->variants['_original_webp']['path'], '/');
            }

            return $this->url($prefix);
        }

        $variant = $this->variants[$size] ?? null;
        if ($variant === null) {
            if ($webp && isset($this->variants['_original_webp']['path'])) {
                return $prefix . '/' . ltrim($this->variants['_original_webp']['path'], '/');
            }

            return $this->url($prefix);
        }

        if ($webp && isset($variant['webp']) && $variant['webp'] !== '') {
            return $prefix . '/' . ltrim($variant['webp'], '/');
        }

        return $prefix . '/' . ltrim($variant['path'], '/');
    }

    /**
     * Build a responsive `srcset` attribute string across non-cropped widths (plus original).
     */
    public function srcset(bool $webp = false, string $urlPrefix = '/uploads'): string
    {
        if (!$this->isImage()) {
            return '';
        }

        $prefix = rtrim($urlPrefix, '/');
        $byWidth = [];

        foreach ($this->variants as $name => $info) {
            if ($name === 'thumbnail' || $name === '_original_webp') {
                continue;
            }
            $w = $info['width'] ?? 0;
            if ($w <= 0) {
                continue;
            }
            // Skip square/cropped variants from proportional srcset when aspect ratio differs materially
            $h = $info['height'] ?? 0;
            if ($this->width !== null && $this->height !== null && $this->width > 0 && $this->height > 0 && $h > 0) {
                $origRatio = $this->width / $this->height;
                $varRatio = $w / $h;
                if (abs($origRatio - $varRatio) > 0.12) {
                    continue;
                }
            }

            if ($webp) {
                if (isset($info['webp']) && $info['webp'] !== '') {
                    $byWidth[$w] = $prefix . '/' . ltrim($info['webp'], '/');
                }
            } else {
                $byWidth[$w] = $prefix . '/' . ltrim($info['path'], '/');
            }
        }

        // Include original image (or its WebP companion) as the largest descriptor
        if ($this->width !== null && $this->width > 0) {
            if ($webp) {
                if ($this->extension === 'webp') {
                    $byWidth[$this->width] = $this->url($prefix);
                } elseif (isset($this->variants['_original_webp']['path'])) {
                    $byWidth[$this->width] = $prefix . '/' . ltrim($this->variants['_original_webp']['path'], '/');
                }
            } else {
                $byWidth[$this->width] = $this->url($prefix);
            }
        }

        if ($byWidth === []) {
            return '';
        }

        ksort($byWidth, SORT_NUMERIC);
        $entries = [];
        foreach ($byWidth as $w => $u) {
            $entries[] = sprintf('%s %dw', $u, $w);
        }

        return implode(', ', $entries);
    }

    /**
     * CSS `object-position` percentage string reflecting this image's focal point.
     */
    public function focalPointCss(): string
    {
        return sprintf('%s%% %s%%', round($this->focalX * 100, 1), round($this->focalY * 100, 1));
    }

    public function displayTitle(): string
    {
        return trim($this->title) !== '' ? $this->title : $this->originalName;
    }

    public function isImage(): bool
    {
        return $this->kind === self::KIND_IMAGE;
    }

    public function isDocument(): bool
    {
        return $this->kind === self::KIND_DOCUMENT;
    }

    public function isAudio(): bool
    {
        return $this->kind === self::KIND_AUDIO;
    }

    public function isVideo(): bool
    {
        return $this->kind === self::KIND_VIDEO;
    }

    public function isArchive(): bool
    {
        return $this->kind === self::KIND_ARCHIVE;
    }

    public function kindLabel(): string
    {
        $key = 'core.media.kinds.' . (isset(self::KIND_LABELS[$this->kind]) ? $this->kind : self::KIND_OTHER);
        $translated = t($key);

        return $translated !== $key ? $translated : (self::KIND_LABELS[$this->kind] ?? 'Fichier');
    }

    public function humanSize(): string
    {
        return \Prime\I18n\LocaleFormatter::fallback()->bytes($this->sizeBytes);
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' o';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', ' ') . ' Ko';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 2, ',', ' ') . ' Mo';
        }

        return number_format($bytes / (1024 * 1024 * 1024), 2, ',', ' ') . ' Go';
    }

    /**
     * Collect every relative upload file path belonging to this media item (original + variants + WebP).
     *
     * @return list<string>
     */
    public function allRelativePaths(): array
    {
        $paths = [];
        if ($this->diskPath !== '') {
            $paths[$this->diskPath] = true;
        }

        foreach ($this->variants as $variant) {
            if ($variant['path'] !== '') {
                $paths[$variant['path']] = true;
            }
            if (isset($variant['webp']) && $variant['webp'] !== '') {
                $paths[$variant['webp']] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * Serialize to JSON-ready array for admin APIs, picker modal, and editor blocks.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(string $urlPrefix = '/uploads'): array
    {
        $thumbUrl = $this->isImage() ? $this->variantUrl('thumbnail', false, $urlPrefix) : null;
        $mediumUrl = $this->isImage() ? $this->variantUrl('medium', false, $urlPrefix) : null;

        return [
            'id' => $this->id,
            'folder_id' => $this->folderId,
            'author_id' => $this->authorId,
            'author_name' => $this->authorName,
            'disk_path' => $this->diskPath,
            'filename' => $this->filename,
            'original_name' => $this->originalName,
            'extension' => $this->extension,
            'mime_type' => $this->mimeType,
            'kind' => $this->kind,
            'kind_label' => $this->kindLabel(),
            'size_bytes' => $this->sizeBytes,
            'human_size' => $this->humanSize(),
            'width' => $this->width,
            'height' => $this->height,
            'checksum' => $this->checksum,
            'alt' => $this->alt,
            'title' => $this->title,
            'display_title' => $this->displayTitle(),
            'caption' => $this->caption ?? '',
            'description' => $this->description ?? '',
            'focal_x' => $this->focalX,
            'focal_y' => $this->focalY,
            'focal_css' => $this->focalPointCss(),
            'url' => $this->url($urlPrefix),
            'thumbnail_url' => $thumbUrl,
            'medium_url' => $mediumUrl,
            'srcset' => $this->srcset(false, $urlPrefix),
            'srcset_webp' => $this->srcset(true, $urlPrefix),
            'variants' => $this->variants,
            'usage_count' => $this->usageCount,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @param array<mixed, mixed> $raw
     * @return array<string, array{path: string, webp?: ?string, width?: int, height?: int, size_bytes?: int}>
     */
    private static function normalizeVariants(array $raw): array
    {
        $normalized = [];
        foreach ($raw as $key => $val) {
            if (!is_string($key) || !is_array($val) || !is_string($val['path'] ?? null)) {
                continue;
            }
            $entry = ['path' => $val['path']];
            if (isset($val['webp']) && is_string($val['webp']) && $val['webp'] !== '') {
                $entry['webp'] = $val['webp'];
            }
            if (isset($val['width']) && is_numeric($val['width'])) {
                $entry['width'] = (int) $val['width'];
            }
            if (isset($val['height']) && is_numeric($val['height'])) {
                $entry['height'] = (int) $val['height'];
            }
            if (isset($val['size_bytes']) && is_numeric($val['size_bytes'])) {
                $entry['size_bytes'] = (int) $val['size_bytes'];
            }
            $normalized[$key] = $entry;
        }

        return $normalized;
    }
}
