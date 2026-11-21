<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Kernel;

use PHPUnit\Framework\TestCase;
use Prime\Hooks\Hooks;
use Prime\Kernel\Application;
use Prime\Kernel\Config;
use Prime\Kernel\Env;
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

        $this->assertSame('1.0.0', Application::VERSION);
        $this->assertTrue($app->isBooted());
        $this->assertSame(['registered:1.0.0', 'booted:yes'], $events);
        $this->assertSame($this->rootDir . '/config', $app->configPath());
        $envStorage = Env::get('PRIME_STORAGE_PATH');
        $expectedStorageRoot = is_string($envStorage) && trim($envStorage) !== ''
            ? rtrim(trim($envStorage), DIRECTORY_SEPARATOR . '/')
            : $this->rootDir . '/storage';
        $this->assertSame($expectedStorageRoot . '/logs', $app->storagePath('logs'));
        $this->assertSame($this->rootDir . '/content/plugins', $app->contentPath('plugins'));
        $this->assertSame($this->rootDir . '/public/assets', $app->publicPath('assets'));
        $this->assertSame($this->rootDir . '/resources/views', $app->resourcesPath('views'));
    }

    public function testTestingBootstrapIsolatesDotEnvAndLocalStorage(): void
    {
        $this->assertSame('testing', Env::get('APP_ENV'));
        $this->assertTrue((bool) Env::get('PRIME_DISABLE_DOTENV'));

        $tmpRoot = sys_get_temp_dir() . '/prime_env_isolation_' . bin2hex(random_bytes(4));
        mkdir($tmpRoot . '/config', 0755, true);
        file_put_contents($tmpRoot . '/config/app.php', "<?php\ndeclare(strict_types=1);\nreturn ['name' => (string) \\Prime\\Kernel\\Env::get('APP_NAME', 'Prime CMS')];\n");
        file_put_contents($tmpRoot . '/.env', "APP_NAME=\"Polluted Local Site\"\nPRIME_ISOLATION_PROBE=leaked\n");

        try {
            $app = new Application($tmpRoot, loadEnv: true);
            $this->assertSame('Prime CMS', $app->config()->get('app.name'));
            $this->assertNull(Env::get('PRIME_ISOLATION_PROBE'));
            $this->assertSame($tmpRoot . '/storage/logs', $app->storagePath('logs'));
        } finally {
            @unlink($tmpRoot . '/.env');
            @unlink($tmpRoot . '/config/app.php');
            @rmdir($tmpRoot . '/config');
            @rmdir($tmpRoot);
        }
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
