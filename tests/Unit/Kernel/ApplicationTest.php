<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Kernel;

use PHPUnit\Framework\TestCase;
use Prime\Hooks\Hooks;
use Prime\Kernel\Application;
use Prime\Kernel\Config;
use Prime\Log\Logger;

final class ApplicationTest extends TestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 3);
    }

    public function testApplicationLifecycleEmitsHooksAndResolvesPaths(): void
    {
        $app = new Application($this->rootDir, loadEnv: false);
        $hooks = new Hooks();
        $events = [];

        $hooks->on('app.registered', static function (Application $a) use (&$events): void {
            $events[] = 'registered:' . $a->version();
        });
        $hooks->on('app.booted', static function (Application $a) use (&$events): void {
            $events[] = 'booted:' . ($a->isBooted() ? 'yes' : 'no');
        });

        // Pre-bind custom Hooks before boot so we can inspect lifecycle events
        $app->config()->set('app.providers', []);
        $app->container()->instance(Hooks::class, $hooks);
        $app->boot();

        $this->assertSame('0.1.0', Application::VERSION);
        $this->assertTrue($app->isBooted());
        $this->assertSame(['registered:0.1.0', 'booted:yes'], $events);
        $this->assertSame($this->rootDir . '/config', $app->configPath());
        $this->assertSame($this->rootDir . '/storage/logs', $app->storagePath('logs'));
        $this->assertSame($this->rootDir . '/content/plugins', $app->contentPath('plugins'));
        $this->assertSame($this->rootDir . '/public/assets', $app->publicPath('assets'));
        $this->assertSame($this->rootDir . '/resources/views', $app->resourcesPath('views'));
    }

    public function testGlobalHelpersFacadeContainerServices(): void
    {
        $app = new Application($this->rootDir, loadEnv: false);
        $app->boot();

        $this->assertSame($app, app());
        $this->assertInstanceOf(Config::class, config());
        $this->assertSame('Prime CMS', config('app.name'));
        $this->assertInstanceOf(Logger::class, logger());
        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'));
        $this->assertStringEndsWith('/health', url('health'));
        $this->assertStringContainsString('/assets/brand/logo-128.png?v=', asset('brand/logo-128.png'));
    }
}
