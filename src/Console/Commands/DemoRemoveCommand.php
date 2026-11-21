<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Demo\DemoContent;
use Prime\Kernel\Application;
use Throwable;

/**
 * `demo:remove` — deletes exactly what `demo:install` created and restores what it replaced.
 */
final class DemoRemoveCommand implements CommandInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    public function name(): string
    {
        return 'demo:remove';
    }

    public function description(): string
    {
        return 'Retire le contenu de démonstration et restaure les menus, zones et réglages remplacés';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        try {
            $demo = DemoContent::fromApplication($this->app);
            if (!$demo->isInstalled()) {
                $output->writeln('Aucun contenu de démonstration installé.');

                return self::SUCCESS;
            }
            $result = $demo->remove();
        } catch (Throwable $exception) {
            $output->error('Retrait de la démonstration impossible : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf(
            'Démonstration retirée : %d contenus, %d termes, %d images, %d menus.',
            $result['entries'],
            $result['terms'],
            $result['media'],
            $result['menus'],
        ));

        return self::SUCCESS;
    }
}
