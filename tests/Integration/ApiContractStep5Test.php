<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Content\EntryLockRepository;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Kernel\Application;
use Prime\Security\Csrf;
use Prime\Settings\Settings;
use Prime\Tests\Support\ApiTestFixture;
use RuntimeException;

/**
 * Contrat de l'étape 5 de la phase 10 (écrit par la revue, ne pas modifier).
 * Écriture des contenus et des termes, corps JSON, CSRF de session, concurrence, ressources.
 *
 * Fixture : billet 1 publié (admin), billet 2 brouillon (admin), billet 3 brouillon (contributeur).
 */
final class ApiContractStep5Test extends TestCase
{
    use ApiTestFixture;

    // --- Création et permissions ------------------------------------------------------------

    public function testATokenCreatesAnEntry(): void
    {
        $app = $this->createApp(null);
        $token = $this->token($app, 1, ['*']);

        $response = $this->send($app, 'POST', '/api/v1/content/post', ['title' => 'Créé par l’API', 'status' => 'draft'], $token);

        $this->assertSame(201, $response->status(), $response->body());
        $data = $this->json($response)['data'] ?? [];
        $this->assertSame('Créé par l’API', $data['title'] ?? null);
        $this->assertSame('draft', $data['status'] ?? null);
        $this->assertIsInt($data['id'] ?? null);
        $this->assertNotNull($this->db($app)->selectOne('SELECT id FROM pc_entries WHERE id = ?', [$data['id']]));
    }

    public function testATokenWithoutTheCapabilityCannotCreate(): void
    {
        $app = $this->createApp(null);
        $token = $this->token($app, 2, ['content.view']);

        $response = $this->send($app, 'POST', '/api/v1/content/post', ['title' => 'Non'], $token);

        $this->assertSame(403, $response->status());
        $this->assertSame('forbidden', $this->json($response)['error']['code'] ?? null);
    }

    public function testAnonymousCallersCannotWrite(): void
    {
        $response = $this->send($this->createApp(null), 'POST', '/api/v1/content/post', ['title' => 'Non']);

        $this->assertSame(401, $response->status());
    }

    // --- Corps des requêtes -----------------------------------------------------------------

    public function testABodyThatIsNotJsonIs415(): void
    {
        $app = $this->createApp(null);
        $response = $this->raw($app, 'POST', '/api/v1/content/post', 'title=x', ['Content-Type' => 'application/x-www-form-urlencoded'], $this->token($app, 1, ['*']));

        $this->assertSame(415, $response->status());
        $this->assertSame('unsupported_media_type', $this->json($response)['error']['code'] ?? null);
    }

    public function testInvalidJsonIs400(): void
    {
        $app = $this->createApp(null);
        $response = $this->raw($app, 'POST', '/api/v1/content/post', '{"title": ', ['Content-Type' => 'application/json'], $this->token($app, 1, ['*']));

        $this->assertSame(400, $response->status());
        $this->assertSame('invalid_json', $this->json($response)['error']['code'] ?? null);
    }

    public function testATooLargeBodyIs413(): void
    {
        $app = $this->createApp(null);
        $app->get(Settings::class)->set('api.max_body_kb', 16);
        $body = (string) json_encode(['title' => str_repeat('a', 20 * 1024)]);

        $response = $this->raw($app, 'POST', '/api/v1/content/post', $body, ['Content-Type' => 'application/json'], $this->token($app, 1, ['*']));

        $this->assertSame(413, $response->status());
        $this->assertSame('payload_too_large', $this->json($response)['error']['code'] ?? null);
    }

    public function testValidationErrorsAre422WithTheFields(): void
    {
        $app = $this->createApp(null);
        $response = $this->send($app, 'POST', '/api/v1/content/post', ['title' => '', 'status' => 'nimporte'], $this->token($app, 1, ['*']));

        $this->assertSame(422, $response->status());
        $error = $this->json($response)['error'] ?? [];
        $this->assertSame('validation_failed', $error['code'] ?? null);
        $this->assertArrayHasKey('title', $error['fields'] ?? []);
        $this->assertArrayHasKey('status', $error['fields'] ?? []);
    }

