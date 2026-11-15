<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Prime\Http\Exception\NotFoundException;
use Prime\Http\Middleware\RedirectIfNotInstalled;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Session;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;

final class RedirectIfNotInstalledTest extends TestCase
{
    public function testRedirectsAppRoutesToInstallWhenNotInstalledWhileAllowingHealthInstallAndPublicAssets(): void
    {
        $app = new Application(dirname(__DIR__, 3), loadEnv: false);
        $session = new Session(sys_get_temp_dir(), useNativeSession: false);
        $checker = new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return false;
            }
        };

        $middleware = new RedirectIfNotInstalled($checker, $app, $session);
        $next = static fn (): Response => Response::text('passed');

        // Home and admin redirect to /install
        $home = $middleware->process(Request::create('GET', '/'), $next);
        $this->assertSame(302, $home->status());
        $this->assertSame('/install', $home->header('Location'));

        $admin = $middleware->process(Request::create('GET', '/admin'), $next);
        $this->assertSame(302, $admin->status());
        $this->assertSame('/install', $admin->header('Location'));

        // /install*, /health, and static files under public/ pass through
        $this->assertSame(200, $middleware->process(Request::create('GET', '/install'), $next)->status());
        $this->assertSame(200, $middleware->process(Request::create('GET', '/install/database'), $next)->status());
        $this->assertSame(200, $middleware->process(Request::create('GET', '/health'), $next)->status());
        $this->assertSame(200, $middleware->process(Request::create('GET', '/assets/admin/css/admin.css'), $next)->status());

        // Never treat public/index.php as a pass-through static asset
        $indexPhp = $middleware->process(Request::create('GET', '/index.php'), $next);
        $this->assertSame(302, $indexPhp->status());
    }

    public function testReturns404ForInstallRoutesOnceInstalledExceptOneTimeDoneConfirmation(): void
    {
        $app = new Application(dirname(__DIR__, 3), loadEnv: false);
        $session = new Session(sys_get_temp_dir(), useNativeSession: false);
        $checker = new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        };

        $middleware = new RedirectIfNotInstalled($checker, $app, $session);
        $next = static fn (): Response => Response::text('ok');

        // Normal site routes pass through
        $this->assertSame(200, $middleware->process(Request::create('GET', '/'), $next)->status());

        // One-time /install/done flash allows GET /install/done once
        $session->flash(RedirectIfNotInstalled::COMPLETION_FLASH_KEY, ['site_name' => 'Demo']);
        $this->assertSame(200, $middleware->process(Request::create('GET', '/install/done'), $next)->status());
        $session->getFlash(RedirectIfNotInstalled::COMPLETION_FLASH_KEY);

        // Without flash, /install/done returns 404
        $thrown = false;
        try {
            $middleware->process(Request::create('GET', '/install/done'), $next);
        } catch (NotFoundException) {
            $thrown = true;
        }
        $this->assertTrue($thrown);

        // All other /install* routes throw 404 immediately
        foreach (['/install', '/install/requirements', '/install/database', '/install/run'] as $path) {
            $caught = false;
            try {
                $middleware->process(Request::create('GET', $path), $next);
            } catch (NotFoundException) {
                $caught = true;
            }
            $this->assertTrue($caught, sprintf('Expected 404 for %s after installation.', $path));
        }
    }
}
