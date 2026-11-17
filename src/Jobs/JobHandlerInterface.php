<?php

declare(strict_types=1);

namespace Prime\Jobs;

/**
 * A queued work unit. Handlers are registered by name in `JobHandlerRegistry`; throwing any
 * `Throwable` makes the queue retry the job with an exponential backoff, and the last message is
 * kept in `pc_jobs.last_error` for the admin screen.
 */
interface JobHandlerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, Job $job): void;
}