    // --- Session et CSRF --------------------------------------------------------------------

    public function testASessionWriteNeedsTheCsrfHeader(): void
    {
        $app = $this->createApp('admin');

        $refused = $this->send($app, 'POST', '/api/v1/content/post', ['title' => 'Sans jeton CSRF']);
        $this->assertSame(403, $refused->status());
        $this->assertSame('csrf_mismatch', $this->json($refused)['error']['code'] ?? null);

        $accepted = $this->send($app, 'POST', '/api/v1/content/post', ['title' => 'Avec jeton CSRF'], headers: ['X-Prime-CSRF' => $app->get(Csrf::class)->token()]);
        $this->assertSame(201, $accepted->status(), $accepted->body());
    }

    // --- Modification et concurrence -------------------------------------------------------

    public function testAnEntryIsReadableByIdWithAnEtag(): void
    {
        $app = $this->createApp(null);
        $response = $this->send($app, 'GET', '/api/v1/content/post/2', token: $this->token($app, 1, ['*']));

        $this->assertSame(200, $response->status());
        $this->assertSame(2, $this->json($response)['data']['id'] ?? null);
        $this->assertNotEmpty($response->header('ETag'));
    }

    public function testAStaleIfMatchIs412AndAFreshOneUpdates(): void
    {
        $app = $this->createApp(null);
        $token = $this->token($app, 1, ['*']);
        $etag = (string) $this->send($app, 'GET', '/api/v1/content/post/2', token: $token)->header('ETag');

        $stale = $this->send($app, 'PATCH', '/api/v1/content/post/2', ['title' => 'Trop tard'], $token, ['If-Match' => 'W/"perime"']);
        $this->assertSame(412, $stale->status());
        $this->assertSame('precondition_failed', $this->json($stale)['error']['code'] ?? null);

        $fresh = $this->send($app, 'PATCH', '/api/v1/content/post/2', ['title' => 'Titre modifié'], $token, ['If-Match' => $etag]);
        $this->assertSame(200, $fresh->status(), $fresh->body());
        $this->assertSame('Titre modifié', $this->json($fresh)['data']['title'] ?? null);
        $this->assertNotSame($etag, $fresh->header('ETag'), 'l’ETag change après une modification');
    }

    public function testAContributorCannotEditSomeoneElsesEntry(): void
    {
        $app = $this->createApp(null);
        $response = $this->send($app, 'PATCH', '/api/v1/content/post/2', ['title' => 'Piraté'], $this->token($app, 2, ['*']));

        $this->assertSame(404, $response->status());
        $this->assertSame('Brouillon admin', $this->db($app)->selectOne('SELECT title FROM pc_entries WHERE id = 2')['title'] ?? null);
    }

    public function testAnEntryLockedByAnotherUserIs409(): void
    {
        $app = $this->createApp(null);
        $app->get(EntryLockRepository::class)->acquire(3, 2);

        $response = $this->send($app, 'PATCH', '/api/v1/content/post/3', ['title' => 'Conflit'], $this->token($app, 1, ['*']));

        $this->assertSame(409, $response->status());
        $this->assertSame('conflict', $this->json($response)['error']['code'] ?? null);
    }

    public function testTrashRestoreAndPermanentDelete(): void
    {
        $app = $this->createApp(null);
        $token = $this->token($app, 1, ['*']);

        $this->assertSame(200, $this->send($app, 'DELETE', '/api/v1/content/post/2', token: $token)->status());
        $this->assertSame('trash', $this->db($app)->selectOne('SELECT status FROM pc_entries WHERE id = 2')['status'] ?? null);

        $this->assertSame(200, $this->send($app, 'POST', '/api/v1/content/post/2/restore', [], $token)->status());
        $this->assertSame('draft', $this->db($app)->selectOne('SELECT status FROM pc_entries WHERE id = 2')['status'] ?? null);

        $this->send($app, 'DELETE', '/api/v1/content/post/2', token: $token);
        $deleted = $this->send($app, 'DELETE', '/api/v1/content/post/2', token: $token, query: ['force' => '1']);
        $this->assertContains($deleted->status(), [200, 204]);
        $this->assertNull($this->db($app)->selectOne('SELECT id FROM pc_entries WHERE id = 2'));
    }

