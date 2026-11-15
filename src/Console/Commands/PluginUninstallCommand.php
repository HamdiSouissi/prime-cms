<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Plugins\Exception\PluginException;
use Prime\Plugins\PluginManager;

/**
 * Uninstalls an inactive plugin (migrations rollback, settings/assets/DB cleanup,
 * and file removal unless `--keep-files` is passed).
 */
final class PluginUninstallCommand implements CommandInterface
{
    public function __construct(private readonly PluginManager $manager)
    {
    }

    public function name(): string
    {
        return 'plugin:uninstall';
    }

    public function description(): string
    {
        return 'Désinstalle un plugin inactif (migrations, réglages, assets et fichiers sauf --keep-files)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $slug = trim((string) ($parsed['positionals'][0] ?? ''));
        $keepFiles = isset($parsed['options']['keep-files']) && $parsed['options']['keep-files'] !== false;

        if ($slug === '') {
            $output->error('Usage : php bin/prime plugin:uninstall <slug> [--keep-files]');

            return self::FAILURE;
        }

        try {
            $this->manager->uninstall($slug, deleteFiles: !$keepFiles);
            $output->writeln(
                $keepFiles
                    ? sprintf('Plugin "%s" désinstallé (fichiers conservés dans content/plugins/%s).', $slug, $slug)
                    : sprintf('Plugin "%s" désinstallé et supprimé avec succès.', $slug),
            );

            return self::SUCCESS;
        } catch (PluginException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
