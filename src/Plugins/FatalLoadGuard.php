<?php

declare(strict_types=1);

namespace Prime\Plugins;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Fatal-error witness guard.
 *
 * Before loading or invoking a plugin (or a theme), the guard writes the slug/phase being loaded
 * into a witness file and removes it upon normal return. If PHP aborts with a fatal error
 * (`E_ERROR`, `E_COMPILE_ERROR`, OOM…), either the shutdown handler or a later boot's
 * {@see consumePendingCrash()} isolates the offending extension.
 *
 * Concurrency (Prime CMS 1.0.2): every guard instance — i.e. every request — owns its own witness
 * file (`loading.<token>.json` next to the configured path) and holds an exclusive `flock()` on it
 * while loading. A witness is only reported as a crash when its lock can be taken, which means the
 * process that wrote it is gone without cleaning up (the OS releases locks of a killed process).
 * A witness still locked belongs to a request running right now and is never mistaken for a crash.
 * Previously a single shared `loading.json` made a concurrent request isolate healthy plugins.
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

    /** @var ?callable(string, string, string, string, int): void */
    private $onFatalCallback = null;

    /** Witness file owned by this instance (unique per request), created on first use. */
    private ?string $ownFile = null;

    /** @var resource|null Open handle holding the exclusive lock on {@see $ownFile}. */
    private $handle = null;

    public function __construct(private readonly string $witnessFile)
    {
    }

    public function __destruct()
    {
        // Normal end of the object without clear(): keep the file (it is a crash witness),
        // just release the handle so another request can take the lock.
        if (is_resource($this->handle)) {
            @flock($this->handle, LOCK_UN);
            @fclose($this->handle);
        }
        $this->handle = null;
    }

    /**
     * The configured witness path. Witness files live next to it: the legacy file itself
     * (written by Prime CMS ≤ 1.0.1) and one `<name>.<token>.json` per loading request.
     */
    public function witnessFile(): string
    {
        return $this->witnessFile;
    }

    /**
     * Register a PHP shutdown listener to immediately isolate a plugin on uncatchable fatals.
     *
     * @param callable(string, string, string, string, int): void $onFatal Receives `($slug, $phase, $rawMessage, $file, $line)`
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

            // Only this request's own witness tells what it was loading when it died.
            $pending = $this->ownFile !== null ? $this->readFile($this->ownFile) : null;
            if ($pending === null) {
                return;
            }

            $this->clear();

            if ($this->onFatalCallback !== null) {
                try {
                    ($this->onFatalCallback)(
                        $pending['slug'],
                        $pending['phase'],
                        $error['message'],
                        $error['file'],
                        $error['line'],
                    );
                } catch (Throwable) {
                    // Never crash inside shutdown recovery.
                }
            }
        });
    }

    /**
     * Report a witness left behind by a request that died while loading (OOM, SIGKILL…), and
     * delete it. Witnesses of requests still running (lock held) are ignored. Returns null when
     * there is no abandoned witness.
     *
     * @return ?array{slug: string, phase: string, started_at: string}
     */
    public function consumePendingCrash(): ?array
    {
        foreach ($this->witnessFiles() as $file) {
            if ($file === $this->ownFile) {
                continue;
            }

            $handle = @fopen($file, 'r');
            if ($handle === false) {
                continue;
            }

            // Lock held by a live request: it is loading right now, not crashed.
            if (!@flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                continue;
            }

            $pending = $this->decode((string) stream_get_contents($handle));
            @unlink($file);
            @flock($handle, LOCK_UN);
            fclose($handle);

            if ($pending !== null) {
                return $pending;
            }
        }

        return null;
    }

    /**
     * Execute `$callback` while recording `$slug`/`$phase` in this request's witness file.
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
        $payload = json_encode([
            'slug' => $slug,
            'phase' => $phase,
            'started_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'pid' => getmypid(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($payload)) {
            return;
        }

        $handle = $this->ownHandle();
        if ($handle === null) {
            return;
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $payload);
        fflush($handle);
    }

    /**
     * Normal return: delete this request's witness and release its lock.
     */
    public function clear(): void
    {
        if ($this->ownFile !== null && is_file($this->ownFile)) {
            @unlink($this->ownFile);
        }

        if (is_resource($this->handle)) {
            @flock($this->handle, LOCK_UN);
            @fclose($this->handle);
        }

        $this->handle = null;
        $this->ownFile = null;
    }

    /**
     * First witness found (this request's own first, then any other), without consuming it.
     * Mainly a diagnostic: `null` means no loading is recorded anywhere.
     *
     * @return ?array{slug: string, phase: string, started_at: string}
     */
    public function readWitness(): ?array
    {
        if ($this->ownFile !== null) {
            $own = $this->readFile($this->ownFile);
            if ($own !== null) {
                return $own;
            }
        }

        foreach ($this->witnessFiles() as $file) {
            $witness = $this->readFile($file);
            if ($witness !== null) {
                return $witness;
            }
        }

        return null;
    }

    /**
     * @return resource|null
     */
    private function ownHandle()
    {
        if (is_resource($this->handle)) {
            return $this->handle;
        }

        $dir = dirname($this->witnessFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $file = $this->witnessPrefix() . bin2hex(random_bytes(8)) . '.json';
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return null;
        }

        if (!@flock($handle, LOCK_EX)) {
            fclose($handle);
            @unlink($file);

            return null;
        }

        $this->handle = $handle;
        $this->ownFile = $file;

        return $handle;
    }

    /**
     * Legacy shared file plus every per-request witness next to it.
     *
     * @return list<string>
     */
    private function witnessFiles(): array
    {
        $files = glob($this->witnessPrefix() . '*.json') ?: [];
        if (is_file($this->witnessFile)) {
            array_unshift($files, $this->witnessFile);
        }

        return array_values(array_unique($files));
    }

    /** `…/loading.json` → `…/loading.` */
    private function witnessPrefix(): string
    {
        $base = basename($this->witnessFile);
        $stem = str_ends_with($base, '.json') ? substr($base, 0, -5) : $base;

        return dirname($this->witnessFile) . DIRECTORY_SEPARATOR . $stem . '.';
    }

    /**
     * @return ?array{slug: string, phase: string, started_at: string}
     */
    private function readFile(string $file): ?array
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $raw = @file_get_contents($file);

        return is_string($raw) ? $this->decode($raw) : null;
    }

    /**
     * @return ?array{slug: string, phase: string, started_at: string}
     */
    private function decode(string $raw): ?array
    {
        if ($raw === '') {
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
