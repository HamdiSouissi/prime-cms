<?php

declare(strict_types=1);

namespace Prime\Cache;

use Closure;
use JsonException;
use Prime\Hooks\Hooks;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Session;

/**
 * Full-page cache for anonymous public `GET`/`HEAD` responses (`storage/cache/pages/`).
 *
 * Strict safety rules:
 * - Only anonymous `GET`/`HEAD` requests (no session, remember-me, or auth cookies).
 * - Only HTTP 200 responses without `Set-Cookie`, `Cache-Control: private/no-store/no-cache`,
 *   `X-Robots-Tag: noindex`, or `<meta name="robots" content="...noindex...">`.
 * - Query string whitelist: ONLY `page` is allowed (`?page=2`); any other query parameter bypasses.
 * - Never caches admin, install, preview, customizer preview, or search pages.
 * - Plugin filter `cache.page.cacheable` can disable caching for any request/response.
 * - Emits `X-Prime-Cache: HIT|MISS|BYPASS` and supports `ETag` + `304 Not Modified`.
 */
final class PageCache
{
    /**
     * Query parameter whitelist for public cached pages.
     *
     * @var list<string>
     */
    private const ALLOWED_QUERY_PARAMS = ['page'];

    /**
     * Cookie names indicating an authenticated or stateful admin session.
     *
     * @var list<string>
     */
    private const AUTH_COOKIES = [
        Session::DEFAULT_NAME,
        'prime_remember',
        'prime_auth',
        'PHPSESSID',
    ];

    /**
     * @var Closure(): int
     */
    private readonly Closure $clock;

    private bool $dirInitialized = false;

