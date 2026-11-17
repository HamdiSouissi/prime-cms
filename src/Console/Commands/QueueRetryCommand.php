<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Jobs\JobRepository;
use Throwable;

/**
 * Puts failed jobs back into the queue, or drops the ones past their TTL (`bin/prime queue:retry`).
 */
final class QueueRetryCommand implements CommandInterface
{
    public function __construct(private readonly JobRepository $jobs)
    {
    }

    public function name(): string
    {
        return 'queue:retry';
    }

    public function description(): string
    {
        return 'Relance les tâches en échec (`queue:retry [--all] [--id=N] [--prune=60]`)';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args, ['all']);
        $options = $parsed['options'];

        try {
            if (ArgParser::hasFlag($options, 'prune') || isset($options['prune'])) {
                $minutes = max(1, (int) ArgParser::stringOption($options, 'prune', '60'));
                $deleted = $this->jobs->pruneFailed($minutes);
                $output->writeln(sprintf('%d tâche(s) en échec supprimée(s) (au-delà de %d minutes).', $deleted, $minutes));

                return self::SUCCESS;
            }

            $id = (int) ArgParser::stringOption($options, 'id', '0');

            if ($id > 0) {
                $output->writeln($this->jobs->retry($id)
                    ? sprintf('Tâche #%d remise en file.', $id)
                    : sprintf('Aucune tâche en échec avec l’identifiant %d.', $id));

                return self::SUCCESS;
            }

            $count = ArgParser::hasFlag($options, 'all') || $id === 0 ? $this->jobs->retryAll() : 0;
            $output->writeln(sprintf('%d tâche(s) remise(s) en file.', $count));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $output->error('Opération impossible : ' . $exception->getMessage());

            return self::FAILURE;
        }
    }
}
