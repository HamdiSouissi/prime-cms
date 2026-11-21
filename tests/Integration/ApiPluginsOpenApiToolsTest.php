<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use HelloPrime\Api\GreetingsApi;
use LogicException;
use PHPUnit\Framework\TestCase;
use Prime\Api\ApiRouteRegistry;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Kernel\Application;
use Prime\Log\Logger;
use Prime\Plugins\PluginApi;
use Prime\Security\Csrf;
use Prime\Tests\Support\ApiTestFixture;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/content/plugins/hello-prime/src/Api/GreetingsApi.php';

/**
 * Step 7 of the REST API: plugin routes, the OpenAPI document, « Outils › API », token events.
 */
final class ApiPluginsOpenApiToolsTest extends TestCase
{
    use ApiTestFixture;

    // --- Plugin routes ----------------------------------------------------------------------

    public function testAPluginServesItsRoutesUnderItsOwnNamespace(): void
    {
        $app = $this->createApp(null);
        $api = $this->pluginApi($app, 'demo');
        $api->get('/hello', static fn (Request $request): Response => Response::json(['data' => 'bonjour']), public: true);
        $api->post('/hello', static fn (Request $request): Response => Response::json(['data' => $request->attribute('api.body')], 201), permission: 'settings.manage');

        $this->assertSame('bonjour', $this->json($this->call($app, 'GET', '/api/demo/v1/hello'))['data'] ?? null);
        $this->assertSame(401, $this->call($app, 'POST', '/api/demo/v1/hello', ['a' => 1])->status());
        $this->assertSame(403, $this->call($app, 'POST', '/api/demo/v1/hello', ['a' => 1], $this->token($app, 2))->status());

        $created = $this->call($app, 'POST', '/api/demo/v1/hello', ['a' => 1], $this->token($app, 1));
        $this->assertSame(201, $created->status());
        $this->assertSame(['a' => 1], $this->json($created)['data'] ?? null);
    }

    public function testAFailingPluginHandlerIsAnOpaque500(): void
    {
        $app = $this->createApp(null);
        $this->pluginApi($app, 'broken')->get('/boom', static function (): never {
            throw new RuntimeException('secret-detail');
        }, public: true);

        $response = $this->call($app, 'GET', '/api/broken/v1/boom');

        $this->assertSame(500, $response->status());
        $this->assertSame('server_error', $this->json($response)['error']['code'] ?? null);
        $this->assertStringNotContainsString('secret-detail', $response->body());
    }

    public function testAPluginCannotDeclareTheSameRouteTwice(): void
    {
        $api = $this->pluginApi($this->createApp(null), 'demo');
        $api->get('/items/{id}', static fn (): Response => Response::json([]), public: true);

        $this->expectException(LogicException::class);
        $api->get('/items/{slug}', static fn (): Response => Response::json([]), public: true);
    }

    public function testHelloPrimeGreetingsAreReadPubliclyAndWrittenWithItsCapability(): void
    {
        $app = $this->createApp(null);
        $this->db($app)->pdo()->exec('CREATE TABLE pc_hello_prime_greetings (id INTEGER PRIMARY KEY AUTOINCREMENT, author VARCHAR(80), message VARCHAR(280), created_at DATETIME)');
        // The plugin declares its capability when it boots; the fixture does not load plugins.
        $app->get(Hooks::class)->filter('auth.capabilities', static fn (array $caps): array => $caps + ['hello_prime.manage' => ['label' => 'Hello Prime', 'group' => 'Hello Prime']]);
        $api = $this->pluginApi($app, 'hello-prime');
        $api->get('/greetings', [GreetingsApi::class, 'index'], public: true);
        $api->post('/greetings', [GreetingsApi::class, 'store'], permission: 'hello_prime.manage');

        $this->assertSame(422, $this->call($app, 'POST', '/api/hello-prime/v1/greetings', ['message' => ''], $this->token($app, 1))->status());
        $this->assertSame(201, $this->call($app, 'POST', '/api/hello-prime/v1/greetings', ['message' => 'Salut !'], $this->token($app, 1))->status());
        $this->assertSame(403, $this->call($app, 'POST', '/api/hello-prime/v1/greetings', ['message' => 'Non'], $this->token($app, 2))->status());

        $this->assertSame(['Salut !'], array_column($this->json($this->call($app, 'GET', '/api/hello-prime/v1/greetings'))['data'] ?? [], 'message'));
    }

    // --- OpenAPI ----------------------------------------------------------------------------

    public function testTheDocumentDescribesOnlyWhatTheCallerMayUse(): void
    {
        $app = $this->createApp(null);

        $anonymous = $this->json($this->call($app, 'GET', '/api/v1/openapi.json'));
        $this->assertSame('3.1.0', $anonymous['openapi'] ?? null);
        $this->assertArrayHasKey('get', $anonymous['paths']['/api/v1/content/{type}'] ?? []);
        $this->assertArrayNotHasKey('post', $anonymous['paths']['/api/v1/content/{type}'] ?? []);
        $this->assertArrayNotHasKey('/api/v1/users', $anonymous['paths'] ?? []);

        $admin = $this->json($this->call($app, 'GET', '/api/v1/openapi.json', token: $this->token($app, 1)));
        $this->assertArrayHasKey('post', $admin['paths']['/api/v1/content/{type}'] ?? []);
        $this->assertArrayHasKey('/api/v1/users', $admin['paths'] ?? []);
        $this->assertSame('content.create', $admin['paths']['/api/v1/content/{type}']['post']['x-prime-capability'] ?? null);
    }

