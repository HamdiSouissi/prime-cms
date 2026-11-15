<?php

declare(strict_types=1);

namespace Prime\Install;

/**
 * Immutable configuration value object consumed by `Prime\Install\Installer`.
 *
 * Masks passwords in debug dumps so credentials never leak into error output.
 */
final readonly class InstallConfig
{
    public function __construct(
        public string $dbHost,
        public int $dbPort,
        public string $dbDatabase,
        public string $dbUsername,
        public string $dbPassword,
        public string $dbPrefix,
        public string $siteName,
        public string $siteUrl,
        public string $siteLocale,
        public string $timezone,
        public string $adminDisplayName,
        public string $adminEmail,
        public string $adminPassword,
        public string $appEnv = 'production',
        public bool $appDebug = false,
        public bool $createDatabase = false,
        public bool $skipEnvWrite = false,
        public bool $allowManualEnv = false,
        public ?string $appKey = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'dbHost' => $this->dbHost,
            'dbPort' => $this->dbPort,
            'dbDatabase' => $this->dbDatabase,
            'dbUsername' => $this->dbUsername,
            'dbPassword' => '********',
            'dbPrefix' => $this->dbPrefix,
            'siteName' => $this->siteName,
            'siteUrl' => $this->siteUrl,
            'siteLocale' => $this->siteLocale,
            'timezone' => $this->timezone,
            'adminDisplayName' => $this->adminDisplayName,
            'adminEmail' => $this->adminEmail,
            'adminPassword' => '********',
            'appEnv' => $this->appEnv,
            'appDebug' => $this->appDebug,
            'createDatabase' => $this->createDatabase,
            'skipEnvWrite' => $this->skipEnvWrite,
            'allowManualEnv' => $this->allowManualEnv,
            'appKey' => $this->appKey !== null ? '********' : null,
        ];
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string, prefix: string, charset: string, collation: string}
     */
    public function databaseArray(): array
    {
        return [
            'host' => $this->dbHost,
            'port' => $this->dbPort,
            'database' => $this->dbDatabase,
            'username' => $this->dbUsername,
            'password' => $this->dbPassword,
            'prefix' => $this->dbPrefix,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ];
    }
}
