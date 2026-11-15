<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Content\EntryService;
use Prime\Kernel\Application;
use Throwable;

/**
 * Permanently purges trashed entries older than retention period (`bin/prime content:purge-trash`).
 */
final class ContentPurgeTrashCommand implements CommandInterface
{
    public function __construct(
        private readonly Application $app,
        private readonly EntryService $service,
    ) {
    }

    public function name(): string
    {
        return 'content:purge-trash';
    }

    public function description(): string
    {
        return 'Supprime définitivement les contenus en corbeille expirés (--days=30)';
    }

    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args);
        $opts = $parsed['options'];

        $defaultDays = max(1, (int) $this->app->config()->get('content.trash_retention_days', 30));
        $daysOpt = ArgParser::stringOption($opts, 'days');
        $days = $daysOpt !== '' && ctype_digit($daysOpt) ? (int) $daysOpt : $defaultDays;

        try {
            $purged = $this->service->purgeTrash($days);
        } catch (Throwable $exception) {
            $output->error('Erreur lors de la purge de la corbeille : ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($purged === 0) {
            $output->writeln(sprintf('Aucun élément expiré en corbeille (> %d jour(s)).', $days));
        } else {
            $output->writeln(sprintf('%d élément(s) supprimé(s) définitivement de la corbeille (> %d jour(s)).', $purged, $days));
        }

        return self::SUCCESS;
    }
}
