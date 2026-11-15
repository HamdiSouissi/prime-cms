<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Middleware\SecurityHeaders;
use Prime\Http\Middleware\TrailingSlash;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Router;
use Prime\Kernel\Container;
use Prime\Kernel\ErrorHandler;
use Prime\Log\Logger;
use Prime\View\View;

final class MiddlewarePipelineTest extends TestCase
{
    public function testExecutesGlobalAndRouteMiddlewaresInOrder(): void
    {
        $trace = [];

        $makeRecorder = static function (string $name) use (&$trace): MiddlewareInterface {
            return new class ($name, $trace) implements MiddlewareInterface {
                /**
                 * @param list<string> $trace
                 */
                public function __construct(
                    private readonly string $name,
                    private array &$trace,
                ) {
                }

                public function process(Request $request, callable $next): Response
                {
                    $this->trace[] = $this->name . ':before';
                    $response = $next($request);
                    $this->trace[] = $this->name . ':after';

                    return $response->withHeader('X-Trace-' . $this->name, 'ok');
                }
            };
        };

        $router = new Router();
        $router->get('/pipeline', static function () use (&$trace): Response {
            $trace[] = 'handler';

            return Response::text('done');
        })->middleware($makeRecorder('route'));

        $hooks = new Hooks();
        $container = new Container();
        $logger = new Logger(sys_get_temp_dir());
        $view = new View(dirname(__DIR__, 3) . '/resources/views', $hooks);
        $errorHandler = new ErrorHandler($logger, $view, false);

        $kernel = new Kernel(
            $router,
            $container,
            $hooks,
            $errorHandler,
            [$makeRecorder('global1'), $makeRecorder('global2')],
        );

        $response = $kernel->handle(Request::create('GET', '/pipeline'));

        $this->assertSame(200, $response->status());
        $this->assertSame('done', $response->body());
        $this->assertSame([
            'global1:before',
            'global2:before',
            'route:before',
            'handler',
            'route:after',
            'global2:after',
            'global1:after',
        ], $trace);
        $this->assertSame('ok', $response->header('X-Trace-global1'));
        $this->assertSame('ok', $response->header('X-Trace-route'));
    }

    public function testTrailingSlashMiddlewareRedirectsNonRootPathsAndPreservesQuery(): void
    {
        $middleware = new TrailingSlash();
        $next = static fn (Request $req): Response => Response::text($req->path());

        $rootResponse = $middleware->process(Request::create('GET', '/'), $next);
        $this->assertSame(200, $rootResponse->status());

        $redirectResponse = $middleware->process(Request::create('GET', '/blog/?page=2'), $next);
        $this->assertSame(301, $redirectResponse->status());
        $this->assertSame('/blog?page=2', $redirectResponse->header('Location'));
    }

    public function testTrailingSlashMiddlewareNeverEmitsProtocolRelativeOpenRedirect(): void
    {
        $middleware = new TrailingSlash();
        $next = static fn (Request $req): Response => Response::text($req->path());

        $doubleSlashTarget = $middleware->process(Request::create('GET', '//evil.com/'), $next);
        $this->assertSame(301, $doubleSlashTarget->status());
        $this->assertSame('/evil.com', $doubleSlashTarget->header('Location'));
        $this->assertStringStartsNotWith('//', (string) $doubleSlashTarget->header('Location'));

        $tripleSlashWithQuery = $middleware->process(Request::create('GET', '///evil.com/path/?next=1'), $next);
        $this->assertSame(301, $tripleSlashWithQuery->status());
        $this->assertSame('/evil.com/path?next=1', $tripleSlashWithQuery->header('Location'));
        $this->assertStringStartsNotWith('//', (string) $tripleSlashWithQuery->header('Location'));

        $onlySlashes = $middleware->process(Request::create('GET', '//'), $next);
        $this->assertSame(200, $onlySlashes->status());
        $this->assertSame('/', $onlySlashes->body());
    }

    public function testSecurityHeadersMiddlewareAddsAllRequiredHeaders(): void
    {
        $middleware = new SecurityHeaders();
        $response = $middleware->process(
            Request::create('GET', '/'),
            static fn (): Response => Response::html('<p>ok</p>'),
        );

        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->header('Referrer-Policy'));
        $this->assertSame('SAMEORIGIN', $response->header('X-Frame-Options'));
        $this->assertStringContainsString("default-src 'self'", (string) $response->header('Content-Security-Policy'));
    }
}
