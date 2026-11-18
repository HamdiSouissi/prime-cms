<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Console\ConsoleApplication;
use Prime\Console\ConsoleOutput;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Kernel\Application;
use Prime\Scheduler\Schedule;
use Prime\Security\Csrf;
use Prime\Settings\Settings;
use Prime\Settings\SettingsRegistry;
use Prime\Tests\Support\ApiTestFixture;

/**
 * Contrat de l'étape 4 de la phase 10 (écrit par la revue, ne pas modifier).
 * Couvre : finitions de l'écran des jetons, middlewares L1 (débit, CORS, activation,
 * lecture publique, cache HTTP), réglages `api.*`, et fin de L2 (CLI, fiche utilisateur, nettoyage).
 */
final class ApiContractStep4Test extends TestCase
{
    use ApiTestFixture;

    // --- Écran « Mon profil › Jetons d'API » ------------------------------------------------

    public function testAnAdministratorCanTickEveryAbility(): void
    {
        $body = $this->page($this->createApp('admin'), '/admin/profile', ['tab' => 'api-tokens']);

        $this->assertMatchesRegularExpression('/name="abilities\[\]"[^>]*value="settings\.manage"/', $body);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="abilities\[\]"[^>]*disabled/', $body, 'un administrateur doit pouvoir cocher toutes les capacités');
    }

    public function testAContributorIsOnlyOfferedTheirOwnAbilities(): void
    {
        $body = $this->page($this->createApp('contributor'), '/admin/profile', ['tab' => 'api-tokens']);

        $this->assertMatchesRegularExpression('/name="abilities\[\]"[^>]*value="content\.view"/', $body);
        $this->assertStringNotContainsString('value="settings.manage"', $body, 'les capacités que le compte n\'a pas ne sont pas affichées');
    }

    public function testTheTokenListShowsThePrefix(): void
    {
        $app = $this->createApp('admin');
        $issued = $this->tokens($app)->issue($this->user($app, 1), 'Liste', ['content.view']);
        $prefix = substr($issued['token'], 5, 8);

        $this->assertStringContainsString($prefix, $this->page($app, '/admin/profile', ['tab' => 'api-tokens']));
    }

    public function testTheTabInTheUrlWinsOverTheRememberedTab(): void
    {
        $app = $this->createApp('admin');
        $this->post($app, '/admin/profile/api-tokens', ['name' => 'Onglet', 'abilities' => ['content.view'], 'expires_days' => '30']);
        $this->page($app, '/admin/profile', ['tab' => 'api-tokens']);

        $body = $this->page($app, '/admin/profile', ['tab' => 'info']);

        $this->assertMatchesRegularExpression('/id="profile-tabs-tab-info"[^>]*aria-selected="true"/', $body);
    }

    public function testExpirationChoicesFollowTheMaximumDuration(): void
    {
        $app = $this->createApp('admin');
        $body = $this->page($app, '/admin/profile', ['tab' => 'api-tokens']);

        $this->assertMatchesRegularExpression('/<option[^>]*value="90"[^>]*selected/', $body, '90 jours par défaut');
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="expires_days"[\s\S]*?<option[^>]*value="0"[\s\S]*?<\/select>/', $body, 'pas de jeton sans expiration quand api.token_max_days vaut 365');

        $this->post($app, '/admin/profile/api-tokens', ['name' => 'Malin', 'abilities' => ['content.view'], 'expires_days' => '0']);
        $row = $this->db($app)->selectOne("SELECT expires_at FROM pc_api_tokens WHERE name = 'Malin'");
        $this->assertNotNull($row['expires_at'] ?? null, 'une durée hors limites est ramenée au maximum');
        $this->assertLessThanOrEqual(time() + 366 * 86400, strtotime((string) $row['expires_at'] . ' UTC'));
    }

    // --- Réglages api.* ---------------------------------------------------------------------

    public function testTheApiSettingsLiveOnTheSecurityScreen(): void
    {
        $registry = $this->createApp(null)->get(SettingsRegistry::class);

        $this->assertArrayHasKey('api', $registry->sections('security'));
        foreach (['api.enabled', 'api.public_read', 'api.cors_origins', 'api.rate_limit_authenticated', 'api.rate_limit_anonymous', 'api.max_body_kb', 'api.token_max_days'] as $name) {
            $field = $registry->field($name);
            $this->assertNotNull($field, $name);
            $this->assertSame('api', $field->section, $name);
        }
    }

    public function testADisabledApiAnswersJson404Everywhere(): void
    {
        $app = $this->createApp(null);
        $this->setting($app, 'api.enabled', false);

        foreach (['/api/v1', '/api/v1/content/post'] as $uri) {
            $response = $this->get($app, $uri);
            $this->assertSame(404, $response->status(), $uri);
            $this->assertSame('not_found', $this->json($response)['error']['code'] ?? null, $uri);
        }
    }

