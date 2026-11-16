<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Themes\ThemeManager;

/**
 * CLI command: `php bin/prime themes:list`.
 */
final class ThemeListCommand implements CommandInterface
{
    public function __construct(private readonly ThemeManager $themes)
    {
    }

    public function name(): string
    {
        return 'themes:list';
    }

    public function description(): string
    {
        return 'Lister les thèmes installés et leur état';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $all = $this->themes->discover(fresh: true);
        if ($all === []) {
            $output->writeln('Aucun thème trouvé dans content/themes/.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($all as $info) {
            $status = $info->active
                ? 'actif'
                : (!$info->valid ? 'invalide' : (!$info->compatible ? 'incompatible' : 'inactif'));

            $rows[] = [
                $info->slug,
                $info->name(),
                $info->version(),
                $info->parentSlug() ?? '—',
                $status,
            ];
        }

        $output->table(['Slug', 'Nom', 'Version', 'Parent', 'État'], $rows);

        return self::SUCCESS;
    }
}
