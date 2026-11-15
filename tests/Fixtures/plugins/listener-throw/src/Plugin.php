<?php

declare(strict_types=1);

namespace FixtureListenerThrow;

use Prime\Plugins\AbstractPlugin;
use Prime\Plugins\PluginContext;
use RuntimeException;

final class Plugin extends AbstractPlugin
{
    public function register(PluginContext $context): void
    {
        $context->hooks()->on('fixture.trigger', static function (): void {
            throw new RuntimeException('Intentional listener exception');
        }, priority: 5);

        $context->hooks()->filter('fixture.filter', static function (string $value): string {
            throw new RuntimeException('Intentional filter exception');
        }, priority: 5);
    }
}
