<?php

declare(strict_types=1);

namespace FixtureBaseLib;

use Prime\Plugins\AbstractPlugin;
use Prime\Plugins\PluginContext;

final class Plugin extends AbstractPlugin
{
    /** @var list<string> */
    public static array $events = [];

    public function register(PluginContext $context): void
    {
        self::$events[] = 'base-lib:register';
    }

    public function boot(PluginContext $context): void
    {
        self::$events[] = 'base-lib:boot';
    }
}
