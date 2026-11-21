<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;
use Prime\Settings\Settings;
use Prime\Update\Updater;
use RuntimeException;

/**
 * `bin/prime update` on a real database left at version 0.9 (migrations of the REST API and of
 * phase 12 not yet applied): the update applies them, records 1.x, and leaves maintenance as it was.
 */
#[Group('database')]
final class UpdaterDatabaseTest extends DatabaseTestCase
{
    public function testA09DatabaseIsBroughtToTheCurrentVersion(): void
    {
        // A 0.9 database: only the migrations up to the jobs table (000025) have run.
        $old = sys_get_temp_dir() . '/prime_mig09_' . bin2hex(random_bytes(4));
        mkdir($old);
        $newer = [];
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            if (strcmp(substr($name, 0, 17), '2026_11_15_000025') <= 0) {
                copy($file, $old . '/' . basename($file));
            } else {
                $newer[] = $name;
            }
        }
        (new Migrator($this->connection, new Schema($this->connection), [$old]))->run();
        array_map('unlink', glob($old . '/*.php') ?: []);
        rmdir($old);
        $this->assertNotSame([], $newer);
        $this->assertFalse((new Schema($this->connection))->hasTable('api_tokens'));

        $app = $this->app();
        $settings = $app->get(Settings::class);
        $settings->set('prime_version', '0.9.0');
        $settings->set('advanced.maintenance', false);
        $updater = new Updater($app);

        $before = $updater->status();
        $this->assertSame('0.9.0', $before['installed']);
        $this->assertSame(array_values($newer), array_column(array_filter($before['pending'], static fn (array $row): bool => $row['source'] === 'core'), 'migration'));

        try {
            $updater->run(backupConfirmed: false);
            $this->fail('une mise à jour sans sauvegarde confirmée doit être refusée');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Sauvegardez', $exception->getMessage());
        }
        $this->assertSame('0.9.0', $settings->get('prime_version'));

        $result = $updater->run(backupConfirmed: true);

        $this->assertSame('0.9.0', $result['from']);
        $this->assertSame(Application::VERSION, $result['to']);
        $this->assertGreaterThanOrEqual(count($newer), $result['migrations']);
        $this->assertTrue((new Schema($this->connection))->hasTable('api_tokens'));
        $this->assertSame([], array_filter($updater->status()['pending'], static fn (array $row): bool => $row['source'] === 'core'));
        $this->assertSame(Application::VERSION, $settings->get('prime_version'));
        $this->assertFalse($settings->getBool('advanced.maintenance', true), 'la maintenance revient à son état précédent');
        $this->assertSame('0.9.0', $settings->get(Updater::LAST_RUN)['from'] ?? null);
    }

    private function app(): Application
    {
        $app = new Application(dirname(__DIR__, 2), loadEnv: false, registerErrorHandler: false);
        $app->config()->set('app.env', 'testing');
        $app->config()->set('app.key', 'base64:' . base64_encode(str_repeat('u', 32)));
        $app->config()->set('cache.page.directory', sys_get_temp_dir() . '/prime_update_pages_' . uniqid('', true));
        $app->boot();
        $app->container()->instance(InstallCheckerInterface::class, new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        });
        $app->container()->instance(Connection::class, $this->connection);

        return $app;
    }
}
