<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Kernel\Application;
use Prime\Update\Updater;
use Throwable;

/**
 * `php bin/prime update --backup-done | --backup [--check]`
 */
final class UpdateCommand implements CommandInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    public function name(): string
    {
        return 'update';
    }

    public function description(): string
    {
        return 'Met à jour la base et les fichiers publics après un déploiement (--backup-done ou --backup ; --check pour voir sans rien faire)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $options = ArgParser::parse($args)['options'];
        $updater = new Updater($this->app);

        try {
            $status = $updater->status();
        } catch (Throwable $exception) {
            $output->error('Impossible de lire l’état de l’installation : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf('Version installée : %s — version du code : %s', $status['installed'], $status['code']));
        $output->writeln(sprintf('Migrations en attente : %d', count($status['pending'])));
        foreach ($status['pending'] as $row) {
            $output->writeln(sprintf('  - %s (%s)', $row['migration'], $row['source']));
        }

        if (($options['check'] ?? false) !== false) {
            return self::SUCCESS;
        }

        if (($options['backup-done'] ?? false) === false && ($options['backup'] ?? false) === false) {
            $output->error('Sauvegardez la base avant la mise à jour : relancez avec --backup-done (sauvegarde faite) ou --backup (mysqldump).');

            return self::FAILURE;
        }

        try {
            $result = $updater->run(
                backupConfirmed: ($options['backup-done'] ?? false) !== false,
                automaticBackup: ($options['backup'] ?? false) !== false,
                log: static fn (string $line) => $output->writeln('  … ' . $line),
            );
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf('Mise à jour terminée : %s → %s, %d migration(s).', $result['from'], $result['to'], $result['migrations']));

        return self::SUCCESS;
    }
}