    /**
     * @param (Closure(): int)|null $clock Optional deterministic Unix timestamp clock
     */
    public function __construct(
        private readonly string $directory,
        private bool $enabled = true,
        private int $defaultTtl = 600,
        private readonly ?Hooks $hooks = null,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function defaultTtl(): int
    {
        return max(10, $this->defaultTtl);
    }

    public function setDefaultTtl(int $ttl): void
    {
        $this->defaultTtl = max(10, $ttl);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Determine whether an incoming HTTP request is eligible for page caching.
     */
    public function isCacheableRequest(Request $request): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $method = strtoupper($request->method());
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        // Never cache authenticated requests carrying Authorization headers or auth cookies.
        if ($request->header('authorization') !== null) {
            return false;
        }

        foreach (self::AUTH_COOKIES as $cookieName) {
            $val = $request->cookie($cookieName);
            if ($val !== null && $val !== '') {
                return false;
            }
        }

        $path = $this->normalizePath($request->path());
        if (
            $path === '/admin'
            || str_starts_with($path, '/admin/')
            || $path === '/install'
            || str_starts_with($path, '/install/')
            || $path === '/recherche'
            || str_starts_with($path, '/recherche/')
            || $path === '/search'
            || str_starts_with($path, '/search/')
        ) {
            return false;
        }

        // Strict query whitelist: only `page` (positive integer) is allowed.
        $query = $request->query();
        foreach ($query as $name => $value) {
            if (!in_array((string) $name, self::ALLOWED_QUERY_PARAMS, true)) {
                return false;
            }
            if (!is_scalar($value) || preg_match('/^[1-9][0-9]*$/', (string) $value) !== 1) {
                return false;
            }
        }

        if ($this->hooks !== null) {
            $allowed = $this->hooks->apply('cache.page.cacheable', true, $request, null);
            if ($allowed === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine whether an outgoing HTTP response may be stored in the page cache.
     */
    public function isCacheableResponse(Request $request, Response $response): bool
    {
        if (!$this->isCacheableRequest($request)) {
            return false;
        }

        if ($response->status() !== 200) {
            return false;
        }

        if ($response->hasHeader('Set-Cookie')) {
            return false;
        }

        $cacheControl = strtolower((string) ($response->header('Cache-Control') ?? ''));
        if (
            str_contains($cacheControl, 'private')
            || str_contains($cacheControl, 'no-store')
            || str_contains($cacheControl, 'no-cache')
        ) {
            return false;
        }

        $robotsHeader = strtolower((string) ($response->header('X-Robots-Tag') ?? ''));
        if (str_contains($robotsHeader, 'noindex')) {
            return false;
        }

        $body = $response->body();
        if (preg_match('/<meta\b[^>]*name\s*=\s*["\']robots["\'][^>]*content\s*=\s*["\'][^"\']*noindex/i', $body) === 1
            || preg_match('/<meta\b[^>]*content\s*=\s*["\'][^"\']*noindex[^"\']*["\'][^>]*name\s*=\s*["\']robots["\']/i', $body) === 1
        ) {
            return false;
        }

        if ($this->hooks !== null) {
            $allowed = $this->hooks->apply('cache.page.cacheable', true, $request, $response);
            if ($allowed === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to serve a cached response (or `304 Not Modified` on matching `If-None-Match`).
     */
    public function lookup(Request $request): ?Response
    {
        if (!$this->isCacheableRequest($request)) {
            return null;
        }

        $file = $this->filePathFor($request);
        if (!is_file($file)) {
            return null;
        }

        $entry = $this->readEntry($file);
        if ($entry === null) {
            return null;
        }

        if ($entry['expires_at'] <= ($this->clock)()) {
            @unlink($file);

            return null;
        }

        $etag = $entry['etag'];
        $headers = $this->filterHeaders($entry['headers'], ['etag', 'x-prime-cache', 'set-cookie']);

        if ($this->etagMatches($request->header('if-none-match'), $etag)) {
            return new Response('', 304, [
                'ETag' => $etag,
                'X-Prime-Cache' => 'HIT',
                'Cache-Control' => $headers['Cache-Control'] ?? 'public, max-age=0, must-revalidate',
            ]);
        }

        $body = strtoupper($request->method()) === 'HEAD' ? '' : $entry['body'];

        return new Response($body, 200, array_merge(
            $headers,
            ['ETag' => $etag, 'X-Prime-Cache' => 'HIT'],
        ));
    }

    /**
     * Store a cacheable response and return it enriched with `ETag` and `X-Prime-Cache: MISS`.
     */
    public function store(Request $request, Response $response, ?int $ttl = null): Response
    {
        if (!$this->isCacheableResponse($request, $response)) {
            return $response->withHeader('X-Prime-Cache', 'BYPASS');
        }

        $this->ensureDirectory();

        $body = $response->body();
        $etag = '"' . sha1($body) . '"';
        $headers = $this->filterHeaders($response->headers(), ['etag', 'x-prime-cache', 'set-cookie']);
        $headers['ETag'] = $etag;

        $page = $request->query('page');
        $effectiveTtl = max(10, $ttl ?? $this->defaultTtl);
        $record = [
            'path' => $this->normalizePath($request->path()),
            'page' => is_scalar($page) ? (int) $page : 1,
            'expires_at' => ($this->clock)() + $effectiveTtl,
            'etag' => $etag,
            'headers' => $headers,
            'body' => $body,
        ];

        try {
            $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return $response->withHeader('X-Prime-Cache', 'BYPASS');
        }

        if (!$this->atomicWrite($this->filePathFor($request), $json)) {
            return $response->withHeader('X-Prime-Cache', 'BYPASS');
        }

        $enriched = $response
            ->withHeader('ETag', $etag)
            ->withHeader('X-Prime-Cache', 'MISS');

        if ($this->etagMatches($request->header('if-none-match'), $etag)) {
            return new Response('', 304, [
                'ETag' => $etag,
                'X-Prime-Cache' => 'MISS',
                'Cache-Control' => $headers['Cache-Control'] ?? 'public, max-age=0, must-revalidate',
            ]);
        }

        return $enriched;
    }

    /**
     * Purge cached pages matching exact normalized paths OR path prefixes (for paginated archives).
     *
     * @param list<string> $exactPaths Normalized paths (e.g. `/blog/my-post`, `/`)
     * @param list<string> $prefixPaths Path prefixes to match paginated/archive pages (e.g. `/page/`, `/blog/page/`)
     * @return int Number of purged page files
     */
    public function purgePaths(array $exactPaths = [], array $prefixPaths = []): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $exactSet = [];
        foreach ($exactPaths as $p) {
            $exactSet[$this->normalizePath($p)] = true;
        }

        $prefixes = [];
        foreach ($prefixPaths as $prefix) {
            $norm = rtrim($this->normalizePath($prefix), '/') . '/';
            $prefixes[] = $norm;
        }

        $purged = 0;
        $files = glob($this->directory . '/*.page') ?: [];
        foreach ($files as $file) {
            $entry = $this->readEntry($file);
            if ($entry === null) {
                @unlink($file);
                continue;
            }

            $entryPath = $entry['path'];
            $matched = isset($exactSet[$entryPath]);
            if (!$matched) {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($entryPath, $prefix) || $entryPath . '/' === $prefix) {
                        $matched = true;
                        break;
                    }
                }
            }

            if ($matched && @unlink($file)) {
                $purged++;
            }
        }

        if ($purged > 0) {
            $this->hooks?->emit('cache.purged', [
                'scope' => 'targeted',
                'paths' => array_keys($exactSet),
                'prefixes' => $prefixes,
                'count' => $purged,
            ]);
        }

        return $purged;
    }

    /**
     * Purge every cached public page and emit the `cache.purged` hook.
     */
    public function purgeAll(): int
    {
        if (!is_dir($this->directory)) {
            $this->hooks?->emit('cache.purged', ['scope' => 'pages', 'count' => 0]);

            return 0;
        }

        $purged = 0;
        $files = glob($this->directory . '/*.page') ?: [];
        foreach ($files as $file) {
            if (is_file($file) && @unlink($file)) {
                $purged++;
            }
        }

        $this->hooks?->emit('cache.purged', ['scope' => 'pages', 'count' => $purged]);

        return $purged;
    }

    /**
     * Remove expired cached pages.
     */
    public function pruneExpired(): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $now = ($this->clock)();
        $pruned = 0;

        $files = glob($this->directory . '/*.page') ?: [];
        foreach ($files as $file) {
            $entry = $this->readEntry($file);
            if ($entry === null || $entry['expires_at'] <= $now) {
                if (@unlink($file)) {
                    $pruned++;
                }
            }
        }

        return $pruned;
    }

    /**
     * Return active cached page count and total disk bytes.
     *
     * @return array{entries: int, bytes: int}
     */
    public function stats(): array
    {
        if (!is_dir($this->directory)) {
            return ['entries' => 0, 'bytes' => 0];
        }

        $now = ($this->clock)();
        $entries = 0;
        $bytes = 0;

        $files = glob($this->directory . '/*.page') ?: [];
        foreach ($files as $file) {
            $entry = $this->readEntry($file);
            if ($entry === null || $entry['expires_at'] <= $now) {
                continue;
            }
            $entries++;
            $size = @filesize($file);
            if (is_int($size) && $size > 0) {
                $bytes += $size;
            }
        }

        return ['entries' => $entries, 'bytes' => $bytes];
    }

    /**
     * Compute the deterministic cache key for a public request.
     */
    public function cacheKeyFor(Request $request): string
    {
        $path = $this->normalizePath($request->path());
        $query = $request->query();
        $page = isset($query['page']) && is_scalar($query['page']) ? (string) $query['page'] : '';
        $host = strtolower(trim((string) ($request->header('host') ?? 'default')));

        return $host . '|' . $path . ($page !== '' ? '?page=' . $page : '');
    }

    private function filePathFor(Request $request): string
    {
        return rtrim($this->directory, '/\\') . '/' . hash('sha256', $this->cacheKeyFor($request)) . '.page';
    }

    private function normalizePath(string $path): string
    {
        $trimmed = '/' . trim($path, '/');

        return $trimmed === '//' ? '/' : $trimmed;
    }

    /**
     * Drop response headers (case-insensitively) that must never be replayed from the cache.
     *
     * @param array<string, string> $headers
     * @param list<string>          $lowercasedNames
     *
     * @return array<string, string>
     */
    private function filterHeaders(array $headers, array $lowercasedNames): array
    {
        $blocked = array_flip($lowercasedNames);
        $result = [];

        foreach ($headers as $name => $value) {
            if (isset($blocked[strtolower((string) $name)])) {
                continue;
            }
            $result[(string) $name] = $value;
        }

        return $result;
    }

    private function etagMatches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || trim($ifNoneMatch) === '') {
            return false;
        }

        if (trim($ifNoneMatch) === '*') {
            return true;
        }

        $candidates = array_map('trim', explode(',', $ifNoneMatch));
        foreach ($candidates as $candidate) {
            $normalized = str_starts_with($candidate, 'W/') ? substr($candidate, 2) : $candidate;
            if (hash_equals($etag, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{path: string, page: int, expires_at: int, etag: string, headers: array<string, string>, body: string}|null
     */
    private function readEntry(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (
            !is_array($decoded)
            || !is_string($decoded['path'] ?? null)
            || !is_int($decoded['expires_at'] ?? null)
            || !is_string($decoded['etag'] ?? null)
            || !is_array($decoded['headers'] ?? null)
            || !is_string($decoded['body'] ?? null)
        ) {
            return null;
        }

        $headers = [];
        foreach ($decoded['headers'] as $name => $val) {
            if (is_string($name) && is_string($val)) {
                $headers[$name] = $val;
            }
        }

        return [
            'path' => $decoded['path'],
            'page' => is_int($decoded['page'] ?? null) ? $decoded['page'] : 1,
            'expires_at' => $decoded['expires_at'],
            'etag' => $decoded['etag'],
            'headers' => $headers,
            'body' => $decoded['body'],
        ];
    }

    private function atomicWrite(string $targetPath, string $contents): bool
    {
        $tmpPath = @tempnam($this->directory, 'ptmp_');
        if (!is_string($tmpPath)) {
            return false;
        }

        if (@file_put_contents($tmpPath, $contents, LOCK_EX) === false) {
            @unlink($tmpPath);

            return false;
        }

        @chmod($tmpPath, 0640);

        if (!@rename($tmpPath, $targetPath)) {
            @unlink($tmpPath);

            return false;
        }

        return true;
    }

    private function ensureDirectory(): void
    {
        if ($this->dirInitialized && is_dir($this->directory)) {
            return;
        }

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0750, true);
        }

        $htaccess = rtrim($this->directory, '/\\') . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n");
        }

        $index = rtrim($this->directory, '/\\') . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }

        $this->dirInitialized = true;
    }
}
