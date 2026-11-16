<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Themes\Exception\ThemeException;
use Prime\Themes\ThemeManager;

/**
 * CLI command: `php bin/prime themes:activate {slug}`.
 */
final class ThemeActivateCommand implements CommandInterface
{
    public function __construct(private readonly ThemeManager $themes)
    {
    }

    public function name(): string
    {
        return 'themes:activate';
    }

    public function description(): string
    {
        return 'Activer un thème installé (ex. themes:activate aurora)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $slug = trim((string) ($args[0] ?? ''));
        if ($slug === '') {
            $output->error('Usage : php bin/prime themes:activate <slug>');

            return self::FAILURE;
        }

        try {
            $activated = $this->themes->activate($slug);
            $output->success(sprintf('Thème « %s » (%s) activé avec succès.', $activated->name(), $activated->slug));

            return self::SUCCESS;
        } catch (ThemeException $e) {
            $output->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
