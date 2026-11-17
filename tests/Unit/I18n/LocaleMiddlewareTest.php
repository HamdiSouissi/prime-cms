<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\I18n\Http\LocaleMiddleware;
use Prime\I18n\LocaleFormatter;
use Prime\I18n\Translator;
use Prime\Settings\Settings;
use Prime\Tests\Support\MysqlEmulatingPdo;

final class LocaleMiddlewareTest extends TestCase
{
    private Settings $settings;

    private Translator $translator;

    private LocaleFormatter $formatter;

    protected function setUp(): void
    {
        $pdo = new MysqlEmulatingPdo('name');
        $connection = new Connection(['prefix' => 'pc_'], hooks: new Hooks(), debug: false, pdo: $pdo);
        $connection->statement(
            'CREATE TABLE `pc_settings` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT UNIQUE NOT NULL, `value` TEXT NULL, `autoload` INTEGER NOT NULL DEFAULT 1, `created_at` TEXT NOT NULL, `updated_at` TEXT NOT NULL)',
        );

        $this->settings = new Settings($connection);
        $this->translator = new Translator('fr', 'en', [dirname(__DIR__, 3) . '/resources/lang']);
        $this->formatter = new LocaleFormatter($this->translator, 'UTC');
    }

    private function middleware(): LocaleMiddleware
    {
        return new LocaleMiddleware($this->translator, $this->formatter, $this->settings);
    }

    public function testPublicPagesUseTheConfiguredSiteLanguage(): void
    {
        $this->settings->set('language.site', 'en');

        $seen = [];
        $response = $this->middleware()->process(
            Request::create('GET', '/'),
            function (Request $request) use (&$seen): Response {
                $seen = [
                    'attribute' => $request->attribute('locale'),
                    'translation' => $this->translator->translate('site.read_more'),
                    'date' => $this->translator->locale(),
                ];

                return Response::html('ok');
            },
        );

        $this->assertSame('ok', $response->body());
        $this->assertSame('en', $seen['attribute']);
        $this->assertSame('Read more', $seen['translation']);
        $this->assertSame('en', $seen['date']);
        $this->assertSame('fr', $this->translator->locale());
    }

    public function testAdminPagesPreferTheAdminLanguageAndRestoreTheScopeAfterwards(): void
    {
        $this->settings->set('language.site', 'en');
        $this->settings->set('language.admin', 'fr');

        $captured = null;
        $response = $this->middleware()->process(
            Request::create('GET', '/admin/settings'),
            static function (Request $request) use (&$captured): Response {
                $captured = $request->attribute('locale');

                return new Response('ok');
            },
        );

        $this->assertSame('fr', $captured);
        $this->assertSame('ok', $response->body());
        $this->assertSame('fr', $this->translator->locale(), 'The locale must be restored after the response');
    }

    public function testFallsBackToTheFallbackLanguageAndIgnoresUnknownSettings(): void
    {
        $this->settings->set('language.fallback', 'en');

        $this->assertSame('en', $this->middleware()->resolveLocale(Request::create('GET', '/blog')));

        // Unknown locales are ignored in favour of the configured fallback language.
        $this->settings->set('language.site', 'zz');
        $this->assertSame('en', $this->middleware()->resolveLocale(Request::create('GET', '/blog')));

        $this->middleware()->process(
            Request::create('GET', '/blog'),
            function (): Response {
                $this->assertSame('en', $this->translator->locale());
                $this->assertSame('Save', $this->translator->translate('core.actions.save'));

                return new Response('');
            },
        );

        // A locale shipped only by a plugin is still honoured.
        $extra = sys_get_temp_dir() . '/prime_lang_de_' . uniqid('', true);
        mkdir($extra . '/de', 0750, true);
        file_put_contents($extra . '/de/core.php', "<?php\nreturn ['actions' => ['save' => 'Speichern']];");
        $this->translator->addPath($extra);
        $this->settings->set('language.site', 'de');

        $this->assertSame('de', $this->middleware()->resolveLocale(Request::create('GET', '/blog')));
        $this->middleware()->process(
            Request::create('GET', '/blog'),
            function (): Response {
                $this->assertSame('Speichern', $this->translator->translate('core.actions.save'));

                return new Response('');
            },
        );

        @unlink($extra . '/de/core.php');
        @rmdir($extra . '/de');
        @rmdir($extra);
    }

    public function testTimezoneSettingIsAppliedAndRestored(): void
    {
        $this->settings->set('general.timezone', 'Europe/Paris');

        $this->middleware()->process(
            Request::create('GET', '/'),
            function (): Response {
                $this->assertSame('Europe/Paris', $this->formatter->timezone());

                return new Response('');
            },
        );

        $this->assertSame('UTC', $this->formatter->timezone());

        $this->settings->set('general.timezone', 'Not/AZone');
        $this->middleware()->process(
            Request::create('GET', '/'),
            function (): Response {
                $this->assertSame('UTC', $this->formatter->timezone());

                return new Response('');
            },
        );
    }
}
