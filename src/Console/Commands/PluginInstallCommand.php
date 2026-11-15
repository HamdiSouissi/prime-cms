<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Plugins\Exception\PluginInstallationException;
use Prime\Plugins\PluginInstaller;

/**
 * Installs or replaces a plugin from a local `.zip` archive (`php bin/prime plugin:install <zip> [--replace] [--force]`).
 */
final class PluginInstallCommand implements CommandInterface
{
    public function __construct(private readonly PluginInstaller $installer)
    {
    }

    public function name(): string
    {
        return 'plugin:install';
    }

    public function description(): string
    {
        return 'Installe un plugin depuis une archive ZIP locale (--replace / --force)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $zipPath = trim((string) ($parsed['positionals'][0] ?? ''));
        $replace = isset($parsed['options']['replace']) && $parsed['options']['replace'] !== false;
        $force = isset($parsed['options']['force']) && $parsed['options']['force'] !== false;

        if ($zipPath === '') {
            $output->error('Usage : php bin/prime plugin:install <chemin-archive.zip> [--replace] [--force]');

            return self::FAILURE;
        }

        try {
            $result = $this->installer->installFromZip($zipPath, $replace || $force, $force);
            $manifest = $result['manifest'];

            foreach ($result['warnings'] as $warning) {
                $output->writeln('[AVERTISSEMENT] ' . $warning);
            }

            if ($result['replaced']) {
                $output->writeln(sprintf(
                    'Plugin "%s" (%s) remplacé : %s -> %s.',
                    $manifest->slug,
                    $manifest->name,
                    $result['previous_version'] ?? 'inconnue',
                    $manifest->version,
                ));
            } else {
                $output->writeln(sprintf(
                    'Plugin "%s" (%s v%s) installé à l\'état inactif. Activez-le avec : php bin/prime plugin:activate %s',
                    $manifest->slug,
                    $manifest->name,
                    $manifest->version,
                    $manifest->slug,
                ));
            }

            return self::SUCCESS;
        } catch (PluginInstallationException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
