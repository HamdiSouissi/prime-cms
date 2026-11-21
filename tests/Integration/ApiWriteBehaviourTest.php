<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Prime\Api\ApiRoute;
use Prime\Api\ApiRouteRegistry;
use Prime\Api\Controller\SiteController;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Kernel\Application;
use Prime\Security\Csrf;
use Prime\Settings\Settings;
use Prime\Tests\Support\ApiTestFixture;

/**
 * Behaviour of the write API beyond the step 5 contract: route declarations, rate limiting
 * of writes, lifecycle hooks, listing filters, term maintenance.
 */
final class ApiWriteBehaviourTest extends TestCase
{
    use ApiTestFixture;

    public function testARouteMustDeclareExactlyOneOfAPermissionOrPublic(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ApiRoute('GET', '/x', [SiteController::class, 'show']);
    }

    public function testARouteCannotBeBothPublicAndProtected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ApiRoute('GET', '/x', [SiteController::class, 'show'], permission: 'content.view', public: true);
    }

    public function testTwoRoutesAnsweringTheSameUrlAreRefused(): void
    {
        $registry = $this->createApp(null)->get(ApiRouteRegistry::class);

        $this->expectException(LogicException::class);
        $registry->add(new ApiRoute('GET', '/site', [SiteController::class, 'show'], public: true), owner: 'demo');
    }

    public function testAWriteOverTheRateLimitIsNotPerformed(): void
    {
        $app = $this->createApp(null);
        $app->get(Settings::class)->set('api.rate_limit_authenticated', 1);
        $token = $this->token($app, 1);
        $before = $this->countEntries($app);

        $this->assertSame(201, $this->send($app, 'POST', '/api/v1/content/post', ['title' => 'Un'], $token)->status());
        $this->assertSame(429, $this->send($app, 'POST', '/api/v1/content/post', ['title' => 'Deux'], $token)->status());

        $this->assertSame($before + 1, $this->countEntries($app), 'la requête refusée ne doit rien écrire');
    }

    public function testLifecycleHooksSeeEveryRequestAndCannotBreakTheResponse(): void
    {
        $app = $this->createApp(null);
        $hooks = $app->get(Hooks::class);
        $seen = [];
        $hooks->on('api.request', static function (array $info) use (&$seen): void {
            $seen[] = $info;
        });
        $hooks->filter('api.response', static fn (): string => 'pas une réponse');

        $response = $this->send($app, 'GET', '/api/v1/site');

        $this->assertSame(200, $response->status());
        $this->assertArrayHasKey('data', $this->json($response));
        $this->assertCount(1, $seen);
        $this->assertSame(200, $seen[0]['status']);
        $this->assertSame('/api/v1/site', $seen[0]['path']);
    }

    public function testASessionWriteWithTheFormHeaderOnlyIsRefused(): void
    {
        $app = $this->createApp('admin');
        $response = $this->send($app, 'POST', '/api/v1/content/post', ['title' => 'x'], headers: ['X-CSRF-Token' => $app->get(Csrf::class)->token()]);

        $this->assertSame(403, $response->status());
        $this->assertSame('csrf_mismatch', $this->json($response)['error']['code'] ?? null);
    }

    public function testListingsSupportFieldsOrderAndFilters(): void
    {
        $app = $this->createApp(null);
        $token = $this->token($app, 1);

        $titles = array_column($this->json($this->send($app, 'GET', '/api/v1/content/post', token: $token, query: ['status' => 'draft', 'orderby' => 'title', 'order' => 'asc', 'fields' => 'title']))['data'] ?? [], 'title');
        $this->assertSame(['Brouillon admin', 'Brouillon contributeur'], $titles);

        $item = $this->json($this->send($app, 'GET', '/api/v1/content/post', query: ['fields' => 'title']))['data'][0] ?? [];
        $this->assertSame(['id', 'title', '_links'], array_keys($item));

        $this->assertSame(400, $this->send($app, 'GET', '/api/v1/content/post', query: ['orderby' => 'password'])->status());
        $this->assertSame([], $this->json($this->send($app, 'GET', '/api/v1/content/post', query: ['after' => '2030-01-01']))['data'] ?? null);
    }

    public function testDeletingForGoodAnEntryOutsideTheTrashIsAConflict(): void
    {
        $app = $this->createApp(null);

        $response = $this->send($app, 'DELETE', '/api/v1/content/post/2', token: $this->token($app, 1), query: ['force' => '1']);

        $this->assertSame(409, $response->status());
        $this->assertNotNull($this->db($app)->selectOne('SELECT id FROM pc_entries WHERE id = 2'));
    }

    public function testTermsCanBeRenamedAndDeleted(): void
    {
        $app = $this->createApp(null);
        $token = $this->token($app, 1);
        $id = $this->json($this->send($app, 'POST', '/api/v1/terms/category', ['name' => 'Cuisine'], $token))['data']['id'] ?? 0;

        $renamed = $this->send($app, 'PATCH', '/api/v1/terms/category/' . $id, ['name' => 'Gastronomie'], $token);
        $this->assertSame('Gastronomie', $this->json($renamed)['data']['name'] ?? null);

        $this->assertSame(403, $this->send($app, 'DELETE', '/api/v1/terms/category/' . $id, token: $this->token($app, 2))->status());
        $this->assertSame(204, $this->send($app, 'DELETE', '/api/v1/terms/category/' . $id, token: $token)->status());
        $this->assertSame(404, $this->send($app, 'GET', '/api/v1/terms/category/' . $id)->status());
    }

    public function testTheCreatedEntryIsLocatedAndCarriesItsEtag(): void
    {
        $app = $this->createApp(null);
        $response = $this->send($app, 'POST', '/api/v1/content/post', ['title' => 'Situé'], $this->token($app, 1));

        $id = $this->json($response)['data']['id'] ?? 0;
        $this->assertStringEndsWith('/api/v1/content/post/' . $id, (string) $response->header('Location'));
        $this->assertStringStartsWith('W/"' . $id . '-', (string) $response->header('ETag'));
        $this->assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
    }

    private function token(Application $app, int $userId): string
    {
        return $this->tokens($app)->issue($this->user($app, $userId), 'comportement', ['*'])['token'];
    }

    private function countEntries(Application $app): int
    {
        return (int) ($this->db($app)->selectOne('SELECT COUNT(*) AS n FROM pc_entries')['n'] ?? 0);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $headers
     * @param array<string, string>     $query
     */
    private function send(Application $app, string $method, string $uri, ?array $json = null, ?string $token = null, array $headers = [], array $query = []): Response
    {
        $headers += ['Accept' => 'application/json'];
        $raw = '';
        if ($json !== null) {
            $raw = (string) json_encode($json, JSON_UNESCAPED_UNICODE);
            $headers += ['Content-Type' => 'application/json'];
        }
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $app->get(Kernel::class)->handle(Request::create($method, $uri, query: $query, headers: $headers, rawBody: $raw));
    }
}
