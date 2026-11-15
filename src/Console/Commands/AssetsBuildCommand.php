<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Admin\Assets\CssBuilder;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Kernel\Application;
use Throwable;

/**
 * CLI command `bin/prime assets:build` compiling admin CSS sources into `public/assets/admin/css/admin.css`.
 */
final class AssetsBuildCommand implements CommandInterface
{
    public function __construct(
        private readonly Application $app,
        private readonly CssBuilder $builder = new CssBuilder(),
    ) {
    }

    public function name(): string
    {
        return 'assets:build';
    }

    public function description(): string
    {
        return 'Concatène et minifie les feuilles de style du dashboard dans public/assets/admin/css/admin.css';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args, ConsoleOutput $output): int
    {
        $manifestPath = $this->app->resourcesPath('assets/admin/manifest.php');
        $sourceDir = $this->app->resourcesPath('assets/admin/css');
        $outputPath = $this->app->publicPath('assets/admin/css/admin.css');

        try {
            $result = $this->builder->build($manifestPath, $sourceDir, $outputPath);
        } catch (Throwable $e) {
            $output->error('Échec de la compilation CSS : ' . $e->getMessage());

            return self::FAILURE;
        }

        $kb = number_format($result['bytes'] / 1024, 2, '.', '');
        $output->writeln(sprintf(
            'Assets CSS compilés : %d fichiers → public/assets/admin/css/admin.css (%s Ko)',
            count($result['files']),
            $kb,
        ));

        return self::SUCCESS;
    }
}
