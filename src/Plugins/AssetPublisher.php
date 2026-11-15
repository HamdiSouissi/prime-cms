<?php

declare(strict_types=1);

namespace Prime\Plugins;

use Prime\Plugins\Exception\PluginException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Publishes whitelisted static assets from `content/plugins/{slug}/assets/` into
 * `public/assets/plugins/{slug}/`, strictly excluding PHP scripts, hidden files,
 * and symlinks.
 */
final class AssetPublisher
{
    /**
     * Exact whitelist of static file extensions allowed in `public/assets/plugins/`.
     */
    public const ALLOWED_EXTENSIONS = [
        'css',
        'js',
        'mjs',
        'json',
        'map',
        'svg',
        'png',
        'jpg',
        'jpeg',
        'gif',
        'webp',
        'avif',
        'ico',
        'woff',
        'woff2',
        'ttf',
        'otf',
        'txt',
    ];

    public function __construct(
        private readonly string $publicAssetsRoot,
        private readonly string $publicUrlPrefix = '/assets/plugins',
    ) {
    }

    /**
     * Determine whether a relative filename is permitted for public publication.
     */
    public static function isAllowedFile(string $relativePath): bool
    {
        $normalized = str_replace('\\', '/', $relativePath);
        if ($normalized === '' || str_contains($normalized, "\0") || str_contains($normalized, '..')) {
            return false;
        }

        foreach (explode('/', trim($normalized, '/')) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                return false;
            }
        }

