<?php

declare(strict_types=1);

namespace Prime\Plugins;

use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Notices;
use Prime\Auth\Capabilities;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\Kernel\Application;
use Prime\Log\Logger;
use Prime\Settings\Settings;
use Prime\View\View;

/**
 * Official API context handed to every plugin during `register()`, `boot()`,
 * `activate()`, `deactivate()`, and `uninstall()`.
 *
 * Designed so complex official plugins (Prime Builder, Prime Commerce) can build
 * their complete admin, front-end, database, asset, capability, and settings layers
 * exclusively through this API without core bypasses.
 */
final class PluginContext
{
    private readonly PluginHooks $pluginHooks;
    private readonly PluginRouter $pluginRouter;
    private readonly PluginCapabilities $pluginCapabilities;
    private readonly PluginSettings $pluginSettings;
    private readonly PluginNotices $pluginNotices;
    private readonly Logger $pluginLogger;
    private ?string $settingsUrl;

    public function __construct(
        private readonly PluginManifest $manifest,
        private readonly string $directory,
        private readonly Application $app,
        Hooks $hooks,
        Router $router,
        private readonly View $views,
        private readonly AdminMenu $adminMenu,
        Capabilities $capabilities,
        Settings $settings,
        private readonly Connection $db,
        private readonly AssetPublisher $assetPublisher,
        Notices $notices,
        Logger $pluginLogger,
    ) {
        $this->pluginHooks = new PluginHooks($hooks, $manifest->slug);
        $this->pluginRouter = new PluginRouter($router, $manifest->slug);
        $this->pluginCapabilities = new PluginCapabilities($hooks, $capabilities, $manifest->slug, $manifest->name);
        $this->pluginSettings = new PluginSettings($settings, $manifest->slug);
        $this->pluginNotices = new PluginNotices($notices, $manifest->slug);
        $this->pluginLogger = $pluginLogger;
        $this->settingsUrl = $manifest->settingsUrl;
    }

    public function manifest(): PluginManifest
    {
        return $this->manifest;
    }

    public function slug(): string
    {
        return $this->manifest->slug;
    }

    public function version(): string
    {
        return $this->manifest->version;
    }

    /**
     * Resolve an absolute path inside this plugin's directory (`content/plugins/{slug}/...`).
     */
    public function path(string $relative = ''): string
    {
        $base = rtrim($this->directory, DIRECTORY_SEPARATOR . '/');
        $clean = ltrim($relative, DIRECTORY_SEPARATOR . '/');

        return $clean === '' ? $base : $base . DIRECTORY_SEPARATOR . $clean;
    }

    /**
     * Return the cache-busted public URL of an asset published from this plugin's `assets/` folder.
     */
    public function url(string $asset): string
    {
        return $this->assetPublisher->publicUrl($this->manifest->slug, $this->manifest->version, $asset);
    }

    public function app(): Application
    {
        return $this->app;
    }

    public function hooks(): PluginHooks
    {
        return $this->pluginHooks;
    }

    public function router(): PluginRouter
    {
        return $this->pluginRouter;
    }

    public function views(): View
    {
        return $this->views;
    }

    public function adminMenu(): AdminMenu
    {
        return $this->adminMenu;
    }

    public function capabilities(): PluginCapabilities
    {
        return $this->pluginCapabilities;
    }

    public function settings(): PluginSettings
    {
        return $this->pluginSettings;
    }

    public function db(): Connection
    {
        return $this->db;
    }

    public function logger(): Logger
    {
        return $this->pluginLogger;
    }

    public function notices(): PluginNotices
    {
        return $this->pluginNotices;
    }

    /**
     * Declare the admin settings URL displayed by the "Réglages" button on `/admin/plugins`.
     */
    public function setSettingsUrl(string $url): void
    {
        if (str_starts_with($url, '/admin/') && !str_contains($url, '..')) {
            $this->settingsUrl = $url;
        }
    }

    public function settingsUrl(): ?string
    {
        return $this->settingsUrl;
    }
}
