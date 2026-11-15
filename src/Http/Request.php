<?php

declare(strict_types=1);

namespace Prime\Http;

/**
 * Immutable HTTP request value object.
 */
final class Request
{
    /**
     * HTTP methods allowed via HTML form `_method` spoofing on POST requests.
     */
    private const SPOOFABLE_METHODS = ['PUT', 'PATCH', 'DELETE'];

    private string $method;

    private string $path;

    /**
     * @var array<string, mixed>
     */
    private array $query;

    /**
     * @var array<string, mixed>
     */
    private array $body;

    private string $rawBody;

    /**
     * Lowercased header map.
     *
     * @var array<string, string>
     */
    private array $headers;

    /**
     * @var array<string, string>
     */
    private array $cookies;

    /**
     * @var array<string, mixed>
     */
    private array $files;

    /**
     * @var array<string, mixed>
     */
    private array $server;

    /**
     * Route parameters and request-scoped attributes.
     *
     * @var array<string, mixed>
     */
    private array $attributes;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        string $method = 'GET',
        string $path = '/',
        array $query = [],
        array $body = [],
        array $headers = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        string $rawBody = '',
        array $attributes = [],
    ) {
        $this->headers = self::normalizeHeaders($headers);
        $this->rawBody = $rawBody;
        $this->query = $query;
        $this->cookies = $cookies;
        $this->files = $files;
        $this->server = $server;
        $this->attributes = $attributes;

        $this->path = self::normalizePath($path);
        $this->body = self::resolveBody($body, $rawBody, $this->headers);
        $this->method = self::resolveMethod($method, $this->body);
    }

    /**
     * Construct a Request from PHP superglobals.
     */
    public static function fromGlobals(): self
    {
        /** @var array<string, mixed> $server */
        $server = $_SERVER;
        /** @var array<string, mixed> $query */
        $query = $_GET;
        /** @var array<string, mixed> $body */
        $body = $_POST;
        /** @var array<string, mixed> $files */
        $files = $_FILES;

        $cookies = [];
        foreach ($_COOKIE as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $cookies[$k] = (string) $v;
            }
        }

        $method = isset($server['REQUEST_METHOD']) && is_string($server['REQUEST_METHOD'])
            ? $server['REQUEST_METHOD']
            : 'GET';

        $requestUri = isset($server['REQUEST_URI']) && is_string($server['REQUEST_URI'])
            ? $server['REQUEST_URI']
            : '/';

        $parsedPath = parse_url($requestUri, PHP_URL_PATH);
        $path = is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/';

        $rawBody = file_get_contents('php://input');
        $headers = self::extractHeadersFromServer($server);

        return new self(
            method: $method,
            path: $path,
            query: $query,
            body: $body,
            headers: $headers,
            cookies: $cookies,
            files: $files,
            server: $server,
            rawBody: $rawBody === false ? '' : $rawBody,
        );
    }

    /**
     * Ergonomic factory for manual construction and testing.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public static function create(
        string $method = 'GET',
        string $uri = '/',
        array $query = [],
        array $body = [],
        array $headers = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        string $rawBody = '',
    ): self {
        $parsedPath = parse_url($uri, PHP_URL_PATH);
        $path = is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/';

        $uriQueryString = parse_url($uri, PHP_URL_QUERY);
        $uriQuery = [];
        if (is_string($uriQueryString) && $uriQueryString !== '') {
            parse_str($uriQueryString, $parsedQuery);
            foreach ($parsedQuery as $k => $v) {
                $uriQuery[(string) $k] = $v;
            }
        }

        $mergedQuery = array_merge($uriQuery, $query);

        $defaultServer = array_merge([
            'REQUEST_METHOD' => strtoupper($method),
            'REQUEST_URI' => $uri,
            'REMOTE_ADDR' => '127.0.0.1',
        ], $server);

        return new self(
            method: $method,
            path: $path,
            query: $mergedQuery,
            body: $body,
            headers: $headers,
            cookies: $cookies,
            files: $files,
            server: $defaultServer,
            rawBody: $rawBody,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function uri(): string
    {
        if ($this->query === []) {
            return $this->path;
        }

        return $this->path . '?' . http_build_query($this->query);
    }

    /**
     * Retrieve a query parameter or the entire query array when `$key` is null.
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    /**
     * Retrieve a body parameter or the entire parsed body array when `$key` is null.
     */
    public function body(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }

        return $this->body[$key] ?? $default;
    }

    /**
     * Retrieve a parameter from body first, then query string.
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return array_merge($this->query, $this->body);
        }

        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }

        return $this->query[$key] ?? $default;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $normalized = strtolower($name);

        return $this->headers[$normalized] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->files;
    }

    public function file(string $key): mixed
    {
        return $this->files[$key] ?? null;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function ip(): string
    {
        $addr = $this->server['REMOTE_ADDR'] ?? null;

        return is_string($addr) && $addr !== '' ? $addr : '127.0.0.1';
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('x-requested-with') ?? '') === 'xmlhttprequest';
    }

    public function wantsJson(): bool
    {
        $accept = strtolower($this->header('accept') ?? '');

        return str_contains($accept, 'application/json') || str_contains($accept, '+json');
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * Return a new Request instance with an added/updated attribute.
     */
    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;

        return $clone;
    }

    /**
     * Return a new Request instance with multiple attributes merged.
     *
     * @param array<string, mixed> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = array_merge($clone->attributes, $attributes);

        return $clone;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        return $normalized;
    }

    private static function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        return str_starts_with($path, '/') ? $path : '/' . $path;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private static function resolveBody(array $body, string $rawBody, array $headers): array
    {
        $contentType = strtolower($headers['content-type'] ?? '');

        if ($rawBody !== '' && str_contains($contentType, 'application/json')) {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function resolveMethod(string $method, array $body): string
    {
        $upper = strtoupper(trim($method));

        if ($upper === 'POST' && isset($body['_method']) && is_string($body['_method'])) {
            $override = strtoupper(trim($body['_method']));
            if (in_array($override, self::SPOOFABLE_METHODS, true)) {
                return $override;
            }
        }

        return $upper !== '' ? $upper : 'GET';
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function extractHeadersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $name = strtolower(str_replace('_', '-', $key));
                $headers[$name] = $value;
            }
        }

        if (!isset($headers['authorization']) && isset($server['REDIRECT_HTTP_AUTHORIZATION']) && is_string($server['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = $server['REDIRECT_HTTP_AUTHORIZATION'];
        }

        return $headers;
    }
}
