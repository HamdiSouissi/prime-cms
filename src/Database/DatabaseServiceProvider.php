<?php

declare(strict_types=1);

namespace Prime\Database;

use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Hooks\Hooks;
use Prime\Kernel\Config;
use Prime\Kernel\Container;
use Prime\Kernel\ServiceProvider;

/**
 * Registers the lazy database Connection, Schema builder, and Migrator.
 */
final class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();
        $app = $this->app;

        $container->singleton(Connection::class, static function (Container $c) use ($app): Connection {
            /** @var Config $config */
            $config = $c->get(Config::class);
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            $dbConfig = $config->get('database', []);

            return new Connection(
                config: is_array($dbConfig) ? $dbConfig : [],
                hooks: $hooks instanceof Hooks ? $hooks : null,
                debug: $app->isDebug(),
            );
        });

        $container->singleton(Schema::class, static function (Container $c): Schema {
            /** @var Connection $connection */
            $connection = $c->get(Connection::class);

            return new Schema($connection);
        });

        $container->singleton(Migrator::class, static function (Container $c) use ($app): Migrator {
            /** @var Connection $connection */
            $connection = $c->get(Connection::class);
            /** @var Schema $schema */
            $schema = $c->get(Schema::class);
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            return new Migrator(
                connection: $connection,
                schema: $schema,
                paths: [$app->basePath('database/migrations')],
                hooks: $hooks instanceof Hooks ? $hooks : null,
            );
        });
    }
}
