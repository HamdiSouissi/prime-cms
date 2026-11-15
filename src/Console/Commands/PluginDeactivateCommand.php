<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Plugins\Exception\PluginException;
use Prime\Plugins\PluginManager;

/**
 * Deactivates an active plugin while keeping its data intact.
 */
final class PluginDeactivateCommand implements CommandInterface
{
    public function __construct(private readonly PluginManager $manager)
    {
    }

    public function name(): string
    {
        return 'plugin:deactivate';
    }

    public function description(): string
    {
        return 'Désactive un plugin actif tout en conservant ses données';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $slug = trim((string) ($parsed['positionals'][0] ?? ''));

        if ($slug === '') {
            $output->error('Usage : php bin/prime plugin:deactivate <slug>');

            return self::FAILURE;
        }

        try {
            $info = $this->manager->deactivate($slug);
            $output->writeln(sprintf('Plugin "%s" (%s) désactivé.', $info->slug, $info->name()));

            return self::SUCCESS;
        } catch (PluginException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
