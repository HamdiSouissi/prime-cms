<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * Static guard on `database/migrations/`, run by the unit job (no database needed).
 *
 * Two branches adding a migration on the same day is the classic merge accident: identical timestamps
 * make `ksort()` order undefined between them, and two `create()` calls for the same table break every
 * already-migrated install on the next `migrate`. The Migrator throws on a duplicate *name*, not on a
 * duplicate table, so this is the check that catches it before CI merges.
 */
final class MigrationFilesTest extends TestCase
{
    private const PATTERN = '/^(\d{4}_\d{2}_\d{2})_(\d{6})_([a-z0-9_]+)\.php$/';

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function migrations(): array
    {
        $directory = dirname(__DIR__, 3) . '/database/migrations';
        $files = scandir($directory);
        $this->assertIsArray($files, 'database/migrations/ must be readable');

        $names = array_values(array_filter($files, static fn (string $file): bool => preg_match(self::PATTERN, $file) === 1));

        return [$directory, $names];
    }

    public function testEveryFileIsNamedAfterTheCoreConvention(): void
    {
        [$directory, $names] = $this->migrations();

        foreach (scandir($directory) ?: [] as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            $this->assertSame(
                1,
                preg_match(self::PATTERN, $file),
                sprintf('Migration "%s" does not match YYYY_MM_DD_HHMMSS_slug.php; the Migrator would silently skip it.', $file),
            );
        }

        $this->assertNotEmpty($names);
    }

    public function testNoTwoMigrationsShareATimestampAndTheSequenceNeverRepeats(): void
    {
        [$directory, $names] = $this->migrations();

        /** @var array<string, string> $seen */
        $seen = [];

        foreach ($names as $file) {
            $this->assertSame(1, preg_match(self::PATTERN, $file, $m), $file);
            $stamp = $m[1] . '_' . $m[2];

            $this->assertArrayNotHasKey($stamp, $seen, sprintf(
                'Migrations "%s" and "%s" share the timestamp %s: the run order then depends on the file system.',
                $seen[$stamp] ?? '?',
                $file,
                $stamp,
            ));

            $seen[$stamp] = $file;
        }

        // Two branches appending a migration on the same day are the accident this guards: within one
        // date the sequence must stay consecutive. Renumbering a file that already shipped would silently
        // un-migrate an install that recorded the old name in `pc_migrations`.
        $previous = null;

        foreach (array_keys($seen) as $stamp) {
            $this->assertSame(1, preg_match(self::PATTERN, $stamp . '_x.php', $m), $stamp);

            [$date, $sequence] = [$m[1], (int) $m[2]];

            if ($previous !== null && $previous[0] === $date) {
                $this->assertSame(
                    $previous[1] + 1,
                    $sequence,
                    sprintf('Migration numbering jumps or repeats inside %s (%d followed by %d) — append new files, never renumber shipped ones.', $date, $previous[1], $sequence),
                );
            }

            $previous = [$date, $sequence];
        }
    }

    public function testNoTwoMigrationsCreateTheSameTable(): void
    {
        [$directory, $names] = $this->migrations();

        /** @var array<string, string> $owners */
        $owners = [];

        foreach ($names as $file) {
            $source = (string) file_get_contents($directory . '/' . $file);

            if (preg_match_all('/\$schema->create\(\s*\'([a-z0-9_]+)\'/', $source, $m) === 0) {
                continue;
            }

            foreach ($m[1] as $table) {
                $this->assertArrayNotHasKey($table, $owners, sprintf(
                    'Table "%s" is created by both "%s" and "%s".',
                    $table,
                    $owners[$table] ?? '?',
                    $file,
                ));

                $owners[$table] = $file;
            }
        }

        // The tables every install must end up with, phase 9 included.
        foreach (['settings', 'users', 'roles', 'entries', 'media', 'menus', 'redirects', 'jobs', 'mail_log'] as $table) {
            $this->assertArrayHasKey($table, $owners, sprintf('No migration creates "%s" any more.', $table));
        }
    }

    public function testEveryMigrationThatCreatesAlsoDrops(): void
    {
        [$directory, $names] = $this->migrations();

        foreach ($names as $file) {
            $source = (string) file_get_contents($directory . '/' . $file);

            if (!str_contains($source, '$schema->create(')) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/public function down\(/',
                $source,
                sprintf('"%s" creates tables but has no down(): `migrate:rollback` and `migrate:fresh` would abort on it.', $file),
            );
        }
    }
}
