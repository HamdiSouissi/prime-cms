<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Http\Router;

/**
 * Displays all HTTP routes registered in the application router.
 */
final class RoutesCommand implements CommandInterface
{
    public function __construct(private readonly Router $router)
    {
    }

    public function name(): string
    {
        return 'routes';
    }

    public function description(): string
    {
        return 'Liste toutes les routes HTTP enregistrées';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $routes = $this->router->routes();

        if ($routes === []) {
            $output->writeln('Aucune route enregistrée.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($routes as $route) {
            $rows[] = [
                implode('|', $route->methods()),
                $route->path(),
                $route->getName() ?? '—',
                $route->handlerLabel(),
            ];
        }

        $output->table(['Méthode', 'Chemin', 'Nom', 'Handler'], $rows);

        return self::SUCCESS;
    }
}
