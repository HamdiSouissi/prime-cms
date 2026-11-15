<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Database\Migrations\Migrator;
use Throwable;

/**
 * Executes all pending database migrations.
 */
final class MigrateCommand implements CommandInterface
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Exécute les migrations de base de données en attente';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        try {
            $executed = $this->migrator->run();
        } catch (Throwable $exception) {
            $output->error('Erreur de migration : ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($executed === []) {
            $output->writeln('Aucune migration en attente.');

            return self::SUCCESS;
        }

        foreach ($executed as $migration) {
            $output->writeln('  [OK] ' . $migration);
        }

        $output->writeln(sprintf('%d migration(s) exécutée(s).', count($executed)));

        return self::SUCCESS;
    }
}
