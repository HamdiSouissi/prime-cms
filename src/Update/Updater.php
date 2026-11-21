<?php

declare(strict_types=1);

namespace Prime\Update;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Cache\CacheManager;
use Prime\Database\Migrations\MigrationRepository;
use Prime\Database\Migrations\Migrator;
use Prime\Kernel\Application;
use Prime\Log\Logger;
use Prime\Plugins\PluginManager;
use Prime\Settings\Settings;
use Prime\Themes\ThemeManager;
use RuntimeException;
use Throwable;

/**
 * Brings the database and the published files in line with the code that was just deployed
 * (`php bin/prime update`, « Outils › Mises à jour »).
 *
 * Order: backup (confirmed, or `mysqldump`) → maintenance on → core migrations → migrations of
 * active plugins → plugin and theme assets → caches → version recorded → maintenance back to
 * its previous state. Every step is logged; the first failure stops the run and is reported.
 * There is no remote update server: the code is deployed by the administrator (git, archive).
 */
final class Updater
{
    public const LAST_RUN = 'update.last_run';

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * @return array{installed: string, code: string, pending: list<array{source: string, migration: string}>, last_run: ?array<string, mixed>, mysqldump: bool}
     */
    public function status(): array
    {
        $settings = $this->app->get(Settings::class);
        $installed = $settings->get('prime_version');
        $last = $settings->get(self::LAST_RUN);

        return [
            'installed' => is_string($installed) && $installed !== '' ? $installed : '0.0.0',
            'code' => Application::VERSION,
            'pending' => $this->pendingMigrations(),
            'last_run' => is_array($last) ? $last : null,
            'mysqldump' => $this->mysqldumpPath() !== null,
        ];
    }

    /**
     * @return list<array{source: string, migration: string}>
     */
    public function pendingMigrations(): array
    {
        $migrator = $this->app->get(Migrator::class);
        $pending = [];
        foreach ($migrator->status() as $row) {
            if ($row['status'] === 'pending') {
                $pending[] = ['source' => MigrationRepository::DEFAULT_SOURCE, 'migration' => $row['migration']];
            }
        }

        foreach ($this->activePluginMigrationDirs() as $slug => $dir) {
            $ran = $migrator->repository()->ran($slug);
            foreach (array_keys($migrator->discoverInDirectory($dir)) as $name) {
                if (!isset($ran[$slug . ':' . $name]) && !isset($ran[$name])) {
                    $pending[] = ['source' => $slug, 'migration' => $name];
                }
            }
        }

        return $pending;
    }

