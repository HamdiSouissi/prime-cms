<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Database\Migrations\Migrator;
use Throwable;

/**
 * Displays the execution status of every discovered and logged migration.
 */
final class MigrateStatusCommand implements CommandInterface
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function name(): string
    {
        return 'migrate:status';
    }

    public function description(): string
    {
        return 'Affiche l\'état de toutes les migrations (exécutées / en attente)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        try {
            $entries = $this->migrator->status();
        } catch (Throwable $exception) {
            $output->error('Impossible de lire l\'état des migrations : ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($entries === []) {
            $output->writeln('Aucune migration trouvée.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                $entry['migration'],
                $entry['status'] === 'ran' ? 'Exécutée' : 'En attente',
                $entry['batch'] !== null ? (string) $entry['batch'] : '-',
                $entry['ran_at'] ?? '-',
            ];
        }

        $output->table(['Migration', 'Statut', 'Lot', 'Exécutée le (UTC)'], $rows);

        return self::SUCCESS;
    }
}
