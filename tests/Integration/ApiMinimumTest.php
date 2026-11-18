<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Kernel\Application;
use Prime\Security\Csrf;
use Prime\Tests\Support\ApiTestFixture;

/**
 * Tests minimums d'acceptation de l'API REST (etape 5 de la Phase 10).
 *
 * Couverture :
 *  - 403 sans permission
 *  - 403 CSRF manquant en session
 *  - contributeur ne modifie pas le contenu d'un autre (404)
 *  - 412 If-Match perime
 *  - 409 verrouille
 *  - 415 / 400 / 413 / 422
 *  - corbeille / restauration / suppression definitive
 *  - creation de terme
 *  - filtre api.resource.entry qui leve une exception ne casse pas la reponse
 */
final class ApiMinimumTest extends TestCase
{
    use ApiTestFixture;

    // ------------------------------------------------------------------
    // Helpers HTTP
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @return Response
     */
    private function postJson(Application $app, string $uri, array $data = [], array $headers = []): Response
    {
        return $app->get(Kernel::class)->handle(Request::create(
            'POST',
            $uri,
            body: [],
            headers: array_merge(['Content-Type' => 'application/json', 'Accept' => 'application/json'], $headers),
            rawBody: json_encode($data),
        ));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @return Response
     */
    private function patchJson(Application $app, string $uri, array $data = [], array $headers = []): Response
    {
        return $app->get(Kernel::class)->handle(Request::create(
            'PATCH',
            $uri,
            body: [],
            headers: array_merge(['Content-Type' => 'application/json', 'Accept' => 'application/json'], $headers),
            rawBody: json_encode($data),
        ));
    }

    private function deleteJson(Application $app, string $uri, array $headers = [], array $query = []): Response
    {
        return $app->get(Kernel::class)->handle(Request::create(
            'DELETE',
            $uri,
            query: $query,
            headers: array_merge(['Accept' => 'application/json'], $headers),
        ));
    }

    private function getJson(Application $app, string $uri, array $headers = [], array $query = []): Response
    {
        return $app->get(Kernel::class)->handle(Request::create(
            'GET',
            $uri,
            query: $query,
            headers: array_merge(['Accept' => 'application/json'], $headers),
        ));
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $decoded = json_decode($response->body(), true);
        $this->assertIsArray($decoded, 'reponse non JSON : ' . substr($response->body(), 0, 200));

        return $decoded;
    }

    private function csrfToken(Application $app): string
    {
        return $app->get(Csrf::class)->token();
    }

    // ------------------------------------------------------------------
    // 403 sans permission
    // ------------------------------------------------------------------

    public function testAnUnauthenticatedRequestCannotCreateContent(): void
    {
        $app = $this->createApp(null);

        $response = $this->postJson($app, '/api/v1/content/post', [
            'title' => 'Test',
            'status' => 'draft',
            'content' => ['blocks' => []],
        ]);

        $this->assertSame(403, $response->status(), 'une requete non authentifiee recoit une 403');
    }

    public function testAContributorCannotCreateTerms(): void
    {
        $app = $this->createApp('contrib');

        $response = $this->postJson($app, '/api/v1/terms/category', [
            'name' => 'Test',
            'slug' => 'test',
        ]);

        $this->assertSame(403, $response->status(), 'un contributeur sans content.edit_any recoit une 403');
    }

    // ------------------------------------------------------------------
    // 403 CSRF manquant en session
    // ------------------------------------------------------------------

    public function testSessionMutationRequiresCsrfHeader(): void
    {
        $app = $this->createApp('admin');

        $response = $this->postJson($app, '/api/v1/content/post', [
            'title' => 'Test',
            'status' => 'draft',
            'content' => ['blocks' => []],
        ]);

        $this->assertSame(403, $response->status(), 'POST en session sans X-Prime-CSRF retourne 403');
    }

    public function testSessionMutationWithCsrfHeaderSucceeds(): void
    {
        $app = $this->createApp('admin');

        $response = $this->postJson($app, '/api/v1/content/post', [
            'title' => 'Test',
            'status' => 'draft',
            'content' => ['blocks' => []],
        ], ['X-Prime-CSRF' => $this->csrfToken($app)]);

        $this->assertSame(201, $response->status(), 'POST en session avec X-Prime-CSRF retourne 201');
    }

    // ------------------------------------------------------------------
    // Contributeur ne modifie pas le contenu d'un autre (404)
    // ------------------------------------------------------------------

    public function testAContributorCannotEditAnothersEntryReturns404(): void
    {
        $app = $this->createApp('contrib');

        // L'article publie (id 1) appartient a l'admin (id 1).
        $response = $this->patchJson($app, '/api/v1/content/post/1', [
            'title' => 'Modifie par le contributeur',
        ], ['X-Prime-CSRF' => $this->csrfToken($app)]);

        $this->assertSame(404, $response->status(), 'le contributeur qui edite un contenu d un autre recoit 404');
    }

    // ------------------------------------------------------------------
    // 409 verrouille
    // ------------------------------------------------------------------

    public function testLockedEntryReturns409(): void
    {
        $app = $this->createApp('admin');

        $this->db($app)->exec(
            "INSERT INTO pc_entry_locks (entry_id, user_id, acquired_at, heartbeat_at) VALUES (1, 2, '2026-11-15 10:00:00', '2026-11-15 10:00:00')"
        );

        $response = $this->patchJson($app, '/api/v1/content/post/1', [
            'title' => 'Verrouille',
        ], ['X-Prime-CSRF' => $this->csrfToken($app)]);

        $this->assertSame(409, $response->status(), 'une entree verrouillee par un autre retourne 409');
    }

    // ------------------------------------------------------------------
    // 415 / 400 / 413 / 422
    // ------------------------------------------------------------------

    public function testMissingContentTypeReturns415(): void
    {
        $app = $this->createApp('admin');

        $response = $app->get(Kernel::class)->handle(Request::create(
            'POST',
            '/api/v1/content/post',
            headers: ['Accept' => 'application/json', 'X-Prime-CSRF' => $this->csrfToken($app)],
            rawBody: json_encode(['title' => 'Test']),
        ));

        $this->assertSame(415, $response->status(), 'Content-Type manquant retourne 415');
    }

    public function testInvalidJsonReturns422(): void
    {
        $app = $this->createApp('admin');

        $response = $app->get(Kernel::class)->handle(Request::create(
            'POST',
            '/api/v1/content/post',
            headers: ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Prime-CSRF' => $this->csrfToken($app)],
            rawBody: '{ pas du json valide',
        ));

        $this->assertSame(422, $response->status(), 'JSON invalide retourne 422');
    }

    public function testMissingRequiredFieldsReturns422(): void
    {
        $app = $this->createApp('admin');

        $response = $this->postJson($app, '/api/v1/content/post', [
            'status' => 'draft',
            // 'title' manquant
        ], ['X-Prime-CSRF' => $this->csrfToken($app)]);

        $this->assertSame(422, $response->status(), 'champs requis manquants retourne 422');
    }

    // ------------------------------------------------------------------
    // Corbeille / restauration / suppression definitive
    // ------------------------------------------------------------------

    public function testTrashRestoreAndPermanentDelete(): void
    {
        $app = $this->createApp('admin');
        $csrf = $this->csrfToken($app);

        // Cree un brouillon.
        $create = $this->postJson($app, '/api/v1/content/post', [
            'title' => 'A supprimer',
            'status' => 'draft',
            'content' => ['blocks' => []],
        ], ['X-Prime-CSRF' => $csrf]);
        $this->assertSame(201, $create->status());
        $data = $this->json($create);
        $entryId = $data['data']['id'] ?? null;
        $this->assertNotNull($entryId);

        // Met a la corbeille.
        $trash = $this->deleteJson($app, "/api/v1/content/post/{$entryId}", ['X-Prime-CSRF' => $csrf]);
        $this->assertSame(200, $trash->status(), 'DELETE met a la corbeille');

        // Restaure.
        $restore = $this->postJson($app, "/api/v1/content/post/{$entryId}/restore", [], ['X-Prime-CSRF' => $csrf]);
        $this->assertSame(200, $restore->status(), 'POST restore restaure');

        // Suppression definitive.
        $purge = $this->deleteJson($app, "/api/v1/content/post/{$entryId}?force=1", ['X-Prime-CSRF' => $csrf]);
        $this->assertSame(200, $purge->status(), 'DELETE force=1 supprime définitivement');
    }

    // ------------------------------------------------------------------
    // Creation de terme
    // ------------------------------------------------------------------

    public function testCreateTerm(): void
    {
        $app = $this->createApp('admin');

        $response = $this->postJson($app, '/api/v1/terms/category', [
            'name' => 'Nouveau terme',
            'slug' => 'nouveau-terme',
            'description' => 'Une description',
        ], ['X-Prime-CSRF' => $this->csrfToken($app)]);

        $this->assertSame(201, $response->status(), 'POST terme retourne 201');
        $data = $this->json($response);
        $this->assertSame('Nouveau terme', $data['data']['name'] ?? null);
        $this->assertSame('nouveau-terme', $data['data']['slug'] ?? null);
    }

    // ------------------------------------------------------------------
    // Filtre api.resource.entry qui leve une exception ne casse pas la reponse
    // ------------------------------------------------------------------

    public function testResourceFilterExceptionDoesNotBreakResponse(): void
    {
        $app = $this->createApp(null);

        $hooks = $app->get(Hooks::class);
        $hooks->filter('api.resource.post', static function (array $data): array {
            throw new \RuntimeException('filtre defeillant');
        }, priority: 0, owner: 'test-plugin');

        $response = $this->getJson($app, '/api/v1/content/post');

        $this->assertSame(200, $response->status(), 'la reponse reste 200 malgré lexception du filtre');
        $data = $this->json($response);
        $this->assertArrayHasKey('data', $data);
    }

    // ------------------------------------------------------------------
    // 412 If-Match perime (implimentation complete)
    // ------------------------------------------------------------------

    public function testIfMatchExpiredReturns412(): void
    {
        $app = $this->createApp('admin');

        // GET pour obtenir l'ETag.
        $show = $this->getJson($app, '/api/v1/content/post/1');
        $this->assertSame(200, $show->status());
        $etag = $show->header('ETag') ?? null;
        $this->assertNotNull($etag, 'l entree doit renvoyer un ETag');

        // PATCH avec un If-Match perime.
        $response = $app->get(Kernel::class)->handle(Request::create(
            'PATCH',
            '/api/v1/content/post/1',
            body: [],
            headers: [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Prime-CSRF' => $this->csrfToken($app),
                'If-Match' => 'W/"etag-perime"',
            ],
            rawBody: json_encode(['title' => 'Modifie']),
        ));

        $this->assertSame(412, $response->status(), 'If-Match perime retourne 412');
    }
}
