<?php

declare(strict_types=1);

namespace FixtureDepConsumer;

use FixtureBaseLib\Plugin as BasePlugin;
use Prime\Plugins\AbstractPlugin;
use Prime\Plugins\PluginContext;

final class Plugin extends AbstractPlugin
{
    public function register(PluginContext $context): void
    {
        BasePlugin::$events[] = 'dep-consumer:register';
    }

    public function boot(PluginContext $context): void
    {
        BasePlugin::$events[] = 'dep-consumer:boot';
    }
}
