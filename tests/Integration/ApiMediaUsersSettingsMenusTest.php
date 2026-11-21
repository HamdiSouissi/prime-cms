<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Kernel\Application;
use Prime\Media\MediaConfig;
use Prime\Plugins\AssetPublisher;
use Prime\Tests\Support\ApiTestFixture;
use Prime\Tests\Support\MediaFixtures;

/**
 * Step 6 of the REST API: media library, accounts, settings and menus.
 */
final class ApiMediaUsersSettingsMenusTest extends TestCase
{
    use ApiTestFixture;

    private string $tmpDir = '';

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            AssetPublisher::deleteDirectory($this->tmpDir);
        }
        $this->tmpDir = '';
    }

    // --- Media ------------------------------------------------------------------------------

    public function testAnImageIsUploadedReencodedAndListed(): void
    {
        $app = $this->app();
        $token = $this->token($app, 1);
        $jpg = MediaFixtures::createJpeg($this->tmpDir . '/source.jpg', 640, 480);

        $response = $this->upload($app, $token, $jpg, 'vacances.jpg', 'image/jpeg', ['alt' => 'Plage']);

        $this->assertSame(201, $response->status(), $response->body());
        $data = $this->json($response)['data'] ?? [];
        $this->assertSame('image', $data['kind'] ?? null);
        $this->assertSame('Plage', $data['alt'] ?? null);
        $this->assertSame(640, $data['width'] ?? null);
        $this->assertStringStartsWith('http', (string) ($data['url'] ?? ''));
        $this->assertArrayNotHasKey('disk_path', $data);
        $this->assertArrayNotHasKey('checksum', $data);

        $listed = array_column($this->json($this->call($app, 'GET', '/api/v1/media', token: $token, query: ['kind' => 'image']))['data'] ?? [], 'id');
        $this->assertContains($data['id'], $listed);
    }

    public function testASvgIsRefused(): void
    {
        $app = $this->app();
        $svg = $this->tmpDir . '/logo.svg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $response = $this->upload($app, $this->token($app, 1), $svg, 'logo.svg', 'image/svg+xml');

        $this->assertSame(422, $response->status());
        $this->assertSame('validation_failed', $this->json($response)['error']['code'] ?? null);
        $this->assertSame(0, (int) ($this->db($app)->selectOne('SELECT COUNT(*) AS n FROM pc_media')['n'] ?? -1));
    }

    public function testATooLargeUploadIsRefused(): void
    {
        $app = $this->app();
        $jpg = MediaFixtures::createJpeg($this->tmpDir . '/big.jpg', 64, 64);

        $response = $app->get(Kernel::class)->handle(Request::create('POST', '/api/v1/media', headers: [
            'Authorization' => 'Bearer ' . $this->token($app, 1),
            'Content-Type' => 'multipart/form-data; boundary=x',
        ], files: ['file' => MediaFixtures::uploadedFile($jpg, 'big.jpg', 'image/jpeg')], server: ['CONTENT_LENGTH' => (string) (5 * 1024 * 1024)]));

        $this->assertSame(413, $response->status());
    }

    public function testAJsonBodyIsRefusedOnTheUploadRoute(): void
    {
        $app = $this->app();
        $response = $this->call($app, 'POST', '/api/v1/media', ['file' => 'x'], $this->token($app, 1));

        $this->assertSame(415, $response->status());
    }

    public function testAMediaInUseCannotBeDeletedWithoutForce(): void
    {
        $app = $this->app();
        $token = $this->token($app, 1);
        $id = $this->json($this->upload($app, $token, MediaFixtures::createJpeg($this->tmpDir . '/used.jpg', 80, 80), 'used.jpg', 'image/jpeg'))['data']['id'] ?? 0;
        $this->db($app)->pdo()->exec("INSERT INTO pc_media_usage (media_id, entry_id, context) VALUES ($id, 1, 'content')");

        $this->assertSame(409, $this->call($app, 'DELETE', '/api/v1/media/' . $id, token: $token)->status());
        $this->assertSame(204, $this->call($app, 'DELETE', '/api/v1/media/' . $id, token: $token, query: ['force' => '1'])->status());
        $this->assertNull($this->db($app)->selectOne('SELECT id FROM pc_media WHERE id = ?', [$id]));
    }

    public function testMetadataCanBeEditedButNotSomeoneElsesByAContributor(): void
    {
        $app = $this->app();
        $id = $this->json($this->upload($app, $this->token($app, 1), MediaFixtures::createJpeg($this->tmpDir . '/m.jpg', 80, 80), 'm.jpg', 'image/jpeg'))['data']['id'] ?? 0;

        $updated = $this->call($app, 'PATCH', '/api/v1/media/' . $id, ['alt' => 'Nouveau texte', 'focal_x' => 0.2], $this->token($app, 1));
        $this->assertSame(200, $updated->status(), $updated->body());
        $this->assertSame('Nouveau texte', $this->json($updated)['data']['alt'] ?? null);

        $this->assertSame(403, $this->call($app, 'PATCH', '/api/v1/media/' . $id, ['alt' => 'x'], $this->token($app, 2))->status());
    }

    // --- Users ------------------------------------------------------------------------------

    public function testAccountsNeverLeakSecretsAndNeedUsersView(): void
    {
        $app = $this->app();

        $this->assertSame(403, $this->call($app, 'GET', '/api/v1/users', token: $this->token($app, 2))->status());

        $response = $this->call($app, 'GET', '/api/v1/users', token: $this->token($app, 1));
        $this->assertSame(200, $response->status());
        $body = $response->body();
        foreach (['password_hash', 'two_factor_secret', 'recovery', 'token_hash'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertContains('contrib@prime.test', array_column($this->json($response)['data'] ?? [], 'email'));
    }

    // --- Settings ---------------------------------------------------------------------------

    public function testSecretsAreNeverReturnedAndPublicReadIsLimited(): void
    {
        $app = $this->app();
        $app->get(\Prime\Settings\Settings::class)->setSecret('mail.password', 'tres-secret');

        $public = $this->call($app, 'GET', '/api/v1/settings')->body();
        $this->assertStringNotContainsString('tres-secret', $public);
        $this->assertStringNotContainsString('api.rate_limit_authenticated', $public, 'un anonyme ne lit que les réglages publics');

        $manager = $this->call($app, 'GET', '/api/v1/settings', token: $this->token($app, 1));
        $this->assertStringNotContainsString('tres-secret', $manager->body());
        $this->assertTrue($this->json($manager)['meta']['secrets_configured']['mail.password'] ?? false);
        $this->assertArrayHasKey('api.rate_limit_authenticated', $this->json($manager)['data'] ?? []);
    }

    public function testAnInvalidSettingIs422AndNothingIsWritten(): void
    {
        $app = $this->app();
        $token = $this->token($app, 1);

        $invalid = $this->call($app, 'PATCH', '/api/v1/settings', ['api.rate_limit_anonymous' => 0, 'site.name' => 'Ne doit pas être écrit'], $token);
        $this->assertSame(422, $invalid->status(), $invalid->body());
        $this->assertArrayHasKey('api.rate_limit_anonymous', $this->json($invalid)['error']['fields'] ?? []);
        $this->assertNotSame('Ne doit pas être écrit', $app->get(\Prime\Settings\Settings::class)->getString('site.name'));

        $this->assertSame(422, $this->call($app, 'PATCH', '/api/v1/settings', ['nexiste.pas' => 1], $token)->status());

        $ok = $this->call($app, 'PATCH', '/api/v1/settings', ['api.rate_limit_anonymous' => 30], $token);
        $this->assertSame(200, $ok->status(), $ok->body());
        $this->assertSame(30, $app->get(\Prime\Settings\Settings::class)->getInt('api.rate_limit_anonymous'));

        $this->assertSame(403, $this->call($app, 'PATCH', '/api/v1/settings', ['api.rate_limit_anonymous' => 31], $this->token($app, 2))->status());
    }

    // --- Menus ------------------------------------------------------------------------------

    public function testAMenuHidesItemsPointingToDrafts(): void
    {
        $app = $this->app();
        $pdo = $this->db($app)->pdo();
        $now = '2026-11-15 10:00:00';
        $pdo->exec("INSERT INTO pc_menus (id, name, slug, location, created_at, updated_at) VALUES (1, 'Principal', 'principal', 'primary', '$now', '$now')");
        $pdo->exec("INSERT INTO pc_menu_items (menu_id, position, type, target_id, label, created_at, updated_at) VALUES (1, 1, 'entry', 1, 'Article', '$now', '$now'), (1, 2, 'entry', 2, 'Brouillon', '$now', '$now'), (1, 3, 'url', NULL, 'Externe', '$now', '$now')");
        $pdo->exec("UPDATE pc_menu_items SET url = 'https://exemple.test' WHERE label = 'Externe'");

        $response = $this->call($app, 'GET', '/api/v1/menus/primary');

        $this->assertSame(200, $response->status(), $response->body());
        $this->assertSame(['Article', 'Externe'], array_column($this->json($response)['data']['items'] ?? [], 'label'));
        $this->assertSame(404, $this->call($app, 'GET', '/api/v1/menus/footer')->status());
    }

    // --- Helpers ----------------------------------------------------------------------------

    private function app(): Application
    {
        $app = $this->createApp(null);
        $this->tmpDir = sys_get_temp_dir() . '/prime-api-media-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0775, true);

        $raw = $app->config()->get('media', []);
        $app->container()->instance(MediaConfig::class, new MediaConfig($this->tmpDir, is_array($raw) ? $raw : []));

        $pdo = $this->db($app)->pdo();
        $pdo->exec('CREATE TABLE pc_media_usage (media_id INTEGER, entry_id INTEGER, context VARCHAR(100), PRIMARY KEY (media_id, entry_id, context))');
        $pdo->exec('CREATE TABLE pc_media_folders (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER NULL, name VARCHAR(120), slug VARCHAR(140), created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_menus (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(150) NOT NULL, slug VARCHAR(120) NOT NULL UNIQUE, location VARCHAR(80) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $pdo->exec("CREATE TABLE pc_menu_items (id INTEGER PRIMARY KEY AUTOINCREMENT, menu_id INTEGER NOT NULL, parent_id INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0, type VARCHAR(20) NOT NULL DEFAULT 'url', target_id INTEGER NULL, url VARCHAR(500) NULL, label VARCHAR(255) NOT NULL DEFAULT '', target VARCHAR(16) NOT NULL DEFAULT '_self', css_class VARCHAR(255) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)");

        return $app;
    }

    private function token(Application $app, int $userId): string
    {
        return $this->tokens($app)->issue($this->user($app, $userId), 'etape6', ['*'])['token'];
    }

    /**
     * @param array<string, string> $fields
     */
    private function upload(Application $app, string $token, string $path, string $name, string $mime, array $fields = []): Response
    {
        return $app->get(Kernel::class)->handle(Request::create('POST', '/api/v1/media', body: $fields, headers: [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'multipart/form-data; boundary=x',
            'Accept' => 'application/json',
        ], files: ['file' => MediaFixtures::uploadedFile($path, $name, $mime)]));
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $query
     */
    private function call(Application $app, string $method, string $uri, ?array $json = null, ?string $token = null, array $query = []): Response
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

        return $app->get(Kernel::class)->handle(Request::create($method, $uri, query: $query, headers: $headers, rawBody: $raw));
    }
}
