<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Content\EntryService;
use Throwable;

/**
 * Publishes all scheduled content entries whose `published_at` has passed (`bin/prime content:publish-scheduled`).
 */
final class ContentPublishScheduledCommand implements CommandInterface
{
    public function __construct(private readonly EntryService $service)
    {
    }

    public function name(): string
    {
        return 'content:publish-scheduled';
    }

    public function description(): string
    {
        return 'Publie les contenus planifiés arrivés à échéance';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        try {
            $count = $this->service->publishScheduled();
        } catch (Throwable $exception) {
            $output->error('Erreur lors de la publication planifiée : ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($count === 0) {
            $output->writeln('Aucun contenu planifié arrivé à échéance.');
        } else {
            $output->writeln(sprintf('%d contenu(s) planifié(s) publié(s).', $count));
        }

        return self::SUCCESS;
    }
}
