<?php

declare(strict_types=1);

namespace Prime\Install;

use Prime\Kernel\Application;
use Prime\Settings\Settings;
use Throwable;

/**
 * Verifies installation state: BOTH `storage/installed.lock` AND the
 * `installed_at` database setting must be present.
 */
final class InstallChecker implements InstallCheckerInterface
{
    public const LOCK_FILENAME = 'installed.lock';

    public function __construct(
        private readonly Application $app,
        private readonly Settings $settings,
    ) {
    }

    public function lockFilePath(): string
    {
        return $this->app->storagePath(self::LOCK_FILENAME);
    }

    public function isInstalled(): bool
    {
        if (!is_file($this->lockFilePath())) {
            return false;
        }

        try {
            $installedAt = $this->settings->get('installed_at');

            return is_string($installedAt) && trim($installedAt) !== '';
        } catch (Throwable) {
            return false;
        }
    }
}
