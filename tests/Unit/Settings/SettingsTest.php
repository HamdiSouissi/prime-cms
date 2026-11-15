<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Settings\Settings;
use Prime\Tests\Support\MysqlEmulatingPdo;

final class SettingsTest extends TestCase
{
    private Connection $connection;
    private Hooks $hooks;
    private Settings $settings;

    protected function setUp(): void
    {
        $pdo = new MysqlEmulatingPdo('name');

        $this->hooks = new Hooks();
        $this->connection = new Connection(['prefix' => 'pc_'], hooks: $this->hooks, debug: true, pdo: $pdo);
        $this->connection->statement(
            'CREATE TABLE `pc_settings` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT UNIQUE NOT NULL, `value` TEXT NULL, `autoload` INTEGER NOT NULL DEFAULT 1, `created_at` TEXT NOT NULL, `updated_at` TEXT NOT NULL)',
        );
        $this->settings = new Settings($this->connection, $this->hooks);
    }

    public function testPreservesAllJsonTypesAndFiresSettingsUpdatedEvent(): void
    {
        $events = [];
        $this->hooks->on('settings.updated', static function (string $name, mixed $old, mixed $new) use (&$events): void {
            $events[] = ['name' => $name, 'old' => $old, 'new' => $new];
        });

        $this->settings->set('site_name', 'Prime CMS');
        $this->settings->set('items_per_page', 25);
        $this->settings->set('tax_rate', 0.2);
        $this->settings->set('maintenance_mode', false);
        $this->settings->set('features', ['builder' => true, 'commerce' => false]);
        $this->settings->set('nullable_opt', null);
        $this->settings->set('lazy_secret', 'non-autoload', autoload: false);

        // Fresh Settings instance to verify database persistence + type fidelity.
        $fresh = new Settings($this->connection, $this->hooks);

        $this->assertSame('Prime CMS', $fresh->get('site_name'));
        $this->assertSame(25, $fresh->get('items_per_page'));
        $this->assertSame(0.2, $fresh->get('tax_rate'));
        $this->assertFalse($fresh->get('maintenance_mode'));
        $this->assertSame(['builder' => true, 'commerce' => false], $fresh->get('features'));
        $this->assertTrue($fresh->has('nullable_opt'));
        $this->assertNull($fresh->get('nullable_opt', 'fallback'));
        $this->assertSame('non-autoload', $fresh->get('lazy_secret'));
        $this->assertSame('default-val', $fresh->get('missing_key', 'default-val'));

        // Update existing setting and verify old/new values passed to hook.
        $this->settings->set('site_name', 'Mon Site');
        $lastEvent = end($events);
        $this->assertIsArray($lastEvent);
        $this->assertSame('site_name', $lastEvent['name']);
        $this->assertSame('Prime CMS', $lastEvent['old']);
        $this->assertSame('Mon Site', $lastEvent['new']);

        $this->assertTrue($this->settings->delete('items_per_page'));
        $this->assertFalse($this->settings->has('items_per_page'));
        $this->assertFalse($this->settings->delete('items_per_page'));
    }

    public function testAutoloadLoadsAllAutoloadedSettingsInASingleQuery(): void
    {
        $this->settings->set('a', 'one', autoload: true);
        $this->settings->set('b', 2, autoload: true);
        $this->settings->set('c', true, autoload: true);
        $this->settings->set('lazy', 'later', autoload: false);

        $fresh = new Settings($this->connection);
        $this->connection->clearQueryLog();

        $this->assertSame('one', $fresh->get('a'));
        $this->assertSame(2, $fresh->get('b'));
        $this->assertTrue($fresh->get('c'));
        $this->assertTrue($fresh->has('a'));

        // Only ONE SQL query should have been executed for all autoloaded reads!
        $this->assertCount(1, $this->connection->queryLog());

        // Reading a non-autoloaded setting triggers 1 additional lookup, then caches it.
        $this->assertSame('later', $fresh->get('lazy'));
        $this->assertSame('later', $fresh->get('lazy'));
        $this->assertCount(2, $this->connection->queryLog());
    }
}
