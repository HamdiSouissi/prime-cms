<?php

declare(strict_types=1);

namespace Prime\Settings;

use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Kernel\Container;
use Prime\Kernel\ServiceProvider;

/**
 * Registers the persistent Settings service in the application container.
 */
final class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->singleton(Settings::class, static function (Container $c): Settings {
            /** @var Connection $connection */
            $connection = $c->get(Connection::class);
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            return new Settings($connection, $hooks instanceof Hooks ? $hooks : null);
        });
    }
}
