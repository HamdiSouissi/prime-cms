<?php

declare(strict_types=1);

namespace Prime\Plugins;

use Prime\Database\Connection;
use Throwable;

/**
 * Removes what a plugin leaves behind outside its own tables, settings and assets, which
 * PluginManager already handles:
 *
 * - the plugin's capabilities granted to roles and listed in API token abilities
 *   (capabilities are prefixed with the slug in snake case: `my_plugin.`),
 * - its queued jobs (handlers prefixed with `{slug}.`) and queued e-mails built from its
 *   templates (`{slug}::…`),
 * - the run stamps of its scheduled tasks (task names prefixed with `{slug}.`).
 *
 * Every step is independent and tolerant: a table that does not exist is skipped.
 */
final class PluginDataCleaner
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $schedulerStampDirectory,
    ) {
    }

    public static function capabilityPrefix(string $slug): string
    {
        return str_replace('-', '_', $slug) . '.';
    }

    /**
     * @return array{capabilities: int, tokens: int, jobs: int, stamps: int}
     */
    public function clean(string $slug): array
    {
        return [
            'capabilities' => $this->revokeCapabilities($slug),
            'tokens' => $this->stripTokenAbilities($slug),
            'jobs' => $this->deleteJobs($slug),
            'stamps' => $this->deleteSchedulerStamps($slug),
        ];
    }

    private function revokeCapabilities(string $slug): int
    {
        try {
            return $this->db->delete(
                sprintf("DELETE FROM `%s` WHERE `capability` LIKE ? ESCAPE '!'", $this->db->prefixed('role_capabilities')),
                [self::like(self::capabilityPrefix($slug))],
            );
        } catch (Throwable) {
            return 0;
        }
    }

    private function stripTokenAbilities(string $slug): int
    {
        $prefix = self::capabilityPrefix($slug);
        $updated = 0;

        try {
            $rows = $this->db->select(
                sprintf("SELECT `id`, `abilities` FROM `%s` WHERE `abilities` LIKE ? ESCAPE '!'", $this->db->prefixed('api_tokens')),
                ['%' . self::like($prefix)],
            );
        } catch (Throwable) {
            return 0;
        }

        foreach ($rows as $row) {
            $abilities = json_decode((string) ($row['abilities'] ?? '[]'), true);
            if (!is_array($abilities)) {
                continue;
            }

            $kept = array_values(array_filter($abilities, static fn (mixed $cap): bool => is_string($cap) && !str_starts_with($cap, $prefix)));
            if (count($kept) !== count($abilities)) {
                $this->db->update(
                    sprintf('UPDATE `%s` SET `abilities` = ? WHERE `id` = ?', $this->db->prefixed('api_tokens')),
                    [json_encode($kept), (int) $row['id']],
                );
                ++$updated;
            }
        }

        return $updated;
    }

    private function deleteJobs(string $slug): int
    {
        try {
            return $this->db->delete(
                sprintf("DELETE FROM `%s` WHERE `handler` LIKE ? ESCAPE '!' OR `payload` LIKE ? ESCAPE '!'", $this->db->prefixed('jobs')),
                [self::like($slug . '.'), '%"template":"' . self::like($slug . '::')],
            );
        } catch (Throwable) {
            return 0;
        }
    }

    private function deleteSchedulerStamps(string $slug): int
    {
        $deleted = 0;
        foreach (glob(rtrim($this->schedulerStampDirectory, '/\\') . DIRECTORY_SEPARATOR . $slug . '.*') ?: [] as $file) {
            if (is_file($file) && @unlink($file)) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * Escapes LIKE wildcards in a literal prefix (escape character `!`, portable across MySQL,
     * MariaDB and SQLite) and appends `%`.
     */
    private static function like(string $literal): string
    {
        return strtr($literal, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
}
