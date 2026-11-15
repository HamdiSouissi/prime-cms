<?php

declare(strict_types=1);

namespace Prime\Install;

/**
 * Structured outcome returned by `Prime\Install\Installer::install()`.
 */
final readonly class InstallResult
{
    /**
     * @param list<string> $migrations
     */
    private function __construct(
        public bool $completed,
        public bool $manualEnvRequired,
        public ?string $envContent,
        public ?string $appKey,
        public string $installedAt,
        public array $migrations,
        public int $adminUserId,
        public bool $envWritten,
    ) {
    }

    /**
     * @param list<string> $migrations
     */
    public static function completed(
        string $installedAt,
        array $migrations,
        int $adminUserId,
        bool $envWritten,
    ): self {
        return new self(
            completed: true,
            manualEnvRequired: false,
            envContent: null,
            appKey: null,
            installedAt: $installedAt,
            migrations: $migrations,
            adminUserId: $adminUserId,
            envWritten: $envWritten,
        );
    }

    public static function manualEnvRequired(string $envContent, string $appKey): self
    {
        return new self(
            completed: false,
            manualEnvRequired: true,
            envContent: $envContent,
            appKey: $appKey,
            installedAt: '',
            migrations: [],
            adminUserId: 0,
            envWritten: false,
        );
    }
}