    public function testWithoutPublicReadAnonymousContentRequestsNeedAuthentication(): void
    {
        $app = $this->createApp(null);
        $this->setting($app, 'api.public_read', false);

        $this->assertSame(401, $this->get($app, '/api/v1/content/post')->status());
        $this->assertSame(200, $this->get($app, '/api/v1')->status(), 'l\'index reste public');

        $issued = $this->tokens($app)->issue($this->user($app, 2), 'lecture', ['content.view']);
        $this->assertSame(200, $this->get($app, '/api/v1/content/post', ['Authorization' => 'Bearer ' . $issued['token']])->status());
    }

    // --- Limite de débit --------------------------------------------------------------------

    public function testAnonymousCallersAreRateLimited(): void
    {
        $app = $this->createApp(null);
        $this->setting($app, 'api.rate_limit_anonymous', 2);

        $first = $this->get($app, '/api/v1');
        $this->assertSame('2', $first->header('X-RateLimit-Limit'));
        $this->assertSame('1', $first->header('X-RateLimit-Remaining'));
        $this->get($app, '/api/v1');

        $blocked = $this->get($app, '/api/v1');
        $this->assertSame(429, $blocked->status());
        $this->assertSame('rate_limited', $this->json($blocked)['error']['code'] ?? null);
        $this->assertGreaterThan(0, (int) $blocked->header('Retry-After'));
    }

    public function testTheAuthenticatedLimitIsCountedPerToken(): void
    {
        $app = $this->createApp(null);
        $this->setting($app, 'api.rate_limit_authenticated', 1);
        $a = $this->tokens($app)->issue($this->user($app, 1), 'a', ['*']);
        $b = $this->tokens($app)->issue($this->user($app, 1), 'b', ['*']);

        $this->assertSame(200, $this->get($app, '/api/v1/me', ['Authorization' => 'Bearer ' . $a['token']])->status());
        $this->assertSame(429, $this->get($app, '/api/v1/me', ['Authorization' => 'Bearer ' . $a['token']])->status());
        $this->assertSame(200, $this->get($app, '/api/v1/me', ['Authorization' => 'Bearer ' . $b['token']])->status(), 'un autre jeton a son propre compteur');
    }

    // --- CORS -------------------------------------------------------------------------------

    public function testNoCorsHeaderIsSentByDefault(): void
    {
        $response = $this->get($this->createApp(null), '/api/v1', ['Origin' => 'https://front.test']);

        $this->assertNull($response->header('Access-Control-Allow-Origin'));
    }

