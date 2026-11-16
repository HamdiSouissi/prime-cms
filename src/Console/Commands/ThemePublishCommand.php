<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Themes\Exception\ThemeException;
use Prime\Themes\ThemeManager;

/**
 * CLI command: `php bin/prime themes:publish [slug]`.
 */
final class ThemePublishCommand implements CommandInterface
{
    public function __construct(private readonly ThemeManager $themes)
    {
    }

    public function name(): string
    {
        return 'themes:publish';
    }

    public function description(): string
    {
        return 'Publier les assets statiques des thèmes dans public/assets/themes/';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $slug = isset($args[0]) && trim($args[0]) !== '' && !str_starts_with($args[0], '-')
            ? trim($args[0])
            : null;

        try {
            $counts = $this->themes->publishAssets($slug);
            if ($counts === []) {
                $output->writeln('Aucun asset de thème à publier.');

                return self::SUCCESS;
            }

            foreach ($counts as $tSlug => $count) {
                $output->success(sprintf('Thème « %s » : %d fichier(s) publié(s) dans public/assets/themes/%s/.', $tSlug, $count, $tSlug));
            }

            return self::SUCCESS;
        } catch (ThemeException $e) {
            $output->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
