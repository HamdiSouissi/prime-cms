<?php

declare(strict_types=1);

namespace Prime\Plugins;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Fatal-error witness guard (`storage/plugins/loading.json`).
 *
 * Before loading or invoking a plugin, writes the currently loading slug/phase into
 * `storage/plugins/loading.json` and removes it upon normal return. If PHP aborts with a
 * fatal error (e.g. `E_ERROR`, `E_COMPILE_ERROR`, OOM), either the shutdown handler or
 * the next boot's `recoverPendingCrash()` isolates the offending plugin automatically.
 */
final class FatalLoadGuard
{
    private const FATAL_TYPES = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
    ];

    private bool $shutdownRegistered = false;

    /** @var ?callable(string, string, string): void */
    private $onFatalCallback = null;

    public function __construct(private readonly string $witnessFile)
    {
    }

    public function witnessFile(): string
    {
        return $this->witnessFile;
    }

    /**
     * Register a PHP shutdown listener to immediately isolate a plugin on uncatchable fatals.
     *
     * @param callable(string, string, string): void $onFatal Receives `($slug, $phase, $errorMessage)`
     */
    public function registerShutdownHandler(callable $onFatal): void
    {
        $this->onFatalCallback = $onFatal;

        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], self::FATAL_TYPES, true)) {
                return;
            }

            $pending = $this->readWitness();
            if ($pending === null) {
                return;
            }

            $this->clear();

            if ($this->onFatalCallback !== null) {
                $message = sprintf(
                    'Erreur fatale PHP (%s) dans %s:%d',
                    $error['message'],
                    $error['file'],
                    $error['line'],
                );
                try {
                    ($this->onFatalCallback)($pending['slug'], $pending['phase'], $message);
                } catch (Throwable) {
                    // Never crash inside shutdown recovery.
                }
            }
        });
    }

    /**
     * Check if a leftover `loading.json` witness exists from a prior hard crash (OOM/SIGKILL).
     * Clears the file and returns the crashed plugin payload, or null if clean.
     *
     * @return ?array{slug: string, phase: string, started_at: string}
     */
    public function consumePendingCrash(): ?array
    {
        $pending = $this->readWitness();
        if ($pending === null) {
            return null;
        }

        $this->clear();

        return $pending;
    }

    /**
     * Execute `$callback` while recording `$slug`/`$phase` in the witness file.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function guard(string $slug, string $phase, callable $callback): mixed
    {
        $this->markLoading($slug, $phase);

        try {
            return $callback();
        } finally {
            $this->clear();
        }
    }

    public function markLoading(string $slug, string $phase): void
    {
        $dir = dirname($this->witnessFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $payload = json_encode([
            'slug' => $slug,
            'phase' => $phase,
            'started_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (is_string($payload)) {
            @file_put_contents($this->witnessFile, $payload, LOCK_EX);
        }
    }

    public function clear(): void
    {
        if (is_file($this->witnessFile)) {
            @unlink($this->witnessFile);
        }
    }

    /**
     * @return ?array{slug: string, phase: string, started_at: string}
     */
    public function readWitness(): ?array
    {
        if (!is_file($this->witnessFile) || !is_readable($this->witnessFile)) {
            return null;
        }

        $raw = @file_get_contents($this->witnessFile);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (
            !is_array($decoded)
            || !isset($decoded['slug'], $decoded['phase'])
            || !is_string($decoded['slug'])
            || !is_string($decoded['phase'])
            || $decoded['slug'] === ''
        ) {
            return null;
        }

        return [
            'slug' => $decoded['slug'],
            'phase' => $decoded['phase'],
            'started_at' => isset($decoded['started_at']) && is_string($decoded['started_at'])
                ? $decoded['started_at']
                : '',
        ];
    }
}
