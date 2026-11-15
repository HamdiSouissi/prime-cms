<?php

declare(strict_types=1);

namespace Prime\Http;

use Closure;
use InvalidArgumentException;
use Prime\Http\Middleware\MiddlewareInterface;

/**
 * Represents a single registered HTTP route with optional constraints, name, and middlewares.
 */
final class Route
{
    /**
     * @var list<string>
     */
    private readonly array $methods;

    private readonly string $path;

    /**
     * @var callable|array{0: class-string, 1: string}
     */
    private readonly mixed $handler;

    /**
     * @var list<string|MiddlewareInterface>
     */
    private array $middleware = [];

    private ?string $name = null;

    private readonly string $namePrefix;

    /**
     * Parsed per-segment route definitions for segment-based matching and URL generation.
     *
     * @var list<array{
     *     tokens: list<array{type: 'static', value: string}|array{type: 'param', name: string, constraint: ?string, optional: bool}>,
     *     regex: string,
     *     optional: bool
     * }>|null
     */
    private ?array $segments = null;

    /**
     * @param list<string> $methods
     * @param callable|array{0: class-string, 1: string} $handler
     * @param list<string|MiddlewareInterface> $middleware
     */
    public function __construct(
        array $methods,
        string $path,
        callable|array $handler,
        array $middleware = [],
        ?string $name = null,
        string $namePrefix = '',
    ) {
        $this->methods = array_values(array_unique(array_map('strtoupper', $methods)));
        $this->path = self::normalizePath($path);
        $this->handler = $handler;
        $this->middleware = $middleware;
        $this->namePrefix = $namePrefix;
        $this->name = $name !== null ? $this->namePrefix . $name : null;
    }

    /**
     * Assign a unique route name (used for URL generation).
     */
    public function name(string $name): self
    {
        $this->name = $this->namePrefix . $name;

        return $this;
    }

