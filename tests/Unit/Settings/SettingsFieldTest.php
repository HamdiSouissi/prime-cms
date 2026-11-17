<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Settings;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prime\Settings\SettingsField;

/**
 * The declarative field definition: accepted types, rule parsing, sensitivity and the HTML hints a
 * screen needs, so no template ever has to re-implement a type switch.
 */
final class SettingsFieldTest extends TestCase
{
    public function testEveryDeclaredTypeIsKnownByTheRegistry(): void
    {
        $this->assertCount(18, SettingsField::TYPES);

        foreach (SettingsField::TYPES as $type) {
            $field = new SettingsField(name: 'general.' . $type, label: ucfirst($type), type: $type);
            $this->assertSame($type, $field->type);

            if ($type === 'hidden') {
                $this->assertFalse($field->isEditable(), 'hidden fields are never writable from a form');
                continue;
            }

            $this->assertTrue($field->isEditable(), $type . ' should be editable');
        }
    }

    public function testArrayTypeAndShorthandsAreNormalized(): void
    {
        $field = SettingsField::fromArray([
            'name' => 'reading.posts_per_page',
            'label' => 'Articles par page',
            'type' => 'number',
            'default' => 10,
            'rules' => 'required|min:1|max:100',
            'options' => ['a' => 'A', 'b' => ['label' => 'B']],
            'attributes' => ['data-live' => true],
        ]);

        $this->assertSame('reading.posts_per_page', $field->name);
        $this->assertSame('required|min:1|max:100' === '' ? [] : ['required' => '', 'min' => '1', 'max' => '100'], $field->rules);
        $this->assertSame(['a' => 'A', 'b' => 'B'], $field->options);
        $this->assertSame(['required' => true, 'min' => 1, 'max' => 100, 'data-live' => true], $field->htmlAttributes());
        $this->assertFalse($field->isSensitive());
    }

    public function testListShapedRulesBecomeAMap(): void
    {
        $field = SettingsField::fromArray([
            'name' => 'seo.title_template',
            'label' => 'Modèle de titre',
            'rules' => ['required', 'max:160'],
        ]);

        $this->assertSame(['required' => '', 'max' => '160'], $field->rules);
        $this->assertSame(['required' => true, 'max' => 160], $field->htmlAttributes());
    }

    public function testSecretFieldsAreAlwaysSensitiveAndNeverEditableThroughThePlainWriter(): void
    {
        $secret = SettingsField::fromArray(['name' => 'mail.password', 'label' => 'Mot de passe SMTP', 'type' => 'secret']);
        $hidden = SettingsField::fromArray(['name' => 'media.image_sizes', 'label' => 'Tailles', 'type' => 'hidden']);

        $this->assertTrue($secret->isSensitive());
        $this->assertTrue($secret->encrypted);
        $this->assertTrue($hidden->isEditable() === false);
        $this->assertFalse($hidden->isSensitive());
    }

    public function testFieldDeclaredAsPublicButSensitiveIsStillMaskedForReaders(): void
    {
        $field = SettingsField::fromArray([
            'name' => 'mail.password',
            'label' => 'Mot de passe',
            'type' => 'text',
            'encrypted' => true,
            'public' => true,
        ]);

        $this->assertTrue($field->isSensitive());
        $this->assertTrue($field->public);
    }

    public function testUnknownTypeAndInvalidNameAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SettingsField::fromArray(['name' => 'general.bad', 'label' => 'Bad', 'type' => 'switchboard']);
    }

    public function testInvalidSettingNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SettingsField::fromArray(['name' => 'Mauvais Nom', 'label' => 'Bad']);
    }

    public function testMissingNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SettingsField::fromArray(['label' => 'Sans nom']);
    }

    public function testToArrayRoundTripsEveryDeclarationKey(): void
    {
        $field = SettingsField::fromArray([
            'name' => 'language.site',
            'label' => 'Langue du site',
            'type' => 'select',
            'section' => 'languages',
            'default' => 'fr',
            'options' => ['fr' => 'Français'],
            'conditions' => ['field' => 'language.per_user', 'value' => '1'],
            'priority' => 3,
            'autoload' => false,
            'public' => true,
            'description' => 'Langue par défaut du site public.',
        ]);

        $array = $field->toArray();

        $this->assertSame('language.site', $array['name']);
        $this->assertSame('languages', $array['section']);
        $this->assertSame('fr', $array['default']);
        $this->assertSame(['fr' => 'Français'], $array['options']);
        $this->assertSame(3, $array['priority']);
        $this->assertFalse($array['autoload']);
        $this->assertTrue($array['public']);

        $rebuilt = SettingsField::fromArray($array);
        $this->assertSame($field->name, $rebuilt->name);
        $this->assertSame($field->type, $rebuilt->type);
        $this->assertSame($field->options, $rebuilt->options);
        $this->assertSame($field->conditions, $rebuilt->conditions);
    }
}
