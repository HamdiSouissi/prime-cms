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

    private ?string $compiledPattern = null;

    /**
     * Parsed route tokens for compilation and URL generation.
     *
     * @var list<array{type: 'static', value: string}|array{type: 'param', name: string, constraint: string, optional: bool, precedingSlash: bool}>|null
     */
    private ?array $tokens = null;

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
        return in_array(strtoupper($method), $this->methods, true);
    }

    /**
     * Attempt to match the route's path pattern against a request path.
     *
     * @return array<string, string>|null Matched parameters, or null if no match.
     */
    public function matchesPath(string $path): ?array
    {
        $pattern = $this->compile();

        if (preg_match($pattern, $path, $matches) !== 1) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key) && $value !== '') {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * Generate a relative URL path for this route with the provided parameters.
     *
     * @param array<string, scalar|null> $params
     */
    public function generateUrl(array $params = []): string
    {
        $tokens = $this->parseTokens();
        $usedParams = [];
        $url = '';

        foreach ($tokens as $token) {
            if ($token['type'] === 'static') {
                $url .= $token['value'];
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

            if (preg_match('#^' . $token['constraint'] . '$#', $value) !== 1) {
                throw new InvalidArgumentException(
                    sprintf('Parameter [%s] value [%s] does not match constraint [%s].', $name, $value, $token['constraint']),
                );
            }

            if ($token['precedingSlash']) {
                $url .= '/';
            }

            $url .= rawurlencode($value);
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
            return $this->handler[0] . '::' . $this->handler[1];
        }

        if (is_string($this->handler)) {
            return $this->handler;
        }

        if (is_object($this->handler)) {
            return $this->handler::class . '::__invoke';
        }

        return 'callable';
    }

    private function compile(): string
    {
        if ($this->compiledPattern !== null) {
            return $this->compiledPattern;
        }

        $regex = '#^';

        foreach ($this->parseTokens() as $token) {
            if ($token['type'] === 'static') {
                $regex .= preg_quote($token['value'], '#');
                continue;
            }

            $namedGroup = sprintf('(?P<%s>%s)', $token['name'], $token['constraint']);

            if ($token['optional']) {
                $regex .= $token['precedingSlash']
                    ? '(?:/' . $namedGroup . ')?'
                    : '(?:' . $namedGroup . ')?';
            } else {
                $regex .= $namedGroup;
            }
        }

        $this->compiledPattern = $regex . '$#';

        return $this->compiledPattern;
    }

    /**
     * Tokenize the route path, supporting nested braces inside constraints (e.g. `{year:\d{4}}`).
     *
     * @return list<array{type: 'static', value: string}|array{type: 'param', name: string, constraint: string, optional: bool, precedingSlash: bool}>
     */
    private function parseTokens(): array
    {
        if ($this->tokens !== null) {
            return $this->tokens;
        }

        /** @var list<array{type: 'static', value: string}|array{type: 'param', name: string, constraint: string, optional: bool, precedingSlash: bool}> $tokens */
        $tokens = [];
        $length = strlen($this->path);
        $buffer = '';
        $i = 0;

        while ($i < $length) {
            if ($this->path[$i] !== '{') {
                $buffer .= $this->path[$i];
                $i++;
                continue;
            }

            // Find matching closing brace accounting for nested quantifiers like `{4}`
            $depth = 0;
            $start = $i + 1;
            $end = null;

            for ($j = $i; $j < $length; $j++) {
                if ($this->path[$j] === '{') {
                    $depth++;
                } elseif ($this->path[$j] === '}') {
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

            $definition = substr($this->path, $start, $end - $start);
            $colonPos = strpos($definition, ':');

            if ($colonPos !== false) {
                $rawName = substr($definition, 0, $colonPos);
                $constraint = substr($definition, $colonPos + 1);
            } else {
                $rawName = $definition;
                $constraint = '[^/]+';
            }

            $optional = str_ends_with($rawName, '?');
            $paramName = $optional ? substr($rawName, 0, -1) : $rawName;

            if ($paramName === '' || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $paramName) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid route parameter name [%s] in path [%s].', $paramName, $this->path));
            }

            $precedingSlash = false;
            if ($optional && str_ends_with($buffer, '/') && $i > 1) {
                $buffer = substr($buffer, 0, -1);
                $precedingSlash = true;
            }

            if ($buffer !== '') {
                $tokens[] = ['type' => 'static', 'value' => $buffer];
                $buffer = '';
            }

            $tokens[] = [
                'type' => 'param',
                'name' => $paramName,
                'constraint' => $constraint !== '' ? $constraint : '[^/]+',
                'optional' => $optional,
                'precedingSlash' => $precedingSlash,
            ];

            $i = $end + 1;
        }

        if ($buffer !== '') {
            $tokens[] = ['type' => 'static', 'value' => $buffer];
        }

        $this->tokens = $tokens;

        return $this->tokens;
    }

    private static function normalizePath(string $path): string
    {
        return '/' . trim($path, '/');
    }
}
