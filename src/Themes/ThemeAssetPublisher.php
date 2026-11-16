<?php

declare(strict_types=1);

namespace Prime\Themes;

use Prime\Plugins\AssetPublisher;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Publishes whitelisted static assets from `content/themes/{slug}/assets/` (plus
 * the theme's `screenshot.webp` / `screenshot.png`) into `public/assets/themes/{slug}/`.
 */
final class ThemeAssetPublisher
{
    private readonly AssetPublisher $publisher;

    public function __construct(
        private readonly string $publicThemesRoot,
        private readonly string $publicUrlPrefix = '/assets/themes',
    ) {
        $this->publisher = new AssetPublisher($this->publicThemesRoot, $this->publicUrlPrefix);
    }

    /**
     * Publish assets and screenshot for a theme directory into `public/assets/themes/{slug}/`.
     *
     * @return int Number of published files
     */
    public function publish(string $slug, string $themePath): int
    {
        $assetsDir = rtrim($themePath, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . 'assets';
        $count = 0;

        // Preserve any generated `options-*.css` file across republishing
        $preservedOptionsCss = $this->snapshotOptionsCss($slug);

        if (is_dir($assetsDir)) {
            $count += $this->publisher->publish($slug, $themePath);
        } else {
            $targetDir = $this->targetDir($slug);
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }
        }

        // Copy theme root screenshot (`screenshot.webp` preferred, fallback `screenshot.png`)
        foreach (['screenshot.webp', 'screenshot.png'] as $shot) {
            $source = rtrim($themePath, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $shot;
            if (is_file($source) && !is_link($source) && is_readable($source)) {
                $targetDir = $this->targetDir($slug);
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0755, true);
                }
                if (@copy($source, $targetDir . DIRECTORY_SEPARATOR . $shot)) {
                    $count++;
                }
            }
        }

        foreach ($preservedOptionsCss as $filename => $contents) {
            $targetDir = $this->targetDir($slug);
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }
            @file_put_contents($targetDir . DIRECTORY_SEPARATOR . $filename, $contents);
        }

        return $count;
    }

    /**
     * Remove all published assets for a theme slug.
     */
    public function unpublish(string $slug): void
    {
        $this->publisher->remove($slug);
    }

    /**
     * Return the public URL for a published theme asset (`css/aurora.css` -> `/assets/themes/aurora/css/aurora.css`).
     */
    public function url(string $slug, string $relativePath): string
    {
        $clean = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($clean === '' || str_contains($clean, '..') || str_contains($clean, "\0")) {
            return '';
        }

        return rtrim($this->publicUrlPrefix, '/') . '/' . rawurlencode($slug) . '/' . $clean;
    }

    /**
     * Return the published screenshot URL for `$slug` if present, or null.
     */
    public function screenshotUrl(string $slug, string $themePath): ?string
    {
        $targetDir = $this->targetDir($slug);
        foreach (['screenshot.webp', 'screenshot.png'] as $shot) {
            $published = $targetDir . DIRECTORY_SEPARATOR . $shot;
            if (is_file($published)) {
                return $this->url($slug, $shot);
            }
            $source = rtrim($themePath, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $shot;
            if (is_file($source) && !is_link($source)) {
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0755, true);
                }
                if (@copy($source, $published)) {
                    return $this->url($slug, $shot);
                }
            }
        }

        return null;
    }

    public function targetDir(string $slug): string
    {
        return rtrim($this->publicThemesRoot, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $slug;
    }

    /**
     * @return array<string, string>
     */
    private function snapshotOptionsCss(string $slug): array
    {
        $targetDir = $this->targetDir($slug);
        if (!is_dir($targetDir)) {
            return [];
        }

        $files = glob($targetDir . DIRECTORY_SEPARATOR . 'options-*.css');
        if ($files === false) {
            return [];
        }

        $saved = [];
        foreach ($files as $file) {
            if (is_file($file) && is_readable($file)) {
                $content = file_get_contents($file);
                if (is_string($content)) {
                    $saved[basename($file)] = $content;
                }
            }
        }

        return $saved;
    }

    /**
     * Remove a directory recursively (helper for theme deletion / cleanup).
     */
    public static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}
