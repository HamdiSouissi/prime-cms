<?php

declare(strict_types=1);

namespace Prime\Admin\Data;

use Prime\Admin\Menu\MenuItem;

/**
 * Shared validation helpers for admin view-data value objects normalized after hooks/filters.
 */
final class FilterValueHelper
{
    private const ICON_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    /**
     * Validate a safe admin/web URL (relative path starting with `/`, or http(s) URL; never `javascript:`/`data:`/control chars).
     */
    public static function isSafeUrl(string $url): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '' || preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            return false;
        }

        if (str_starts_with($trimmed, '/')) {
            return !str_starts_with($trimmed, '//');
        }

        if (preg_match('/^https?:\/\//i', $trimmed) === 1) {
            return filter_var($trimmed, FILTER_VALIDATE_URL) !== false;
        }

        return false;
    }

    public static function isValidIcon(string $icon): bool
    {
        return preg_match(self::ICON_PATTERN, $icon) === 1;
    }

    public static function isValidAccent(string $accent): bool
    {
        return in_array($accent, MenuItem::ALLOWED_ACCENTS, true);
    }

    /**
     * Read a required non-empty string field.
     *
     * @param array<mixed, mixed> $data
     */
    public static function requiredString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || !is_string($data[$key])) {
            return null;
        }

        $trimmed = trim($data[$key]);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Read an optional string field; returns null if present with a non-string type.
     *
     * @param array<mixed, mixed> $data
     */
    public static function optionalString(array $data, string $key, string $default = ''): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return $default;
        }

        if (!is_string($data[$key])) {
            return null;
        }

        return trim($data[$key]);
    }
}
