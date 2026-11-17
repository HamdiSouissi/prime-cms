<?php

declare(strict_types=1);

namespace Prime\Cache;

use Closure;
use InvalidArgumentException;
use JsonException;

/**
 * File-based JSON data cache (`storage/cache/data/`) with atomic writes (tmp + rename),
 * SHA-256 hashed filenames, non-executable `.cache` extension, and `.htaccess` protection.
 *
 * Uses strict JSON encoding only — zero PHP object serialization/unserialization.
 */
final class FileCache implements CacheInterface
{
    /**
     * @var Closure(): int
     */
    private readonly Closure $clock;

    private bool $dirInitialized = false;

    /**
     * @param (Closure(): int)|null $clock Optional deterministic Unix timestamp clock
     */
    public function __construct(
        private readonly string $directory,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return $default;
        }

        $entry = $this->readEnvelope($path);
        if ($entry === null) {
            return $default;
        }

        if ($entry['expires_at'] !== null && $entry['expires_at'] <= ($this->clock)()) {
            @unlink($path);

            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($ttl !== null && $ttl <= 0) {
            return $this->delete($key);
        }

        $this->ensureDirectory();

        $envelope = [
            'expires_at' => $ttl !== null ? ($this->clock)() + $ttl : null,
            'value' => $value,
        ];

        try {
            $json = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Cache value must be JSON-serializable: ' . $e->getMessage(), 0, $e);
        }

        return $this->atomicWrite($this->pathFor($key), $json);
    }

    public function has(string $key): bool
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return false;
        }

        $entry = $this->readEnvelope($path);
        if ($entry === null) {
            return false;
        }

        if ($entry['expires_at'] !== null && $entry['expires_at'] <= ($this->clock)()) {
            @unlink($path);

            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return true;
        }

        return @unlink($path);
    }

    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        $path = $this->pathFor($key);
        if (is_file($path)) {
            $entry = $this->readEnvelope($path);
            if ($entry !== null && ($entry['expires_at'] === null || $entry['expires_at'] > ($this->clock)())) {
                return $entry['value'];
            }
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function increment(string $key, int $amount = 1, ?int $ttl = null): int
    {
        $this->ensureDirectory();
        $lockPath = $this->pathFor($key) . '.lock';
        $lock = @fopen($lockPath, 'c+');

        if ($lock !== false) {
            @flock($lock, LOCK_EX);
        }

        try {
            $current = $this->get($key, 0);
            $next = (is_int($current) ? $current : (int) (is_numeric($current) ? $current : 0)) + $amount;
            $this->set($key, $next, $ttl);

            return $next;
        } finally {
            if ($lock !== false) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
                @unlink($lockPath);
            }
        }
    }

    public function flush(): bool
    {
        if (!is_dir($this->directory)) {
            return true;
        }

        $ok = true;
        $files = glob($this->directory . '/*.cache') ?: [];
        foreach ($files as $file) {
            if (is_file($file) && !@unlink($file)) {
                $ok = false;
            }
        }

        $tmps = glob($this->directory . '/tmp_*') ?: [];
        foreach ($tmps as $tmp) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        return $ok;
    }

    public function namespace(string $namespace): NamespacedCache
    {
        return new NamespacedCache($this, $namespace);
    }

    /**
     * Delete all expired `.cache` files (and stale temporary files older than 10 minutes).
     *
     * @return int Number of deleted cache entries
     */
    public function pruneExpired(): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $now = ($this->clock)();
        $pruned = 0;

        $files = glob($this->directory . '/*.cache') ?: [];
        foreach ($files as $file) {
            $entry = $this->readEnvelope($file);
            if ($entry === null || ($entry['expires_at'] !== null && $entry['expires_at'] <= $now)) {
                if (@unlink($file)) {
                    $pruned++;
                }
            }
        }

        $tmps = glob($this->directory . '/tmp_*') ?: [];
        foreach ($tmps as $tmp) {
            $mtime = @filemtime($tmp);
            if ($mtime !== false && ($now - $mtime) > 600) {
                @unlink($tmp);
            }
        }

        return $pruned;
    }

    /**
     * Return active entry count and total disk usage in bytes.
     *
     * @return array{entries: int, bytes: int}
     */
    public function stats(): array
    {
        if (!is_dir($this->directory)) {
            return ['entries' => 0, 'bytes' => 0];
        }

        $now = ($this->clock)();
        $entries = 0;
        $bytes = 0;

        $files = glob($this->directory . '/*.cache') ?: [];
        foreach ($files as $file) {
            $entry = $this->readEnvelope($file);
            if ($entry === null || ($entry['expires_at'] !== null && $entry['expires_at'] <= $now)) {
                continue;
            }

            $entries++;
            $size = @filesize($file);
            if (is_int($size) && $size > 0) {
                $bytes += $size;
            }
        }

        return ['entries' => $entries, 'bytes' => $bytes];
    }

    /**
     * Return the hashed non-executable cache path for `$key`.
     */
    public function pathFor(string $key): string
    {
        return rtrim($this->directory, '/\\') . '/' . hash('sha256', $key) . '.cache';
    }

    /**
     * @return array{expires_at: ?int, value: mixed}|null
     */
    private function readEnvelope(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded) || !array_key_exists('value', $decoded)) {
            return null;
        }

        $expiresAt = $decoded['expires_at'] ?? null;
        if ($expiresAt !== null && !is_int($expiresAt)) {
            return null;
        }

        return [
            'expires_at' => $expiresAt,
            'value' => $decoded['value'],
        ];
    }

    private function atomicWrite(string $targetPath, string $contents): bool
    {
        $tmpPath = @tempnam($this->directory, 'tmp_');
        if (!is_string($tmpPath)) {
            return false;
        }

        $written = @file_put_contents($tmpPath, $contents, LOCK_EX);
        if ($written === false) {
            @unlink($tmpPath);

            return false;
        }

        @chmod($tmpPath, 0640);

        if (!@rename($tmpPath, $targetPath)) {
            @unlink($tmpPath);

            return false;
        }

        return true;
    }

    private function ensureDirectory(): void
    {
        if ($this->dirInitialized && is_dir($this->directory)) {
            return;
        }

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0750, true);
        }

        // Same two-user story as `PageCache`: the CLI that flushes the data cache and the web process
        // that writes it are rarely the same account, and the entries have to survive both.
        if (!@is_writable($this->directory)) {
            @chmod($this->directory, 0777);
        }

        $htaccess = rtrim($this->directory, '/\\') . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n");
        }

        $index = rtrim($this->directory, '/\\') . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }

        $this->dirInitialized = true;
    }
}
