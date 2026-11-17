<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Security\Encrypter;
use Prime\Settings\Settings;
use Prime\Tests\Support\MysqlEmulatingPdo;
use RuntimeException;

/**
 * Typed readers, bulk writes, encrypted secrets and the memoization helper of the settings store.
 */
final class SettingsTypedAccessorsTest extends TestCase
{
    private Settings $settings;

    protected function setUp(): void
    {
        $this->settings = $this->settingsWith();
    }

    public function testBooleanReaderAcceptsEveryShapeAFormCanSubmit(): void
    {
        $this->settings->set('flags.enabled', true);
        $this->settings->set('flags.disabled', '0');
        $this->settings->set('flags.yes', 'yes');
        $this->settings->set('flags.no', 'no');
        $this->settings->set('flags.one', 1);
        $this->settings->set('flags.json', 'false');

        $this->assertTrue($this->settings->getBool('flags.enabled'));
        $this->assertFalse($this->settings->getBool('flags.disabled'));
        $this->assertTrue($this->settings->getBool('flags.yes'));
        $this->assertFalse($this->settings->getBool('flags.no'));
        $this->assertTrue($this->settings->getBool('flags.one'));
        $this->assertFalse($this->settings->getBool('flags.json'));
        $this->assertTrue($this->settings->getBool('flags.missing', true));
        $this->assertFalse($this->settings->getBool('flags.missing'));
    }

    public function testIntegerReaderClampsToTheDeclaredRange(): void
    {
        $this->settings->set('security.password_min_length', 2);
        $this->assertSame(8, $this->settings->getInt('security.password_min_length', 10, 8, 64));

        $this->settings->set('security.password_min_length', 99);
        $this->assertSame(64, $this->settings->getInt('security.password_min_length', 10, 8, 64));

        $this->settings->set('security.password_min_length', '14');
        $this->assertSame(14, $this->settings->getInt('security.password_min_length', 10, 8, 64));

        $this->settings->set('security.password_min_length', 'forty-two');
        $this->assertSame(10, $this->settings->getInt('security.password_min_length', 10, 8, 64));

        $this->assertSame(30, $this->settings->getInt('reading.posts_per_page', 30));
    }

    public function testScalarReadersFallBackToTheDefaultInsteadOfThrowing(): void
    {
        $this->settings->set('perf.ratio', 0.75);
        $this->settings->set('site.name', 'Prime');
        $this->settings->set('media.image_sizes', ['thumbnail' => ['width' => 150]]);
        $this->settings->set('reading.broken', 'not-an-array');

        $this->assertSame(0.75, $this->settings->getFloat('perf.ratio'));
        $this->assertSame(1.0, $this->settings->getFloat('perf.missing', 1.0));
        $this->assertSame('Prime', $this->settings->getString('site.name'));
        $this->assertSame('fallback', $this->settings->getString('site.other', 'fallback'));
        $this->assertSame('0.75', $this->settings->getString('perf.ratio'));
        $this->assertSame(['thumbnail' => ['width' => 150]], $this->settings->getArray('media.image_sizes'));
        $this->assertSame([], $this->settings->getArray('reading.broken'));
        $this->assertSame(['fallback'], $this->settings->getArray('reading.missing', ['fallback']));
    }

    public function testSetManyWritesOnlyTheValuesThatActuallyChanged(): void
    {
        $this->settings->set('site.name', 'Prime');

        $changed = $this->settings->setMany([
            'site.name' => 'Prime',
            'site.tagline' => 'Un CMS',
            'general.timezone' => 'Europe/Paris',
        ]);

        $this->assertSame(['site.tagline', 'general.timezone'], $changed);
        $this->assertSame('Un CMS', $this->settings->get('site.tagline'));
        $this->assertSame('Europe/Paris', $this->settings->get('general.timezone'));
        $this->assertSame([], $this->settings->setMany(['site.name' => 'Prime']));
    }

    public function testSecretsRoundTripAndNeverStorePlaintext(): void
    {
        $settings = $this->settingsWith('base64:' . base64_encode(random_bytes(32)));

        $settings->setSecret('mail.password', 's3crét smtp');

        $this->assertSame('s3crét smtp', $settings->getSecret('mail.password'));
        $this->assertTrue($settings->isSecret('mail.password'));

        $stored = $settings->get('mail.password');
        $this->assertIsArray($stored);
        $this->assertArrayHasKey(Settings::ENCRYPTED_MARKER, (array) $stored);
        $this->assertStringNotContainsString('s3crét', (string) json_encode($stored));

        $settings->setSecret('mail.password', '   ');
        $this->assertFalse($settings->isSecret('mail.password'));
        $this->assertSame('fallback', $settings->getSecret('mail.password', 'fallback'));
    }

    public function testSecretReaderToleratesAPlainValueStoredByAnOlderRelease(): void
    {
        $this->settings->set('mail.password', 'plain-legacy');

        $this->assertSame('plain-legacy', $this->settings->getSecret('mail.password'));
        $this->assertFalse($this->settings->isSecret('mail.password'));
    }

    public function testWritingASecretWithoutAnEncrypterFailsLoudly(): void
    {
        $this->expectException(RuntimeException::class);

        $this->settings->setSecret('mail.password', 'nope');
    }

    public function testRememberReturnsTheLoaderValueWithoutACacheDriver(): void
    {
        $calls = 0;

        $value = $this->settings->remember('menu:primary', 60, static function () use (&$calls): array {
            ++$calls;

            return ['items' => 3];
        });

        $this->assertSame(['items' => 3], $value);
        $this->assertSame(1, $calls);
        $this->assertSame(['items' => 3], $this->settings->rememberArray('menu:primary', 60, static fn (): array => ['items' => 3]));
        $this->assertSame(['fallback'], $this->settings->rememberArray('menu:scalar', 60, static fn (): string => 'nope', ['fallback']));
    }

    private function settingsWith(?string $appKey = null): Settings
    {
        $pdo = new MysqlEmulatingPdo('name');
        $pdo->exec(
            'CREATE TABLE `pc_settings` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT UNIQUE NOT NULL,'
            . ' `value` TEXT NULL, `autoload` INTEGER NOT NULL DEFAULT 1, `created_at` TEXT NOT NULL, `updated_at` TEXT NOT NULL)',
        );

        $connection = new Connection(['prefix' => 'pc_'], hooks: new Hooks(), debug: false, pdo: $pdo);

        return new Settings($connection, null, null, $appKey === null ? null : new Encrypter($appKey));
    }
}
