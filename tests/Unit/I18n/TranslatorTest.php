<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Prime\Cache\ArrayCache;
use Prime\Hooks\Hooks;
use Prime\I18n\Translator;

final class TranslatorTest extends TestCase
{
    private string $langPath;

    private string $overridePath;

    /** @var list<string> */
    private array $namespacePaths = [];

    protected function setUp(): void
    {
        $this->langPath = dirname(__DIR__, 3) . '/resources/lang';
        $this->overridePath = sys_get_temp_dir() . '/prime_lang_override_' . uniqid('', true);
        mkdir($this->overridePath . '/fr', 0750, true);
        mkdir($this->overridePath . '/en', 0750, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->namespacePaths as $dir) {
            foreach (glob($dir . '/*/*.php') ?: [] as $file) {
                @unlink((string) $file);
            }
            foreach ([$dir . '/fr', $dir . '/en'] as $locale) {
                @rmdir($locale);
            }
            @rmdir($dir);
            @rmdir(dirname($dir));
        }

        foreach (glob($this->overridePath . '/*/*.php') ?: [] as $file) {
            @unlink((string) $file);
        }

        foreach ([$this->overridePath . '/fr', $this->overridePath . '/en', $this->overridePath] as $dir) {
            @rmdir((string) $dir);
        }

        Translator::setInstance(null);
    }

    private function translator(string $locale = 'fr', ?Hooks $hooks = null): Translator
    {
        return new Translator($locale, Translator::FALLBACK_LOCALE, [$this->langPath], $hooks);
    }

    /**
     * Create a fake extension shipping `lang/{locale}/messages.php` and return its lang root.
     */
    private function extensionLang(string $kind, string $slug, array $catalogues): string
    {
        $root = sys_get_temp_dir() . '/prime_ext_' . uniqid('', true) . '/' . $kind . '/' . $slug . '/lang';
        foreach ($catalogues as $locale => $messages) {
            if (!is_dir($root . '/' . $locale)) {
                mkdir($root . '/' . $locale, 0750, true);
            }
            file_put_contents($root . '/' . $locale . '/messages.php', "<?php\nreturn " . var_export($messages, true) . ';');
        }

        $this->namespacePaths[] = $root;

        return $root;
    }

    public function testResolvesNestedKeysAndFallsBackToTheCoreGroup(): void
    {
        $translator = $this->translator();

        $this->assertSame('Enregistrer', $translator->translate('core.actions.save'));
        $this->assertSame('Enregistrer', $translator->translate('actions.save'));
        $this->assertSame('Tableau de bord', $translator->translate('admin.menu.dashboard'));
        $this->assertSame('Page introuvable', $translator->translate('site.not_found.title'));
    }

    public function testMissingKeysReturnTheKeyAndAreReported(): void
    {
        $hooks = new Hooks();
        $reported = [];
        $hooks->on('translations.missing', static function (string $key, string $locale) use (&$reported): void {
            $reported[] = $locale . ':' . $key;
        });

        $translator = $this->translator('fr', $hooks);
        $this->assertSame('core.does.not_exist', $translator->translate('core.does.not_exist'));
        $this->assertSame(['fr:core.does.not_exist'], $reported);
        $this->assertSame(['fr:core.does.not_exist'], $translator->missingKeys());

        $translator->resetMissingKeys();
        $this->assertSame([], $translator->missingKeys());
    }

    public function testFallsBackToEnglishWhenTheActiveLocaleHasNoEntry(): void
    {
        $translator = $this->translator('de');

        $this->assertSame('Save', $translator->translate('core.actions.save'));
        $this->assertTrue($translator->has('core.actions.save', 'de'));
    }

    public function testReplacesNamedAndPositionalPlaceholders(): void
    {
        file_put_contents(
            $this->overridePath . '/fr/core.php',
            "<?php\nreturn ['greet' => 'Bonjour :name, vous avez :count message.', 'sprintf' => 'Site %s en %s'];",
        );

        $translator = new Translator('fr', 'en', [$this->langPath, $this->overridePath]);

        $this->assertSame(
            'Bonjour Claire, vous avez 3 message.',
            $translator->translate('core.greet', ['name' => 'Claire', 'count' => 3]),
        );
        $this->assertSame('Site Aurora en français', $translator->translate('core.sprintf', ['Aurora', 'français']));
        $this->assertSame(
            'Bonjour, vous avez 0 message.',
            $translator->translate('core.greet', ['name' => '', 'count' => 0]),
        );
    }

    public function testChoiceUsesFrenchAndEnglishPluralRules(): void
    {
        $translator = $this->translator('fr');

        $this->assertSame('1 résultat', $translator->choice('core.pagination.results', 1));
        $this->assertSame('12 résultats', $translator->choice('core.pagination.results', 12));
        $this->assertSame('0 résultat', $translator->choice('core.pagination.results', 0));

        $english = $this->translator('en');
        $this->assertSame('1 media item', $english->choice('admin.media.count', 1));
        $this->assertSame('4 media items', $english->choice('admin.media.count', 4));
    }

