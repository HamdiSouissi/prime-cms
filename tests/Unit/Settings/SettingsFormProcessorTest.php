<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Security\Encrypter;
use Prime\Settings\Settings;
use Prime\Settings\SettingsField;
use Prime\Settings\SettingsFormProcessor;
use Prime\Settings\SettingsRegistry;
use Prime\Tests\Support\MysqlEmulatingPdo;

/**
 * The single write path shared by the admin screens, the CLI and the REST endpoints: sanitize by
 * declared type, validate with the shared rules, protect blank secrets, then persist and notify.
 */
final class SettingsFormProcessorTest extends TestCase
{
    private SettingsRegistry $registry;

    private Settings $settings;

    private Hooks $hooks;

    protected function setUp(): void
    {
        $pdo = new MysqlEmulatingPdo('name');
        $pdo->exec(
            'CREATE TABLE `pc_settings` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT UNIQUE NOT NULL,'
            . ' `value` TEXT NULL, `autoload` INTEGER NOT NULL DEFAULT 1, `created_at` TEXT NOT NULL, `updated_at` TEXT NOT NULL)',
        );

        $this->hooks = new Hooks();
        $this->settings = new Settings(
            new Connection(['prefix' => 'pc_'], hooks: $this->hooks, debug: false, pdo: $pdo),
            $this->hooks,
            null,
            new Encrypter('base64:' . base64_encode(random_bytes(32))),
        );
        $this->registry = new SettingsRegistry();
    }

