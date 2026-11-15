<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Plugins\Exception\PluginException;
use Prime\Plugins\PluginManager;

/**
 * Publishes static assets for one plugin or all active plugins (`php bin/prime plugin:publish-assets [slug]`).
 */
final class PluginPublishAssetsCommand implements CommandInterface
{
    public function __construct(private readonly PluginManager $manager)
    {
    }

    public function name(): string
    {
        return 'plugin:publish-assets';
    }

    public function description(): string
    {
        return 'Publie les assets statiques autorisés d\'un plugin ou de tous les plugins actifs';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $slug = isset($parsed['positionals'][0]) && trim($parsed['positionals'][0]) !== ''
            ? trim($parsed['positionals'][0])
            : null;

        try {
            $published = $this->manager->publishAssets($slug);
            if ($published === []) {
                $output->writeln('Aucun plugin actif à publier.');

                return self::SUCCESS;
            }

            foreach ($published as $pluginSlug => $count) {
                $output->writeln(sprintf('Assets publiés pour "%s" : %d fichier(s).', $pluginSlug, $count));
            }

            return self::SUCCESS;
        } catch (PluginException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
