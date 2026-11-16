<?php

declare(strict_types=1);

namespace Prime\Media;

use Prime\Kernel\Application;
use Throwable;

/**
 * Responsive HTML & URL rendering helpers for Prime CMS media (`media_url`, `media_srcset`, `media_image`).
 */
final class MediaHelper
{
    /**
     * Resolve a `Media` instance or positive media ID through the container's `MediaRepository`.
     */
    public static function resolve(int|Media|null $media, ?MediaRepository $repository = null): ?Media
    {
        if ($media instanceof Media) {
            return $media;
        }
        if ($media === null || $media <= 0) {
            return null;
        }

        if ($repository !== null) {
            return $repository->find($media);
        }

        if (!Application::hasInstance()) {
            return null;
        }

        try {
            $container = Application::getInstance()->container();
            if ($container->has(MediaRepository::class)) {
                /** @var MediaRepository $repo */
                $repo = $container->make(MediaRepository::class);

                return $repo->find($media);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Return the public URL of a media or one of its generated variants.
     */
    public static function url(
        int|Media|null $media,
        ?string $variant = null,
        ?string $format = null,
        ?MediaRepository $repository = null,
    ): ?string {
        $resolved = self::resolve($media, $repository);

        return $resolved?->url($variant, $format);
    }

    /**
     * Build a responsive `srcset` attribute string sorted ascending by pixel width.
     *
     * @param 'original'|'webp'|null $format When null, prefers original fallback variants (`'original'`).
     */
    public static function srcset(
        int|Media|null $media,
        ?string $format = null,
        ?MediaRepository $repository = null,
    ): string {
        $resolved = self::resolve($media, $repository);
        if ($resolved === null || !$resolved->isImage()) {
            return '';
        }

        $targetFormat = $format ?? 'original';
        $candidates = [];

        foreach ($resolved->variants as $variantData) {
            $w = (int) ($variantData['width'] ?? 0);
            if ($w <= 0) {
                continue;
            }
            $relPath = $targetFormat === 'webp'
                ? ($variantData['webp_path'] ?? null)
                : ($variantData['path'] ?? null);
            if (!is_string($relPath) || $relPath === '') {
                continue;
            }
            $candidates[$w] = '/uploads/' . ltrim($relPath, '/') . ' ' . $w . 'w';
        }

        // For original srcset, also include the full original image if wider than all generated variants.
        if ($targetFormat === 'original' && $resolved->width !== null && $resolved->width > 0) {
            $origW = $resolved->width;
            if (!isset($candidates[$origW])) {
                $candidates[$origW] = $resolved->url() . ' ' . $origW . 'w';
            }
        }

        if ($candidates === []) {
            return '';
        }

        ksort($candidates, SORT_NUMERIC);

        return implode(', ', $candidates);
    }

    /**
     * Render a responsive `<picture>` / `<img>` HTML tag for an image media.
     *
     * Supported `$attrs` options:
     * - `variant`: preferred fallback variant (`thumbnail` | `medium` | `large` | null for original, default `large`)
     * - `sizes`: HTML `sizes` attribute (default `100vw`)
     * - `alt`: override alt text (defaults to `$media->altText`)
     * - `decorative`: bool — forces `alt="" aria-hidden="true"`
     * - `class`: CSS classes on `<img>`
     * - `picture_class`: CSS classes on `<picture>`
     * - `loading`: `lazy` | `eager` (default `lazy`)
     * - `decoding`: `async` | `sync` | `auto` (default `async`)
     * - `fetchpriority`: `high` | `low` | `auto`
     * - `style`: additional inline CSS (auto-includes `object-position` from focal point unless already set)
     * - `use_focal`: bool (default true — applies `object-position: X% Y%` when focal point != 50% 50%)
     *
     * @param array<string, mixed> $attrs
     */
    public static function image(
        int|Media|null $media,
        array $attrs = [],
        ?MediaRepository $repository = null,
    ): string {
        $resolved = self::resolve($media, $repository);
        if ($resolved === null || !$resolved->isImage()) {
            return '';
        }

        $variant = array_key_exists('variant', $attrs)
            ? (is_string($attrs['variant']) && $attrs['variant'] !== '' ? $attrs['variant'] : null)
            : 'large';

        $fallbackSrc = $resolved->url($variant, 'original');
        $fallbackSrcset = self::srcset($resolved, 'original');
        $webpSrcset = self::srcset($resolved, 'webp');

        $sizes = is_string($attrs['sizes'] ?? null) && trim($attrs['sizes']) !== ''
            ? trim($attrs['sizes'])
            : '100vw';

        $decorative = (bool) ($attrs['decorative'] ?? false);
        $alt = $decorative
            ? ''
            : (is_string($attrs['alt'] ?? null) ? $attrs['alt'] : $resolved->altText);

        $loading = is_string($attrs['loading'] ?? null) && in_array($attrs['loading'], ['lazy', 'eager'], true)
            ? $attrs['loading']
            : 'lazy';
        $decoding = is_string($attrs['decoding'] ?? null) && in_array($attrs['decoding'], ['async', 'sync', 'auto'], true)
            ? $attrs['decoding']
            : 'async';

        // Pick intrinsic dimensions matching the chosen fallback variant if available.
        $width = $resolved->width;
        $height = $resolved->height;
        if ($variant !== null && isset($resolved->variants[$variant])) {
            $vW = (int) ($resolved->variants[$variant]['width'] ?? 0);
            $vH = (int) ($resolved->variants[$variant]['height'] ?? 0);
            if ($vW > 0 && $vH > 0) {
                $width = $vW;
                $height = $vH;
            }
        }

        $imgParts = [
            'src="' . self::esc($fallbackSrc) . '"',
        ];
        if ($fallbackSrcset !== '') {
            $imgParts[] = 'srcset="' . self::esc($fallbackSrcset) . '"';
            $imgParts[] = 'sizes="' . self::esc($sizes) . '"';
        }
        $imgParts[] = 'alt="' . self::esc($alt) . '"';
        if ($width !== null && $width > 0) {
            $imgParts[] = 'width="' . $width . '"';
        }
        if ($height !== null && $height > 0) {
            $imgParts[] = 'height="' . $height . '"';
        }
        $imgParts[] = 'loading="' . $loading . '"';
        $imgParts[] = 'decoding="' . $decoding . '"';

        if ($decorative) {
            $imgParts[] = 'aria-hidden="true"';
        }

        if (is_string($attrs['fetchpriority'] ?? null) && in_array($attrs['fetchpriority'], ['high', 'low', 'auto'], true)) {
            $imgParts[] = 'fetchpriority="' . $attrs['fetchpriority'] . '"';
        }

        if (is_string($attrs['class'] ?? null) && trim($attrs['class']) !== '') {
            $imgParts[] = 'class="' . self::esc(trim($attrs['class'])) . '"';
        }

        $useFocal = array_key_exists('use_focal', $attrs) ? (bool) $attrs['use_focal'] : true;
        $styleParts = [];
        if (is_string($attrs['style'] ?? null) && trim($attrs['style']) !== '') {
            $styleParts[] = rtrim(trim($attrs['style']), ';');
        }
        if (
            $useFocal
            && (abs($resolved->focalX - 0.5) >= 0.005 || abs($resolved->focalY - 0.5) >= 0.005)
            && !str_contains(strtolower(implode(';', $styleParts)), 'object-position')
        ) {
            $styleParts[] = 'object-position:' . $resolved->focalPositionCss();
        }
        if ($styleParts !== []) {
            $imgParts[] = 'style="' . self::esc(implode(';', $styleParts)) . '"';
        }

        $imgTag = '<img ' . implode(' ', $imgParts) . '>';

        if ($webpSrcset !== '') {
            $pictureClass = is_string($attrs['picture_class'] ?? null) && trim($attrs['picture_class']) !== ''
                ? ' class="' . self::esc(trim($attrs['picture_class'])) . '"'
                : '';

            return sprintf(
                '<picture%s><source type="image/webp" srcset="%s" sizes="%s">%s</picture>',
                $pictureClass,
                self::esc($webpSrcset),
                self::esc($sizes),
                $imgTag,
            );
        }

        return $imgTag;
    }

    /**
     * Format byte size into human-readable French units (o, Ko, Mo, Go).
     */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' o';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', ' ') . ' Ko';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', ' ') . ' Mo';
        }

        return number_format($bytes / (1024 * 1024 * 1024), 2, ',', ' ') . ' Go';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
