<?php

declare(strict_types=1);

namespace Prime\Jobs;

use Prime\Content\EntryService;

/**
 * Publishes the content that reached its scheduled time (`content.publish_scheduled`).
 *
 * The same work is reachable from the visitor-time fallback and from `bin/prime
 * content:publish-scheduled`; queueing it means a burst of due entries is processed by a worker
 * instead of by the next visitor's request.
 */
final class ContentPublishHandler implements JobHandlerInterface
{
    public const NAME = 'content.publish_scheduled';

    public function __construct(private readonly EntryService $entries)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, Job $job): void
    {
        $this->entries->publishScheduled(isset($payload['now']) ? (string) $payload['now'] : null);
    }
}
