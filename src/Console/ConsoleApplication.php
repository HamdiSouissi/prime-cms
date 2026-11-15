<?php

declare(strict_types=1);

namespace Prime\Console;

use Prime\Console\Commands\AboutCommand;
use Prime\Console\Commands\ListCommand;
use Prime\Console\Commands\RoutesCommand;
use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\Kernel\Application;

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
        $this->register(new RoutesCommand($router));

        if ($this->app->container()->has(Hooks::class)) {
            /** @var Hooks $hooks */
            $hooks = $this->app->get(Hooks::class);
            $hooks->emit('console.commands', $this);
        }
    }
}
