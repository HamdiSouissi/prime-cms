<?php

declare(strict_types=1);

namespace Prime\Api;

use Closure;
use InvalidArgumentException;

/**
 * Declarative description of one REST API route.
 *
 * Every route either requires a capability or is explicitly public: there is no implicit
 * public route. The same description feeds the router, the permission middleware and the
 * OpenAPI document, so a route cannot be served without being documented.
 */
final class ApiRoute
{
    /**
     * Permission value for routes open to any authenticated caller, whatever its abilities.
     */
    public const AUTHENTICATED = '@authenticated';

    public const BODY_NONE = 'none';
    public const BODY_JSON = 'json';
    public const BODY_MULTIPART = 'multipart';

    private const METHODS = ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'];

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param array<string, array{in?: string, type?: string, description?: string, required?: bool}> $parameters
     * @param array<string, mixed>                                                                     $requestSchema
     * @param array<string, mixed>                                                                     $responseSchema
     * @param list<int>                                                                                $errors
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array|Closure $handler,
        public readonly ?string $permission = null,
        public readonly bool $public = false,
        public readonly string $summary = '',
        public readonly string $tag = 'core',
        public readonly array $parameters = [],
        public readonly array $requestSchema = [],
        public readonly array $responseSchema = [],
        public readonly array $errors = [],
        public readonly string $body = self::BODY_NONE,
        public readonly ?string $name = null,
    ) {
        if (!in_array($this->method, self::METHODS, true)) {
            throw new InvalidArgumentException(sprintf('API route method [%s] is not supported.', $this->method));
        }

        if (!str_starts_with($this->path, '/')) {
            throw new InvalidArgumentException(sprintf('API route path [%s] must start with "/".', $this->path));
        }

        if ($this->public === ($this->permission !== null && $this->permission !== '')) {
            throw new InvalidArgumentException(sprintf(
                'API route %s %s must declare exactly one of a permission or public: true.',
                $this->method,
                $this->path,
            ));
        }

        if (!in_array($this->body, [self::BODY_NONE, self::BODY_JSON, self::BODY_MULTIPART], true)) {
            throw new InvalidArgumentException(sprintf('API route body type [%s] is not supported.', $this->body));
        }
    }

    /**
     * Route path with every `{param}` normalised, used to detect two routes answering the same URL.
     */
    public function signature(): string
    {
        return $this->method . ' ' . (string) preg_replace('/\{[^}]+\}/', '{}', $this->path);
    }

    /**
     * Path in OpenAPI form: `{id:\d+}` becomes `{id}`.
     */
    public function documentedPath(): string
    {
        return (string) preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]*)?\}/', '{$1}', $this->path);
    }

    /**
     * @return list<string>
     */
    public function pathParameters(): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]*)?\}/', $this->path, $matches);

        return $matches[1];
    }
}