    /**
     * @param callable(string): void|null $log
     * @return array{from: string, to: string, migrations: int, backup: ?string, steps: list<string>}
     */
    public function run(bool $backupConfirmed, bool $automaticBackup = false, ?callable $log = null): array
    {
        if (!$backupConfirmed && !$automaticBackup) {
            throw new RuntimeException('Sauvegardez la base avant la mise à jour : confirmez la sauvegarde ou choisissez la sauvegarde automatique.');
        }

        $settings = $this->app->get(Settings::class);
        $logger = $this->app->container()->has(Logger::class) ? $this->app->get(Logger::class) : null;
        $steps = [];
        $say = static function (string $line) use (&$steps, $log, $logger): void {
            $steps[] = $line;
            $logger?->info('Mise à jour : ' . $line);
            if ($log !== null) {
                $log($line);
            }
        };

        $from = $this->status()['installed'];
        $backup = null;
        if ($automaticBackup) {
            $backup = $this->backup();
            $say('sauvegarde de la base : ' . basename($backup));
        }

        $wasInMaintenance = $settings->getBool('advanced.maintenance', false);
        $settings->set('advanced.maintenance', true);
        $say('site public en maintenance');

        $migrations = 0;
        try {
            $core = $this->app->get(Migrator::class)->run();
            $migrations += count($core);
            $say(sprintf('migrations du noyau : %d', count($core)));

            foreach ($this->activePluginMigrationDirs() as $slug => $dir) {
                $result = $this->app->get(Migrator::class)->runForSource($slug, $dir);
                $migrations += count($result['executed']);
                $say(sprintf('migrations de %s : %d', $slug, count($result['executed'])));
            }

            if ($this->app->container()->has(PluginManager::class)) {
                $published = $this->app->get(PluginManager::class)->publishAssets();
                $say(sprintf('fichiers publics des plugins : %d', array_sum($published)));
            }
            if ($this->app->container()->has(ThemeManager::class)) {
                $published = $this->app->get(ThemeManager::class)->publishAssets();
                $say(sprintf('fichiers publics des thèmes : %d', array_sum($published)));
            }
            if ($this->app->container()->has(CacheManager::class)) {
                $this->app->get(CacheManager::class)->clearAll();
                $say('caches vidés');
            }

            $settings->set('prime_version', Application::VERSION);
            $settings->set(self::LAST_RUN, [
                'from' => $from,
                'to' => Application::VERSION,
                'at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'migrations' => $migrations,
                'backup' => $backup !== null ? basename($backup) : null,
            ], false);
        } catch (Throwable $exception) {
            $say('échec : ' . $exception->getMessage());
            throw new RuntimeException('La mise à jour s’est arrêtée : ' . $exception->getMessage(), 0, $exception);
        } finally {
            $settings->set('advanced.maintenance', $wasInMaintenance);
            $say($wasInMaintenance ? 'maintenance conservée (elle était active avant)' : 'fin de la maintenance');
        }

        $say(sprintf('version %s → %s', $from, Application::VERSION));

        return ['from' => $from, 'to' => Application::VERSION, 'migrations' => $migrations, 'backup' => $backup, 'steps' => $steps];
    }

    /**
     * `mysqldump` into `storage/backups/` (refused to the web by its own `.htaccess`). The password
     * goes through the environment, never on the command line.
     */
    public function backup(): string
    {
        $binary = $this->mysqldumpPath();
        if ($binary === null) {
            throw new RuntimeException('mysqldump est introuvable sur ce serveur : sauvegardez la base vous-même, puis confirmez-le.');
        }

        $dir = $this->app->storagePath('backups');
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Impossible de créer le dossier des sauvegardes.');
        }
        if (!is_file($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }

        $config = $this->app->config();
        $file = $dir . '/prime-' . gmdate('Ymd-His') . '.sql';
        $command = [
            $binary,
            '--single-transaction',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--host=' . (string) $config->get('database.host', '127.0.0.1'),
            '--port=' . (string) $config->get('database.port', 3306),
            '--user=' . (string) $config->get('database.username', ''),
            '--result-file=' . $file,
            (string) $config->get('database.database', ''),
        ];

        $env = getenv();
        $env['MYSQL_PWD'] = (string) $config->get('database.password', '');
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Impossible de lancer mysqldump.');
        }
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0 || !is_file($file) || filesize($file) === 0) {
            @unlink($file);
            throw new RuntimeException('La sauvegarde a échoué : ' . trim((string) $stderr));
        }
        chmod($file, 0640);

        return $file;
    }

    private function mysqldumpPath(): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            foreach (['mysqldump', 'mariadb-dump'] as $name) {
                $candidate = rtrim($dir, '/') . '/' . $name;
                if ($dir !== '' && is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string> slug => migrations directory
     */
    private function activePluginMigrationDirs(): array
    {
        if (!$this->app->container()->has(PluginManager::class)) {
            return [];
        }

        $dirs = [];
        foreach ($this->app->get(PluginManager::class)->discover(fresh: true) as $plugin) {
            $dir = $plugin->directory . DIRECTORY_SEPARATOR . 'migrations';
            if ($plugin->isActive() && is_dir($dir)) {
                $dirs[$plugin->slug] = $dir;
            }
        }

        return $dirs;
    }
}
