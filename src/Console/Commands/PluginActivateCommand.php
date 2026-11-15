<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Plugins\Exception\PluginException;
use Prime\Plugins\PluginManager;

/**
 * Activates a plugin by slug (`php bin/prime plugin:activate <slug>`).
 */
final class PluginActivateCommand implements CommandInterface
{
    public function __construct(private readonly PluginManager $manager)
    {
    }

    public function name(): string
    {
        return 'plugin:activate';
    }

    public function description(): string
    {
        return 'Active un plugin (valide, exécute ses migrations et publie ses assets)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $slug = trim((string) ($parsed['positionals'][0] ?? ''));

        if ($slug === '') {
            $output->error('Usage : php bin/prime plugin:activate <slug>');

            return self::FAILURE;
        }

        try {
            $info = $this->manager->activate($slug);
            $output->writeln(sprintf('Plugin "%s" (%s v%s) activé avec succès.', $info->slug, $info->name(), $info->version()));

            return self::SUCCESS;
        } catch (PluginException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
