<?php

declare(strict_types=1);

namespace Prime\Settings;

use Closure;

/**
 * Lazy providers feeding the option lists of declared settings (`timezones`, `locales`, `roles`,
 * `pages`, `categories`, …).
 *
 * Resolving on demand keeps the registry free of database queries: a settings screen only asks for
 * the sources its fields actually reference.
 */
final class SettingsOptionSources
{
    /**
     * @var array<string, Closure(): array<int|string, string>>
     */
    private array $providers = [];

    /**
     * @var array<string, array<int|string, string>>
     */
    private array $resolved = [];

    /**
     * @param Closure(): array<int|string, string> $factory
     */
    public function provide(string $key, Closure $factory): void
    {
        $this->providers[$key] = $factory;
        unset($this->resolved[$key]);
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * @return array<string, Closure(): array<int|string, string>>
     */
    public function keys(): array
    {
        return $this->providers;
    }

    /**
     * @return array<int|string, string>
     */
    public function resolve(?string $key): array
    {
        if ($key === null || $key === '') {
            return [];
        }

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $provider = $this->providers[$key] ?? null;
        if ($provider === null) {
            return [];
        }

        $options = $provider();
        $this->resolved[$key] = $options;

        return $options;
    }

    public function flush(): void
    {
        $this->resolved = [];
    }
}
