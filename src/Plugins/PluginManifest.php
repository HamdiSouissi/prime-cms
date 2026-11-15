<?php

declare(strict_types=1);

namespace Prime\Plugins;

/**
 * Validated, immutable representation of a plugin's `plugin.json` manifest.
 */
final readonly class PluginManifest
{
    /**
     * @param list<string> $requiredExtensions
     * @param array<string, string> $dependencies Map of plugin slug => SemVer constraint
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $description,
        public string $version,
        public string $authorName,
        public ?string $authorUrl,
        public string $license,
        public string $namespace,
        public string $main,
        public ?string $requiresPrime,
        public ?string $requiresPhp,
        public array $requiredExtensions,
        public array $dependencies,
        public ?string $icon,
        public string $accent,
        public ?string $settingsUrl,
        public string $hash,
    ) {
    }

    /**
     * Return two uppercase initials derived from the plugin's display name (fallback when no icon).
     */
    public function initials(): string
    {
        $words = preg_split('/[\s_-]+/', trim($this->name));
        if ($words === false || $words === []) {
            return 'PL';
        }

        if (count($words) >= 2 && $words[0] !== '' && $words[1] !== '') {
            return strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
        }

        return strtoupper(mb_substr($this->name, 0, 2));
    }
}
