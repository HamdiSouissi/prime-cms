<?php

declare(strict_types=1);

namespace Prime\Install;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Hooks\Hooks;
use Prime\Install\Exception\InstallException;
use Prime\Kernel\Application;
use Prime\Kernel\Env;
use Prime\Media\MediaConfig;
use Prime\Media\UploadsGuard;
use Prime\Security\Hasher;
use Prime\Settings\Settings;
use Throwable;

/**
 * Unified installation service shared by the web wizard (`InstallController`)
 * and the non-interactive CLI command (`bin/prime install`).
 *
 * Guarantees that `storage/installed.lock` is written strictly as the final step
 * and never left behind after a failure.
 */
final class Installer
{
    private readonly Hasher $hasher;

    public function __construct(
        private readonly Application $app,
        private readonly InstallerValidator $validator = new InstallerValidator(),
        private readonly DatabaseTester $databaseTester = new DatabaseTester(),
        private readonly EnvWriter $envWriter = new EnvWriter(),
        private readonly ?Hooks $hooks = null,
        ?Hasher $hasher = null,
    ) {
        $authCfg = $app->config()->get('auth', []);
        $this->hasher = $hasher ?? new Hasher(
            config: is_array($authCfg) ? $authCfg : [],
            cacheDir: $app->storagePath('cache'),
        );
    }

    /**
     * Run the full Prime CMS installation pipeline.
     *
     * @throws InstallException Identifying the exact failing step on any error
     */
    public function install(InstallConfig $config): InstallResult
    {
        $lockPath = $this->app->storagePath(InstallChecker::LOCK_FILENAME);
        if (is_file($lockPath)) {
            throw new InstallException(
                'lock',
                'Prime CMS semble déjà installé (le fichier storage/installed.lock existe déjà). Supprimez-le uniquement si vous souhaitez réinstaller en environnement local.',
            );
        }

        // Step 1: Validate all parameters strictly.
        $this->validateAll($config);

        $dbConfig = $config->databaseArray();

        // Step 2: Optionally create the database if requested.
        if ($config->createDatabase) {
            try {
                $this->databaseTester->createDatabase($dbConfig);
            } catch (Throwable $exception) {
                throw new InstallException(
                    'database',
                    'Impossible de créer la base de données « ' . $config->dbDatabase . ' ». Vérifiez les privilèges de l\'utilisateur MySQL.',
                    $exception,
                );
            }
        }

        // Step 3: Verify database connectivity and ensure no conflicting tables exist.
        $test = $this->databaseTester->test($dbConfig);
        if (!$test['ok']) {
            throw new InstallException('database', $test['message']);
        }

        // Step 4: Write or verify `.env` configuration.
        $envWritten = false;
        if (!$config->skipEnvWrite) {
            $envOutcome = $this->handleEnvConfiguration($config);
            if ($envOutcome instanceof InstallResult) {
                return $envOutcome;
            }
            $envWritten = $envOutcome;
        }

        // Step 5: Open fresh connection with final credentials and execute core migrations.
        $connection = new Connection($dbConfig, hooks: $this->hooks, debug: false);
        $schema = new Schema($connection);
        $migrator = new Migrator(
            connection: $connection,
            schema: $schema,
            paths: [$this->app->basePath('database/migrations')],
            hooks: $this->hooks,
        );

        try {
            $migrations = $migrator->run();
        } catch (Throwable $exception) {
            throw new InstallException(
                'migrations',
                'Échec lors de la création des tables de base de données : ' . $exception->getMessage(),
                $exception,
            );
        }

        // Step 6: Create the administrator account with Argon2id (or bcrypt fallback).
        $nowUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $installedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        try {
            $passwordHash = $this->hasher->make($config->adminPassword);

            $adminUserId = $connection->table('users')->insertGetId([
                'email' => mb_strtolower(trim($config->adminEmail), 'UTF-8'),
                'password_hash' => $passwordHash,
                'display_name' => trim($config->adminDisplayName),
                'status' => 'active',
                'locale' => $config->siteLocale,
                'last_login_at' => null,
                'password_changed_at' => $nowUtc,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ]);

            $adminRole = $connection->table('roles')
                ->where('slug', '=', 'administrator')
                ->first();

            if ($adminRole !== null) {
                $connection->table('user_roles')->insert([
                    'user_id' => $adminUserId,
                    'role_id' => (int) $adminRole['id'],
                ]);
            }
        } catch (Throwable $exception) {
            throw new InstallException(
                'admin',
                'Impossible de créer le compte administrateur dans la table des utilisateurs.',
                $exception,
            );
        }

        // Step 7: Save core site settings.
        $settings = new Settings($connection, $this->hooks);

        try {
            $settings->set('site_name', trim($config->siteName));
            $settings->set('site_url', rtrim(trim($config->siteUrl), '/'));
            $settings->set('site_locale', $config->siteLocale);
            $settings->set('timezone', $config->timezone);
            $settings->set('installed_at', $installedAt);
            $settings->set('prime_version', Application::VERSION);
        } catch (Throwable $exception) {
            throw new InstallException(
                'settings',
                'Impossible d\'enregistrer les réglages initiaux du site.',
                $exception,
            );
        }

        // Step 7b: Prepare hardened `content/uploads/.htaccess` and best-effort `public/uploads` symlink.
        try {
            $uploadsGuard = new UploadsGuard(MediaConfig::fromApplication($this->app));
            $uploadsGuard->createPublicSymlink();
        } catch (Throwable) {
            // Non-fatal: PHP fallback `GET /uploads/{path}` remains available if symlinks are unsupported.
        }

        // Step 8: Write `storage/installed.lock` strictly as the final operation (zero secrets!).
        $this->writeLockFile($lockPath, $installedAt);

        // Synchronize active container config/connection/settings for the current process.
        $this->syncRuntimeServices($config, $connection, $settings);

        // Step 9: Emit `install.completed` hook with safe, secret-free metadata.
        $this->hooks?->emit('install.completed', [
            'site_name' => trim($config->siteName),
            'site_url' => rtrim(trim($config->siteUrl), '/'),
            'site_locale' => $config->siteLocale,
            'timezone' => $config->timezone,
            'installed_at' => $installedAt,
            'version' => Application::VERSION,
            'migrations' => $migrations,
        ]);

        return InstallResult::completed(
            installedAt: $installedAt,
            migrations: $migrations,
            adminUserId: $adminUserId,
            envWritten: $envWritten,
        );
    }

