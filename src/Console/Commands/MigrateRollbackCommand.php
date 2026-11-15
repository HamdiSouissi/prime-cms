<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Database\Migrations\Migrator;
use Throwable;

/**
 * Rolls back the last N migration batches (`--step=N`, default 1).
 */
final class MigrateRollbackCommand implements CommandInterface
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function name(): string
    {
        return 'migrate:rollback';
    }

    public function description(): string
    {
        return 'Annule le dernier lot de migrations (--step=N)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $stepRaw = ArgParser::stringOption($parsed['options'], 'step', '1');

        if (!ctype_digit($stepRaw) || (int) $stepRaw < 1) {
            $output->error('L\'option --step doit être un entier positif supérieur ou égal à 1.');

            return self::FAILURE;
        }

        try {
            $rolledBack = $this->migrator->rollback((int) $stepRaw);
        } catch (Throwable $exception) {
            $output->error('Erreur lors de l\'annulation : ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($rolledBack === []) {
            $output->writeln('Aucune migration à annuler.');

            return self::SUCCESS;
        }

        foreach ($rolledBack as $migration) {
            $output->writeln('  [ROLLBACK] ' . $migration);
        }

        $output->writeln(sprintf('%d migration(s) annulée(s).', count($rolledBack)));

        return self::SUCCESS;
    }
}
