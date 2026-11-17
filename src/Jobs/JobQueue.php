<?php

declare(strict_types=1);

namespace Prime\Jobs;

use Prime\Hooks\Hooks;
use Prime\Log\Logger;
use Throwable;

/**
 * Dispatching and running of queued work.
 *
 * Failure policy: a handler that throws is retried until `max_attempts` is reached, each retry
 * waiting `base * 2^(attempt-1)` seconds (capped), so a database hiccup or a rate-limited remote
 * service heals by itself. Once the attempts are exhausted the job is parked with `failed_at` set —
 * visible in the admin screen and retryable — and never deleted.
 */
final class JobQueue
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobHandlerRegistry $handlers,
        private readonly ?Hooks $hooks = null,
        private readonly ?Logger $logger = null,
        private readonly int $defaultMaxAttempts = 3,
        private readonly int $baseBackoffSeconds = 5,
        private readonly int $maxBackoffSeconds = 900,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(
        string $handler,
        array $payload = [],
        string $queue = 'default',
        int $delaySeconds = 0,
        ?int $maxAttempts = null,
    ): int {
        if (!$this->handlers->has($handler)) {
            throw new \InvalidArgumentException(sprintf('Aucun traitement enregistré pour la tâche « %s ».', $handler));
        }

        $id = $this->jobs->push(
            $handler,
            $payload,
            $queue,
            $delaySeconds,
            $maxAttempts ?? $this->defaultMaxAttempts,
        );

        $this->hooks?->emit('job.pushed', $handler, $id, $queue, $payload);

        return $id;
    }

    /**
     * Whether `$handler` already has an attempt in flight or waiting.
     */
    public function isQueued(string $handler): bool
    {
        return $this->jobs->queuedCount($handler) > 0;
    }

    /**
     * Claim and run one job. Returns `null` when nothing was due.
     *
     * @return array{status: string, id: int, handler: string, attempts: int, delay: int, error: ?string}|null
     */
    public function runNext(string $worker, ?string $queue = null, ?int $now = null): ?array
    {
        $job = $this->jobs->claim($worker, $queue, $now);

        if ($job === null) {
            return null;
        }

        $handler = $this->handlers->resolve($job->handler);
        if ($handler === null) {
            $error = sprintf('Traitant inconnue : « %s ».', $job->handler);
            $this->jobs->markFailed($job->id, $error);
            $this->hooks?->emit('job.failed', $job, $error);
            $this->logger?->error('Tâche orpheline en file', ['job' => $job->id, 'handler' => $job->handler]);

            return ['status' => 'failed', 'id' => $job->id, 'handler' => $job->handler, 'attempts' => $job->attempts, 'delay' => 0, 'error' => $error];
        }

        $this->hooks?->emit('job.started', $job);

        try {
            $handler($job->payload, $job);
        } catch (Throwable $exception) {
            return $this->handleFailure($job, $exception);
        }

        $this->jobs->complete($job->id);
        $this->hooks?->emit('job.completed', $job);

        return ['status' => 'completed', 'id' => $job->id, 'handler' => $job->handler, 'attempts' => $job->attempts, 'delay' => 0, 'error' => null];
    }

    /**
     * Drain the queue. `$maxJobs` bounds one tick so a worker never blocks forever on a busy site.
     *
     * @return array{processed: int, released: int, failed: int, idle: bool}
     */
    public function drain(int $maxJobs = 50, ?string $queue = null, string $worker = 'cli', ?int $now = null): array
    {
        $processed = 0;
        $released = 0;
        $failed = 0;
        $idle = false;

        for ($run = 0; $run < max(1, $maxJobs); ++$run) {
            $result = $this->runNext($worker, $queue, $now);

            if ($result === null) {
                $idle = true;
                break;
            }

            match ($result['status']) {
                'completed' => $processed++,
                'released' => $released++,
                default => $failed++,
            };
        }

        return ['processed' => $processed, 'released' => $released, 'failed' => $failed, 'idle' => $idle];
    }

    /**
     * The delay applied to the retry after `$attempt` failed tries.
     */
    public function backoffFor(int $attempt): int
    {
        $exponent = max(0, $attempt - 1);
        $delay = $this->baseBackoffSeconds * (2 ** min(10, $exponent));

        return (int) min($this->maxBackoffSeconds, max(1, $delay));
    }

    /**
     * @return array{status: string, id: int, handler: string, attempts: int, delay: int, error: ?string}
     */
    private function handleFailure(Job $job, Throwable $exception): array
    {
        $error = $exception->getMessage();
        $this->logger?->error('Tâche en échec', [
            'job' => $job->id,
            'handler' => $job->handler,
            'attempt' => $job->attempts,
            'error' => $error,
        ]);

        if ($job->exhausted() || $exception instanceof UnrecoverableJobException) {
            $this->jobs->markFailed($job->id, $error);
            $this->hooks?->emit('job.failed', $job, $error);

            return ['status' => 'failed', 'id' => $job->id, 'handler' => $job->handler, 'attempts' => $job->attempts, 'delay' => 0, 'error' => $error];
        }

        $delay = $this->backoffFor($job->attempts);
        $this->jobs->release($job->id, $error, $delay);
        $this->hooks?->emit('job.released', $job, $delay, $error);

        return ['status' => 'released', 'id' => $job->id, 'handler' => $job->handler, 'attempts' => $job->attempts, 'delay' => $delay, 'error' => $error];
    }
}
