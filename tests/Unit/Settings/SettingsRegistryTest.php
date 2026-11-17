<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Settings;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Settings\Settings;
use Prime\Settings\SettingsOptionSources;
use Prime\Settings\SettingsRegistry;
use Prime\Tests\Support\MysqlEmulatingPdo;

/**
 * The declarative catalogue: sections, ordering, extension overrides, freezing, and what a browser
 * is allowed to see.
 */
final class SettingsRegistryTest extends TestCase
{
    private SettingsRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new SettingsRegistry();
    }

    public function testCoreConfigDeclaresEveryScreenAndFieldWithoutConflict(): void
    {
        $config = require dirname(__DIR__, 3) . '/config/settings.php';

        $registry = new SettingsRegistry();
        $registry->register($config['sections'], $config['fields'], 'core');

        $this->assertArrayHasKey('security', $registry->sections());
        $this->assertArrayHasKey('performance', $registry->sections());
        $this->assertArrayHasKey('scheduler', $registry->sections());

        $fields = $registry->fields();
        $this->assertGreaterThan(60, count($fields));

        foreach ($fields as $name => $field) {
            $this->assertSame($name, $field->name);
            $this->assertArrayHasKey($field->section, $registry->sections(), $name . ' points to an undeclared section');
        }

        $this->assertSame(10, $fields['security.password_min_length']->default);
        $this->assertSame(['administrator'], $fields['security.two_factor_required_roles']->default);
        $this->assertTrue($fields['mail.password']->isSensitive());
    }

    public function testFieldsAreGroupedBySectionAndOrderedByPriority(): void
    {
        $this->registry->register(['general' => ['label' => 'Général']], [
            ['name' => 'site.tagline', 'label' => 'Sous-titre', 'section' => 'general', 'priority' => 20],
            ['name' => 'site.name', 'label' => 'Titre', 'section' => 'general', 'priority' => 10],
            ['name' => 'site.extra', 'label' => 'Extra', 'section' => 'general', 'priority' => 10],
        ]);

        $this->assertSame(['site.extra', 'site.name', 'site.tagline'], array_keys($this->registry->fields('general')));
        $this->assertSame([], $this->registry->fields('reading'));
    }

    public function testAnExtensionMayRefineACoreFieldWithoutChangingItsType(): void
    {
        $this->registry->add(['name' => 'site.name', 'label' => 'Titre du site', 'type' => 'text', 'default' => 'Prime', 'section' => 'general']);
        $this->registry->add(['name' => 'site.name', 'label' => 'Nom public', 'type' => 'text', 'description' => 'Affiché dans l’en-tête']);

        $field = $this->registry->field('site.name');
        $this->assertNotNull($field);
        $this->assertSame('Nom public', $field->label);
        $this->assertSame('Affiché dans l’en-tête', $field->description);
        $this->assertSame('Prime', $field->default);
    }

    public function testChangingTheTypeOfAnExistingFieldIsRejected(): void
    {
        $this->registry->add(['name' => 'site.name', 'label' => 'Titre', 'type' => 'text']);

        $this->expectException(InvalidArgumentException::class);

        $this->registry->add(['name' => 'site.name', 'label' => 'Titre', 'type' => 'number']);
    }

    public function testUndeclaredSectionsAndInvalidIdentifiersAreGuarded(): void
    {
        $this->registry->add(['name' => 'plugin.thing', 'label' => 'Thing', 'type' => 'text', 'section' => 'plugin']);

        $this->assertArrayHasKey('plugin', $this->registry->sections());
        $this->assertSame('plugin', $this->registry->sections()['plugin']['label']);

        $this->expectException(InvalidArgumentException::class);
        $this->registry->section('Mauvaise Section');
    }

    public function testLockedRegistryRefusesLateDeclarations(): void
    {
        $this->registry->add(['name' => 'site.name', 'label' => 'Titre']);
        $this->registry->lock();

        $this->assertTrue($this->registry->isLocked());

        $this->expectException(InvalidArgumentException::class);
        $this->registry->add(['name' => 'site.other', 'label' => 'Other']);
    }

    public function testForgetOwnerRemovesOnlyTheFieldsContributedByThatExtension(): void
    {
        $this->registry->add(['name' => 'site.name', 'label' => 'Titre'], 'core');
        $this->registry->add(['name' => 'hello_prime.opt', 'label' => 'Option'], 'hello-prime');

        $this->registry->forgetOwner('hello-prime');

        $this->assertTrue($this->registry->has('site.name'));
        $this->assertFalse($this->registry->has('hello_prime.opt'));
    }

    public function testValuesApplyDefaultsForUnstoredSettingsAndHideSecrets(): void
    {
        $settings = $this->settings();
        $this->registry->register([
            'mail' => ['label' => 'E-mails'],
        ], [
            ['name' => 'mail.from_name', 'label' => 'Nom', 'type' => 'text', 'section' => 'mail', 'default' => 'Prime CMS'],
            ['name' => 'mail.from_address', 'label' => 'Adresse', 'type' => 'email', 'section' => 'mail', 'default' => 'no-reply@example.com'],
            ['name' => 'mail.password', 'label' => 'Mot de passe', 'type' => 'secret', 'section' => 'mail', 'default' => null],
        ], 'core');

        $settings->set('mail.from_name', 'Atelier Lumière');

        $values = $this->registry->values($settings, 'mail');

        $this->assertSame('Atelier Lumière', $values['mail.from_name']);
        $this->assertSame('no-reply@example.com', $values['mail.from_address']);
        $this->assertNull($values['mail.password']);
        $this->assertArrayNotHasKey('site.name', $values);

        $this->assertSame([
            'mail.from_address' => 'no-reply@example.com',
            'mail.from_name' => 'Prime CMS',
            'mail.password' => null,
        ], $this->registry->defaults('mail'));
    }

    public function testPublicValuesExposeOnlyNonSensitiveDeclaredSettings(): void
    {
        $settings = $this->settings();
        $this->registry->register([], [
            ['name' => 'site.name', 'label' => 'Titre', 'type' => 'text', 'default' => 'Prime', 'public' => true],
            ['name' => 'site.secret', 'label' => 'Clé', 'type' => 'text', 'default' => 'x', 'public' => true, 'encrypted' => true],
            ['name' => 'security.login_max_attempts', 'label' => 'Tentatives', 'type' => 'number', 'default' => 5],
        ], 'core');

        $this->assertSame(['site.name' => 'Prime'], $this->registry->publicValues($settings));
    }

    public function testLazyOptionSourcesAreResolvedOnceAndMergeWithStaticOptions(): void
    {
        $calls = 0;
        $sources = new SettingsOptionSources();
        $sources->provide('pages', static function () use (&$calls): array {
            ++$calls;

            return [7 => 'Accueil', 9 => 'Contact'];
        });

        $this->assertSame([7 => 'Accueil', 9 => 'Contact'], $sources->resolve('pages'));
        $this->assertSame([7 => 'Accueil', 9 => 'Contact'], $sources->resolve('pages'));
        $this->assertSame(1, $calls);
        $this->assertSame([], $sources->resolve('unknown'));
        $this->assertTrue($sources->has('pages'));

        $sources->flush();
        $this->registry->setSources($sources);
        $this->registry->add([
            'name' => 'reading.front_page_id',
            'label' => 'Page d’accueil',
            'type' => 'select',
            'options' => 'pages',
            'default' => 0,
        ], 'core');

        $field = $this->registry->field('reading.front_page_id');
        $this->assertNotNull($field);
        $this->assertSame('pages', $field->optionsSource);
        $this->assertSame([7 => 'Accueil', 9 => 'Contact'], $this->registry->options($field));
    }

    private function settings(): Settings
    {
        $pdo = new MysqlEmulatingPdo('name');
        $pdo->exec(
            'CREATE TABLE `pc_settings` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT UNIQUE NOT NULL,'
            . ' `value` TEXT NULL, `autoload` INTEGER NOT NULL DEFAULT 1, `created_at` TEXT NOT NULL, `updated_at` TEXT NOT NULL)',
        );

        return new Settings(new Connection(['prefix' => 'pc_'], hooks: new Hooks(), debug: false, pdo: $pdo));
    }
}
