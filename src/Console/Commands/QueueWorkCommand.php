<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Jobs\JobQueue;
use Prime\Jobs\JobRepository;
use Throwable;

/**
 * Runs the queue (`bin/prime queue:work`).
 *
 * One tick drains at most `--max` jobs and then exits, which is what a supervisor, a cron entry or
 * the `scheduler` container in docker-compose should call. `--forever` keeps the process alive for
 * local development, polling every `--sleep` seconds while the queue is empty.
 */
final class QueueWorkCommand implements CommandInterface
{
    public function __construct(
        private readonly JobQueue $queue,
        private readonly JobRepository $jobs,
    ) {
    }

    public function name(): string
    {
        return 'queue:work';
    }

    public function description(): string
    {
        return 'Traite les tâches en file (`queue:work [--queue=name] [--max=50] [--once] [--forever] [--sleep=3]`)';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args, ['once', 'forever', 'verbose']);
        $options = $parsed['options'];
        $queue = ArgParser::stringOption($options, 'queue', '') ?: null;
        $max = max(1, (int) ArgParser::stringOption($options, 'max', '50'));
        $sleep = max(1, min(60, (int) ArgParser::stringOption($options, 'sleep', '3')));
        $once = ArgParser::hasFlag($options, 'once');
        $forever = ArgParser::hasFlag($options, 'forever');
        $verbose = ArgParser::hasFlag($options, 'verbose');
        $worker = gethostname() . ':' . getmypid();

        try {
            $released = $this->jobs->releaseStuck(600);
            if ($released > 0) {
                $output->writeln(sprintf('%d tâche(s) rendues après réservation expirée.', $released));
            }
        } catch (Throwable $exception) {
            $output->error('Contrôle des réservations impossible : ' . $exception->getMessage());

            return self::FAILURE;
        }

        $processed = 0;
        $failed = 0;
        $handled = 0;

        while (true) {
            try {
                $result = $this->queue->runNext($worker, $queue);
            } catch (Throwable $exception) {
                $output->error('Le worker a échoué : ' . $exception->getMessage());

                return self::FAILURE;
            }

            if ($result === null) {
                if ($forever) {
                    sleep($sleep);
                    continue;
                }

                break;
            }

            ++$handled;

            if ($result['status'] === 'completed') {
                ++$processed;
            } elseif ($result['status'] === 'failed') {
                ++$failed;
            }

            if ($verbose) {
                $output->writeln(sprintf(
                    '%s %s (#%d, %d tentative(s))%s',
                    $result['status'],
                    $result['handler'],
                    $result['id'],
                    $result['attempts'],
                    $result['error'] !== null ? ' — ' . $result['error'] : '',
                ));
            }

            if ($once || $handled >= $max) {
                break;
            }
        }

        $output->writeln(sprintf(
            '%d tâche(s) traitée(s), %d échec(s) permanent%s.',
            $processed,
            $failed,
            $failed === 0 ? '' : 's',
        ));

        return self::SUCCESS;
    }
}
