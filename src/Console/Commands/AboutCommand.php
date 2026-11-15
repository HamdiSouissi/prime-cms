<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Kernel\Application;

/**
 * Displays runtime version, environment, and core filesystem paths.
 */
final class AboutCommand implements CommandInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    public function name(): string
    {
        return 'about';
    }

    public function description(): string
    {
        return 'Affiche les informations sur Prime CMS et son environnement';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $rows = [
            ['Application', (string) $this->app->config()->get('app.name', 'Prime CMS')],
            ['Version', Application::VERSION],
            ['PHP', PHP_VERSION],
            ['Environnement', $this->app->environment()],
            ['Mode debug', $this->app->isDebug() ? 'activé' : 'désactivé'],
            ['Fuseau horaire', (string) $this->app->config()->get('app.timezone', 'UTC')],
            ['Chemin racine', $this->app->basePath()],
            ['Chemin config', $this->app->configPath()],
            ['Chemin storage', $this->app->storagePath()],
            ['Chemin content', $this->app->contentPath()],
            ['Chemin public', $this->app->publicPath()],
            ['Chemin resources', $this->app->resourcesPath()],
        ];

        $output->table(['Propriété', 'Valeur'], $rows);

        return self::SUCCESS;
    }
}
