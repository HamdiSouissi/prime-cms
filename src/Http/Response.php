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
     * Emit status code, headers, and body to the client.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $entry) {
                header($entry['name'] . ': ' . $entry['value'], true);
            }
        }

        if ($this->status !== 204) {
            echo $this->body;
        }
    }
}
