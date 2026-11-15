<?php

declare(strict_types=1);

namespace Prime\Plugins;

/**
 * Contract implemented by every Prime CMS plugin entry class (`plugin.json` -> `main`).
 */
interface PluginInterface
{
    /**
     * Register services, hooks, routes, capabilities, menus, and view namespaces.
     */
    public function register(PluginContext $context): void;

    /**
     * Run after all active plugins have completed `register()`.
     */
    public function boot(PluginContext $context): void;

    /**
     * Executed once when an administrator activates the plugin.
     */
    public function activate(PluginContext $context): void;

    /**
     * Executed once when an administrator deactivates the plugin (keep user data intact).
     */
    public function deactivate(PluginContext $context): void;

    /**
     * Executed once before the plugin's migrations, settings, assets, and files are removed.
     */
    public function uninstall(PluginContext $context): void;
}