    public function testSanitizationFollowsTheDeclaredType(): void
    {
        $this->registry->register(['general' => ['label' => 'Général']], [
            ['name' => 'site.name', 'label' => 'Titre', 'type' => 'text', 'rules' => 'max:12'],
            ['name' => 'site.tagline', 'label' => 'Sous-titre', 'type' => 'text'],
            ['name' => 'site.email', 'label' => 'E-mail', 'type' => 'email'],
            ['name' => 'site.url', 'label' => 'URL', 'type' => 'url'],
            ['name' => 'reading.per_page', 'label' => 'Par page', 'type' => 'number', 'rules' => 'min:1|max:20', 'default' => 10],
            ['name' => 'general.privacy', 'label' => 'Privé', 'type' => 'toggle'],
            ['name' => 'seo.lang', 'label' => 'Langue', 'type' => 'select', 'section' => 'general', 'options' => ['fr' => 'FR', 'en' => 'EN'], 'default' => 'fr'],
            ['name' => 'media.sizes', 'label' => 'Tailles', 'type' => 'multiselect', 'options' => ['thumb' => 'V', 'hero' => 'H']],
            ['name' => 'general.night', 'label' => 'Thème', 'type' => 'checkbox'],
        ], 'core');

        $result = $this->processor()->process('general', [
            'site.name' => '  Prime <script>alert(1)</script> CMS padding extra  ',
            'site.tagline' => '',
            'site.email' => '  Admin@Example.Fr ',
            'site.url' => 'example.com/blog/',
            'reading.per_page' => '12',
            'general.privacy' => '1',
            'seo.lang' => 'de',
            'media.sizes' => ['thumb', 'ghost', 'thumb'],
            'general.night' => 'on',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(12, $this->settings->get('reading.per_page'));
        // Tags are stripped (and whitespace collapsed) *before* the declared max length is applied,
        // so what reaches the database is exactly what the browser will show.
        $this->assertSame('Prime alert(', $this->settings->get('site.name'));
        $this->assertSame('', $this->settings->get('site.tagline'));
        $this->assertSame('admin@example.fr', $this->settings->get('site.email'));
        $this->assertSame('https://example.com/blog/', $this->settings->get('site.url'));
        $this->assertSame([], $result['errors']);
        $this->assertTrue($this->settings->get('general.privacy'));
        // An unknown choice collapses to the declared default; since that default is already the
        // effective value, no row is written for it.
        $this->assertContains('seo.lang', $result['unchanged']);
        $this->assertSame('fr', $this->settings->get('seo.lang', 'fr'));
        $this->assertSame(['thumb'], $this->settings->get('media.sizes'));
        $this->assertTrue($this->settings->get('general.night'));
    }

    public function testOutOfRangeNumbersAreRefusedInsteadOfSilentlyClamped(): void
    {
        $this->registry->register(['reading' => ['label' => 'Lecture']], [
            ['name' => 'reading.per_page', 'label' => 'Par page', 'type' => 'number', 'section' => 'reading', 'rules' => 'required|min:1|max:20', 'default' => 10],
        ], 'core');

        $result = $this->processor()->process('reading', ['reading.per_page' => '9999']);

        $this->assertSame(['reading.per_page'], array_keys($result['errors']));
        $this->assertStringContainsString('20', $result['errors']['reading.per_page']);
        $this->assertSame([], $result['saved']);
        $this->assertFalse($this->settings->has('reading.per_page'));

        $fractional = new SettingsFormProcessor($this->registry, $this->settings, $this->hooks);
        $this->assertSame('Le champ « Ratio » doit être un nombre entier.', $fractional->validate(
            new SettingsField(name: 'reading.ratio', label: 'Ratio', type: 'number', rules: ['integer' => '', 'min' => '0.5']),
            'trois',
        ));
        $this->assertNull($fractional->validate(
            new SettingsField(name: 'reading.ratio', label: 'Ratio', type: 'number', rules: ['min' => '0.5', 'max' => '1.5']),
            0.75,
        ));
    }

    public function testUncheckedBoxesAndMissingChoiceFieldsBecomeDeterministicValues(): void
    {
        $this->registry->register(['reading' => ['label' => 'Lecture']], [
            ['name' => 'reading.comments', 'label' => 'Commentaires', 'type' => 'checkbox', 'section' => 'reading', 'default' => true],
            ['name' => 'reading.modes', 'label' => 'Modes', 'type' => 'multiselect', 'section' => 'reading', 'options' => ['a' => 'A']],
        ], 'core');

        $result = $this->processor()->process('reading', ['unrelated' => 'x']);

        $this->assertSame(['reading.comments', 'reading.modes'], $result['saved']);
        $this->assertFalse($this->settings->get('reading.comments'));
        $this->assertSame([], $this->settings->get('reading.modes'));
    }

    public function testValidationErrorsBlockTheWholeSectionAndNothingIsWritten(): void
    {
        $this->registry->register(['security' => ['label' => 'Sécurité']], [
            ['name' => 'security.min_length', 'label' => 'Longueur', 'type' => 'number', 'section' => 'security', 'rules' => 'required|min:8|max:64'],
            ['name' => 'security.email', 'label' => 'E-mail', 'type' => 'email', 'section' => 'security', 'rules' => 'required|email'],
        ], 'core');

        $result = $this->processor()->process('security', [
            'security.min_length' => '3',
            'security.email' => 'pas-un-email',
        ]);

        $this->assertArrayHasKey('security.min_length', $result['errors']);
        $this->assertArrayHasKey('security.email', $result['errors']);
        $this->assertSame([], $result['saved']);
        $this->assertFalse($this->settings->has('security.min_length'), 'a rejected submission must not persist anything');
    }

    public function testColourDateAndTimeFieldsAreValidatedByTheirFormat(): void
    {
        $this->registry->register(['design' => ['label' => 'Apparence']], [
            ['name' => 'design.accent', 'label' => 'Couleur', 'type' => 'color', 'section' => 'design', 'default' => '#101010'],
            ['name' => 'design.launch', 'label' => 'Lancement', 'type' => 'date', 'section' => 'design', 'rules' => 'required'],
            ['name' => 'design.hour', 'label' => 'Heure', 'type' => 'time', 'section' => 'design', 'rules' => 'required'],
        ], 'core');

        $invalid = $this->processor()->process('design', [
            'design.accent' => 'rouge',
            'design.launch' => '15/11/2026',
            'design.hour' => '25:00',
        ]);

        $this->assertCount(3, $invalid['errors']);

        $valid = $this->processor()->process('design', [
            'design.accent' => '#AB12CD',
            'design.launch' => '2026-11-15',
            'design.hour' => '08:30',
        ]);

        $this->assertSame([], $valid['errors']);
        $this->assertSame('#ab12cd', $this->settings->get('design.accent'));
        $this->assertSame('2026-11-15', $this->settings->get('design.launch'));
        $this->assertSame('08:30', $this->settings->get('design.hour'));
    }

    public function testSecretsAreEncryptedSkippedWhenBlankAndNeverEchoed(): void
    {
        $this->registry->register(['mail' => ['label' => 'E-mails']], [
            ['name' => 'mail.password', 'label' => 'Mot de passe SMTP', 'type' => 'secret', 'section' => 'mail'],
        ], 'core');

        $processor = $this->processor();

        $first = $processor->process('mail', ['mail.password' => 'hunter2']);
        $this->assertSame(['mail.password'], $first['saved']);
        $this->assertTrue($this->settings->isSecret('mail.password'));
        $this->assertStringNotContainsString('hunter2', (string) json_encode($this->settings->get('mail.password')));
        $this->assertSame('hunter2', $this->settings->getSecret('mail.password'));

        // A form that posts an empty secret keeps the stored value (write-only fields).
        $second = $processor->process('mail', ['mail.password' => '   ']);
        $this->assertSame([], $second['saved']);
        $this->assertSame(['mail.password'], $second['skipped']);
        $this->assertSame('hunter2', $this->settings->getSecret('mail.password'));

        $third = $processor->process('mail', []);
        $this->assertSame(['mail.password'], $third['skipped']);
        $this->assertSame('hunter2', $this->settings->getSecret('mail.password'));
    }

    public function testUnchangedValuesAreReportedAndNotRewritten(): void
    {
        $this->registry->register(['general' => ['label' => 'Général']], [
            ['name' => 'site.name', 'label' => 'Titre', 'type' => 'text', 'default' => 'Prime'],
        ], 'core');

        $this->settings->set('site.name', 'Prime');

        $result = $this->processor()->process('general', ['site.name' => 'Prime']);

        $this->assertSame([], $result['saved']);
        $this->assertSame(['site.name'], $result['unchanged']);
    }

    public function testConditionsAndOnlyFilterTheWrittenFields(): void
    {
        $this->registry->register(['general' => ['label' => 'Général']], [
            ['name' => 'site.name', 'label' => 'Titre', 'type' => 'text', 'default' => 'Prime'],
            ['name' => 'site.tagline', 'label' => 'Sous-titre', 'type' => 'text', 'default' => ''],
        ], 'core');

        $result = $this->processor()->process('general', ['site.name' => 'A', 'site.tagline' => 'B'], ['site.name']);

        $this->assertSame(['site.name'], $result['saved']);
        $this->assertFalse($this->settings->has('site.tagline'));
    }

    public function testHooksCanVetoAWriteAndRewriteAValidatedValue(): void
    {
        $this->registry->register(['general' => ['label' => 'Général']], [
            ['name' => 'site.name', 'label' => 'Titre', 'type' => 'text', 'default' => 'Prime'],
            ['name' => 'site.forbidden', 'label' => 'Interdit', 'type' => 'text', 'default' => ''],
        ], 'core');

        $this->hooks->filter('settings.validate', static fn (mixed $value, object $field): mixed => $field->name === 'site.name' ? mb_strtoupper((string) $value) : $value);
        $this->hooks->filter('settings.before_save', static function (bool $allowed, string $name): bool {
            return $name !== 'site.forbidden' ? $allowed : false;
        });

        $result = $this->processor()->process('general', ['site.name' => 'prime', 'site.forbidden' => 'nope']);

        $this->assertSame(['site.name'], $result['saved']);
        $this->assertSame(['site.forbidden'], $result['skipped']);
        $this->assertSame('PRIME', $this->settings->get('site.name'));
        $this->assertFalse($this->settings->has('site.forbidden'));

        $events = [];
        $this->hooks->on('settings.saved', static function (string $section, array $saved) use (&$events): void {
            $events[] = [$section, $saved];
        });

        $this->processor()->process('general', ['site.name' => 'Nouveau']);
        $this->assertSame([['general', ['site.name']]], $events);
    }

    public function testHiddenFieldsAreNeverWritableEvenWhenSubmitted(): void
    {
        $this->registry->register(['advanced' => ['label' => 'Avancé']], [
            ['name' => 'advanced.internal', 'label' => 'Interne', 'type' => 'hidden', 'section' => 'advanced', 'default' => 'keep'],
        ], 'core');

        $result = $this->processor()->process('advanced', ['advanced.internal' => 'tampered']);

        $this->assertSame([], $result['saved']);
        $this->assertFalse($this->settings->has('advanced.internal'));
    }

    public function testMultilineFieldsKeepNewlinesAndLoseMarkup(): void
    {
        $this->registry->register(['general' => ['label' => 'Général']], [
            ['name' => 'site.description', 'label' => 'Description', 'type' => 'textarea'],
            ['name' => 'seo.robots', 'label' => 'robots.txt', 'type' => 'code', 'section' => 'general'],
        ], 'core');

        $this->processor()->process('general', [
            'site.description' => "Ligne 1  \r\n<b>gras</b>\r\nLigne 3",
            'seo.robots' => "User-agent: *\r\nDisallow: /admin   ",
        ]);

        $this->assertSame("Ligne 1\ngras\nLigne 3", $this->settings->get('site.description'), 'plain-text areas never keep markup');
        $this->assertSame("User-agent: *\nDisallow: /admin", $this->settings->get('seo.robots'));
    }

    private function processor(): SettingsFormProcessor
    {
        return new SettingsFormProcessor($this->registry, $this->settings, $this->hooks);
    }
}
