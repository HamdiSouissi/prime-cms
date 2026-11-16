<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Prime\I18n\LanguageChecker;
use Prime\I18n\Translator;
use Prime\Validation\Validator;

final class LanguageCheckerTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/prime_lang_check_' . uniqid('', true);
        mkdir($this->fixture . '/lang/fr', 0750, true);
        mkdir($this->fixture . '/lang/en', 0750, true);
        mkdir($this->fixture . '/views', 0750, true);
    }

    protected function tearDown(): void
    {
        // Explicit directories instead of a `GLOB_BRACE` glob: the brace flag is not portable.
        foreach ([$this->fixture . '/lang/fr', $this->fixture . '/lang/en', $this->fixture . '/views'] as $dir) {
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        @rmdir($this->fixture . '/lang');

        @rmdir($this->fixture);
        Validator::setTranslator(null);
    }

    private function write(string $relative, string $contents): void
    {
        file_put_contents($this->fixture . '/' . $relative, $contents);
    }

    private function checker(): LanguageChecker
    {
        return new LanguageChecker(
            langPath: $this->fixture . '/lang',
            reference: 'fr',
            scanPaths: [$this->fixture . '/views'],
        );
    }

    public function testDetectsMissingEmptyAndPlaceholderDriftAgainstTheReferenceLocale(): void
    {
        $this->write('lang/fr/core.php', "<?php\nreturn ['a' => 'Alpha :x', 'b' => 'Bravo', 'c' => 'Vide', 'd' => 'un|:count'];");
        $this->write('lang/en/core.php', "<?php\nreturn ['a' => 'Alpha :y', 'c' => '   ', 'd' => 'one'];");

        $report = $this->checker()->compare();

        $this->assertSame(
            ['missing' => ['core.b'], 'extra' => [], 'placeholders' => ['core.a', 'core.d'], 'empty' => ['core.c']],
            $report['en'],
        );
        $this->assertSame(['en', 'fr'], $this->checker()->locales());
    }

    public function testReportsCatalogueErrorsAndUnknownKeysUsedByTemplates(): void
    {
        $this->write('lang/fr/core.php', "<?php\nreturn ['known' => 'Connnu'];");
        $this->write('lang/en/core.php', "<?php\nreturn ['known' => 'Known'];");
        $this->write(
            'views/page.php',
            "<div><?= t('core.known') ?><?= t('core.typo_key') ?></div>\n<p>Texte en français non traduit</p>\n",
        );

        $report = $this->checker()->report();

        $this->assertFalse($report['ok']);
        $this->assertSame([], $report['catalogue']['en']['missing']);
        $this->assertArrayHasKey('core.typo_key', $report['unknown']);
        $this->assertStringContainsString('page.php:', $report['unknown']['core.typo_key'][0]);
        $this->assertNotEmpty($report['hardcoded']);
        $this->assertStringContainsString('Texte en français', $report['hardcoded'][0]['text']);
        $this->assertNotEmpty($report['warnings']);
    }

    public function testIgnoresUnrelatedCallsAndReportsUnusedKeysOnlyWhenUsageIsDetected(): void
    {
        $this->write('lang/fr/core.php', "<?php\nreturn ['used' => 'Utilisé', 'idle' => 'Inutilisé'];");
        $this->write('lang/en/core.php', "<?php\nreturn ['used' => 'Used', 'idle' => 'Unused'];");
        $this->write('views/loop.php', "<?php\n\$items = array_filter(\$all);\$out = str_replace('a', 'b', \$x);\$d = date('Y-m-d H:i:s');\necho t('core.used');\n");

        $report = $this->checker()->report();

        $this->assertSame([], $report['unknown']);
        $this->assertSame(1, $report['used']);
        $this->assertSame(['core.idle'], $report['unused']);
    }

    public function testPluginCatalogueIsResolvedThroughItsNamespace(): void
    {
        $this->write('lang/fr/core.php', "<?php\nreturn ['known' => 'Connu'];");
        $this->write('lang/en/core.php', "<?php\nreturn ['known' => 'Known'];");
        $pluginLang = $this->fixture . '/plugins/hello-prime/lang';
        mkdir($pluginLang . '/fr', 0750, true);
        mkdir($pluginLang . '/en', 0750, true);
        $this->write('plugins/hello-prime/lang/fr/messages.php', "<?php\nreturn ['views' => ['title' => 'Bonjour', 'count' => ':count message']];");
        $this->write('plugins/hello-prime/lang/en/messages.php', "<?php\nreturn ['views' => ['title' => 'Hello', 'count' => ':count message']];");
        $this->write('views/plugin.php', "<p><?= t('plugin::hello-prime.views.title') ?><?= t('plugin::hello-prime.views.missing') ?></p>\n");

        $checker = new LanguageChecker(
            langPath: $this->fixture . '/lang',
            reference: 'fr',
            scanPaths: [$this->fixture . '/views', $this->fixture . '/plugins'],
        );

        $report = $checker->report();

        $this->assertArrayHasKey('plugin::hello-prime.views.title', $checker->flatten('fr'));
        $this->assertArrayHasKey('plugin::hello-prime.views.title', $checker->flatten('en'));
        $this->assertSame(['plugin::hello-prime.views.missing'], array_keys($report['unknown']));
        $this->assertContains('plugin::hello-prime.views.count', $report['unused']);
        $this->assertSame([], $report['catalogue']['en']['missing']);
        $this->assertSame([], $report['catalogue']['en']['placeholders']);

        $this->removeFixtureTree($this->fixture . '/plugins');
    }

    private function removeFixtureTree(string $dir): void
    {
        foreach (glob($dir . '/*/*/*.php') ?: [] as $file) {
            @unlink((string) $file);
        }
        foreach (glob($dir . '/*/*', GLOB_ONLYDIR) ?: [] as $localeDir) {
            @rmdir((string) $localeDir);
        }
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $langDir) {
            @rmdir((string) $langDir);
        }
        @rmdir($dir);
    }

    public function testRealPrimeCatalogueIsConsistent(): void
    {
        $checker = new LanguageChecker(
            langPath: dirname(__DIR__, 3) . '/resources/lang',
            reference: Translator::DEFAULT_LOCALE,
            scanPaths: [dirname(__DIR__, 3) . '/resources/lang'],
        );

        $report = $checker->report();

        $this->assertSame(['en', 'fr'], $report['locales']);
        $this->assertSame([], $report['errors']);
        $this->assertSame([], $report['unknown']);
        $this->assertArrayHasKey('en', $report['catalogue']);
        $this->assertSame([], $report['catalogue']['en']['missing']);
        $this->assertSame([], $report['catalogue']['en']['empty']);
        $this->assertSame([], $report['catalogue']['en']['placeholders']);
        $this->assertGreaterThan(900, count($checker->flatten('fr')));
    }

    public function testValidatorUsesTranslatedMessagesAndKeepsExplicitOverrides(): void
    {
        $translator = new Translator('en', 'en', [dirname(__DIR__, 3) . '/resources/lang']);
        Validator::setTranslator($translator);

        $result = Validator::make(['title' => 'ab', 'email' => 'nope'], [
            'title' => 'required|min:5',
            'email' => 'required|email',
        ]);

        $errors = $result->errors();
        $this->assertSame('The title field must contain at least 5 characters.', $errors['title']);
        $this->assertSame('Please enter a valid email address.', $errors['email']);

        $overridden = Validator::make(
            ['title' => 'ab'],
            ['title' => 'min:5'],
            messages: ['title.min' => 'Trop court !'],
            labels: ['title' => 'Titre'],
        );
        $this->assertSame('Trop court !', $overridden->errors()['title']);

        // Numeric and string variants of the same rule resolve differently.
        $numeric = Validator::make(['age' => 3], ['age' => 'min:18']);
        $this->assertSame('The age field must be greater than or equal to 18.', $numeric->errors()['age']);

        Validator::setTranslator(null);
        $french = Validator::make(['age' => 3], ['age' => 'min:18']);
        $this->assertSame('Le champ « age » doit être supérieur ou égal à 18.', $french->errors()['age']);
    }

    public function testValidatorAppliesFieldLabelsToTranslatedTemplates(): void
    {
        Validator::setTranslator(new Translator('fr', 'en', [dirname(__DIR__, 3) . '/resources/lang']));

        $result = Validator::make(
            ['pseudo' => ''],
            ['pseudo' => 'required'],
            labels: ['pseudo' => 'Identifiant'],
        );

        $this->assertSame('Le champ « Identifiant » est obligatoire.', $result->errors()['pseudo']);
    }
}