    private function validateAll(InstallConfig $config): void
    {
        $dbErrors = $this->validator->validateDatabase([
            'db_host' => $config->dbHost,
            'db_port' => (string) $config->dbPort,
            'db_database' => $config->dbDatabase,
            'db_username' => $config->dbUsername,
            'db_prefix' => $config->dbPrefix,
        ]);
        if ($dbErrors !== []) {
            throw new InstallException('validation', (string) reset($dbErrors));
        }

        $siteErrors = $this->validator->validateSite([
            'site_name' => $config->siteName,
            'site_url' => $config->siteUrl,
            'site_locale' => $config->siteLocale,
            'timezone' => $config->timezone,
        ]);
        if ($siteErrors !== []) {
            throw new InstallException('validation', (string) reset($siteErrors));
        }

        $adminErrors = $this->validator->validateAdmin([
            'display_name' => $config->adminDisplayName,
            'email' => $config->adminEmail,
            'password' => $config->adminPassword,
            'password_confirmation' => $config->adminPassword,
        ], $config->siteName);
        if ($adminErrors !== []) {
            throw new InstallException('validation', (string) reset($adminErrors));
        }
    }

    /**
     * @return bool|InstallResult True if written automatically, false if verified manually created, or InstallResult for manual prompt
     */
    private function handleEnvConfiguration(InstallConfig $config): bool|InstallResult
    {
        $envPath = $this->app->basePath('.env');
        $templatePath = $this->app->basePath('.env.example');
        $appKey = $config->appKey ?? $this->envWriter->generateAppKey();

        $values = [
            'APP_NAME' => trim($config->siteName),
            'APP_URL' => rtrim(trim($config->siteUrl), '/'),
            'APP_KEY' => $appKey,
            'APP_ENV' => $config->appEnv,
            'APP_DEBUG' => $config->appDebug ? 'true' : 'false',
            'APP_TIMEZONE' => $config->timezone,
            'APP_LOCALE' => $config->siteLocale,
            'DB_HOST' => $config->dbHost,
            'DB_PORT' => (string) $config->dbPort,
            'DB_DATABASE' => $config->dbDatabase,
            'DB_USERNAME' => $config->dbUsername,
            'DB_PASSWORD' => $config->dbPassword,
            'DB_PREFIX' => $config->dbPrefix,
        ];

        if ($this->envWriter->isWritable($envPath)) {
            $written = $this->envWriter->write($envPath, $values, $templatePath);
            if ($written) {
                return true;
            }
        }

        // If `.env` is not writable, check whether the user already created it manually with matching values.
        if (is_file($envPath) && is_readable($envPath)) {
            $raw = file_get_contents($envPath);
            if ($raw !== false && $this->manualEnvMatches($raw, $config)) {
                return false;
            }
        }

        if ($config->allowManualEnv) {
            $content = $this->envWriter->buildContent($envPath, $values, $templatePath);

            return InstallResult::manualEnvRequired($content, $appKey);
        }

        throw new InstallException(
            'env',
            'Impossible d\'écrire le fichier .env à la racine du projet. Vérifiez les permissions du dossier ou utilisez --skip-env si vos variables d\'environnement sont gérées par le serveur.',
        );
    }

    private function manualEnvMatches(string $rawContent, InstallConfig $config): bool
    {
        $parsed = Env::parse($rawContent);

        $appKey = $parsed['APP_KEY'] ?? '';
        if (!str_starts_with($appKey, 'base64:') || strlen($appKey) < 20) {
            return false;
        }

        return ($parsed['DB_HOST'] ?? '') === $config->dbHost
            && ($parsed['DB_DATABASE'] ?? '') === $config->dbDatabase
            && ($parsed['DB_USERNAME'] ?? '') === $config->dbUsername
            && ($parsed['DB_PREFIX'] ?? '') === $config->dbPrefix;
    }

    private function writeLockFile(string $lockPath, string $installedAt): void
    {
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $payload = json_encode([
            'installed_at' => $installedAt,
            'version' => Application::VERSION,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $written = @file_put_contents($lockPath, $payload, LOCK_EX);
        if ($written === false) {
            if (is_file($lockPath)) {
                @unlink($lockPath);
            }

            throw new InstallException(
                'lock',
                'Impossible de créer le fichier de verrouillage storage/installed.lock. Vérifiez les droits d\'écriture sur le dossier storage/.',
            );
        }

        @chmod($lockPath, 0644);
    }

    private function syncRuntimeServices(InstallConfig $config, Connection $connection, Settings $settings): void
    {
        $appConfig = $this->app->config();
        $appConfig->set('app.name', trim($config->siteName));
        $appConfig->set('app.url', rtrim(trim($config->siteUrl), '/'));
        $appConfig->set('app.locale', $config->siteLocale);
        $appConfig->set('app.timezone', $config->timezone);
        $appConfig->set('database', $config->databaseArray());

        $container = $this->app->container();
        $container->instance(Connection::class, $connection);
        $container->instance(Settings::class, $settings);
    }
}
