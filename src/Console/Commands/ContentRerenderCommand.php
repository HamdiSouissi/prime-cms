<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Content\EntryService;
use Throwable;

/**
 * Re-renders cached HTML (`content_html`) and plain text (`content_text`) from JSON blocks (`bin/prime content:rerender`).
 */
final class ContentRerenderCommand implements CommandInterface
{
    public function __construct(private readonly EntryService $service)
    {
    }

    public function name(): string
    {
        return 'content:rerender';
    }

    public function description(): string
    {
        return 'Recalcule le rendu HTML/texte des contenus depuis leurs blocs JSON (--type)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $type = ArgParser::stringOption($parsed['options'], 'type');

        try {
            $count = $this->service->rerender($type !== '' ? $type : null);
        } catch (Throwable $exception) {
            $output->error('Erreur lors du re-rendu des contenus : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf('%d contenu(s) re-rendu(s) avec succès.', $count));

        return self::SUCCESS;
    }
}
