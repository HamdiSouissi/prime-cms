<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Http\Request;
use Prime\I18n\Translator;
use Prime\Security\Csrf;
use Prime\Tests\Support\ApiTestFixture;

/**
 * Contrat d'acceptation de l'API REST, phase 10 — étape 3.
 *
 * Écrit par Claude (revue). **Ce fichier ne doit pas être modifié** : le code de `src/`
 * (et les vues) doivent le faire passer tel quel.
 */
final class ApiContractStep3Test extends TestCase
{
    use ApiTestFixture;

    // --- Capacités effectives = utilisateur ∩ jeton ---------------------------------------

    public function testAnAdministratorCanIssueATokenLimitedToSpecificAbilities(): void
    {
        $app = $this->createApp(null);
        $issued = $this->tokens($app)->issue($this->user($app, 1), 'lecture', ['content.view']);
        $auth = $this->tokens($app)->authenticate($issued['token']);

        $this->assertNotNull($auth);
        $this->assertSame(['content.view'], $auth['abilities'], 'un administrateur (capacité *) peut émettre un jeton restreint');
    }

    public function testAContributorAskingForEverythingOnlyGetsTheirOwnCapabilities(): void
    {
        $app = $this->createApp(null);
        $issued = $this->tokens($app)->issue($this->user($app, 2), 'tout', ['*']);
        $auth = $this->tokens($app)->authenticate($issued['token']);

        $this->assertNotNull($auth);
        $this->assertNotContains('settings.manage', $auth['abilities']);
        $this->assertContains('content.edit_own', $auth['abilities']);
    }

    public function testMeReportsTheEffectiveAbilitiesOfTheToken(): void
    {
        $app = $this->createApp(null);
        $issued = $this->tokens($app)->issue($this->user($app, 1), 'lecture', ['content.view']);

        $data = $this->json($this->get($app, '/api/v1/me', ['Authorization' => 'Bearer ' . $issued['token']]))['data'] ?? [];

        $this->assertSame(['content.view'], $data['capabilities'] ?? null);
        $this->assertArrayNotHasKey('password_hash', $data);
    }

    public function testARestrictedTokenCannotReadDraftsEvenForAnAdministrator(): void
    {
        $app = $this->createApp(null);
        $issued = $this->tokens($app)->issue($this->user($app, 1), 'lecture', ['content.view']);

        $titles = array_column($this->json($this->get($app, '/api/v1/content/post?status=draft', ['Authorization' => 'Bearer ' . $issued['token']]))['data'] ?? [], 'title');

        $this->assertNotContains('Brouillon admin', $titles, 'les règles de EntryService doivent recevoir les capacités du jeton, pas celles du compte');
    }

    // --- Pagination réelle ------------------------------------------------------------------

    public function testPaginationTotalsCountAllMatchingEntriesNotOnlyThePage(): void
    {
        $app = $this->createApp(null);
        $insert = $this->db($app);
        for ($i = 1; $i <= 25; ++$i) {
            $insert->statement(
                "INSERT INTO pc_entries (type, status, title, slug, content, content_html, content_text, author_id, published_at, created_at, updated_at) VALUES ('post', 'publish', ?, ?, '{}', '', '', 1, ?, ?, ?)",
                ['Article ' . $i, 'article-' . $i, '2026-11-14 10:00:00', '2026-11-14 10:00:00', '2026-11-14 10:00:00'],
            );
        }

        $response = $this->get($app, '/api/v1/content/post?per_page=10&page=3');
        $json = $this->json($response);

        $this->assertSame(26, $json['meta']['total'] ?? null);
        $this->assertSame(3, $json['meta']['total_pages'] ?? null);
        $this->assertCount(6, $json['data'] ?? []);
        $this->assertSame('26', $response->header('X-Total-Count'));
        $this->assertNull($json['links']['next'] ?? null);
        $this->assertNotNull($json['links']['prev'] ?? null);
    }

    // --- Messages traduits ------------------------------------------------------------------

    public function testErrorMessagesComeFromTheCatalogueInTheCallersLanguage(): void
    {
        $app = $this->createApp(null);
        $translator = $app->get(Translator::class);
        $fr = $translator->translate('api.errors.not_found', [], 'fr');
        $en = $translator->translate('api.errors.not_found', [], 'en');
        $this->assertNotSame('api.errors.not_found', $fr, 'clé api.errors.not_found absente du catalogue fr');
        $this->assertNotSame($fr, $en, 'la clé doit être traduite en anglais');

        $anonymous = $this->json($this->get($app, '/api/v1/content/post/introuvable'));
        $this->assertSame($fr, $anonymous['error']['message'] ?? null);

        $this->db($app)->statement("UPDATE pc_users SET locale = 'en' WHERE id = 1");
        $issued = $this->tokens($app)->issue($this->user($app, 1), 'en', ['*']);
        $english = $this->json($this->get($app, '/api/v1/content/post/introuvable', ['Authorization' => 'Bearer ' . $issued['token']]));
        $this->assertSame($en, $english['error']['message'] ?? null);
    }

