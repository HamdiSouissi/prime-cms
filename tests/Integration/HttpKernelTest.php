<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Router;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;
use Prime\Kernel\ErrorHandler;
use Prime\Kernel\ServiceProvider;
use Prime\Log\Logger;
use RuntimeException;

final class HttpKernelTest extends TestCase
{
    private string $tempLogDir;

    private string $pageCacheDir;

    protected function setUp(): void
    {
        $this->tempLogDir = sys_get_temp_dir() . '/prime_int_logs_' . uniqid('', true);
        $this->pageCacheDir = sys_get_temp_dir() . '/prime_int_pages_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempLogDir)) {
            $files = glob($this->tempLogDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    unlink($file);
                }
            }
            rmdir($this->tempLogDir);
        }

        if (is_dir($this->pageCacheDir)) {
            // `scandir` instead of a `GLOB_BRACE` glob: the brace flag is not portable
            // (php-wasm rejects it), and dotfiles must be removed too.
            $entries = scandir($this->pageCacheDir);
            foreach ($entries === false ? [] : $entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $this->pageCacheDir . '/' . $entry;
                if (is_dir($path)) {
                    @rmdir($path);
                } else {
                    @unlink($path);
                }
            }
            @rmdir($this->pageCacheDir);
        }
    }

    public function testHomeRouteHidesSystemDetailsInProductionMode(): void
    {
        $app = $this->createBootedApp(debug: false);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $response = $kernel->handle(Request::create('GET', '/'));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('<html lang="fr">', $response->body());
        $this->assertStringContainsString('Prime CMS', $response->body());
        $this->assertStringContainsString(Application::VERSION, $response->body());
        $this->assertStringContainsString('Le noyau est en place', $response->body());
        $this->assertStringContainsString('brand/logo-128.webp', $response->body());
        $this->assertStringContainsString('brand/logo-128.png', $response->body());
        $this->assertStringNotContainsString('État du système', $response->body());
        $this->assertStringNotContainsString(PHP_VERSION, $response->body());
        $this->assertStringNotContainsString('storage/logs', $response->body());
        $this->assertSecurityHeadersPresent($response);
    }

    public function testHomeRouteDisplaysSystemStatusCardWhenDebugIsEnabled(): void
    {
        $app = $this->createBootedApp(debug: true);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $response = $kernel->handle(Request::create('GET', '/'));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Prime CMS', $response->body());
        $this->assertStringContainsString('Le noyau est en place', $response->body());
        $this->assertStringContainsString('État du système', $response->body());
        $this->assertStringContainsString(PHP_VERSION, $response->body());
        $this->assertStringContainsString('storage/logs', $response->body());
        $this->assertSecurityHeadersPresent($response);
    }

    public function testHealthRouteOmitsPhpVersionInProductionMode(): void
    {
        $app = $this->createBootedApp(debug: false);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $response = $kernel->handle(Request::create('GET', '/health'));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('application/json', (string) $response->header('Content-Type'));
        $this->assertSecurityHeadersPresent($response);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('Prime CMS', $payload['app']);
        $this->assertSame(Application::VERSION, $payload['version']);
        $this->assertArrayNotHasKey('php', $payload);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', (string) $payload['time']);
    }

    public function testHealthRouteIncludesPhpVersionWhenDebugIsEnabled(): void
    {
        $app = $this->createBootedApp(debug: true);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $response = $kernel->handle(Request::create('GET', '/health'));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('application/json', (string) $response->header('Content-Type'));
        $this->assertSecurityHeadersPresent($response);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('Prime CMS', $payload['app']);
        $this->assertSame(Application::VERSION, $payload['version']);
        $this->assertSame(PHP_VERSION, $payload['php']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', (string) $payload['time']);
    }

    public function testUnknownRouteReturns404PageWithSecurityHeaders(): void
    {
        $app = $this->createBootedApp();
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $response = $kernel->handle(Request::create('GET', '/inconnu'));

        $this->assertSame(404, $response->status());
        $this->assertStringContainsString('Page introuvable', $response->body());
        $this->assertStringContainsString("Retourner à l'accueil", $response->body());
        $this->assertSecurityHeadersPresent($response);
    }

    public function testWrongMethodReturns405WithAllowHeaderAndSecurityHeaders(): void
    {
        $app = $this->createBootedApp();
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $response = $kernel->handle(Request::create('POST', '/health'));

        $this->assertSame(405, $response->status());
        $this->assertSame('GET', $response->header('Allow'));
        $this->assertSecurityHeadersPresent($response);
    }

    public function testTrailingSlashRedirects301WithSecurityHeaders(): void
    {
        $app = $this->createBootedApp();
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $response = $kernel->handle(Request::create('GET', '/health/'));

        $this->assertSame(301, $response->status());
        $this->assertSame('/health', $response->header('Location'));
        $this->assertSecurityHeadersPresent($response);
    }

    public function testUnhandledExceptionRendersDebugPageWhenDebugIsEnabled(): void
    {
        $app = $this->createBootedApp(debug: true);
        /** @var Router $router */
        $router = $app->get(Router::class);
        $router->get('/boom', static function (): Response {
            throw new RuntimeException('Secret debug failure marker');
        });

        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $response = $kernel->handle(Request::create('GET', '/boom'));

        $this->assertSame(500, $response->status());
        $this->assertStringContainsString('RuntimeException', $response->body());
        $this->assertStringContainsString('Secret debug failure marker', $response->body());
        $this->assertStringContainsString('Extrait du code source', $response->body());
        $this->assertSecurityHeadersPresent($response);
    }

    public function testUnhandledExceptionHidesDetailsInProductionAndLogsError(): void
    {
        $app = $this->createBootedApp(debug: false);
        /** @var Router $router */
        $router = $app->get(Router::class);
        $router->get('/boom', static function (): Response {
            throw new RuntimeException('Confidential internal detail');
        });

        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $response = $kernel->handle(Request::create('GET', '/boom'));

        $this->assertSame(500, $response->status());
        $this->assertStringContainsString('Une erreur est survenue', $response->body());
        $this->assertStringNotContainsString('Confidential internal detail', $response->body());
        $this->assertStringNotContainsString('RuntimeException', $response->body());
        $this->assertSecurityHeadersPresent($response);

        /** @var Logger $logger */
        $logger = $app->get(Logger::class);
        $this->assertFileExists($logger->logFilePath());
        $logContent = (string) file_get_contents($logger->logFilePath());
        $this->assertStringContainsString('Confidential internal detail', $logContent);
    }

    public function testJsonRequestReceivesStructuredJsonErrors(): void
    {
        $app = $this->createBootedApp(debug: false);
        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);

        $response = $kernel->handle(Request::create(
            'GET',
            '/missing-json',
            headers: ['Accept' => 'application/json'],
        ));

        $this->assertSame(404, $response->status());
        $this->assertStringContainsString('application/json', (string) $response->header('Content-Type'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(404, $payload['error']['code']);
        $this->assertSame('Page introuvable.', $payload['error']['message']);
    }

    public function testHttpResponseHookCanFilterOutgoingResponse(): void
    {
        $app = $this->createBootedApp();
        /** @var Hooks $hooks */
        $hooks = $app->get(Hooks::class);
        $hooks->filter('http.response', static fn (Response $res): Response => $res->withHeader('X-Prime-Filtered', 'yes'));

        /** @var Kernel $kernel */
        $kernel = $app->get(Kernel::class);
        $response = $kernel->handle(Request::create('GET', '/health'));

        $this->assertSame('yes', $response->header('X-Prime-Filtered'));
    }

    public function testErrorHandlerRegistersBeforeBootAndHandlesProviderBootFailureInProduction(): void
    {
        $earlyApp = new Application(dirname(__DIR__, 2), loadEnv: false, registerErrorHandler: true);
        /** @var ErrorHandler $earlyHandler */
        $earlyHandler = $earlyApp->get(ErrorHandler::class);

        try {
            $this->assertTrue($earlyHandler->isRegistered());
            $this->assertFalse($earlyApp->isBooted());

            $activeHandler = set_exception_handler(null);
            restore_exception_handler();
            $this->assertSame([$earlyHandler, 'handleUncaughtException'], $activeHandler);
        } finally {
            $earlyHandler->unregister();
        }

        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.debug', false);
        $app->config()->set('logging.path', $this->tempLogDir);

        $failingProvider = new class ($app) extends ServiceProvider {
            public function register(): void
            {
            }

            public function boot(): void
            {
                throw new RuntimeException('Provider boot production failure');
            }
        };

        $app->config()->set('app.providers', [$failingProvider::class]);

        $errorHandler = $app->registerErrorHandler();

        try {
            $caught = null;

            try {
                $app->boot();
            } catch (RuntimeException $e) {
                $caught = $e;
            }

            $this->assertInstanceOf(RuntimeException::class, $caught);
            $response = $errorHandler->handleException($caught, Request::create('GET', '/'));

            $this->assertSame(500, $response->status());
            $this->assertStringContainsString('Une erreur est survenue', $response->body());
            $this->assertStringNotContainsString('Provider boot production failure', $response->body());

            /** @var Logger $logger */
            $logger = $app->get(Logger::class);
            $this->assertFileExists($logger->logFilePath());
            $this->assertStringContainsString(
                'Provider boot production failure',
                (string) file_get_contents($logger->logFilePath()),
            );
        } finally {
            $errorHandler->unregister();
        }
    }

    public function testProviderBootFailureRendersDebugPageAndLogsWhenDebugIsEnabled(): void
    {
        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.debug', true);
        $app->config()->set('logging.path', $this->tempLogDir);

        $failingProvider = new class ($app) extends ServiceProvider {
            public function register(): void
            {
            }

            public function boot(): void
            {
                throw new RuntimeException('Provider boot debug failure');
            }
        };

        $app->config()->set('app.providers', [$failingProvider::class]);

        $errorHandler = $app->registerErrorHandler();

        try {
            $caught = null;

            try {
                $app->boot();
            } catch (RuntimeException $e) {
                $caught = $e;
            }

            $this->assertInstanceOf(RuntimeException::class, $caught);
            $response = $errorHandler->handleException($caught, Request::create('GET', '/'));

            $this->assertSame(500, $response->status());
            $this->assertStringContainsString('RuntimeException', $response->body());
            $this->assertStringContainsString('Provider boot debug failure', $response->body());
            $this->assertStringContainsString('Extrait du code source', $response->body());

            /** @var Logger $logger */
            $logger = $app->get(Logger::class);
            $this->assertFileExists($logger->logFilePath());
            $this->assertStringContainsString(
                'Provider boot debug failure',
                (string) file_get_contents($logger->logFilePath()),
            );
        } finally {
            $errorHandler->unregister();
        }
    }

    private function createBootedApp(bool $debug = false): Application
    {
        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.debug', $debug);
        $app->config()->set('logging.path', $this->tempLogDir);
        // Each booted app gets its own page cache directory: a production response cached by
        // one test must never be replayed to the debug app of the next one.
        $app->config()->set('cache.page.directory', $this->pageCacheDir);
        $app->boot();

        $app->container()->instance(InstallCheckerInterface::class, new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        });

        /** @var ErrorHandler $errorHandler */
        $errorHandler = $app->get(ErrorHandler::class);
        $errorHandler->setDebug($debug);

        return $app;
    }

    private function assertSecurityHeadersPresent(Response $response): void
    {
        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->header('Referrer-Policy'));
        $this->assertSame('SAMEORIGIN', $response->header('X-Frame-Options'));
        $this->assertStringContainsString("'self'", (string) $response->header('Content-Security-Policy'));
    }
}
