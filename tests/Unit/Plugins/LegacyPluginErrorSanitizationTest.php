<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Plugins;

use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Schema;
use Prime\Plugins\PluginManager;
use Prime\Tests\Support\MysqlEmulatingPdo;

final class LegacyPluginErrorSanitizationTest extends TestCase
{
    public function testSanitizeStoredErrorMessageKeepsPluginRelativePathAndHidesServerPaths(): void
    {
        // Legacy error from Docker /var/www/html inside plugin directory -> relative path kept
        $sanitized1 = PluginManager::sanitizeStoredErrorMessage(
            'hello-prime',
            'RuntimeException: Boom (/var/www/html/content/plugins/hello-prime/src/Plugin.php:42)',
        );
        $this->assertSame('RuntimeException: Boom (src/Plugin.php:42)', $sanitized1);
        $this->assertStringNotContainsString('/var/www/html', $sanitized1);

        // Legacy error pointing outside the plugin -> location omitted
        $sanitized2 = PluginManager::sanitizeStoredErrorMessage(
            'hello-prime',
            'Error: Kernel failed (/home/deploy/prime-cms/src/Kernel/Application.php:99)',
        );
        $this->assertSame('Error: Kernel failed', $sanitized2);
        $this->assertStringNotContainsString('/home/deploy', $sanitized2);

        // Windows path inside plugin -> normalized relative path
        $sanitized3 = PluginManager::sanitizeStoredErrorMessage(
            'demo-plugin',
            'FatalError: Syntax error (C:\\inetpub\\prime\\content\\plugins\\demo-plugin\\src\\Boot.php:12)',
        );
        $this->assertSame('FatalError: Syntax error (src/Boot.php:12)', $sanitized3);
        $this->assertStringNotContainsString('C:\\inetpub', $sanitized3);

        // PHP namespaces are preserved intact
        $sanitized4 = PluginManager::sanitizeStoredErrorMessage(
            'hello-prime',
            'Class HelloPrime\\Plugin not found in /var/www/html/content/plugins/hello-prime/src/Plugin.php',
        );
        $this->assertStringContainsString('HelloPrime\\Plugin', $sanitized4);
        $this->assertStringContainsString('src/Plugin.php', $sanitized4);
        $this->assertStringNotContainsString('/var/www/html', $sanitized4);
    }

    public function testMigration000009SanitizesLegacyPluginErrorRows(): void
    {
        $pdo = new MysqlEmulatingPdo('slug');
        $conn = new Connection(['prefix' => 'pc_'], pdo: $pdo);
        $schema = new Schema($conn);

        /** @var Migration $createPlugins */
        $createPlugins = require dirname(__DIR__, 3) . '/database/migrations/2026_11_15_000008_create_plugins_table.php';
        $createPlugins->up($schema);

        $now = '2026-11-15 10:00:00';
        $conn->table('plugins')->insert([
            'slug' => 'legacy-broken',
            'version' => '1.0.0',
            'status' => 'errored',
            'manifest_hash' => str_repeat('a', 64),
            'last_error' => 'RuntimeException: Crash (/var/www/html/content/plugins/legacy-broken/src/Plugin.php:27)',
            'errored_at' => $now,
            'installed_at' => $now,
            'activated_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $conn->table('plugins')->insert([
            'slug' => 'legacy-external',
            'version' => '1.0.0',
            'status' => 'errored',
            'manifest_hash' => str_repeat('b', 64),
            'last_error' => 'Error: Failed in /srv/app/config/secret.php (/srv/app/src/Kernel/App.php:10)',
            'errored_at' => $now,
            'installed_at' => $now,
            'activated_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var Migration $cleanupMigration */
        $cleanupMigration = require dirname(__DIR__, 3) . '/database/migrations/2026_11_15_000009_sanitize_legacy_plugin_errors.php';
        $cleanupMigration->up($schema);

        $broken = $conn->table('plugins')->where('slug', '=', 'legacy-broken')->first();
        $external = $conn->table('plugins')->where('slug', '=', 'legacy-external')->first();

        $this->assertNotNull($broken);
        $this->assertSame('RuntimeException: Crash (src/Plugin.php:27)', $broken['last_error']);

        $this->assertNotNull($external);
        $this->assertStringNotContainsString('/srv/app', (string) $external['last_error']);
    }
}