    // --- Dernière utilisation ---------------------------------------------------------------

    public function testAnAuthenticatedRequestRecordsLastUse(): void
    {
        $app = $this->createApp(null);
        $issued = $this->tokens($app)->issue($this->user($app, 1), 'client', ['*']);

        $this->get($app, '/api/v1/me', ['Authorization' => 'Bearer ' . $issued['token']]);

        $row = $this->db($app)->selectOne('SELECT last_used_at FROM pc_api_tokens WHERE id = ?', [$issued['id']]);
        $this->assertNotNull($row['last_used_at'] ?? null);
    }

    // --- Session de l'administration --------------------------------------------------------

    public function testAnAdminSessionCanReadTheApiWithoutAToken(): void
    {
        $app = $this->createApp('admin');

        $response = $this->get($app, '/api/v1/me');

        $this->assertSame(200, $response->status(), $response->body());
        $this->assertSame(1, $this->json($response)['data']['id'] ?? null);
    }

    // --- Écran « Mon profil › Jetons d'API » ------------------------------------------------

    public function testTheProfileOffersATokenCreationForm(): void
    {
        $app = $this->createApp('admin');

        $response = $app->get(\Prime\Http\Kernel::class)->handle(Request::create('GET', '/admin/profile', query: ['tab' => 'api-tokens']));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('action="/admin/profile/api-tokens"', $response->body());
        $this->assertStringContainsString('name="abilities[]"', $response->body());
    }

    public function testCreatingATokenFromTheProfileShowsItExactlyOnce(): void
    {
        $app = $this->createApp('admin');
        $kernel = $app->get(\Prime\Http\Kernel::class);

        $created = $kernel->handle(Request::create('POST', '/admin/profile/api-tokens', body: [
            Csrf::FIELD_NAME => $app->get(Csrf::class)->token(),
            'name' => 'Intégration',
            'abilities' => ['content.view'],
            'expires_days' => '90',
        ]));
        $this->assertSame(302, $created->status());
        $this->assertStringStartsWith('/admin/profile', (string) $created->header('Location'));

        $first = $kernel->handle(Request::create('GET', '/admin/profile', query: ['tab' => 'api-tokens']))->body();
        $this->assertMatchesRegularExpression('/data-prime-new-token[^>]*>\s*[^<]*pcat_[A-Za-z0-9]{8}_[A-Za-z0-9]{40}/', $first);
        $this->assertStringContainsString('Intégration', $first);

        $second = $kernel->handle(Request::create('GET', '/admin/profile', query: ['tab' => 'api-tokens']))->body();
        $this->assertDoesNotMatchRegularExpression('/pcat_[A-Za-z0-9]{8}_[A-Za-z0-9]{40}/', $second, 'le jeton ne doit plus jamais être affiché');
        $this->assertStringContainsString('Intégration', $second);

        $row = $this->db($app)->selectOne("SELECT expires_at, abilities FROM pc_api_tokens WHERE name = 'Intégration'");
        $this->assertNotNull($row['expires_at'] ?? null);
        $this->assertSame(['content.view'], json_decode((string) $row['abilities'], true));
    }

    public function testATokenCreatedWithoutCsrfIsRefused(): void
    {
        $app = $this->createApp('admin');

        $response = $app->get(\Prime\Http\Kernel::class)->handle(Request::create('POST', '/admin/profile/api-tokens', body: ['name' => 'x', 'abilities' => ['content.view']]));

        $this->assertSame(419, $response->status());
        $this->assertNull($this->db($app)->selectOne("SELECT id FROM pc_api_tokens WHERE name = 'x'"));
    }

    public function testAUserCanRevokeTheirOwnTokenButNotSomeoneElses(): void
    {
        $app = $this->createApp('admin');
        $mine = $this->tokens($app)->issue($this->user($app, 1), 'mine', ['*']);
        $theirs = $this->tokens($app)->issue($this->user($app, 2), 'theirs', ['*']);
        $kernel = $app->get(\Prime\Http\Kernel::class);
        $csrf = $app->get(Csrf::class)->token();

        $ok = $kernel->handle(Request::create('POST', '/admin/profile/api-tokens/' . $mine['id'] . '/revoke', body: [Csrf::FIELD_NAME => $csrf]));
        $this->assertSame(302, $ok->status());
        $this->assertNull($this->tokens($app)->authenticate($mine['token']));

        $denied = $kernel->handle(Request::create('POST', '/admin/profile/api-tokens/' . $theirs['id'] . '/revoke', body: [Csrf::FIELD_NAME => $csrf]));
        $this->assertSame(404, $denied->status());
        $this->assertNotNull($this->tokens($app)->authenticate($theirs['token']));
    }
}
