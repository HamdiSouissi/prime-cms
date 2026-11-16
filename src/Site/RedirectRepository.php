<?php

declare(strict_types=1);

namespace Prime\Site;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Prime\Database\Connection;

/**
 * Manages HTTP 301/302 internal URL redirects (`pc_redirects`) with:
 * - strict internal-path-only enforcement (zero open redirects)
 * - loop / self-redirect prevention
 * - automatic chain shortening (`A -> B` + `B -> C` => `A -> C`)
 * - hit counter tracking
 */
final class RedirectRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Normalize and validate an internal path (`/`-prefixed, no scheme/host, no control chars).
     *
     * @throws InvalidArgumentException When `$path` is external, protocol-relative, or malformed
     */
    public static function normalizeInternalPath(string $path): string
    {
        $trimmed = trim($path);
        if (
            $trimmed === ''
            || str_contains($trimmed, "\0")
            || str_contains($trimmed, "\r")
            || str_contains($trimmed, "\n")
            || str_contains($trimmed, '\\')
        ) {
            throw new InvalidArgumentException('Le chemin de redirection est invalide.');
        }

        // Reject any scheme (`http://`, `javascript:`, etc.) or protocol-relative URL (`//evil.com`)
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $trimmed) === 1 || str_starts_with($trimmed, '//')) {
            throw new InvalidArgumentException('Les redirections doivent pointer uniquement vers un chemin interne.');
        }

        if (!str_starts_with($trimmed, '/')) {
            $trimmed = '/' . $trimmed;
        }

        // Strip query/fragment from stored path and trim trailing slash (except root `/`)
        $pathOnly = explode('#', explode('?', $trimmed, 2)[0], 2)[0];
        $pathOnly = preg_replace('#/+#', '/', $pathOnly) ?? $pathOnly;
        if ($pathOnly !== '/') {
            $pathOnly = rtrim($pathOnly, '/');
        }

        if (str_contains($pathOnly, '..')) {
            throw new InvalidArgumentException('Le chemin de redirection ne peut pas contenir "..".');
        }

        return $pathOnly;
    }

    /**
     * Look up a redirect rule matching `$sourcePath`.
     *
     * @return ?array{id: int, source_path: string, target_path: string, status_code: int, hits: int, last_hit_at: ?string}
     */
    public function findBySource(string $sourcePath): ?array
    {
        try {
            $normalized = self::normalizeInternalPath($sourcePath);
        } catch (InvalidArgumentException) {
            return null;
        }

        $row = $this->db->table('redirects')
            ->where('source_path', '=', $normalized)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'source_path' => (string) $row['source_path'],
            'target_path' => (string) $row['target_path'],
            'status_code' => (int) ($row['status_code'] ?? 301),
            'hits' => (int) ($row['hits'] ?? 0),
            'last_hit_at' => isset($row['last_hit_at']) && $row['last_hit_at'] !== '' ? (string) $row['last_hit_at'] : null,
        ];
    }

    /**
     * Register (or update) an internal redirect while shortening chains and preventing loops.
     *
     * - If `source === target`: ignored (no-op).
     * - If `target` itself redirects to `finalTarget`, resolves `target = finalTarget` (guards against cycles).
     * - If `target === source` after resolution (A -> B -> A): removes conflicting reverse rule instead of looping.
     * - Updates any existing rows pointing to `source` so `X -> source` becomes `X -> finalTarget`.
     * - Deletes any stale rule whose `source_path === finalTarget` (e.g. slug renamed A -> B -> A).
     */
    public function registerRedirect(string $sourcePath, string $targetPath, int $statusCode = 301): bool
    {
        $source = self::normalizeInternalPath($sourcePath);
        $target = self::normalizeInternalPath($targetPath);

        if (!in_array($statusCode, [301, 302, 307, 308], true)) {
            $statusCode = 301;
        }

        if ($source === $target) {
            return false;
        }

        // Follow existing chain starting at `$target` to reach final destination (max 10 hops)
        $visited = [$source => true, $target => true];
        $finalTarget = $target;
        for ($hop = 0; $hop < 10; $hop++) {
            $next = $this->findBySource($finalTarget);
            if ($next === null) {
                break;
            }
            $candidate = $next['target_path'];
            if ($candidate === $source) {
                // Reverse redirect existed (e.g. A -> B then renamed back B -> A): remove reverse rule, target stays $target
                $this->db->table('redirects')->where('id', '=', $next['id'])->delete();
                $finalTarget = $target;
                break;
            }
            if (isset($visited[$candidate])) {
                // Cycle detected — abort safely
                return false;
            }
            $visited[$candidate] = true;
            $finalTarget = $candidate;
        }

        if ($source === $finalTarget) {
            return false;
        }

        $now = $this->nowUtc();

        $this->db->transaction(function () use ($source, $finalTarget, $statusCode, $now): void {
            // If a stale redirect starts at `$finalTarget` (because `$finalTarget` is now a live URL), remove it
            $this->db->table('redirects')
                ->where('source_path', '=', $finalTarget)
                ->delete();

            // Shorten existing chains: any rule targeting `$source` now points directly to `$finalTarget`
            $this->db->table('redirects')
                ->where('target_path', '=', $source)
                ->update([
                    'target_path' => $finalTarget,
                    'updated_at' => $now,
                ]);

            // Remove any accidental self-redirects created by chain shortening
            $table = $this->db->prefixed('redirects');
            $this->db->statement(sprintf('DELETE FROM `%s` WHERE `source_path` = `target_path`', $table));

            $existing = $this->findBySource($source);
            if ($existing !== null) {
                $this->db->table('redirects')
                    ->where('id', '=', $existing['id'])
                    ->update([
                        'target_path' => $finalTarget,
                        'status_code' => $statusCode,
                        'updated_at' => $now,
                    ]);
            } else {
                $this->db->table('redirects')->insert([
                    'source_path' => $source,
                    'target_path' => $finalTarget,
                    'status_code' => $statusCode,
                    'hits' => 0,
                    'last_hit_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        return true;
    }

    /**
     * Increment hit count and timestamp when a redirect is served.
     */
    public function recordHit(int $id): void
    {
        $table = $this->db->prefixed('redirects');
        $this->db->statement(
            sprintf('UPDATE `%s` SET `hits` = `hits` + 1, `last_hit_at` = ? WHERE `id` = ?', $table),
            [$this->nowUtc(), $id],
        );
    }

    /**
     * List recent redirects for the Permalinks admin screen.
     *
     * @return list<array{id: int, source_path: string, target_path: string, status_code: int, hits: int, last_hit_at: ?string, created_at: string}>
     */
    public function all(int $limit = 100): array
    {
        $rows = $this->db->table('redirects')
            ->orderBy('created_at', 'DESC')
            ->limit(max(1, min(500, $limit)))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'source_path' => (string) $row['source_path'],
                'target_path' => (string) $row['target_path'],
                'status_code' => (int) ($row['status_code'] ?? 301),
                'hits' => (int) ($row['hits'] ?? 0),
                'last_hit_at' => isset($row['last_hit_at']) && $row['last_hit_at'] !== '' ? (string) $row['last_hit_at'] : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $out;
    }

    public function delete(int $id): void
    {
        $this->db->table('redirects')->where('id', '=', $id)->delete();
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
