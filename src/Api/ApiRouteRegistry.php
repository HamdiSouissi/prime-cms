<?php

declare(strict_types=1);

namespace Prime\Api;

use LogicException;
use Prime\Http\Router;

/**
 * Registry of every REST API route, core and plugins alike.
 *
 * Routes are mounted on the router as soon as they are added, under a namespace prefix
 * (`/api/v1` for the core, `/api/{plugin}/v1` for a plugin), wrapped in the shared API
 * middleware stack plus the permission and body middlewares the route declares. Two
 * routes answering the same method and URL shape are refused with a clear error.
 */
final class ApiRouteRegistry
{
    public const CORE_PREFIX = '/api/v1';

    /**
     * Shared pipeline, outermost first: lifecycle (timing, events, response filter), switch,
     * authentication, rate limit.
     *
     * @var list<string>
     */
    public const STACK = ['api.lifecycle', 'api.enabled', 'api.auth', 'api.rate_limit'];

    /**
     * @var array<string, array{prefix: string, route: ApiRoute, owner: string}>
     */
    private array $routes = [];

    public function __construct(private readonly Router $router)
    {
    }

    public function add(ApiRoute $route, string $prefix = self::CORE_PREFIX, string $owner = 'core'): void
    {
        $prefix = '/' . trim($prefix, '/');
        $signature = $route->method . ' ' . $prefix . substr($route->signature(), strlen($route->method) + 1);

        if (isset($this->routes[$signature])) {
            throw new LogicException(sprintf(
                'API route %s%s declared by "%s" conflicts with the one declared by "%s".',
                $prefix,
                $route->path,
                $owner,
                $this->routes[$signature]['owner'],
            ));
        }

        $this->routes[$signature] = ['prefix' => $prefix, 'route' => $route, 'owner' => $owner];

        $middleware = self::STACK;
        if (!$route->public) {
            $middleware[] = 'api.can:' . (string) $route->permission;
        }
        $middleware[] = 'api.body:' . $route->body;

        $path = $route->path === '/' ? $prefix : $prefix . $route->path;
        $mounted = $this->router->match([$route->method], $path, $route->handler)->middleware($middleware);
        if ($route->name !== null) {
            $mounted->name($route->name);
        }
    }

    /**
     * @return list<array{prefix: string, route: ApiRoute, owner: string}>
     */
    public function all(): array
    {
        return array_values($this->routes);
    }

    public function count(): int
    {
        return count($this->routes);
    }
}
