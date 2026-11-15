<?php

declare(strict_types=1);

namespace Prime\Http;

use Closure;
use Prime\Hooks\Hooks;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Middleware\SecurityHeaders;
use Prime\Kernel\Container;
use Prime\Kernel\ErrorHandler;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use Throwable;

/**
 * HTTP Kernel orchestrating global middlewares, route dispatch, route middlewares,
 * controller resolution, error handling, and the `http.response` filter hook.
 */
final class Kernel
{
    /**
     * Named middleware aliases supporting optional `:parameter` suffix (e.g. `can:users.view`).
     *
     * @var array<string, Closure(?string): MiddlewareInterface>
     */
    private array $middlewareAliases = [];

    /**
     * @param list<string|MiddlewareInterface> $globalMiddleware
     * @param list<string|MiddlewareInterface> $routeMiddleware Middlewares executed on every matched route (e.g. CSRF)
     */
    public function __construct(
        private readonly Router $router,
        private readonly Container $container,
        private readonly Hooks $hooks,
        private readonly ErrorHandler $errorHandler,
        private array $globalMiddleware = [],
        private array $routeMiddleware = [],
    ) {
    }

    /**
     * Register a named middleware alias (e.g. `'can'` for `'can:users.view'`).
     *
     * @param Closure(?string): MiddlewareInterface $factory
     */
    public function registerMiddlewareAlias(string $alias, Closure $factory): void
    {
        $this->middlewareAliases[$alias] = $factory;
    }

    /**
     * Add a middleware to the global HTTP pipeline.
     */
    public function addMiddleware(string|MiddlewareInterface $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    /**
     * Add a middleware executed for every matched route before route-specific middlewares.
     */
    public function addRouteMiddleware(string|MiddlewareInterface $middleware): void
    {
        $this->routeMiddleware[] = $middleware;
    }

    /**
     * Handle an incoming HTTP request and return the final HTTP response.
     */
    public function handle(Request $request): Response
    {
        $core = function (Request $req): Response {
            try {
                return $this->dispatchRoute($req);
            } catch (Throwable $exception) {
                return $this->errorHandler->handleException($exception, $req);
            }
        };

        try {
            $response = $this->runPipeline($request, $this->globalMiddleware, $core);
        } catch (Throwable $exception) {
            $response = $this->errorHandler->handleException($exception, $request);
            $security = new SecurityHeaders();
            $response = $security->process($request, static fn (): Response => $response);
        }

        $filtered = $this->hooks->apply('http.response', $response, $request);

        return $filtered instanceof Response ? $filtered : $response;
    }

    private function dispatchRoute(Request $request): Response
    {
        $matched = $this->router->dispatch($request);
        $route = $matched['route'];
        $params = $matched['params'];

        $request = $request->withAttributes($params);

        $handlerCore = fn (Request $req): Response => $this->invokeHandler($route->handler(), $req, $params);
        $middlewares = array_merge($this->routeMiddleware, $route->middlewares());

        return $this->runPipeline($request, $middlewares, $handlerCore);
    }

    /**
     * Execute an ordered middleware pipeline wrapping a final core callable.
     *
     * @param list<string|MiddlewareInterface> $middlewares
     * @param callable(Request): Response $core
     */
    private function runPipeline(Request $request, array $middlewares, callable $core): Response
    {
        $pipeline = array_reduce(
            array_reverse($middlewares),
            function (callable $next, string|MiddlewareInterface $middleware): callable {
                return function (Request $req) use ($middleware, $next): Response {
                    $instance = $this->resolveMiddleware($middleware);

                    return $instance->process($req, $next);
                };
            },
            $core,
        );

        return $pipeline($request);
    }

    private function resolveMiddleware(string|MiddlewareInterface $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        $colonPos = strpos($middleware, ':');
        $alias = $colonPos !== false ? substr($middleware, 0, $colonPos) : $middleware;
        $parameter = $colonPos !== false ? substr($middleware, $colonPos + 1) : null;

        if (isset($this->middlewareAliases[$alias])) {
            return ($this->middlewareAliases[$alias])($parameter);
        }

        $resolved = $this->container->make($middleware);
        if (!$resolved instanceof MiddlewareInterface) {
            throw new RuntimeException(
                sprintf('Middleware [%s] must implement %s.', $middleware, MiddlewareInterface::class),
            );
        }

        return $resolved;
    }

    /**
     * Invoke a route handler (Closure or `[Controller::class, 'method']`).
     *
     * @param callable|array{0: class-string|object, 1: string} $handler
     * @param array<string, string> $params
     */
    private function invokeHandler(callable|array $handler, Request $request, array $params): Response
    {
        if (is_array($handler)) {
            [$controllerTarget, $method] = $handler;
            $controller = is_object($controllerTarget)
                ? $controllerTarget
                : $this->container->make($controllerTarget);
            $controllerLabel = is_object($controllerTarget) ? $controllerTarget::class : $controllerTarget;

            if (!method_exists($controller, $method)) {
                throw new RuntimeException(sprintf('Controller method [%s::%s] does not exist.', $controllerLabel, $method));
            }

            $reflection = new ReflectionMethod($controller, $method);
            $arguments = $this->resolveHandlerArguments($reflection->getParameters(), $request, $params);
            $result = $reflection->invokeArgs($controller, $arguments);
        } else {
            $closure = $handler instanceof Closure ? $handler : Closure::fromCallable($handler);
            $reflection = new ReflectionFunction($closure);
            $arguments = $this->resolveHandlerArguments($reflection->getParameters(), $request, $params);
            $result = $closure(...$arguments);
        }

        if (!$result instanceof Response) {
            throw new RuntimeException(
                sprintf('Route handler must return an instance of %s.', Response::class),
            );
        }

        return $result;
    }

    /**
     * Inject Request, route parameters, or container services into handler parameters.
     *
     * @param list<ReflectionParameter> $parameters
     * @param array<string, string> $params
     * @return list<mixed>
     */
    private function resolveHandlerArguments(array $parameters, Request $request, array $params): array
    {
        $arguments = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && $type->getName() === Request::class) {
                $arguments[] = $request;
                continue;
            }

            if (array_key_exists($name, $params)) {
                $arguments[] = $params[$name];
                continue;
            }

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && $this->container->has($type->getName())) {
                $arguments[] = $this->container->get($type->getName());
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            // Fallback: if first untyped parameter is named `$request` or no match, pass $request
            if ($type === null && $name === 'request') {
                $arguments[] = $request;
                continue;
            }

            $arguments[] = null;
        }

        return $arguments;
    }
}
