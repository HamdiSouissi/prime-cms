<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Demo\DemoContent;
use Prime\Kernel\Application;
use Throwable;

/**
 * `demo:install [--posts=10] [--media=6] [--terms=12]` — demonstration content, removable.
 */
final class DemoInstallCommand implements CommandInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    public function name(): string
    {
        return 'demo:install';
    }

    public function description(): string
    {
        return 'Installe le contenu de démonstration (--posts=10 --media=6 --terms=12), retirable par demo:remove';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $options = ArgParser::parse($args)['options'];
        $count = static function (string $name, int $default, int $max) use ($options): int {
            $raw = $options[$name] ?? null;

            return is_string($raw) && ctype_digit($raw) ? max(1, min($max, (int) $raw)) : $default;
        };

        try {
            $demo = DemoContent::fromApplication($this->app);
            $result = $demo->install(
                posts: $count('posts', 10, 5000),
                mediaCount: $count('media', 6, 1000),
                termCount: $count('terms', 12, 200),
                progress: static fn (string $step) => $output->writeln('  … ' . $step),
            );
        } catch (Throwable $exception) {
            $output->error('Installation de la démonstration impossible : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf(
            'Démonstration installée : %d contenus, %d termes, %d images, %d menus. Retrait : php bin/prime demo:remove',
            $result['entries'],
            $result['terms'],
            $result['media'],
            $result['menus'],
        ));

        return self::SUCCESS;
    }
}
