<?php

declare(strict_types=1);

namespace Prime\Cache;

/**
 * Atomic, database-free mirror of the runtime cache options stored in `storage/cache/config.json`.
 *
 * Only booleans and integers are persisted (JSON only — never PHP serialization).
 */
final class CacheRuntimeConfig
{
    public const SETTING_MAP = [
        'cache.driver' => 'driver',
        'cache.page_enabled' => 'page_enabled',
        'cache.page_ttl' => 'page_ttl',
    ];

    /**
     * @return array{driver?: string, page_enabled?: bool, page_ttl?: int}
     */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];

        $driver = $decoded['driver'] ?? null;
        if (is_string($driver) && in_array(strtolower(trim($driver)), ['file', 'apcu', 'array'], true)) {
            $out['driver'] = strtolower(trim($driver));
        }

        if (array_key_exists('page_enabled', $decoded)) {
            $out['page_enabled'] = (bool) $decoded['page_enabled'];
        }

        $ttl = $decoded['page_ttl'] ?? null;
        if (is_int($ttl) && $ttl >= 10 && $ttl <= 86400) {
            $out['page_ttl'] = $ttl;
        }

        return $out;
    }

    /**
     * Persist a saved setting into the runtime config file.
     *
     * @return array{page_enabled: ?bool, page_ttl: ?int, driver: ?string} Changes applied to the runtime objects
     */
    public static function applySetting(string $path, string $name, mixed $value): array
    {
        $key = self::SETTING_MAP[$name] ?? null;
        if ($key === null) {
            return ['page_enabled' => null, 'page_ttl' => null, 'driver' => null];
        }

        $current = self::read($path);
        $changes = ['page_enabled' => null, 'page_ttl' => null, 'driver' => null];

        if ($key === 'driver') {
            $candidate = is_string($value) ? strtolower(trim($value)) : '';
            if (!in_array($candidate, ['file', 'apcu', 'array'], true)) {
                return $changes;
            }
            $current['driver'] = $candidate;
            $changes['driver'] = $candidate;
        } elseif ($key === 'page_enabled') {
            $enabled = (bool) $value;
            $current['page_enabled'] = $enabled;
            $changes['page_enabled'] = $enabled;
        } else {
            $ttl = max(10, min(86400, (int) (is_numeric($value) ? $value : 600)));
            $current['page_ttl'] = $ttl;
            $changes['page_ttl'] = $ttl;
        }

        self::write($path, $current);

        return $changes;
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function write(string $path, array $values): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $json = json_encode($values, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }

        $tmp = @tempnam($dir, 'cfg_');
        if (!is_string($tmp)) {
            return false;
        }

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);

            return false;
        }

        @chmod($tmp, 0640);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }
}