    /**
     * Attach one or more middlewares to this route.
     *
     * @param string|MiddlewareInterface|list<string|MiddlewareInterface> $middleware
     */
    public function middleware(string|MiddlewareInterface|array $middleware): self
    {
        if (is_array($middleware)) {
            foreach ($middleware as $item) {
                $this->middleware[] = $item;
            }
        } else {
            $this->middleware[] = $middleware;
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return $this->methods;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return callable|array{0: class-string, 1: string}
     */
    public function handler(): callable|array
    {
        return $this->handler;
    }

    /**
     * @return list<string|MiddlewareInterface>
     */
    public function middlewares(): array
    {
        return $this->middleware;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function matchesMethod(string $method): bool
    {
        $upper = strtoupper($method);

        return in_array($upper, $this->methods, true)
            || ($upper === 'HEAD' && in_array('GET', $this->methods, true));
    }

    /**
     * Attempt to match the route's path pattern against a request path.
     *
     * Splits the request path on `/` before applying `rawurldecode()` to each
     * segment so encoded slashes (`%2F`) remain within a single parameter.
     *
     * @return array<string, string>|null Matched decoded parameters, or null if no match.
     */
    public function matchesPath(string $path): ?array
    {
        $requestSegments = self::splitAndDecodeRequestPath($path);
        $routeSegments = $this->parseSegments();

        return $this->matchSegments($routeSegments, $requestSegments, 0, 0);
    }

    /**
     * Generate a relative URL path for this route with the provided parameters.
     *
     * Validates raw parameter values against their constraints before encoding
     * each value with `rawurlencode()`.
     *
     * @param array<string, scalar|null> $params
     */
    public function generateUrl(array $params = []): string
    {
        $segments = $this->parseSegments();
        $usedParams = [];
        $url = '';

        foreach ($segments as $segment) {
            if ($segment['optional'] && count($segment['tokens']) === 1 && $segment['tokens'][0]['type'] === 'param') {
                $onlyParam = $segment['tokens'][0];
                $name = $onlyParam['name'];
                $hasValue = array_key_exists($name, $params) && $params[$name] !== null && (string) $params[$name] !== '';

                if (!$hasValue) {
                    continue;
                }
            }

            $encodedSegment = '';

            foreach ($segment['tokens'] as $token) {
                if ($token['type'] === 'static') {
                    $encodedSegment .= $token['value'];
                    continue;
                }

                $name = $token['name'];
                $hasValue = array_key_exists($name, $params) && $params[$name] !== null && (string) $params[$name] !== '';

                if (!$hasValue) {
                    if ($token['optional']) {
                        continue;
                    }

                    throw new InvalidArgumentException(
                        sprintf('Missing required parameter [%s] for route [%s].', $name, $this->name ?? $this->path),
                    );
                }

                $value = (string) $params[$name];
                $usedParams[$name] = true;
                $constraint = $token['constraint'] ?? '.+';

                if (preg_match('#^' . $constraint . '$#u', $value) !== 1) {
                    throw new InvalidArgumentException(
                        sprintf('Parameter [%s] value [%s] does not match constraint [%s].', $name, $value, $constraint),
                    );
                }

                $encodedSegment .= rawurlencode($value);
            }

            $url .= '/' . $encodedSegment;
        }

        if ($url === '') {
            $url = '/';
        }

        $extraQuery = [];
        foreach ($params as $key => $value) {
            if (!isset($usedParams[$key]) && $value !== null) {
                $extraQuery[$key] = $value;
            }
        }

        if ($extraQuery !== []) {
            $url .= '?' . http_build_query($extraQuery);
        }

        return $url;
    }

    /**
     * Return a human-readable description of the route handler (for CLI inspection).
     */
    public function handlerLabel(): string
    {
        if ($this->handler instanceof Closure) {
            return 'Closure';
        }

        if (is_array($this->handler)) {
            $target = is_object($this->handler[0]) ? $this->handler[0]::class : $this->handler[0];

            return $target . '::' . $this->handler[1];
        }

        if (is_string($this->handler)) {
            return $this->handler;
        }

        if (is_object($this->handler)) {
            return $this->handler::class . '::__invoke';
        }

        return 'callable';
    }

    /**
     * @param list<array{
     *     tokens: list<array{type: 'static', value: string}|array{type: 'param', name: string, constraint: ?string, optional: bool}>,
     *     regex: string,
     *     optional: bool
     * }> $routeSegments
     * @param list<string> $requestSegments
     * @return array<string, string>|null
     */
    private function matchSegments(
        array $routeSegments,
        array $requestSegments,
        int $routeIndex,
        int $requestIndex,
    ): ?array {
        $routeCount = count($routeSegments);
        $requestCount = count($requestSegments);

        if ($routeIndex === $routeCount) {
            return $requestIndex === $requestCount ? [] : null;
        }

        $segment = $routeSegments[$routeIndex];

        if ($requestIndex < $requestCount && preg_match($segment['regex'], $requestSegments[$requestIndex], $matches) === 1) {
            $tail = $this->matchSegments($routeSegments, $requestSegments, $routeIndex + 1, $requestIndex + 1);
            if ($tail !== null) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key) && $value !== '') {
                        $params[$key] = $value;
                    }
                }

                return array_merge($params, $tail);
            }
        }

        if ($segment['optional']) {
            return $this->matchSegments($routeSegments, $requestSegments, $routeIndex + 1, $requestIndex);
        }

