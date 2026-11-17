<?php

declare(strict_types=1);

namespace Prime\Scheduler;

use Prime\Log\Logger;
use Prime\Settings\Settings;
use Throwable;

/**
 * Visitor-triggered scheduler tick, for hosts where no cron entry can be installed.
 *
 * A single stamp file plus a non-blocking lock does the throttling: the first request after
 * `scheduler.interval` seconds runs the due tasks and every other request returns immediately, so the
 * cost never falls on more than one visitor per interval.
 */
final class SchedulerFallback
{
    public function __construct(
        private readonly SchedulerRunner $runner,
        private readonly string $stampPath,
        private readonly ?Settings $settings = null,
        private readonly ?Logger $logger = null,
        private readonly int $defaultIntervalSeconds = 60,
    ) {
    }

    /**
     * @return int the number of tasks that ran
     */
    public function maybeRun(?int $now = null): int
    {
        if (!$this->enabled()) {
            return 0;
        }

        $now ??= time();
        $interval = $this->intervalSeconds();
        $directory = dirname($this->stampPath);

        // A fresh installation has no `storage/cache/scheduler` yet, and the visitor-time tick is the
        // only thing that would create it: the stamp has to bootstrap its own directory.
        if ($directory !== '' && !is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $handle = @fopen($this->stampPath, 'c+');

        if ($handle === false) {
            return 0;
        }

        try {
            if (@flock($handle, LOCK_EX | LOCK_NB) === false) {
                return 0;
            }

            $last = (int) trim((string) stream_get_contents($handle));

            if ($last > 0 && ($now - $last) < $interval) {
                return 0;
            }

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string) $now);
            fflush($handle);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }

        try {
            return count($this->runner->run($now));
        } catch (Throwable $exception) {
            // A scheduler tick must never turn into a 500 for a visitor.
            $this->logger?->warning('Exécution planifiée reportée : ' . $exception->getMessage());

            return 0;
        }
    }

    public function enabled(): bool
    {
        $settings = $this->settings;

        if ($settings === null) {
            return false;
        }

        try {
            return $settings->getBool('scheduler.enabled', true) && $settings->getBool('scheduler.fallback', true);
        } catch (Throwable) {
            return false;
        }
    }

    public function intervalSeconds(): int
    {
        try {
            return $this->settings?->getInt('scheduler.interval', $this->defaultIntervalSeconds, 10, 3600)
                ?? $this->defaultIntervalSeconds;
        } catch (Throwable) {
            return $this->defaultIntervalSeconds;
        }
    }
}
