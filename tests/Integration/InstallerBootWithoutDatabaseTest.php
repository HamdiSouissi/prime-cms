<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Kernel\Application;

/**
 * Regression tests for the installer returning HTTP 500 on a fresh checkout.
 *
 * Before installation there is no `.env` (hence no `APP_KEY`) and no database: any service
 * resolved on the way to `/install` must therefore survive without both. The historical bug
 * was {@see \Prime\I18n\Http\LocaleMiddleware} resolving the authentication guard, which eagerly
 * builds the 2FA encrypter (fails without a key) and the rate limiter, turning every public
 * page into a 500. These tests pin the "boots without a key and without a database" contract.
 */
final class InstallerBootWithoutDatabaseTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempWorkspaces = [];

    protected function tearDown(): void
    {
        foreach ($this->tempWorkspaces as $dir) {
            $this->removeWorkspace($dir);
        }
        $this->tempWorkspaces = [];
    }

    public function testWelcomeWizardRendersWithoutDatabaseConnectionOrApplicationKey(): void
    {
        $app = $this->bootFreshWorkspaceApp();
        $kernel = $app->get(Kernel::class);

        $response = $kernel->handle(Request::create('GET', '/install'));

        $this->assertSame(200, $response->status(), 'The installer must render before `.env` and the database exist.');
        $this->assertStringContainsString('Bienvenue dans Prime CMS', $response->body());
    }

    public function testHealthEndpointAnswersWithoutDatabaseConnectionOrApplicationKey(): void
    {
        $app = $this->bootFreshWorkspaceApp();
        $kernel = $app->get(Kernel::class);

        $response = $kernel->handle(Request::create('GET', '/health'));

        $this->assertSame(200, $response->status(), 'The healthcheck must never depend on `.env` or the database.');
        $this->assertStringContainsString('"status":"ok"', $response->body());
    }

    /**
     * Boot an application whose database points to a closed port and whose `APP_KEY` is empty.
     */
    private function bootFreshWorkspaceApp(): Application
    {
        $workspace = $this->createIsolatedWorkspace();

        $app = new Application($workspace, loadEnv: false);
        $app->config()->set('app.debug', false);
        $app->config()->set('app.key', '');
        $app->config()->set('logging.path', $workspace . '/storage/logs');
        // Port 1 is closed: any attempt to touch the database fails immediately instead of
        // silently succeeding against a developer's local server.
        $app->config()->set('database.host', '127.0.0.1');
        $app->config()->set('database.port', 1);
        $app->config()->set('database.database', 'prime_unreachable');
        $app->config()->set('database.username', 'nobody');
        $app->config()->set('database.password', '');
        $app->config()->set('cache.page.directory', $workspace . '/storage/cache/pages');
        $app->boot();

        return $app;
    }

    private function createIsolatedWorkspace(): string
    {
        $repoRoot = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir() . '/prime_install_boot_' . uniqid('', true);

        foreach (['storage/cache', 'storage/logs', 'storage/sessions', 'storage/tmp', 'content/uploads'] as $sub) {
            mkdir($dir . '/' . $sub, 0755, true);
        }

        foreach (['content/themes', 'content/plugins', 'config', 'database', 'resources', 'public'] as $link) {
            symlink($repoRoot . '/' . $link, $dir . '/' . $link);
        }

        $this->tempWorkspaces[] = $dir;

        return $dir;
    }

    private function removeWorkspace(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                @unlink($path);
                continue;
            }

            $children = scandir($path) ?: [];
            foreach ($children as $child) {
                if ($child !== '.' && $child !== '..') {
                    @unlink($path . '/' . $child);
                }
            }
            @rmdir($path);
        }

        @rmdir($dir);
    }
}
