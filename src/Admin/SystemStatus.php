<?php

declare(strict_types=1);

namespace Prime\Admin;

use Prime\Cache\CacheManager;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Jobs\JobRepository;
use Prime\Kernel\Application;
use Prime\Mail\Mailer;
use Prime\Scheduler\SchedulerRunner;
use Throwable;

/**
 * « Outils › État du système » : environment checks and a copyable report.
 *
 * Every check is a plain array so a plugin can add its own through the `system.checks` filter:
 *
 *     $hooks->filter('system.checks', function (array $checks): array {
 *         $checks[] = ['id' => 'shop.gateway', 'group' => 'plugins', 'label' => 'Passerelle', 'status' => 'ok', 'value' => 'Stripe'];
 *         return $checks;
 *     });
 *
 * The report never carries a secret: no database password, application key, SMTP password or
 * token — only versions, switches and verdicts, so it can be pasted into a public support thread.
 */
final class SystemStatus
{
    public const STATUS_OK = 'ok';

    public const STATUS_WARNING = 'warning';

    public const STATUS_ERROR = 'error';

    public const STATUS_INFO = 'info';

    /**
     * Cron is considered stale past this delay without a scheduler pass.
     */
    public const HEARTBEAT_STALE_SECONDS = 600;

    private const REQUIRED_EXTENSIONS = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'openssl', 'fileinfo', 'ctype'];

    private const RECOMMENDED_EXTENSIONS = ['intl', 'gd', 'sodium', 'curl', 'zip', 'opcache', 'apcu'];

    public function __construct(
        private readonly Application $app,
        private readonly ?Connection $db = null,
        private readonly ?Hooks $hooks = null,
        private readonly ?SchedulerRunner $scheduler = null,
        private readonly ?JobRepository $jobs = null,
        private readonly ?Mailer $mailer = null,
        private readonly ?CacheManager $cache = null,
        private readonly ?\Prime\Plugins\PluginManager $plugins = null,
    ) {
    }

    /**
     * @return list<array{id: string, group: string, label: string, status: string, value: string, help: ?string}>
     */
    public function checks(?int $now = null): array
    {
        $now ??= time();
        $checks = [];

        // — Server —
        $checks[] = $this->check(
            'php.version',
            'server',
            t('admin.system.checks.php_version'),
            version_compare(PHP_VERSION, '8.2.0', '>=') ? self::STATUS_OK : self::STATUS_ERROR,
            PHP_VERSION,
            version_compare(PHP_VERSION, '8.2.0', '>=') ? null : t('admin.system.help.php_version'),
        );

        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn (string $ext): bool => !extension_loaded($ext)));
        $checks[] = $this->check(
            'php.extensions',
            'server',
            t('admin.system.checks.required_extensions'),
            $missing === [] ? self::STATUS_OK : self::STATUS_ERROR,
            $missing === [] ? t('admin.system.values.all_present') : implode(', ', $missing),
            $missing === [] ? null : t('admin.system.help.required_extensions'),
        );

        $recommendedMissing = array_values(array_filter(
            self::RECOMMENDED_EXTENSIONS,
            static fn (string $ext): bool => !extension_loaded($ext) && !($ext === 'opcache' && extension_loaded('Zend OPcache')),
        ));
        $checks[] = $this->check(
            'php.recommended',
            'server',
            t('admin.system.checks.recommended_extensions'),
            $recommendedMissing === [] ? self::STATUS_OK : self::STATUS_WARNING,
            $recommendedMissing === [] ? t('admin.system.values.all_present') : implode(', ', $recommendedMissing),
            $recommendedMissing === [] ? null : t('admin.system.help.recommended_extensions'),
        );

        $checks[] = $this->check('php.memory_limit', 'server', t('admin.system.checks.memory_limit'), self::STATUS_INFO, (string) ini_get('memory_limit'));
        $checks[] = $this->check('php.upload_max_filesize', 'server', t('admin.system.checks.upload_max'), self::STATUS_INFO, (string) ini_get('upload_max_filesize') . ' / ' . (string) ini_get('post_max_size'));
        $checks[] = $this->check('php.max_execution_time', 'server', t('admin.system.checks.max_execution_time'), self::STATUS_INFO, (string) ini_get('max_execution_time') . ' s');

        // — Database —
        $checks[] = $this->databaseCheck();

        // — Files —
        foreach (['storage' => $this->app->storagePath(), 'storage/cache' => $this->app->storagePath('cache'), 'storage/logs' => $this->app->storagePath('logs'), 'content/uploads' => $this->app->basePath('content/uploads')] as $label => $path) {
            $writable = is_dir($path) && is_writable($path);
            $checks[] = $this->check(
                'files.' . str_replace('/', '_', $label),
                'files',
                t('admin.system.checks.writable', ['path' => $label]),
                $writable ? self::STATUS_OK : self::STATUS_ERROR,
                $writable ? t('admin.system.values.writable') : t('admin.system.values.not_writable'),
                $writable ? null : t('admin.system.help.writable', ['path' => $label]),
            );
        }

        $free = @disk_free_space($this->app->storagePath());
        if (is_float($free)) {
            $checks[] = $this->check(
                'files.disk_free',
                'files',
                t('admin.system.checks.disk_free'),
                $free < 200 * 1024 * 1024 ? self::STATUS_WARNING : self::STATUS_OK,
                \Prime\I18n\LocaleFormatter::fallback()->bytes((int) $free),
                $free < 200 * 1024 * 1024 ? t('admin.system.help.disk_free') : null,
            );
        }

        // — Application —
        $production = $this->app->environment() === 'production';
        $checks[] = $this->check(
            'app.debug',
            'app',
            t('admin.system.checks.debug'),
            $production && $this->app->isDebug() ? self::STATUS_ERROR : ($this->app->isDebug() ? self::STATUS_WARNING : self::STATUS_OK),
            ($this->app->isDebug() ? t('admin.system.values.on') : t('admin.system.values.off')) . ' (' . $this->app->environment() . ')',
            $this->app->isDebug() ? t('admin.system.help.debug') : null,
        );

        $key = (string) $this->app->config()->get('app.key', '');
        $checks[] = $this->check(
            'app.key',
            'app',
            t('admin.system.checks.app_key'),
            $key !== '' ? self::STATUS_OK : self::STATUS_ERROR,
            $key !== '' ? t('admin.system.values.defined') : t('admin.system.values.missing'),
            $key !== '' ? null : t('admin.system.help.app_key'),
        );

        $url = (string) $this->app->config()->get('app.url', '');
        $https = str_starts_with($url, 'https://');
        $checks[] = $this->check(
            'app.https',
            'app',
            t('admin.system.checks.https'),
            $https ? self::STATUS_OK : ($production ? self::STATUS_ERROR : self::STATUS_WARNING),
            $url !== '' ? $url : '—',
            $https ? null : t('admin.system.help.https'),
        );

        if ($this->cache !== null) {
            $checks[] = $this->check('app.cache', 'app', t('admin.system.checks.cache'), self::STATUS_INFO, $this->cache->driverName());
        }

        // — Background work —
        $checks[] = $this->schedulerCheck($now);

        if ($this->jobs !== null) {
            try {
                $stats = $this->jobs->stats($now);
                $checks[] = $this->check(
                    'queue.failed',
                    'background',
                    t('admin.system.checks.queue'),
                    $stats['failed'] > 0 ? self::STATUS_WARNING : self::STATUS_OK,
                    t('admin.system.values.queue', ['pending' => $stats['pending'], 'failed' => $stats['failed']]),
                    $stats['failed'] > 0 ? t('admin.system.help.queue') : null,
                );
            } catch (Throwable) {
                $checks[] = $this->check('queue.failed', 'background', t('admin.system.checks.queue'), self::STATUS_ERROR, t('admin.system.values.unavailable'));
            }
        }

        if ($this->mailer !== null) {
            try {
                $diagnosis = $this->mailer->diagnose();
                $checks[] = $this->check(
                    'mail.transport',
                    'background',
                    t('admin.system.checks.mail'),
                    $diagnosis['configured'] ? ($diagnosis['driver'] === 'log' ? self::STATUS_WARNING : self::STATUS_OK) : self::STATUS_ERROR,
                    $diagnosis['transport'],
                    $diagnosis['problem'] ?? ($diagnosis['driver'] === 'log' ? t('admin.system.help.mail_log') : null),
                );
            } catch (Throwable) {
                $checks[] = $this->check('mail.transport', 'background', t('admin.system.checks.mail'), self::STATUS_ERROR, t('admin.system.values.unavailable'));
            }
        }

        if ($this->cache !== null) {
            $pageCache = $this->cache->pages()->isEnabled();
            $checks[] = $this->check(
                'cache.pages',
                'app',
                t('admin.system.checks.page_cache'),
                $pageCache ? self::STATUS_OK : self::STATUS_WARNING,
                $pageCache ? t('admin.system.values.on') : t('admin.system.values.off'),
                $pageCache ? null : t('admin.system.help.page_cache'),
            );
        }

        if ($this->plugins !== null) {
            try {
                $errored = [];
                foreach ($this->plugins->discover() as $info) {
                    if ($info->isErrored()) {
                        $errored[] = $info->name();
                    }
                }
                $checks[] = $this->check(
                    'plugins.errored',
                    'plugins',
                    t('admin.system.checks.plugins'),
                    $errored === [] ? self::STATUS_OK : self::STATUS_ERROR,
                    $errored === [] ? t('admin.system.values.none_errored') : implode(', ', $errored),
                    $errored === [] ? null : t('admin.system.help.plugins'),
                );
            } catch (Throwable) {
                // Plugin tables missing (site mid-install): nothing meaningful to report.
            }
        }

        if ($this->hooks !== null) {
            $filtered = $this->hooks->apply('system.checks', $checks, $this);
            if (is_array($filtered)) {
                $checks = $this->normalize($filtered);
            }
        }

        return $checks;
    }

    /**
     * Installation directory, for the cron line shown on the scheduler screen.
     */
    public function basePath(): string
    {
        return $this->app->basePath();
    }

    /**
     * Worst status across the checks (error > warning > ok).
     *
     * @param list<array{status: string}> $checks
     */
    public static function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        if (in_array(self::STATUS_ERROR, $statuses, true)) {
            return self::STATUS_ERROR;
        }

        return in_array(self::STATUS_WARNING, $statuses, true) ? self::STATUS_WARNING : self::STATUS_OK;
    }

    /**
     * Plain-text report, safe to paste in a support request.
     *
     * @param list<array{id: string, group: string, label: string, status: string, value: string, help: ?string}>|null $checks
     */
    public function report(?array $checks = null): string
    {
        $checks ??= $this->checks();
        $lines = [
            '### Prime CMS — ' . t('admin.system.report_title'),
            'Prime CMS ' . Application::VERSION . ' · PHP ' . PHP_VERSION . ' · ' . PHP_OS_FAMILY . ' · ' . gmdate('Y-m-d H:i') . ' UTC',
            '',
        ];

        $group = null;
        foreach ($checks as $check) {
            if ($check['group'] !== $group) {
                $group = $check['group'];
                $title = t('admin.system.groups.' . $group);
                $lines[] = '## ' . ($title !== 'admin.system.groups.' . $group ? $title : ucfirst($group));
            }

            $lines[] = sprintf('- [%s] %s : %s', strtoupper($check['status']), $check['label'], self::scrub($check['value']));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Last line of defence against a plugin check leaking credentials into the report.
     */
    public static function scrub(string $value): string
    {
        $value = (string) preg_replace('#(://[^:/\s]+:)[^@\s]+@#', '$1***@', $value);

        return (string) preg_replace('/((?:password|passwd|secret|token|key)\s*[=:]\s*)\S+/i', '$1***', $value);
    }

    /**
     * @return array{id: string, group: string, label: string, status: string, value: string, help: ?string}
     */
    private function databaseCheck(): array
    {
        if ($this->db === null) {
            return $this->check('db.server', 'database', t('admin.system.checks.database'), self::STATUS_ERROR, t('admin.system.values.unavailable'));
        }

        try {
            $row = $this->db->selectOne('SELECT VERSION() AS v');
            $version = is_array($row) ? (string) ($row['v'] ?? '') : '';
        } catch (Throwable) {
            try {
                $row = $this->db->selectOne('SELECT sqlite_version() AS v');
                $version = is_array($row) ? 'SQLite ' . (string) ($row['v'] ?? '') : '';
            } catch (Throwable) {
                return $this->check('db.server', 'database', t('admin.system.checks.database'), self::STATUS_ERROR, t('admin.system.values.unavailable'), t('admin.system.help.database'));
            }
        }

        $isMaria = stripos($version, 'mariadb') !== false;
        $numeric = (string) preg_replace('/[^0-9.].*$/', '', $version);
        $status = self::STATUS_OK;
        if ($numeric !== '' && !str_starts_with($version, 'SQLite')) {
            $minimum = $isMaria ? '10.6' : '8.0';
            $status = version_compare($numeric, $minimum, '>=') ? self::STATUS_OK : self::STATUS_WARNING;
        }

        return $this->check(
            'db.server',
            'database',
            t('admin.system.checks.database'),
            $status,
            ($isMaria ? 'MariaDB ' : (str_starts_with($version, 'SQLite') ? '' : 'MySQL ')) . $version,
            $status === self::STATUS_OK ? null : t('admin.system.help.database_version'),
        );
    }

    /**
     * @return array{id: string, group: string, label: string, status: string, value: string, help: ?string}
     */
    private function schedulerCheck(int $now): array
    {
        $heartbeat = $this->scheduler?->lastHeartbeat();

        if ($heartbeat === null) {
            return $this->check(
                'scheduler.heartbeat',
                'background',
                t('admin.system.checks.scheduler'),
                self::STATUS_WARNING,
                t('admin.system.values.never'),
                t('admin.system.help.scheduler'),
            );
        }

        $age = max(0, $now - $heartbeat);

        return $this->check(
            'scheduler.heartbeat',
            'background',
            t('admin.system.checks.scheduler'),
            $age > self::HEARTBEAT_STALE_SECONDS ? self::STATUS_WARNING : self::STATUS_OK,
            \Prime\I18n\LocaleFormatter::fallback()->relative($heartbeat, $now),
            $age > self::HEARTBEAT_STALE_SECONDS ? t('admin.system.help.scheduler') : null,
        );
    }

    /**
     * @return array{id: string, group: string, label: string, status: string, value: string, help: ?string}
     */
    private function check(string $id, string $group, string $label, string $status, string $value, ?string $help = null): array
    {
        return ['id' => $id, 'group' => $group, 'label' => $label, 'status' => $status, 'value' => $value, 'help' => $help];
    }

    /**
     * Keep only well-formed checks coming back from the filter.
     *
     * @param array<array-key, mixed> $checks
     *
     * @return list<array{id: string, group: string, label: string, status: string, value: string, help: ?string}>
     */
    private function normalize(array $checks): array
    {
        $clean = [];
        $allowed = [self::STATUS_OK, self::STATUS_WARNING, self::STATUS_ERROR, self::STATUS_INFO];

        foreach ($checks as $check) {
            if (!is_array($check) || !is_string($check['id'] ?? null) || !is_string($check['label'] ?? null)) {
                continue;
            }

            $status = is_string($check['status'] ?? null) && in_array($check['status'], $allowed, true) ? $check['status'] : self::STATUS_INFO;
            $group = is_string($check['group'] ?? null) && preg_match('/^[a-z0-9_-]{1,32}$/', $check['group']) === 1 ? $check['group'] : 'plugins';
            $clean[] = $this->check(
                $check['id'],
                $group,
                $check['label'],
                $status,
                is_scalar($check['value'] ?? null) ? (string) $check['value'] : '',
                is_string($check['help'] ?? null) ? $check['help'] : null,
            );
        }

        return $clean;
    }
}
