<?php

declare(strict_types=1);

namespace Prime\Plugins;

/**
 * Convenience base class providing empty default lifecycle hooks for plugins.
 */
abstract class AbstractPlugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
    }

    public function boot(PluginContext $context): void
    {
    }

    public function activate(PluginContext $context): void
    {
    }

    public function deactivate(PluginContext $context): void
    {
    }

    public function uninstall(PluginContext $context): void
    {
    }
}
