<?php

declare(strict_types=1);

namespace FixtureBootThrow;

use Prime\Plugins\AbstractPlugin;
use Prime\Plugins\PluginContext;
use RuntimeException;

final class Plugin extends AbstractPlugin
{
    public function boot(PluginContext $context): void
    {
        throw new RuntimeException('Intentional failure during boot()');
    }
}
