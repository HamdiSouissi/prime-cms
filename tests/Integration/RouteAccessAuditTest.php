<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Api\ApiRoute;
use Prime\Api\ApiRouteRegistry;
use Prime\Auth\Auth;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Route;
use Prime\Http\Router;
use Prime\Http\Session;
use Prime\Kernel\Application;
use Prime\Security\Csrf;
use Prime\Tests\Support\ApiTestFixture;

/**
 * Security audit (phase 12, L2): every route of the admin and of the API, called by an
 * anonymous visitor and by a contributor, is refused unless the caller holds the permission
 * the route declares. A new route without a declared permission makes this test fail until it
 * is added, knowingly, to one of the two short lists below.
 */
final class RouteAccessAuditTest extends TestCase
{
    use ApiTestFixture;

    /** Admin routes reachable without an account (sign-in and password recovery). */
    private const PUBLIC_ADMIN = [
        'GET /admin/login', 'POST /admin/login',
        'GET /admin/login/two-factor', 'POST /admin/login/two-factor',
        'POST /admin/logout',
        'GET /admin/forgot-password', 'POST /admin/forgot-password',
        'GET /admin/reset-password/{token:[a-f0-9]+}', 'POST /admin/reset-password/{token:[a-f0-9]+}',
    ];

    /** Admin routes any signed-in account may use: only its own profile, tokens and notices. */
    private const OWN_ACCOUNT_PREFIXES = ['/admin/profile', '/admin/notices/'];

    public function testEveryAdminRouteDeclaresWhoMayUseIt(): void
    {
        $undeclared = [];
        foreach ($this->adminRoutes($this->createApp(null)) as $key => $route) {
            if (in_array($key, self::PUBLIC_ADMIN, true) || self::isOwnAccount($route) || self::capabilities($route) !== []) {
                continue;
            }
            $undeclared[] = $key;
        }

        $this->assertSame([], $undeclared, 'routes d’administration sans permission déclarée');
    }

    public function testAnonymousVisitorsAreSentToTheLoginPage(): void
    {
        $app = $this->createApp(null);
        $leaks = [];

        foreach ($this->adminRoutes($app) as $key => $route) {
            if (in_array($key, self::PUBLIC_ADMIN, true)) {
                continue;
            }
            $response = $this->callAdmin($app, $route);
            $location = (string) ($response->header('Location') ?? '');
            if (!($response->status() === 302 && str_starts_with($location, '/admin/login')) && !in_array($response->status(), [401, 403], true)) {
                $leaks[] = $key . ' → ' . $response->status();
            }
        }

        $this->assertSame([], $leaks);
    }

    public function testAContributorIsRefusedEveryAdminRouteItHasNoPermissionFor(): void
    {
        $app = $this->createApp('contributor');
        $contributor = $this->user($app, 2);
        $leaks = [];
        $checked = 0;

        foreach ($this->adminRoutes($app) as $key => $route) {
            $capabilities = self::capabilities($route);
            if ($capabilities === [] || array_filter($capabilities, static fn (string $cap): bool => $contributor->can($cap)) !== []) {
                continue;
            }
            ++$checked;
            $response = $this->callAdmin($app, $route);
            if ($response->status() !== 403) {
                $leaks[] = $key . ' → ' . $response->status();
            }
        }

        $this->assertGreaterThan(50, $checked);
        $this->assertSame([], $leaks);
    }

    public function testEveryApiRouteRefusesCallersWithoutItsPermission(): void
    {
        $app = $this->createApp(null);
        $contributor = $this->user($app, 2);
        $token = $this->tokens($app)->issue($contributor, 'audit', ['*'])['token'];
        $leaks = [];
        $checked = 0;

        foreach ($app->get(ApiRouteRegistry::class)->all() as ['prefix' => $prefix, 'route' => $route]) {
            /** @var ApiRoute $route */
            if ($route->public) {
                continue;
            }
            ++$checked;
            $uri = self::fill($prefix . $route->path);
            $anonymous = $this->callApi($app, $route->method, $uri, null);
            if ($anonymous->status() !== 401) {
                $leaks[] = 'anonyme ' . $route->method . ' ' . $uri . ' → ' . $anonymous->status();
            }

            if ($route->permission !== null && $route->permission !== ApiRoute::AUTHENTICATED && !$contributor->can($route->permission)) {
                $refused = $this->callApi($app, $route->method, $uri, $token);
                if ($refused->status() !== 403) {
                    $leaks[] = 'contributeur ' . $route->method . ' ' . $uri . ' → ' . $refused->status();
                }
            }
        }

        $this->assertGreaterThan(15, $checked);
        $this->assertSame([], $leaks);
    }

    /**
     * @return array<string, Route>
     */
    private function adminRoutes(Application $app): array
    {
        $routes = [];
        foreach ($app->get(Router::class)->routes() as $route) {
            if (!str_starts_with($route->path(), '/admin')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $routes[$method . ' ' . $route->path()] = $route;
                }
            }
        }
        ksort($routes);

        return $routes;
    }

    /**
     * Capabilities from `can:a|b` middlewares (any of them is enough).
     *
     * @return list<string>
     */
    private static function capabilities(Route $route): array
    {
        $caps = [];
        foreach ($route->middlewares() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                foreach (explode('|', substr($middleware, 4)) as $cap) {
                    $caps[] = trim($cap);
                }
            }
        }

        return $caps;
    }

    private static function isOwnAccount(Route $route): bool
    {
        foreach (self::OWN_ACCOUNT_PREFIXES as $prefix) {
            if (str_starts_with($route->path(), $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function fill(string $path): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)(?::(?:[^{}]|\{[^}]*\})+)?\}/i', static fn (array $m): string => match ($m[1]) {
            'type' => 'post',
            'taxonomy' => 'category',
            'token' => 'abc123',
            'location' => 'primary',
            'section', 'page' => 'general',
            'theme', 'slug' => 'aurora',
            default => '1',
        }, $path);
    }

    private function callAdmin(Application $app, Route $route): Response
    {
        $method = in_array('GET', $route->methods(), true) ? 'GET' : $route->methods()[0];
        $body = [];
        if ($method !== 'GET') {
            // A valid CSRF token: the request must be stopped by the access rule, not by the token check.
            $body['_csrf_token'] = (new Csrf($app->get(Session::class)))->token();
        }

        return $app->get(Kernel::class)->handle(Request::create($method, self::fill($route->path()), body: $body, headers: ['Accept' => 'text/html']));
    }

    private function callApi(Application $app, string $method, string $uri, ?string $token): Response
    {
        $headers = ['Accept' => 'application/json'];
        $raw = '';
        if (!in_array($method, ['GET', 'DELETE'], true)) {
            $headers['Content-Type'] = 'application/json';
            $raw = '{}';
        }
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        } else {
            $app->get(Auth::class)->logout();
        }

        return $app->get(Kernel::class)->handle(Request::create($method, $uri, headers: $headers, rawBody: $raw));
    }
}
