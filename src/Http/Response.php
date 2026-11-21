<?php

declare(strict_types=1);

namespace Prime\Http;

use JsonException;

/**
 * Immutable HTTP response value object.
 */
final class Response
{
    /**
     * Stored headers preserving original casing, indexed by lowercase name.
     *
     * @var array<string, array{name: string, value: string}>
     */
    private array $headers = [];

    /**
     * Queued Set-Cookie definitions emitted when sending the response.
     *
     * @var list<array{name: string, value: string, maxAge: int, path: string, secure: bool, httpOnly: bool, sameSite: string}>
     */
    private array $cookies = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = ['name' => $name, 'value' => $value];
        }
    }

    /**
     * Create an HTML response.
     *
     * @param array<string, string> $headers
     */
    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        return new self($html, $status, array_merge(
            ['Content-Type' => 'text/html; charset=UTF-8'],
            $headers,
        ));
    }

    /**
     * Create a JSON response.
     *
     * @param array<string, string> $headers
     * @throws JsonException
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new self($encoded, $status, array_merge(
            ['Content-Type' => 'application/json; charset=UTF-8'],
            $headers,
        ));
    }

    /**
     * Create an HTTP redirect response.
     *
     * @param array<string, string> $headers
     */
    public static function redirect(string $url, int $status = 302, array $headers = []): self
    {
        return new self('', $status, array_merge(
            ['Location' => $url],
            $headers,
        ));
    }

    /**
     * Create a 204 No Content response.
     *
     * @param array<string, string> $headers
     */
    public static function noContent(array $headers = []): self
    {
        return new self('', 204, $headers);
    }

    /**
     * Create a plain text response.
     *
     * @param array<string, string> $headers
     */
    public static function text(string $text, int $status = 200, array $headers = []): self
    {
        return new self($text, $status, array_merge(
            ['Content-Type' => 'text/plain; charset=UTF-8'],
            $headers,
        ));
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        $key = strtolower($name);

        return $this->headers[$key]['value'] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * Return all headers using their canonical names.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $result = [];
        foreach ($this->headers as $entry) {
            $result[$entry['name']] = $entry['value'];
        }

        return $result;
    }

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function withBody(string $body): self
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = ['name' => $name, 'value' => $value];

        return $clone;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        foreach ($headers as $name => $value) {
            $clone->headers[strtolower($name)] = ['name' => $name, 'value' => $value];
        }

        return $clone;
    }

    public function withoutHeader(string $name): self
    {
        $clone = clone $this;
        unset($clone->headers[strtolower($name)]);

        return $clone;
    }

    /**
     * Attach a `Set-Cookie` directive to the response (immutable).
     */
    public function withCookie(
        string $name,
        string $value,
        int $maxAge = 0,
        string $path = '/',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): self {
        $clone = clone $this;
        $clone->cookies = array_values(array_filter(
            $clone->cookies,
            static fn (array $cookie): bool => $cookie['name'] !== $name,
        ));
        $clone->cookies[] = [
            'name' => $name,
            'value' => $value,
            'maxAge' => $maxAge,
            'path' => $path,
            'secure' => $secure,
            'httpOnly' => $httpOnly,
            'sameSite' => $sameSite,
        ];

        return $clone;
    }

    /**
     * Expire a cookie on the client immediately.
     */
    public function withoutCookie(
        string $name,
        string $path = '/',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): self {
        return $this->withCookie($name, '', -3600, $path, $secure, $httpOnly, $sameSite);
    }

    /**
     * @return list<array{name: string, value: string, maxAge: int, path: string, secure: bool, httpOnly: bool, sameSite: string}>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * Find a queued cookie by name (primarily for testing and inspection).
     *
     * @return array{name: string, value: string, maxAge: int, path: string, secure: bool, httpOnly: bool, sameSite: string}|null
     */
    public function cookie(string $name): ?array
    {
        foreach ($this->cookies as $cookie) {
            if ($cookie['name'] === $name) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * Emit status code, headers, cookies, and body to the client.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            // `expose_php` is off in the Docker image; other hosts must not advertise PHP either.
            header_remove('X-Powered-By');
            http_response_code($this->status);
            foreach ($this->headers as $entry) {
                header($entry['name'] . ': ' . $entry['value'], true);
            }
            foreach ($this->cookies as $cookie) {
                header('Set-Cookie: ' . self::formatCookieHeader($cookie), false);
            }
        }

        if ($this->status !== 204) {
            echo $this->body;
        }
    }

    /**
     * @param array{name: string, value: string, maxAge: int, path: string, secure: bool, httpOnly: bool, sameSite: string} $cookie
     */
    public static function formatCookieHeader(array $cookie): string
    {
        $parts = [
            $cookie['name'] . '=' . rawurlencode($cookie['value']),
            'Path=' . $cookie['path'],
        ];

        if ($cookie['maxAge'] !== 0) {
            $expiresTs = $cookie['maxAge'] > 0 ? time() + $cookie['maxAge'] : time() - 3600;
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', $expiresTs) . ' GMT';
            $parts[] = 'Max-Age=' . max(0, $cookie['maxAge']);
        }

        if ($cookie['secure']) {
            $parts[] = 'Secure';
        }

        if ($cookie['httpOnly']) {
            $parts[] = 'HttpOnly';
        }

        if ($cookie['sameSite'] !== '') {
            $parts[] = 'SameSite=' . $cookie['sameSite'];
        }

        return implode('; ', $parts);
    }
}
