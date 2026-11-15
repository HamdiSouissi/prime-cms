<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Database\Migrations\Migrator;
use Throwable;

/**
 * Rolls back every executed migration in reverse order.
 */
final class MigrateResetCommand implements CommandInterface
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function name(): string
    {
        return 'migrate:reset';
    }

    public function description(): string
    {
        return 'Annule l\'ensemble des migrations exécutées';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        try {
            $rolledBack = $this->migrator->reset();
        } catch (Throwable $exception) {
            $output->error('Erreur lors de la réinitialisation : ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($rolledBack === []) {
            $output->writeln('Aucune migration à annuler.');

            return self::SUCCESS;
        }

        foreach ($rolledBack as $migration) {
            $output->writeln('  [RESET] ' . $migration);
        }

        $output->writeln(sprintf('%d migration(s) annulée(s).', count($rolledBack)));

        return self::SUCCESS;
    }
}
