<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Database\Exception\MigrationException;
use Prime\Database\Migrations\Migration;
use Prime\Database\Migrations\Migrator;
use Prime\Database\Schema\Schema;
use Prime\Hooks\Hooks;

final class MigratorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/prime_mig_unit_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testDiscoversCoreMigrationsAndAppliesMigrationsPathsFilter(): void
    {
        $pluginMigDir = $this->tempDir . '/plugin_migrations';
        mkdir($pluginMigDir, 0755, true);
        file_put_contents(
            $pluginMigDir . '/2026_11_16_000001_create_plugin_demo_table.php',
            "<?php\ndeclare(strict_types=1);\nreturn new class () extends Prime\\Database\\Migrations\\Migration {\n    public function up(Prime\\Database\\Schema\\Schema \$schema): void {}\n    public function down(Prime\\Database\\Schema\\Schema \$schema): void {}\n};\n",
        );
        // Non-matching helper file should be ignored
        file_put_contents($pluginMigDir . '/README.md', '# ignored');

        $hooks = new Hooks();
        $hooks->filter('migrations.paths', static function (array $paths) use ($pluginMigDir): array {
            $paths[] = $pluginMigDir;

            return $paths;
        });

        $conn = new Connection(['database' => 'prime_test', 'prefix' => 'pc_']);
        $migrator = new Migrator($conn, new Schema($conn), [dirname(__DIR__, 3) . '/database/migrations'], $hooks);

        $discovered = $migrator->discover();

        $this->assertArrayHasKey('2026_11_15_000001_create_settings_table', $discovered);
        $this->assertArrayHasKey('2026_11_15_000002_create_users_table', $discovered);
        $this->assertArrayHasKey('2026_11_15_000003_add_auth_columns_to_users_table', $discovered);
        $this->assertArrayHasKey('2026_11_15_000004_create_roles_and_permissions_tables', $discovered);
        $this->assertArrayHasKey('2026_11_15_000005_create_auth_security_tables', $discovered);
        $this->assertArrayHasKey('2026_11_15_000006_seed_default_roles', $discovered);
        $this->assertArrayHasKey('2026_11_15_000007_add_source_to_migrations_table', $discovered);
        $this->assertArrayHasKey('2026_11_15_000008_create_plugins_table', $discovered);
        $this->assertArrayHasKey('2026_11_15_000009_sanitize_legacy_plugin_errors', $discovered);
        $this->assertArrayHasKey('2026_11_15_000010_create_entries_table', $discovered);
        $this->assertArrayHasKey('2026_11_15_000011_create_entry_meta_table', $discovered);
        $this->assertArrayHasKey('2026_11_15_000012_create_revisions_table', $discovered);
        $this->assertArrayHasKey('2026_11_15_000013_create_terms_tables', $discovered);
        $this->assertArrayHasKey('2026_11_15_000014_create_entry_locks_table', $discovered);
        $this->assertArrayHasKey('2026_11_15_000015_create_media_folders_table', $discovered);
        $this->assertArrayHasKey('2026_11_15_000016_create_media_table', $discovered);
        $this->assertArrayHasKey('2026_11_15_000017_create_media_usage_table', $discovered);
        $this->assertArrayHasKey('2026_11_15_000018_add_avatar_media_id_to_users_table', $discovered);
        $this->assertArrayHasKey('2026_11_15_000019_create_redirects_table', $discovered);
        $this->assertArrayHasKey('2026_11_15_000020_create_menus_tables', $discovered);
        $this->assertArrayHasKey('2026_11_15_000021_create_zones_and_zone_media_usage_tables', $discovered);
        $this->assertArrayHasKey('2026_11_15_000022_add_themes_manage_capability', $discovered);
        $this->assertArrayHasKey('2026_11_16_000001_create_plugin_demo_table', $discovered);
        $this->assertCount(23, $discovered);

        // Verify each core migration file returns a valid Migration instance.
        foreach ($discovered as $path) {
            $instance = require $path;
            $this->assertInstanceOf(Migration::class, $instance);
        }

        // MySQL advisory lock names must never exceed 64 characters.
        $this->assertTrue(strlen($migrator->lockName()) <= 64);
    }

    public function testDuplicateMigrationNameAcrossDirectoriesThrowsException(): void
    {
        $dirA = $this->tempDir . '/a';
        $dirB = $this->tempDir . '/b';
        mkdir($dirA, 0755, true);
        mkdir($dirB, 0755, true);

        $file = '2026_11_15_120000_duplicate_test.php';
        file_put_contents($dirA . '/' . $file, "<?php\n");
        file_put_contents($dirB . '/' . $file, "<?php\n");

        $conn = new Connection();
        $migrator = new Migrator($conn, new Schema($conn), [$dirA, $dirB]);

        $this->expectException(MigrationException::class);
        $migrator->discover();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($dir);
    }
}