    // --- Termes, types, site, profil --------------------------------------------------------

    public function testTermsCanBeCreatedAndListed(): void
    {
        $app = $this->createApp(null);

        $created = $this->send($app, 'POST', '/api/v1/terms/category', ['name' => 'Voyages'], $this->token($app, 1, ['*']));
        $this->assertSame(201, $created->status(), $created->body());
        $this->assertSame('voyages', $this->json($created)['data']['slug'] ?? null);

        $slugs = array_column($this->json($this->send($app, 'GET', '/api/v1/terms/category'))['data'] ?? [], 'slug');
        $this->assertContains('voyages', $slugs);

        $this->assertSame(403, $this->send($app, 'POST', '/api/v1/terms/category', ['name' => 'Non'], $this->token($app, 2, ['*']))->status());
    }

    public function testSiteAndTypesDescribeTheInstallation(): void
    {
        $app = $this->createApp(null);

        $site = $this->json($this->send($app, 'GET', '/api/v1/site'));
        $this->assertArrayHasKey('name', $site['data'] ?? []);

        $types = array_column($this->json($this->send($app, 'GET', '/api/v1/types'))['data'] ?? [], 'name');
        $this->assertContains('post', $types);
    }

    public function testTheCurrentUserCanRenameThemselves(): void
    {
        $app = $this->createApp(null);
        $token = $this->token($app, 2, ['*']);

        $response = $this->send($app, 'PATCH', '/api/v1/me', ['display_name' => 'Nouveau nom'], $token);
        $this->assertSame(200, $response->status(), $response->body());
        $this->assertSame('Nouveau nom', $this->db($app)->selectOne('SELECT display_name FROM pc_users WHERE id = 2')['display_name'] ?? null);

        $this->assertSame(422, $this->send($app, 'PATCH', '/api/v1/me', ['locale' => 'klingon'], $token)->status());
    }

    // --- Ressources -------------------------------------------------------------------------

    public function testAnEntryResourceCarriesLinks(): void
    {
        $data = $this->json($this->send($this->createApp(null), 'GET', '/api/v1/content/post/article-publie'))['data'] ?? [];

        $this->assertIsString($data['_links']['self'] ?? null);
        $this->assertIsString($data['_links']['collection'] ?? null);
        $this->assertArrayNotHasKey('edit_url', $data['_links'] ?? [], 'un anonyme ne reçoit pas de lien d’édition');
    }

    public function testAFailingResourceFilterDoesNotBreakTheResponse(): void
    {
        $app = $this->createApp(null);
        $app->get(Hooks::class)->filter('api.resource.entry', static function (): never {
            throw new RuntimeException('plugin cassé');
        });

        $response = $this->send($app, 'GET', '/api/v1/content/post/article-publie');

        $this->assertSame(200, $response->status());
        $this->assertSame('Article publié', $this->json($response)['data']['title'] ?? null);
    }

    // --- Helpers ----------------------------------------------------------------------------

    /**
     * @param list<string> $abilities
     */
    private function token(Application $app, int $userId, array $abilities): string
    {
        return $this->tokens($app)->issue($this->user($app, $userId), 'contrat', $abilities)['token'];
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $headers
     * @param array<string, string>     $query
     */
    private function send(Application $app, string $method, string $uri, ?array $json = null, ?string $token = null, array $headers = [], array $query = []): Response
    {
        $raw = $json === null ? '' : (string) json_encode($json, JSON_UNESCAPED_UNICODE);
        if ($json !== null) {
            $headers += ['Content-Type' => 'application/json'];
        }

        return $this->raw($app, $method, $uri, $raw, $headers, $token, $query);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    private function raw(Application $app, string $method, string $uri, string $body, array $headers = [], ?string $token = null, array $query = []): Response
    {
        $headers += ['Accept' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $server = $body === '' ? [] : ['CONTENT_LENGTH' => (string) strlen($body), 'CONTENT_TYPE' => $headers['Content-Type'] ?? ''];

        return $app->get(Kernel::class)->handle(Request::create($method, $uri, query: $query, headers: $headers, server: $server, rawBody: $body));
    }
}
