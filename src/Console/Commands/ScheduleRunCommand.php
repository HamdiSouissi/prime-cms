<?php

declare(strict_types=1);

namespace Prime\Console\Commands;

use DateTimeImmutable;
use Prime\Console\ArgParser;
use Prime\Console\CommandInterface;
use Prime\Console\ConsoleOutput;
use Prime\Scheduler\Schedule;
use Prime\Scheduler\SchedulerRunner;
use Throwable;

/**
 * Runs the due tasks of the schedule (`bin/prime schedule:run`).
 *
 * This is the single entry point a host should call every minute — `* * * * * php /app/bin/prime
 * schedule:run` — and the command the docker `scheduler` service loops on. `--list` prints what the
 * schedule contains (including what plugins contributed) without running anything.
 */
final class ScheduleRunCommand implements CommandInterface
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly SchedulerRunner $runner,
    ) {
    }

    public function name(): string
    {
        return 'schedule:run';
    }

    public function description(): string
    {
        return 'Exécute les tâches planifiées à échéance (`schedule:run [--force] [--list] [--json]`)';
    }

    /**
     * @param list<string> $args
     */
    public function execute(array $args, ConsoleOutput $output): int
    {
        $parsed = ArgParser::parse($args, ['force', 'list', 'json']);
        $options = $parsed['options'];

        if (ArgParser::hasFlag($options, 'list')) {
            return $this->list($output, ArgParser::hasFlag($options, 'json'));
        }

        try {
            $results = $this->runner->run(null, ArgParser::hasFlag($options, 'force'));
        } catch (Throwable $exception) {
            $output->error('Planificateur indisponible : ' . $exception->getMessage());

            return self::FAILURE;
        }

        if (ArgParser::hasFlag($options, 'json')) {
            $output->writeln((string) json_encode(['ran' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($results === []) {
            $output->writeln('Aucune tâche à exécuter pour cette minute.');

            return self::SUCCESS;
        }

        $rows = [];
        $failures = 0;

        foreach ($results as $result) {
            if ($result['status'] === 'error') {
                ++$failures;
            }

            $rows[] = [
                $result['name'],
                $result['status'],
                (string) $result['tick'],
                $result['duration_ms'] . ' ms',
                (string) ($result['error'] ?? ''),
            ];
        }

        $output->table(['Tâche', 'État', 'Minute', 'Durée', 'Erreur'], $rows);
        $output->writeln(sprintf('%d tâche(s) examinée(s), %d échec(s).', count($results), $failures));

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function list(ConsoleOutput $output, bool $asJson): int
    {
        $lastRuns = $this->runner->lastRuns();
        $errors = $this->runner->lastErrors();
        $now = new DateTimeImmutable('now');
        $rows = [];
        $payload = [];

        foreach ($this->schedule->tasks() as $task) {
            $next = $task->nextRun($now);
            $entry = [
                'name' => $task->name,
                'expression' => $task->expression(),
                'owner' => $task->owner,
                'description' => $task->description,
                'action' => $task->isCommand() ? $task->commandLine() : 'closure',
                'last_run' => $lastRuns[$task->name] ?? null,
                'next_run' => $next?->format(SchedulerRunner::TICK_FORMAT),
                'error' => $errors[$task->name] ?? null,
            ];

            $payload[] = $entry;
            $rows[] = [
                $entry['name'],
                $entry['expression'],
                (string) $entry['owner'],
                (string) $entry['last_run'],
                (string) $entry['next_run'],
                (string) ($entry['error'] ?? ''),
            ];
        }

        if ($asJson) {
            $output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $output->writeln('Aucune tâche planifiée déclarée.');

            return self::SUCCESS;
        }

        $output->table(['Tâche', 'Expression', 'Origine', 'Dernière', 'Prochaine', 'Dernière erreur'], $rows);
        $output->writeln(sprintf('%d tâche(s) dans le planificateur.', count($rows)));

        return self::SUCCESS;
    }
}
