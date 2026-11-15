<?php

declare(strict_types=1);

namespace Prime\Plugins;

/**
 * Combined discovery + database + validation state for a plugin on disk.
 */
final readonly class PluginInfo
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ERRORED = 'errored';

    /**
     * @param list<string> $errors Manifest validation errors
     * @param list<string> $warnings Manifest validation warnings
     * @param list<string> $requirementIssues Unmet Prime / PHP / extension constraints
     * @param list<string> $dependencyIssues Unmet or circular plugin dependency issues
     * @param list<string> $requiredBy Slugs of other installed plugins depending on this plugin
     * @param list<string> $activeDependents Slugs of other ACTIVE plugins depending on this plugin
     */
    public function __construct(
        public string $slug,
        public string $directory,
        public ?PluginManifest $manifest,
        public string $status,
        public ?string $installedVersion = null,
        public ?string $lastError = null,
        public ?string $erroredAt = null,
        public ?string $installedAt = null,
        public ?string $activatedAt = null,
        public array $errors = [],
        public array $warnings = [],
        public array $requirementIssues = [],
        public array $dependencyIssues = [],
        public bool $needsUpdate = false,
        public bool $isDowngrade = false,
        public ?string $settingsUrl = null,
        public ?string $iconUrl = null,
        public array $requiredBy = [],
        public array $activeDependents = [],
    ) {
    }

    public function name(): string
    {
        return $this->manifest !== null ? $this->manifest->name : $this->slug;
    }

    public function version(): string
    {
        return $this->manifest !== null
            ? $this->manifest->version
            : ($this->installedVersion ?? '0.0.0');
    }

    public function accent(): string
    {
        return $this->manifest !== null ? $this->manifest->accent : 'violet';
    }

    public function isValid(): bool
    {
        return $this->manifest !== null && $this->errors === [];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isInactive(): bool
    {
        return $this->status === self::STATUS_INACTIVE;
    }

    public function isErrored(): bool
    {
        return $this->status === self::STATUS_ERRORED;
    }

    public function canActivate(): bool
    {
        return $this->isValid()
            && !$this->isActive()
            && $this->requirementIssues === []
            && $this->dependencyIssues === []
            && !$this->isDowngrade;
    }

    public function canDeactivate(): bool
    {
        return $this->isActive() && $this->activeDependents === [];
    }

    public function canUninstall(): bool
    {
        return !$this->isActive() && $this->requiredBy === [];
    }

    /**
     * All detected blocking issues combined (manifest + requirements + dependencies + downgrade).
     *
     * @return list<string>
     */
    public function allIssues(): array
    {
        $issues = array_merge($this->errors, $this->requirementIssues, $this->dependencyIssues);
        if ($this->isDowngrade && $this->manifest !== null && $this->installedVersion !== null) {
            $issues[] = sprintf(
                'Rétrogradation détectée : la version sur disque (%s) est inférieure à la version installée (%s).',
                $this->manifest->version,
                $this->installedVersion,
            );
        }

        return $issues;
    }
}