        return null;
    }

    /**
     * Split the route path into segments and compile per-segment regex patterns.
     *
     * @return list<array{
     *     tokens: list<array{type: 'static', value: string}|array{type: 'param', name: string, constraint: ?string, optional: bool}>,
     *     regex: string,
     *     optional: bool
     * }>
     */
    private function parseSegments(): array
    {
        if ($this->segments !== null) {
            return $this->segments;
        }

        if ($this->path === '/') {
            $this->segments = [];

            return $this->segments;
        }

        $rawSegments = self::splitRoutePath($this->path);
        $parsed = [];

        foreach ($rawSegments as $rawSegment) {
            $tokens = $this->parseSegmentTokens($rawSegment);
            $regex = '#^';
            $allOptionalParams = $tokens !== [];

            foreach ($tokens as $token) {
                if ($token['type'] === 'static') {
                    $allOptionalParams = false;
                    $regex .= preg_quote($token['value'], '#');
                    continue;
                }

                if (!$token['optional']) {
                    $allOptionalParams = false;
                }

                $pattern = $token['constraint'] ?? '.+';
                $namedGroup = sprintf('(?P<%s>%s)', $token['name'], $pattern);
                $regex .= $token['optional'] ? '(?:' . $namedGroup . ')?' : $namedGroup;
            }

            $parsed[] = [
                'tokens' => $tokens,
                'regex' => $regex . '$#u',
                'optional' => $allOptionalParams,
            ];
        }

        $this->segments = $parsed;

        return $this->segments;
    }

    /**
     * Split a route definition path on `/` outside `{...}` parameter braces.
     *
     * @return list<string>
     */
    private static function splitRoutePath(string $path): array
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return [];
        }

        $segments = [];
        $buffer = '';
        $depth = 0;
        $length = strlen($trimmed);

        for ($i = 0; $i < $length; $i++) {
            $char = $trimmed[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}' && $depth > 0) {
                $depth--;
            }

            if ($char === '/' && $depth === 0) {
                $segments[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $segments[] = $buffer;

        return $segments;
    }

    /**
     * Tokenize a single route path segment, supporting nested braces inside constraints (e.g. `{year:\d{4}}`).
     *
     * @return list<array{type: 'static', value: string}|array{type: 'param', name: string, constraint: ?string, optional: bool}>
     */
    private function parseSegmentTokens(string $segment): array
    {
        /** @var list<array{type: 'static', value: string}|array{type: 'param', name: string, constraint: ?string, optional: bool}> $tokens */
        $tokens = [];
        $length = strlen($segment);
        $buffer = '';
        $i = 0;

        while ($i < $length) {
            if ($segment[$i] !== '{') {
                $buffer .= $segment[$i];
                $i++;
                continue;
            }

            // Find matching closing brace accounting for nested quantifiers like `{4}`
            $depth = 0;
            $start = $i + 1;
            $end = null;

            for ($j = $i; $j < $length; $j++) {
                if ($segment[$j] === '{') {
                    $depth++;
                } elseif ($segment[$j] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $j;
                        break;
                    }
                }
            }

            if ($end === null) {
                throw new InvalidArgumentException(sprintf('Unclosed route parameter in path [%s].', $this->path));
            }

            $definition = substr($segment, $start, $end - $start);
            $colonPos = strpos($definition, ':');

            if ($colonPos !== false) {
                $rawName = substr($definition, 0, $colonPos);
                $rawConstraint = substr($definition, $colonPos + 1);
                $constraint = $rawConstraint !== '' ? $rawConstraint : null;
            } else {
                $rawName = $definition;
                $constraint = null;
            }

            $optional = str_ends_with($rawName, '?');
            $paramName = $optional ? substr($rawName, 0, -1) : $rawName;

            if ($paramName === '' || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $paramName) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid route parameter name [%s] in path [%s].', $paramName, $this->path));
            }

            if ($buffer !== '') {
                $tokens[] = ['type' => 'static', 'value' => $buffer];
                $buffer = '';
            }

            $tokens[] = [
                'type' => 'param',
                'name' => $paramName,
                'constraint' => $constraint,
                'optional' => $optional,
            ];

            $i = $end + 1;
        }

        if ($buffer !== '') {
            $tokens[] = ['type' => 'static', 'value' => $buffer];
        }

        return $tokens;
    }

    /**
     * Split a request path on `/` and apply `rawurldecode()` to each segment.
     *
     * @return list<string>
     */
    private static function splitAndDecodeRequestPath(string $path): array
    {
        if ($path === '' || $path === '/') {
            return [];
        }

        $trimmedLeading = str_starts_with($path, '/') ? substr($path, 1) : $path;
        $rawSegments = explode('/', $trimmedLeading);

        return array_map('rawurldecode', $rawSegments);
    }

    private static function normalizePath(string $path): string
    {
        return '/' . trim($path, '/');
    }
}