    public function testEveryRouteIsDocumentedAndNoReferenceIsBroken(): void
    {
        $app = $this->createApp(null);
        $this->pluginApi($app, 'demo')->get('/things', static fn (): Response => Response::json([]), public: true, options: [
            'response' => ['$ref' => '#/components/schemas/DemoThing'],
        ]);
        $app->get(ApiRouteRegistry::class)->addSchema('DemoThing', ['type' => 'object']);

        $document = $this->json($this->call($app, 'GET', '/api/v1/openapi.json', token: $this->token($app, 1)));

        foreach ($app->get(ApiRouteRegistry::class)->all() as ['prefix' => $prefix, 'route' => $route]) {
            $path = $route->documentedPath() === '/' ? $prefix : $prefix . $route->documentedPath();
            $this->assertArrayHasKey(strtolower($route->method), $document['paths'][$path] ?? [], $route->method . ' ' . $path . ' n’est pas documentée');
        }

        preg_match_all('~"\$ref":"#\\\\/components\\\\/(schemas|responses)\\\\/([A-Za-z0-9]+)"~', (string) json_encode($document), $refs, PREG_SET_ORDER);
        $this->assertNotEmpty($refs);
        foreach ($refs as [, $kind, $name]) {
            $this->assertArrayHasKey($name, $document['components'][$kind] ?? [], 'référence cassée : ' . $kind . '/' . $name);
        }
    }

    // --- Outils › API -----------------------------------------------------------------------

    public function testTheToolsScreenListsEveryActiveTokenAndRevokesOne(): void
    {
        $app = $this->createApp('admin');
        $issued = $this->tokens($app)->issue($this->user($app, 2), 'Appli du contributeur', ['content.view']);
        $revoked = $this->tokens($app)->issue($this->user($app, 2), 'Jeton-revoque-zz', ['content.view']);
        $this->tokens($app)->revoke($revoked['id']);

        $page = $app->get(Kernel::class)->handle(Request::create('GET', '/admin/tools/api'));
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Appli du contributeur', $page->body());
        $this->assertStringNotContainsString('Jeton-revoque-zz', $page->body(), 'seuls les jetons actifs sont listés');
        $this->assertStringContainsString('action="/admin/tools/api/tokens/' . $issued['id'] . '/revoke"', $page->body());

        $response = $app->get(Kernel::class)->handle(Request::create('POST', '/admin/tools/api/tokens/' . $issued['id'] . '/revoke', body: [Csrf::FIELD_NAME => $app->get(Csrf::class)->token()]));
        $this->assertSame(302, $response->status());
        $this->assertNull($this->tokens($app)->authenticate($issued['token']));
    }

    public function testTheToolsScreenNeedsSettingsManage(): void
    {
        $response = $this->createApp('contributor')->get(Kernel::class)->handle(Request::create('GET', '/admin/tools/api'));

        $this->assertContains($response->status(), [302, 403]);
    }

    // --- Events and password change ---------------------------------------------------------

    public function testTokenLifecycleEventsAreEmitted(): void
    {
        $app = $this->createApp(null);
        $events = [];
        foreach (['api.token.created', 'api.token.revoked'] as $name) {
            $app->get(Hooks::class)->on($name, static function (array $payload) use (&$events, $name): void {
                $events[] = [$name, $payload['user_id'] ?? null];
            });
        }

        $issued = $this->tokens($app)->issue($this->user($app, 2), 'evt', ['content.view']);
        $this->tokens($app)->revoke($issued['id']);
        $this->tokens($app)->revoke($issued['id']);

        $this->assertSame([['api.token.created', 2], ['api.token.revoked', 2]], $events);
    }

    public function testATokenWithoutDurationStillExpires(): void
    {
        $app = $this->createApp(null);
        $issued = $this->tokens($app)->issue($this->user($app, 1), 'sans durée', ['content.view']);

        $this->assertNotNull($this->db($app)->selectOne('SELECT expires_at FROM pc_api_tokens WHERE id = ?', [$issued['id']])['expires_at'] ?? null);
    }

    public function testRevokingEveryTokenOfAnAccount(): void
    {
        $app = $this->createApp(null);
        $a = $this->tokens($app)->issue($this->user($app, 2), 'a', ['content.view']);
        $b = $this->tokens($app)->issue($this->user($app, 2), 'b', ['content.view']);
        $other = $this->tokens($app)->issue($this->user($app, 1), 'c', ['content.view']);

        $this->assertSame(2, $this->tokens($app)->revokeAllForUser(2));
        $this->assertNull($this->tokens($app)->authenticate($a['token']));
        $this->assertNull($this->tokens($app)->authenticate($b['token']));
        $this->assertNotNull($this->tokens($app)->authenticate($other['token']));
    }

    // --- Helpers ----------------------------------------------------------------------------

    private function pluginApi(Application $app, string $slug): PluginApi
    {
        return new PluginApi($app, $app->get(ApiRouteRegistry::class), $app->get(Logger::class), $slug);
    }

    private function token(Application $app, int $userId): string
    {
        return $this->tokens($app)->issue($this->user($app, $userId), 'etape7', ['*'])['token'];
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function call(Application $app, string $method, string $uri, ?array $json = null, ?string $token = null): Response
    {
        $headers = ['Accept' => 'application/json'];
        $raw = '';
        if ($json !== null) {
            $raw = (string) json_encode($json);
            $headers['Content-Type'] = 'application/json';
        }
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $app->get(Kernel::class)->handle(Request::create($method, $uri, headers: $headers, rawBody: $raw));
    }
}
