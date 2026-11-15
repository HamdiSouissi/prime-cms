<?php

declare(strict_types=1);

namespace Prime\Kernel;

use RuntimeException;

/**
 * Dot-notation configuration repository loaded from PHP array files.
 */
final class Config
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(private array $items = [])
    {
    }

    /**
     * Load all `*.php` configuration files from a directory.
     */
    public static function loadFromDirectory(string $directory): self
    {
        $items = [];

        if (!is_dir($directory)) {
            return new self($items);
        }

        $files = glob(rtrim($directory, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false) {
            return new self($items);
        }

        sort($files);

        foreach ($files as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            $data = require $file;

            if (!is_array($data)) {
                throw new RuntimeException(sprintf('Configuration file [%s] must return an array.', $file));
            }

            $items[$key] = $data;
        }

        return new self($items);
    }

    /**
     * Retrieve a configuration value using dot notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        $segments = explode('.', $key);
        $current = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Set a configuration value using dot notation.
     */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $current = &$this->items;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $current[$segment] = $value;

                return;
            }

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }
    }

    /**
     * Determine whether a configuration key exists (even when its value is null).
     */
    public function has(string $key): bool
    {
        if (array_key_exists($key, $this->items)) {
            return true;
        }

        $segments = explode('.', $key);
        $current = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }

            $current = $current[$segment];
        }

        return true;
    }

    /**
     * Return all configuration entries.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }
}