    public function testThemesAndPluginsCanExtendOrOverrideTheCatalogue(): void
    {
        file_put_contents(
            $this->overridePath . '/fr/admin.php',
            "<?php\nreturn ['menu' => ['dashboard' => 'Console']];",
        );
        file_put_contents(
            $this->overridePath . '/fr/hello-prime.php',
            "<?php\nreturn ['title' => 'Bonjour le monde'];",
        );

        $translator = new Translator('fr', 'en', [$this->langPath, $this->overridePath]);

        $this->assertSame('Console', $translator->translate('admin.menu.dashboard'));
        $this->assertSame('Bonjour le monde', $translator->translate('hello-prime.title'));
        $this->assertSame('Médias', $translator->translate('admin.menu.media'));
    }

    public function testPluginAndThemeCataloguesAreAddressedThroughTheirNamespace(): void
    {
        $plugin = $this->extensionLang('plugins', 'hello-prime', [
            'fr' => ['views' => ['public' => ['greeting' => 'Bonjour depuis Hello Prime']], 'count' => 'un message|:count messages'],
            'en' => ['views' => ['public' => ['greeting' => 'Hello from Hello Prime']]],
        ]);
        $theme = $this->extensionLang('themes', 'aurora', [
            'fr' => ['footer' => ['credit' => 'Propulsé par Prime CMS']],
        ]);

        $translator = new Translator('fr', 'en', [$this->langPath, $plugin, $theme]);

        $this->assertSame('Bonjour depuis Hello Prime', $translator->translate('plugin::hello-prime.views.public.greeting'));
        $this->assertSame('Propulsé par Prime CMS', $translator->translate('theme::aurora.footer.credit'));
        $this->assertSame('3 messages', $translator->choice('plugin::hello-prime.count', 3));
        // Unknown namespaces and unknown slugs must not resolve through a core group.
        $this->assertSame('plugin::inconnu.title', $translator->translate('plugin::inconnu.title'));
        $this->assertSame('other::hello-prime.views.public.greeting', $translator->translate('other::hello-prime.views.public.greeting'));
    }

    public function testRuntimeRegistrationIsMergedWithoutTouchingFiles(): void
    {
        $translator = $this->translator();
        $translator->register('fr', ['mail' => ['custom' => ['subject' => 'Sujet personnalisé']]]);

        $this->assertSame('Sujet personnalisé', $translator->translate('mail.custom.subject'));
    }

    public function testLocaleSwitchingEmitsHookAndNormalizesTags(): void
    {
        $hooks = new Hooks();
        $changes = [];
        $hooks->on('locale.changed', static function (string $locale, string $previous) use (&$changes): void {
            $changes[] = $previous . '->' . $locale;
        });

        $translator = $this->translator('fr', $hooks);
        $translator->setLocale('en-US');

        $this->assertSame(['fr->en'], $changes);
        $this->assertSame('en', $translator->locale());
        $this->assertSame('en-US', $translator->localeTag('en-US'));
        $this->assertSame('fr-FR', $translator->localeTag('fr'));
        $this->assertSame('fr', $translator->normalize('fr_FR'));
        $this->assertTrue($translator->isSupported('fr'));
        $this->assertFalse($translator->isSupported('xx'));
    }

    public function testCatalogueExposesBothLocalesAndFlattenedKeys(): void
    {
        $translator = $this->translator();

        $this->assertSame(['en', 'fr'], $translator->availableLocales());
        $this->assertSame(['admin', 'core', 'mail', 'site', 'validation'], $translator->groups('fr'));

        $messages = $translator->messages('fr');
        $this->assertGreaterThan(500, count($messages));
        $this->assertSame('Enregistrer', $messages['core.actions.save']);
        $this->assertSame($messages, $translator->messages('fr'));
    }

    public function testMergedTreesAreStoredInTheDataCacheAndServedFromIt(): void
    {
        $cache = new ArrayCache();
        $messages = (new Translator('fr', Translator::FALLBACK_LOCALE, [$this->langPath], null, $cache))->messages('fr');
        $this->assertGreaterThan(500, count($messages));

        // A single merged tree is stored, and a second translator sharing that cache returns
        // the very same flattened catalogue instead of re-parsing every language file.
        $this->assertSame(1, $cache->stats()['entries'], 'Exactly one merged tree must be cached.');

        $second = new Translator('fr', Translator::FALLBACK_LOCALE, [$this->langPath], null, $cache);
        $this->assertSame($messages, $second->messages('fr'));
    }

    public function testHelpersUseTheBoundTranslatorInstance(): void
    {
        Translator::setInstance($this->translator('en'));

        $this->assertSame('Save', t('core.actions.save'));
        $this->assertSame('en', current_locale());
        $this->assertSame('1 result', tc('core.pagination.results', 1));
    }
}
