<?php

declare(strict_types=1);

namespace Prime\Themes;

use Prime\Hooks\Hooks;
use Prime\Kernel\Application;
use Prime\Kernel\Container;
use Prime\Settings\Settings;

/**
 * Facade passed to `ThemeInterface::boot(ThemeContext $ctx)`, giving themes
 * controlled access to hooks, styles/scripts, settings, and the DI container.
 */
final class ThemeContext
{
    public function __construct(
        private readonly ThemeManifest $manifest,
        private readonly string $themePath,
        private readonly Application $app,
        private readonly Hooks $hooks,
        private readonly ThemeAssetRegistry $assets,
        private readonly ThemeAssetPublisher $publisher,
        private readonly ?Settings $settings = null,
    ) {
    }

    public function slug(): string
    {
        return $this->manifest->slug;
    }

    public function manifest(): ThemeManifest
    {
        return $this->manifest;
    }

    public function path(string $relative = ''): string
    {
        $base = rtrim($this->themePath, DIRECTORY_SEPARATOR . '/');
        $clean = ltrim($relative, DIRECTORY_SEPARATOR . '/');

        return $clean === '' ? $base : $base . DIRECTORY_SEPARATOR . $clean;
    }

    public function hooks(): Hooks
    {
        return $this->hooks;
    }

    public function settings(): ?Settings
    {
        return $this->settings;
    }

    public function container(): Container
    {
        return $this->app->container();
    }

    public function app(): Application
    {
        return $this->app;
    }

    public function assets(): ThemeAssetRegistry
    {
        return $this->assets;
    }

    /**
     * Resolve a theme-relative asset path (e.g. `css/aurora.css`) to its public URL.
     */
    public function assetUrl(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $this->assets->versionUrl($path);
        }

        return $this->assets->versionUrl($this->publisher->url($this->manifest->slug, $path));
    }

    /**
     * Enqueue a stylesheet for the public site.
     *
     * @param list<string> $deps
     */
    public function style(string $handle, string $path, array $deps = [], string $media = 'all'): self
    {
        $url = str_starts_with($path, '/') ? $path : $this->publisher->url($this->manifest->slug, $path);
        $this->assets->style($handle, $url, $deps, $media);

        return $this;
    }

    /**
     * Enqueue a script for the public site.
     *
     * @param list<string> $deps
     * @param array{defer?: bool, module?: bool} $options
     */
    public function script(string $handle, string $path, array $deps = [], array $options = ['defer' => true, 'module' => false]): self
    {
        $url = str_starts_with($path, '/') ? $path : $this->publisher->url($this->manifest->slug, $path);
        $this->assets->script($handle, $url, $deps, $options);

        return $this;
    }
}
