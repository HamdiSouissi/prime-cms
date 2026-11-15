<?php

declare(strict_types=1);

namespace Prime\Plugins;

use InvalidArgumentException;
use Prime\Settings\Settings;

/**
 * Plugin-scoped key/value settings store backed by `pc_settings` with automatic
 * `plugin.{slug}.` key prefixing so plugins never collide with core or other plugins.
 */
final class PluginSettings
{
    private readonly string $prefix;

    public function __construct(
        private readonly Settings $settings,
        string $slug,
    ) {
        $this->prefix = 'plugin.' . $slug . '.';
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settings->get($this->qualify($key), $default);
    }

    public function has(string $key): bool
    {
        return $this->settings->has($this->qualify($key));
    }

    public function set(string $key, mixed $value, bool $autoload = true): void
    {
        $this->settings->set($this->qualify($key), $value, $autoload);
    }

    public function delete(string $key): bool
    {
        return $this->settings->delete($this->qualify($key));
    }

    /**
     * Return all settings belonging to this plugin (keys returned without prefix).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $prefixed = $this->settings->allByPrefix($this->prefix);
        $len = strlen($this->prefix);
        $unprefixed = [];

        foreach ($prefixed as $fullKey => $value) {
            $unprefixed[substr($fullKey, $len)] = $value;
        }

        return $unprefixed;
    }

    /**
     * Delete all persisted settings owned by this plugin (called on uninstall).
     */
    public function purgeAll(): int
    {
        return $this->settings->deleteByPrefix($this->prefix);
    }

    private function qualify(string $key): string
    {
        $clean = trim($key);
        if ($clean === '') {
            throw new InvalidArgumentException('Plugin setting key must not be empty.');
        }

        if (str_starts_with($clean, $this->prefix)) {
            return $clean;
        }

        return $this->prefix . $clean;
    }
}