        $ext = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, self::ALLOWED_EXTENSIONS, true);
    }

    /**
     * Publish all whitelisted assets of `$slug` from `$pluginDirectory/assets/` into `public/assets/plugins/$slug/`.
     * Returns the number of copied files.
     */
    public function publish(string $slug, string $pluginDirectory): int
    {
        $this->assertValidSlug($slug);
        $sourceDir = rtrim($pluginDirectory, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . 'assets';
        $targetDir = $this->targetDirectory($slug);

        if (!is_dir($sourceDir)) {
            $this->remove($slug);

            return 0;
        }

        $realSource = realpath($sourceDir);
        if ($realSource === false) {
            return 0;
        }

        if (!is_dir($this->publicAssetsRoot)) {
            if (!mkdir($this->publicAssetsRoot, 0755, true) && !is_dir($this->publicAssetsRoot)) {
                throw new PluginException(sprintf('Impossible de créer le dossier public des assets "%s".', $this->publicAssetsRoot));
            }
        }

        $stagingDir = rtrim($this->publicAssetsRoot, DIRECTORY_SEPARATOR . '/')
            . DIRECTORY_SEPARATOR . '.staging-' . $slug . '-' . bin2hex(random_bytes(6));

        if (!mkdir($stagingDir, 0755, true)) {
            throw new PluginException(sprintf('Impossible de créer le dossier temporaire d\'assets "%s".', $stagingDir));
        }

        $copied = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($realSource, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            /** @var SplFileInfo $item */
            foreach ($iterator as $item) {
                $itemPath = $item->getPathname();

                // Never follow symlinks inside plugin assets
                if ($item->isLink() || is_link($itemPath)) {
                    continue;
                }

                $relative = ltrim(substr($itemPath, strlen($realSource)), DIRECTORY_SEPARATOR . '/');
                $relativeUnix = str_replace('\\', '/', $relative);

                if ($item->isDir()) {
                    // Skip hidden directories
                    $segments = explode('/', $relativeUnix);
                    $hasDot = false;
                    foreach ($segments as $seg) {
                        if (str_starts_with($seg, '.')) {
                            $hasDot = true;
                            break;
                        }
                    }
                    if (!$hasDot) {
                        $destSubdir = $stagingDir . DIRECTORY_SEPARATOR . $relative;
                        if (!is_dir($destSubdir)) {
                            mkdir($destSubdir, 0755, true);
                        }
                    }
                    continue;
                }

                if (!$item->isFile() || !self::isAllowedFile($relativeUnix)) {
                    continue;
                }

                // Ensure resolved file truly stays inside $realSource
                $realFile = realpath($itemPath);
                if ($realFile === false || !str_starts_with($realFile, $realSource . DIRECTORY_SEPARATOR)) {
                    continue;
                }

                $destFile = $stagingDir . DIRECTORY_SEPARATOR . $relative;
                $destParent = dirname($destFile);
                if (!is_dir($destParent)) {
                    mkdir($destParent, 0755, true);
                }

                if (!copy($realFile, $destFile)) {
                    throw new PluginException(sprintf('Échec de la copie de l\'asset "%s".', $relativeUnix));
                }
                @chmod($destFile, 0644);
                $copied++;
            }

            // Replace old published assets atomically
            $backupDir = null;
            if (is_dir($targetDir)) {
                $backupDir = $targetDir . '.old-' . bin2hex(random_bytes(4));
                if (!rename($targetDir, $backupDir)) {
                    self::deleteDirectory($targetDir);
                    $backupDir = null;
                }
            }

            if ($copied === 0) {
                self::deleteDirectory($stagingDir);
                if ($backupDir !== null) {
                    self::deleteDirectory($backupDir);
                }

                return 0;
            }

            if (!rename($stagingDir, $targetDir)) {
                if ($backupDir !== null && is_dir($backupDir)) {
                    @rename($backupDir, $targetDir);
                }
                throw new PluginException(sprintf('Impossible de publier les assets du plugin "%s".', $slug));
            }

            if ($backupDir !== null) {
                self::deleteDirectory($backupDir);
            }

            return $copied;
        } catch (PluginException $exception) {
            self::deleteDirectory($stagingDir);
            throw $exception;
        }
    }

    /**
     * Remove published assets for `$slug`.
     */
    public function remove(string $slug): void
    {
        $this->assertValidSlug($slug);
        $target = $this->targetDirectory($slug);
        if (is_dir($target)) {
            self::deleteDirectory($target);
        }
    }

    /**
     * Build the cache-busted public URL for a published plugin asset (`?v={version}-{mtime}`).
     */
    public function publicUrl(string $slug, string $version, string $asset): string
    {
        $this->assertValidSlug($slug);
        $rawAsset = str_replace('\\', '/', trim($asset));
        if ($rawAsset === '' || str_starts_with($rawAsset, '/') || str_contains($rawAsset, '..') || str_contains($rawAsset, "\0")) {
            throw new PluginException(sprintf('Chemin d\'asset non autorisé : "%s".', $asset));
        }

        $cleanAsset = preg_replace('#^(?:\./)+#', '', $rawAsset) ?? '';
        if (str_starts_with($cleanAsset, 'assets/')) {
            $cleanAsset = substr($cleanAsset, strlen('assets/'));
        }

        if (!self::isAllowedFile($cleanAsset)) {
            throw new PluginException(sprintf('Chemin d\'asset non autorisé : "%s".', $asset));
        }

        $filePath = $this->targetDirectory($slug) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanAsset);
        $mtime = is_file($filePath) ? (int) filemtime($filePath) : 0;
        $cacheTag = $mtime > 0 ? $version . '-' . $mtime : $version;

        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $cleanAsset)));

        return rtrim($this->publicUrlPrefix, '/')
            . '/' . rawurlencode($slug)
            . '/' . $encodedPath
            . '?v=' . rawurlencode($cacheTag);
    }

    /**
     * Return absolute path to `public/assets/plugins/{slug}`.
     */
    public function targetDirectory(string $slug): string
    {
        return rtrim($this->publicAssetsRoot, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $slug;
    }

    public static function deleteDirectory(string $directory): void
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

    private function assertValidSlug(string $slug): void
    {
        if (preg_match(ManifestValidator::SLUG_PATTERN, $slug) !== 1) {
            throw new PluginException(sprintf('Slug de plugin invalide : "%s".', $slug));
        }
    }
}