    public function testOnlyConfiguredOriginsAreAllowed(): void
    {
        $app = $this->createApp(null);
        $this->setting($app, 'api.cors_origins', "https://front.test\nhttps://app.test");

        $allowed = $this->get($app, '/api/v1', ['Origin' => 'https://front.test']);
        $this->assertSame('https://front.test', $allowed->header('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('Origin', (string) $allowed->header('Vary'));

        $this->assertNull($this->get($app, '/api/v1', ['Origin' => 'https://front.test.evil.com'])->header('Access-Control-Allow-Origin'));
    }

    public function testAPreflightRequestIsAnswered(): void
    {
        $app = $this->createApp(null);
        $this->setting($app, 'api.cors_origins', 'https://front.test');

        $response = $app->get(Kernel::class)->handle(Request::create('OPTIONS', '/api/v1/content/post', headers: [
            'Origin' => 'https://front.test',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Authorization',
        ]));

        $this->assertSame(204, $response->status());
        $this->assertSame('https://front.test', $response->header('Access-Control-Allow-Origin'));
        $this->assertStringContainsStringIgnoringCase('authorization', (string) $response->header('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('GET', (string) $response->header('Access-Control-Allow-Methods'));
    }

    // --- Cache HTTP, statut, liens, méthode -------------------------------------------------

    public function testAuthenticatedResponsesAreNeverCached(): void
    {
        $app = $this->createApp(null);
        $issued = $this->tokens($app)->issue($this->user($app, 1), 'c', ['*']);

        $response = $this->get($app, '/api/v1/content/post', ['Authorization' => 'Bearer ' . $issued['token']]);

        $this->assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
    }

    public function testAnonymousReadsCarryAnEtagAndAnswer304(): void
    {
        $app = $this->createApp(null);
        $first = $this->get($app, '/api/v1/content/post/article-publie');
        $etag = $first->header('ETag');
        $this->assertNotNull($etag);

        $second = $this->get($app, '/api/v1/content/post/article-publie', ['If-None-Match' => (string) $etag]);
        $this->assertSame(304, $second->status());
        $this->assertSame('', $second->body());
    }

    public function testAnUnknownStatusIsA400EvenForAnonymousCallers(): void
    {
        $response = $this->get($this->createApp(null), '/api/v1/content/post', query: ['status' => 'foo']);

        $this->assertSame(400, $response->status());
        $this->assertSame('invalid_status', $this->json($response)['error']['code'] ?? null);
    }

    public function testFirstAndLastLinksAreUrls(): void
    {
        $links = $this->json($this->get($this->createApp(null), '/api/v1/content/post'))['links'] ?? [];

        $this->assertIsString($links['first'] ?? null);
        $this->assertIsString($links['last'] ?? null);
        $this->assertNull($links['prev'] ?? 'absent');
        $this->assertNull($links['next'] ?? 'absent');
    }

    public function testAWrongMethodIsAJson405(): void
    {
        $response = $this->createApp(null)->get(Kernel::class)->handle(Request::create('DELETE', '/api/v1', headers: ['Accept' => 'application/json']));

        $this->assertSame(405, $response->status());
        $this->assertSame('method_not_allowed', $this->json($response)['error']['code'] ?? null);
    }

    // --- Ligne de commande ------------------------------------------------------------------

    public function testTokensCanBeManagedFromTheCommandLine(): void
    {
        $app = $this->createApp(null);
        $console = new ConsoleApplication($app);

        $create = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'api:token:create', 'contrib@prime.test', '--name=CI', '--abilities=content.view,settings.manage', '--days=30'], $create));
        $this->assertMatchesRegularExpression('/pcat_[A-Za-z0-9]{8}_[A-Za-z0-9]{40}/', $create->stdout());

        $row = $this->db($app)->selectOne("SELECT id, abilities, expires_at FROM pc_api_tokens WHERE name = 'CI'");
        $this->assertSame(['content.view'], json_decode((string) ($row['abilities'] ?? ''), true), 'une capacité que le compte n\'a pas est retirée');
        $this->assertNotNull($row['expires_at'] ?? null);

        $list = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'api:token:list', '--user=contrib@prime.test'], $list));
        $this->assertStringContainsString('CI', $list->stdout());
        $this->assertDoesNotMatchRegularExpression('/pcat_[A-Za-z0-9]{8}_[A-Za-z0-9]{40}/', $list->stdout(), 'la liste n\'affiche jamais un jeton complet');

        $revoke = new ConsoleOutput(buffered: true);
        $this->assertSame(0, $console->run(['bin/prime', 'api:token:revoke', (string) $row['id']], $revoke));
        $this->assertNotNull($this->db($app)->selectOne('SELECT revoked_at FROM pc_api_tokens WHERE id = ?', [$row['id']])['revoked_at'] ?? null);

        $unknown = new ConsoleOutput(buffered: true);
        $this->assertSame(1, $console->run(['bin/prime', 'api:token:create', 'personne@prime.test', '--name=x'], $unknown));
    }

    public function testOldTokensArePrunedBySchedule(): void
    {
        $app = $this->createApp(null);

        $names = array_map(static fn ($task): string => $task->name, $app->get(Schedule::class)->tasks());
        $this->assertContains('api-tokens-prune', $names);
    }

    // --- Fiche d'un utilisateur -------------------------------------------------------------

    public function testAnAdministratorSeesAndRevokesAnotherUsersTokens(): void
    {
        $app = $this->createApp('admin');
        $issued = $this->tokens($app)->issue($this->user($app, 2), 'Appli mobile', ['content.view']);

        $body = $this->page($app, '/admin/users/2/edit');
        $this->assertStringContainsString('Appli mobile', $body);
        $this->assertStringContainsString('action="/admin/users/2/api-tokens/' . $issued['id'] . '/revoke"', $body);

        $response = $this->post($app, '/admin/users/2/api-tokens/' . $issued['id'] . '/revoke', []);
        $this->assertSame(302, $response->status());
        $this->assertNull($this->tokens($app)->authenticate($issued['token']));
    }

    public function testATokenCannotBeRevokedThroughTheWrongUser(): void
    {
        $app = $this->createApp('admin');
        $issued = $this->tokens($app)->issue($this->user($app, 2), 'Autre', ['content.view']);

        $this->assertSame(404, $this->post($app, '/admin/users/1/api-tokens/' . $issued['id'] . '/revoke', [])->status());
        $this->assertNotNull($this->tokens($app)->authenticate($issued['token']));
    }

    // --- Helpers ----------------------------------------------------------------------------

    private function setting(Application $app, string $name, mixed $value): void
    {
        $app->get(Settings::class)->set($name, $value);
    }

    /**
     * @param array<string, string> $query
     */
    private function page(Application $app, string $uri, array $query = []): string
    {
        $response = $app->get(Kernel::class)->handle(Request::create('GET', $uri, query: $query));
        $this->assertSame(200, $response->status(), $uri . ' : ' . substr($response->body(), 0, 300));

        return $response->body();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(Application $app, string $uri, array $body): Response
    {
        return $app->get(Kernel::class)->handle(Request::create('POST', $uri, body: $body + [Csrf::FIELD_NAME => $app->get(Csrf::class)->token()]));
    }
}
