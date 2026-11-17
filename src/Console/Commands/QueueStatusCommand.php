<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Jobs\JobRepository;
use Throwable;

/**
 * Queue health for operators and for the site-health screen (`bin/prime queue:status`).
 */
final class QueueStatusCommand implements CommandInterface
{
    public function __construct(private readonly JobRepository $jobs)
    {
    }

    public function name(): string
    {
        return 'queue:status';
    }

    public function description(): string
    {
        return 'État de la file de tâches (`queue:status [--failed] [--json]`)';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args, ['failed', 'json']);
        $options = $parsed['options'];

        try {
            $stats = $this->jobs->stats();
            $queues = $this->jobs->queues();
        } catch (Throwable $exception) {
            $output->error('Lecture de la file impossible : ' . $exception->getMessage());

            return self::FAILURE;
        }

        if (ArgParser::hasFlag($options, 'failed')) {
            return $this->listFailed($output, max(1, (int) ArgParser::stringOption($options, 'limit', '20')));
        }

        if (ArgParser::hasFlag($options, 'json')) {
            $output->writeln((string) json_encode(
                ['stats' => $stats, 'queues' => $queues],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        }

        $output->table(
            ['File', 'En attente', 'En cours', 'Reportées', 'Échecs'],
            [[
                $queues === [] ? 'aucune' : implode(', ', $queues),
                (string) $stats['pending'],
                (string) $stats['reserved'],
                (string) $stats['delayed'],
                (string) $stats['failed'],
            ]],
        );

        if ($stats['oldest_pending'] !== null) {
            $output->writeln('Plus ancienne tâche disponible : ' . $stats['oldest_pending'] . ' UTC');
        }

        if ($stats['failed'] > 0) {
            $output->writeln(sprintf('queue:status --failed pour lister les %d échec(s).', $stats['failed']));
        }

        return self::SUCCESS;
    }

    private function listFailed(ConsoleOutput $output, int $limit): int
    {
        try {
            $jobs = $this->jobs->failed($limit);
        } catch (Throwable $exception) {
            $output->error('Lecture des échecs impossible : ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($jobs === []) {
            $output->writeln('Aucun échec enregistré.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($jobs as $job) {
            $rows[] = [
                (string) $job->id,
                $job->handler,
                $job->queue,
                sprintf('%d/%d', $job->attempts, $job->maxAttempts),
                (string) $job->failedAt,
                (string) $job->lastError,
            ];
        }

        $output->table(['#', 'Traitement', 'File', 'Tentatives', 'Échec', 'Dernière erreur'], $rows);
        $output->writeln('Relancer : bin/prime queue:failed --retry=ID (ou --retry-all).');

        return self::SUCCESS;
    }
}
