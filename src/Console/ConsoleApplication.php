<?php

declare(strict_types=1);

namespace Prime\Console;

use Prime\Console\Commands\AboutCommand;
use Prime\Console\Commands\AssetsBuildCommand;
use Prime\Console\Commands\DbCheckCommand;
use Prime\Console\Commands\InstallCommand;
use Prime\Console\Commands\ListCommand;
use Prime\Console\Commands\MakeMigrationCommand;
use Prime\Console\Commands\MigrateCommand;
use Prime\Console\Commands\MigrateFreshCommand;
use Prime\Console\Commands\MigrateResetCommand;
use Prime\Console\Commands\MigrateRollbackCommand;
use Prime\Console\Commands\MigrateStatusCommand;
use Prime\Console\Commands\RoutesCommand;
use Prime\Console\Commands\SettingsGetCommand;
use Prime\Console\Commands\SettingsSetCommand;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migrator;
use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\Install\Installer;
use Prime\Kernel\Application;
use Prime\Settings\Settings;

/**
 * Extensible CLI runner for Prime CMS (`bin/prime`).
 */
final class ConsoleApplication
{
    /**
     * @var array<string, CommandInterface>
     */
    private array $commands = [];

    private bool $booted = false;

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * Register a command (can be called by plugins via the `console.commands` hook).
     */
    public function register(CommandInterface $command): void
    {
        $this->commands[$command->name()] = $command;
    }

    /**
     * Return all registered commands sorted alphabetically by name.
     *
     * @return array<string, CommandInterface>
     */
    public function commands(): array
    {
        $this->bootCommands();
        $sorted = $this->commands;
        ksort($sorted);

        return $sorted;
    }

    /**
     * Parse CLI arguments and execute the requested command.
     *
     * @param list<string> $argv
     */
    public function run(array $argv, ?ConsoleOutput $output = null): int
    {
        $output ??= new ConsoleOutput(false);
        $this->bootCommands();

        $commandName = $argv[1] ?? 'list';
        $args = array_slice($argv, 2);

        if ($commandName === '-h' || $commandName === '--help') {
            $commandName = 'list';
        } elseif ($commandName === '-v' || $commandName === '--version') {
            $output->writeln(sprintf('Prime CMS %s', Application::VERSION));

            return CommandInterface::SUCCESS;
        }

        if (!isset($this->commands[$commandName])) {
            $output->error(sprintf('Commande inconnue : "%s".', $commandName));
            $output->error('Exécutez "php bin/prime list" pour voir les commandes disponibles.');

            return CommandInterface::FAILURE;
        }

        return $this->commands[$commandName]->execute($args, $output);
    }

    private function bootCommands(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        /** @var Router $router */
        $router = $this->app->get(Router::class);

        $this->register(new ListCommand($this));
        $this->register(new AboutCommand($this->app));
        $this->register(new AssetsBuildCommand($this->app));
        $this->register(new RoutesCommand($router));

        if ($this->app->container()->has(Connection::class)) {
            /** @var Connection $connection */
            $connection = $this->app->get(Connection::class);
            /** @var Migrator $migrator */
            $migrator = $this->app->get(Migrator::class);

            $this->register(new DbCheckCommand($connection));
            $this->register(new MigrateCommand($migrator));
            $this->register(new MigrateRollbackCommand($migrator));
            $this->register(new MigrateStatusCommand($migrator));
            $this->register(new MigrateResetCommand($migrator));
            $this->register(new MigrateFreshCommand($this->app, $connection, $migrator));
            $this->register(new MakeMigrationCommand($this->app));
        }

        if ($this->app->container()->has(Settings::class)) {
            /** @var Settings $settings */
            $settings = $this->app->get(Settings::class);

            $this->register(new SettingsGetCommand($settings));
            $this->register(new SettingsSetCommand($settings));
        }

        if ($this->app->container()->has(Installer::class)) {
            /** @var Installer $installer */
            $installer = $this->app->get(Installer::class);

            $this->register(new InstallCommand($this->app->config(), $installer));
        }

        if ($this->app->container()->has(Hooks::class)) {
            /** @var Hooks $hooks */
            $hooks = $this->app->get(Hooks::class);
            $hooks->emit('console.commands', $this);
        }
    }
}
