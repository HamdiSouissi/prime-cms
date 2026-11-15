<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prime\Support\Str;

final class StrTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function slugProvider(): array
    {
        return [
            'french accents and apostrophe' => ["L'été à Paris : café & crème brûlée !", 'l-ete-a-paris-cafe-creme-brulee'],
            'ligatures oe and ae' => ['Cœur d’œuvre et Æther', 'coeur-d-oeuvre-et-aether'],
            'german eszett and umlauts' => ['Straße über München', 'strasse-uber-munchen'],
            'italian accents' => ['Perché città così più bella', 'perche-citta-cosi-piu-bella'],
            'spanish and portuguese' => ['Año nuevo em São Paulo', 'ano-nuevo-em-sao-paulo'],
            'emojis and symbols stripped' => ['🚀 Lancement v0.6.0 — 100% natif ✨', 'lancement-v0-6-0-100-natif'],
            'multiple spaces and dashes collapsed' => ['  Hello ---   World___Test  ', 'hello-world-test'],
            'only punctuation returns empty' => ['!!! ??? — …', ''],
            'empty string returns empty' => ['', ''],
        ];
    }

    #[DataProvider('slugProvider')]
    public function testSlugGeneratesCleanAsciiUrls(string $input, string $expected): void
    {
        $this->assertSame($expected, Str::slug($input));
    }

    public function testSlugRespectsMaxLengthWithoutTrailingSeparator(): void
    {
        $slug = Str::slug('un titre vraiment tres long pour tester la troncature propre', '-', 18);
        $this->assertLessThanOrEqual(18, strlen($slug));
        $this->assertFalse(str_ends_with($slug, '-'));
        $this->assertSame('un-titre-vraiment', $slug);
    }

    public function testPlainTextWordCountReadingTimeAndExcerpt(): void
    {
        $html = '<p>Bonjour <strong>Prime CMS</strong>&nbsp;!</p><p>Un éditeur <em>moderne</em>.</p>';
        $plain = Str::plainText($html);

        $this->assertSame('Bonjour Prime CMS ! Un éditeur moderne.', $plain);
        $this->assertSame(6, Str::wordCount($plain));
        $this->assertSame(1, Str::readingTimeMinutes($plain));
        $this->assertSame(0, Str::readingTimeMinutes('   '));

        $longText = str_repeat('Prime ', 60);
        $this->assertSame(60, Str::wordCount($longText));
        $this->assertSame(1, Str::readingTimeMinutes($longText, 200));
        $this->assertSame(2, Str::readingTimeMinutes(str_repeat('mot ', 250), 200));

        $excerpt = Str::excerpt('Prime CMS est un système de gestion de contenu moderne et extensible.', 35);
        $this->assertLessThanOrEqual(36, mb_strlen($excerpt));
        $this->assertTrue(str_ends_with($excerpt, '…'));
    }
}
