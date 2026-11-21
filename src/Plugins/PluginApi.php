<?php

declare(strict_types=1);

namespace Prime\Plugins;

use Closure;
use Prime\Api\ApiError;
use Prime\Api\ApiRoute;
use Prime\Api\ApiRouteRegistry;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Kernel\Application;
use Prime\Log\Logger;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Throwable;

/**
 * REST API registrar exposed via `PluginContext::api()`.
 *
 * Routes live under the plugin's own namespace, `/api/{slug}/v1/…`, run through the same
 * middleware stack as the core API (switch, authentication, rate limit, body checks,
 * capability) and use the same error format. Every route must name a capability or be
 * explicitly public. An exception thrown by a plugin handler becomes a bare 500
 * `server_error`, is logged with the plugin as owner, and leaves the plugin active.
 */
final class PluginApi
{
    public function __construct(
        private readonly Application $app,
        private readonly ApiRouteRegistry $registry,
        private readonly Logger $logger,
        private readonly string $slug,
    ) {
    }

    /**
     * Declares a reusable schema for the OpenAPI document, to be referenced as
     * `#/components/schemas/{Name}` (prefix the name with the plugin, e.g. `HelloPrimeGreeting`).
     *
     * @param array<string, mixed> $schema
     */
    public function schema(string $name, array $schema): void
    {
        $this->registry->addSchema($name, $schema);
    }

    public function prefix(): string
    {
        return '/api/' . $this->slug . '/v1';
    }

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param array<string, mixed>                       $options `summary`, `parameters`, `request`, `response`, `errors`, `name`
     */
    public function get(string $path, array|Closure $handler, ?string $permission = null, bool $public = false, array $options = []): void
    {
        $this->add('GET', $path, $handler, $permission, $public, $options);
    }

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param array<string, mixed>                       $options
     */
    public function post(string $path, array|Closure $handler, ?string $permission = null, bool $public = false, array $options = []): void
    {
        $this->add('POST', $path, $handler, $permission, $public, $options + ['body' => ApiRoute::BODY_JSON]);
    }

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param array<string, mixed>                       $options
     */
    public function patch(string $path, array|Closure $handler, ?string $permission = null, bool $public = false, array $options = []): void
    {
        $this->add('PATCH', $path, $handler, $permission, $public, $options + ['body' => ApiRoute::BODY_JSON]);
    }

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param array<string, mixed>                       $options
     */
    public function delete(string $path, array|Closure $handler, ?string $permission = null, bool $public = false, array $options = []): void
    {
        $this->add('DELETE', $path, $handler, $permission, $public, $options);
    }

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param array<string, mixed>                       $options
     */
    private function add(string $method, string $path, array|Closure $handler, ?string $permission, bool $public, array $options): void
    {
        $route = new ApiRoute(
            method: $method,
            path: $path,
            handler: $this->guard($handler),
            permission: $permission,
            public: $public,
            summary: is_string($options['summary'] ?? null) ? $options['summary'] : '',
            tag: $this->slug,
            parameters: is_array($options['parameters'] ?? null) ? $options['parameters'] : [],
            requestSchema: is_array($options['request'] ?? null) ? $options['request'] : [],
            responseSchema: is_array($options['response'] ?? null) ? $options['response'] : [],
            errors: is_array($options['errors'] ?? null) ? array_values(array_filter($options['errors'], 'is_int')) : [],
            body: is_string($options['body'] ?? null) ? $options['body'] : ApiRoute::BODY_NONE,
            name: is_string($options['name'] ?? null) ? 'plugin.' . $this->slug . '.api.' . $options['name'] : null,
        );

        $this->registry->add($route, $this->prefix(), $this->slug);
    }

    /**
     * Wraps a plugin handler so that nothing it throws can escape as a stack trace.
     *
     * @param array{0: class-string, 1: string}|Closure $handler
     */
    private function guard(array|Closure $handler): Closure
    {
        $slug = $this->slug;
        $logger = $this->logger;
        $app = $this->app;

        return static function (Request $request) use ($handler, $slug, $logger, $app): Response {
            try {
                if ($handler instanceof Closure) {
                    $result = $handler(...self::arguments(new ReflectionFunction($handler), $request, $app));
                } else {
                    [$class, $method] = $handler;
                    $controller = $app->container()->make($class);
                    $result = (new ReflectionMethod($controller, $method))->invokeArgs($controller, self::arguments(new ReflectionMethod($controller, $method), $request, $app));
                }

                if (!$result instanceof Response) {
                    throw new RuntimeException('A plugin API handler must return a Response.');
                }

                return $result;
            } catch (Throwable $exception) {
                $logger->error('Plugin API handler failed', [
                    'plugin' => $slug,
                    'path' => $request->path(),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                return ApiError::response('server_error', 500, $request);
            }
        };
    }

    /**
     * Resolves handler arguments: the Request, route parameters by name, or a service.
     *
     * @return list<mixed>
     */
    private static function arguments(ReflectionFunction|ReflectionMethod $reflection, Request $request, Application $app): array
    {
        $arguments = [];
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

            if ($typeName === Request::class) {
                $arguments[] = $request;
                continue;
            }

            $value = $request->attribute($parameter->getName());
            if ($value !== null) {
                $arguments[] = $typeName === 'int' && is_numeric($value) ? (int) $value : $value;
                continue;
            }

            if ($typeName !== null && !$type->isBuiltin()) {
                $arguments[] = $app->container()->make($typeName);
                continue;
            }

            $arguments[] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
        }

        return $arguments;
    }
}
