<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\CommandInterface;
use Prime\Console\ConsoleApplication;
use Prime\Console\ConsoleOutput;
use Prime\Kernel\Application;

/**
 * Displays all registered Prime CMS CLI commands.
 */
final class ListCommand implements CommandInterface
{
    public function __construct(private readonly ConsoleApplication $console)
    {
    }

    public function name(): string
    {
        return 'list';
    }

    public function description(): string
    {
        return 'Affiche la liste des commandes disponibles';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $output->writeln(sprintf('Prime CMS v%s — Interface en ligne de commande', Application::VERSION));
        $output->writeln();
        $output->writeln('Usage : php bin/prime <commande> [options]');
        $output->writeln();

        $rows = [];
        foreach ($this->console->commands() as $command) {
            $rows[] = [$command->name(), $command->description()];
        }

        $output->table(['Commande', 'Description'], $rows);

        return self::SUCCESS;
    }
}
