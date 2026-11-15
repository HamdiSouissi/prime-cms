<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Closure;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migrator;
use Prime\Kernel\Application;
use Throwable;

/**
 * Drops all tables matching the configured prefix and re-runs every migration.
 *
 * Refuses to run in production without `--force`, and requests interactive
 * confirmation unless `--no-interaction` (`-n`) is passed.
 */
final class MigrateFreshCommand implements CommandInterface
{
    /**
     * @param ?Closure(string): string $inputReader Optional prompt reader for testing
     */
    public function __construct(
        private readonly Application $app,
        private readonly Connection $connection,
        private readonly Migrator $migrator,
        private readonly ?Closure $inputReader = null,
    ) {
    }

    public function name(): string
    {
        return 'migrate:fresh';
    }

    public function description(): string
    {
        return 'Supprime toutes les tables du préfixe et relance toutes les migrations (--force, --no-interaction)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args, ['force', 'no-interaction']);
        $force = ArgParser::hasFlag($parsed['options'], 'force');
        $noInteraction = ArgParser::hasFlag($parsed['options'], 'no-interaction');

        if ($this->app->isProduction() && !$force) {
            $output->error('Commande refusée en production (`APP_ENV=production`). Ajoutez --force si vous assumez la suppression complète des données.');

            return self::FAILURE;
        }

        if (!$noInteraction) {
            $prompt = sprintf(
                'Cette action va SUPPRIMER toutes les tables du préfixe "%s" dans "%s". Continuer ? [o/N] ',
                $this->connection->prefix(),
                $this->connection->database(),
            );

            $answer = $this->readConfirmation($prompt);
            $normalized = strtolower(trim($answer));
            if (!in_array($normalized, ['o', 'oui', 'y', 'yes'], true)) {
                $output->writeln('Opération annulée.');

                return self::SUCCESS;
            }
        }

        try {
            $executed = $this->migrator->fresh();
        } catch (Throwable $exception) {
            $output->error('Erreur lors de migrate:fresh : ' . $exception->getMessage());

            return self::FAILURE;
        }

        foreach ($executed as $migration) {
            $output->writeln('  [OK] ' . $migration);
        }

        $output->writeln(sprintf('Base réinitialisée : %d migration(s) exécutée(s).', count($executed)));

        return self::SUCCESS;
    }

    private function readConfirmation(string $prompt): string
    {
        if ($this->inputReader !== null) {
            return ($this->inputReader)($prompt);
        }

        fwrite(STDOUT, $prompt);
        $line = fgets(STDIN);

        return is_string($line) ? $line : '';
    }
}
