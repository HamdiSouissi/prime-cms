<?php

declare(strict_types=1);

namespace Prime\Http\Middleware;

use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Scheduler\SchedulerFallback;
use Throwable;

/**
 * Runs the schedule from a normal request when the site has no cron entry (see `scheduler.fallback`).
 *
 * The tick happens after the response has been produced — and flushed early when the SAPI allows it —
 * so a slow scheduled task is never paid for by the visitor waiting for the page.
 */
final class RunSchedulerIfDue implements MiddlewareInterface
{
    public function __construct(private readonly SchedulerFallback $fallback)
    {
    }

    /**
     * @param callable(Request): Response $next
     */
    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        try {
            $this->fallback->maybeRun();
        } catch (Throwable) {
            // Scheduling is best-effort: the response stays what it was.
        }

        return $response;
    }
}
