<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Prime\Api\Token\ApiTokenService;
use Prime\Auth\Auth;
use Prime\Auth\UserRepository;
use Prime\Database\Connection;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;
use Prime\Tests\Support\MysqlEmulatingPdo;

/**
 * Contrat d'acceptation de l'API REST, phase 10 — étape 2.
 *
 * Écrit par Claude (revue). **Ce fichier ne doit pas être modifié** par l'implémentation :
 * le code de `src/` doit le faire passer tel quel. Chaque test décrit un comportement exigé
 * par `PHASE-10-prompt.md` et `PHASE-10-etape-2.md`.
 *
 * Données : un administrateur (id 1, capacités `*`), un contributeur (id 2), un article publié
 * et deux brouillons (un par auteur).
 */
final class ApiContractTest extends TestCase
{
    private const NOW = '2026-11-15 10:00:00';

    // ---------------------------------------------------------------------------------
    // Service des jetons
    // ---------------------------------------------------------------------------------

    public function testIssuedTokenHasThePcatFormatAndOnlyItsHashIsStored(): void
    {
        $app = $this->createApp(null);
        $issued = $this->tokens($app)->issue($this->user($app, 1), 'CLI', ['*']);

        $this->assertMatchesRegularExpression('/^pcat_[A-Za-z0-9]{8}_[A-Za-z0-9]{40}$/', $issued['token']);
        $this->assertGreaterThan(0, $issued['id']);

        $row = $this->db($app)->selectOne('SELECT * FROM pc_api_tokens WHERE id = ?', [$issued['id']]);
        $this->assertIsArray($row);
        $this->assertArrayNotHasKey('token', $row, 'aucune colonne ne doit contenir le jeton en clair');
        $this->assertSame(hash('sha256', $issued['token']), $row['token_hash']);
        $this->assertSame(substr($issued['token'], 5, 8), $row['token_prefix']);
        foreach ($row as $value) {
            $this->assertNotSame($issued['token'], $value);
        }
    }

    public function testAuthenticateAcceptsTheIssuedTokenAndRejectsAWrongSecretWithTheSamePrefix(): void
    {
        $app = $this->createApp(null);
        $issued = $this->tokens($app)->issue($this->user($app, 1), 'CLI', ['*']);

        $auth = $this->tokens($app)->authenticate($issued['token']);
        $this->assertNotNull($auth);
        $this->assertSame(1, $auth['user']->id);
        $this->assertSame($issued['id'], $auth['token_id']);

        $forged = substr($issued['token'], 0, 14) . str_repeat('A', 40);
        $this->assertNull($this->tokens($app)->authenticate($forged));
        $this->assertNull($this->tokens($app)->authenticate('not-a-token'));
    }

    public function testRevokedExpiredOrDisabledUserTokensAreRejected(): void
    {
        $app = $this->createApp(null);
        $service = $this->tokens($app);

        $revoked = $service->issue($this->user($app, 1), 'revoked', ['*']);
        $service->revoke($revoked['id']);
        $this->assertNull($service->authenticate($revoked['token']));

        $expired = $service->issue($this->user($app, 1), 'expired', ['*'], new DateTimeImmutable('-1 hour', new DateTimeZone('UTC')));
        $this->assertNull($service->authenticate($expired['token']));

        $contributor = $service->issue($this->user($app, 2), 'contrib', ['*']);
        $this->db($app)->statement("UPDATE pc_users SET status = 'disabled' WHERE id = 2");
        $this->assertNull($service->authenticate($contributor['token']));
    }

    public function testTokenAbilitiesAreLimitedToTheUsersOwnCapabilities(): void
    {
        $app = $this->createApp(null);
        $issued = $this->tokens($app)->issue($this->user($app, 2), 'contrib', ['content.view', 'settings.manage']);
        $auth = $this->tokens($app)->authenticate($issued['token']);

        $this->assertNotNull($auth);
        $this->assertContains('content.view', $auth['abilities']);
        $this->assertNotContains('settings.manage', $auth['abilities'], 'un contributeur ne peut pas se donner settings.manage');
    }

    // ---------------------------------------------------------------------------------
    // Authentification HTTP et format des erreurs
    // ---------------------------------------------------------------------------------

    public function testApiIndexIsPublic(): void
    {
        $response = $this->get($this->createApp(null), '/api/v1');

        $this->assertSame(200, $response->status());
        $json = $this->json($response);
        $this->assertSame('v1', $json['data']['api_version'] ?? null);
        $this->assertFalse($json['data']['authenticated'] ?? true);
    }

    public function testMeWithoutCredentialsIsAJson401(): void
    {
        $response = $this->get($this->createApp(null), '/api/v1/me');

        $this->assertSame(401, $response->status());
        $this->assertStringContainsString('application/json', (string) $response->header('Content-Type'));
        $this->assertSame('unauthenticated', $this->json($response)['error']['code'] ?? null);
        $this->assertSame(401, $this->json($response)['error']['status'] ?? null);
    }

    public function testATokenAloneAuthenticatesWithoutAnyAdminSession(): void
    {
        $app = $this->createApp(null);
        $issued = $this->tokens($app)->issue($this->user($app, 1), 'client', ['*']);

        $response = $this->get($app, '/api/v1/me', ['Authorization' => 'Bearer ' . $issued['token']]);

        $this->assertSame(200, $response->status(), $response->body());
        $this->assertSame(1, $this->json($response)['data']['id'] ?? null);
    }

    public function testAnInvalidBearerTokenIsRejected(): void
    {
        $response = $this->get($this->createApp(null), '/api/v1/me', ['Authorization' => 'Bearer pcat_AAAAAAAA_' . str_repeat('B', 40)]);

        $this->assertSame(401, $response->status());
        $this->assertSame('unauthenticated', $this->json($response)['error']['code'] ?? null);
    }

    public function testATokenInTheQueryStringIsRefused(): void
    {
        $app = $this->createApp(null);
        $issued = $this->tokens($app)->issue($this->user($app, 1), 'client', ['*']);

        $response = $this->get($app, '/api/v1/me', [], ['access_token' => $issued['token']]);

        $this->assertSame(400, $response->status());
        $this->assertSame('invalid_request', $this->json($response)['error']['code'] ?? null);
    }

    public function testAnUnknownApiRouteIsAJson404(): void
    {
        $response = $this->get($this->createApp(null), '/api/v1/does-not-exist');

        $this->assertSame(404, $response->status());
        $this->assertStringContainsString('application/json', (string) $response->header('Content-Type'));
        $this->assertSame('not_found', $this->json($response)['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------------------------
    // Contenus : lecture publique et permissions
    // ---------------------------------------------------------------------------------

    public function testAnonymousListingOnlyContainsPublishedEntriesEvenWithAStatusFilter(): void
    {
        $app = $this->createApp(null);

        foreach (['/api/v1/content/post', '/api/v1/content/post?status=draft'] as $uri) {
            $response = $this->get($app, $uri);
            $this->assertSame(200, $response->status(), $uri);
            $titles = array_column($this->json($response)['data'] ?? [], 'title');
            $this->assertContains('Article publié', $titles, $uri);
            $this->assertNotContains('Brouillon admin', $titles, $uri);
            $this->assertNotContains('Brouillon contributeur', $titles, $uri);
        }
    }

    public function testAContributorTokenCannotSeeAnotherAuthorsDraft(): void
    {
        $app = $this->createApp(null);
        $issued = $this->tokens($app)->issue($this->user($app, 2), 'contrib', ['*']);

        $response = $this->get($app, '/api/v1/content/post?status=draft', ['Authorization' => 'Bearer ' . $issued['token']]);

        $titles = array_column($this->json($response)['data'] ?? [], 'title');
        $this->assertNotContains('Brouillon admin', $titles);
    }

    public function testAnAdministratorTokenSeesDrafts(): void
    {
        $app = $this->createApp(null);
        $issued = $this->tokens($app)->issue($this->user($app, 1), 'admin', ['*']);

        $response = $this->get($app, '/api/v1/content/post?status=draft', ['Authorization' => 'Bearer ' . $issued['token']]);

        $this->assertSame(200, $response->status());
        $this->assertContains('Brouillon admin', array_column($this->json($response)['data'] ?? [], 'title'));
    }

    public function testADraftRequestedBySlugWithoutPermissionIsA404(): void
    {
        $response = $this->get($this->createApp(null), '/api/v1/content/post/brouillon-admin');

        $this->assertSame(404, $response->status());
        $this->assertSame('not_found', $this->json($response)['error']['code'] ?? null);
    }

    public function testAPublishedEntryIsReadableBySlugWithItsPublicUrl(): void
    {
        $response = $this->get($this->createApp(null), '/api/v1/content/post/article-publie');

        $this->assertSame(200, $response->status(), $response->body());
        $data = $this->json($response)['data'] ?? [];
        $this->assertSame('Article publié', $data['title'] ?? null);
        $this->assertStringContainsString('article-publie', (string) ($data['url'] ?? ''));
    }

    public function testNoResponseExposesAnAuthorEmail(): void
    {
        $app = $this->createApp(null);
        $issued = $this->tokens($app)->issue($this->user($app, 1), 'admin', ['*']);

        foreach (['/api/v1/content/post', '/api/v1/content/post/article-publie'] as $uri) {
            $body = $this->get($app, $uri, ['Authorization' => 'Bearer ' . $issued['token']])->body();
            $this->assertStringNotContainsString('admin@prime.test', $body, $uri);
            $this->assertStringNotContainsString('contrib@prime.test', $body, $uri);
        }
    }

    public function testPaginationIsCappedAndCounted(): void
    {
        $response = $this->get($this->createApp(null), '/api/v1/content/post?per_page=500');

        $this->assertSame(200, $response->status());
        $meta = $this->json($response)['meta'] ?? [];
        $this->assertSame(100, $meta['per_page'] ?? null);
        $this->assertSame(1, $meta['page'] ?? null);
        $this->assertSame(1, $meta['total'] ?? null);
        $this->assertSame(1, $meta['total_pages'] ?? null);
        $this->assertSame('1', $response->header('X-Total-Count'));
    }

    public function testAnUnknownContentTypeIsA404(): void
    {
        $response = $this->get($this->createApp(null), '/api/v1/content/nope');

        $this->assertSame(404, $response->status());
        $this->assertSame('not_found', $this->json($response)['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------------------------
    // Outils
    // ---------------------------------------------------------------------------------

    private function createApp(?string $authenticateAs): Application
    {
        $app = new Application(dirname(__DIR__, 2), loadEnv: false);
        $app->config()->set('app.env', 'testing');
        $app->config()->set('app.debug', false);
        $app->config()->set('app.url', 'https://prime.test');
        $app->config()->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app->config()->set('cache.page.enabled', false);
        $app->config()->set('cache.page.directory', sys_get_temp_dir() . '/prime_api_pages_' . uniqid('', true));
        $app->boot();

        $app->container()->instance(InstallCheckerInterface::class, new class () implements InstallCheckerInterface {
            public function isInstalled(): bool
            {
                return true;
            }
        });

        $pdo = new MysqlEmulatingPdo('name');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE pc_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(191) UNIQUE, username VARCHAR(80) NULL UNIQUE, bio TEXT NULL, password_hash VARCHAR(255), display_name VARCHAR(191), status VARCHAR(20), locale VARCHAR(10), avatar_media_id INTEGER NULL, last_login_at DATETIME NULL, session_version INTEGER DEFAULT 1, two_factor_secret TEXT NULL, two_factor_recovery_codes TEXT NULL, two_factor_confirmed_at DATETIME NULL, two_factor_last_step INTEGER NULL, password_changed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, slug VARCHAR(191) UNIQUE, name VARCHAR(191), description TEXT NULL, is_system INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_role_capabilities (role_id INTEGER, capability VARCHAR(100), PRIMARY KEY (role_id, capability))');
        $pdo->exec('CREATE TABLE pc_user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id))');
        $pdo->exec('CREATE TABLE pc_remember_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, selector CHAR(24) UNIQUE, validator_hash CHAR(64), expires_at DATETIME, last_used_at DATETIME NULL, user_agent VARCHAR(255) NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash CHAR(64) UNIQUE, expires_at DATETIME, used_at DATETIME NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_rate_limits (key CHAR(64) PRIMARY KEY, hits INTEGER DEFAULT 0, reset_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(191) NOT NULL UNIQUE, value LONGTEXT NULL, autoload INTEGER NOT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $pdo->exec('CREATE TABLE pc_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, type VARCHAR(40), status VARCHAR(20), title VARCHAR(255), slug VARCHAR(191), excerpt TEXT NULL, content LONGTEXT, content_html LONGTEXT, content_text LONGTEXT, author_id INTEGER NULL, parent_id INTEGER DEFAULT 0, menu_order INTEGER DEFAULT 0, template VARCHAR(100) NULL, published_at DATETIME NULL, previous_status VARCHAR(20) NULL, trashed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME, UNIQUE (type, parent_id, slug))');
        $pdo->exec("CREATE TABLE pc_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue VARCHAR(64) DEFAULT 'default', handler VARCHAR(191), payload TEXT, attempts INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 3, available_at DATETIME, reserved_at DATETIME NULL, failed_at DATETIME NULL, last_error TEXT NULL, created_at DATETIME)");
        $pdo->exec('CREATE TABLE pc_entry_meta (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER, meta_key VARCHAR(191), meta_value LONGTEXT, UNIQUE (entry_id, meta_key))');
        $pdo->exec('CREATE TABLE pc_terms (id INTEGER PRIMARY KEY AUTOINCREMENT, taxonomy VARCHAR(40), name VARCHAR(191), slug VARCHAR(191), description TEXT NULL, parent_id INTEGER DEFAULT 0, menu_order INTEGER DEFAULT 0, count INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME, UNIQUE (taxonomy, slug))');
        $pdo->exec('CREATE TABLE pc_entry_terms (entry_id INTEGER, term_id INTEGER, position INTEGER DEFAULT 0, PRIMARY KEY (entry_id, term_id))');
        $pdo->exec('CREATE TABLE pc_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER, author_id INTEGER NULL, is_autosave INTEGER DEFAULT 0, title VARCHAR(255), excerpt TEXT NULL, content LONGTEXT, meta LONGTEXT NULL, created_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_entry_locks (entry_id INTEGER PRIMARY KEY, user_id INTEGER, acquired_at DATETIME, heartbeat_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_media (id INTEGER PRIMARY KEY AUTOINCREMENT, folder_id INTEGER NULL, author_id INTEGER NULL, disk_path VARCHAR(255) UNIQUE, filename VARCHAR(255), original_name VARCHAR(255), extension VARCHAR(16), mime_type VARCHAR(120), kind VARCHAR(24), size_bytes INTEGER DEFAULT 0, width INTEGER NULL, height INTEGER NULL, checksum CHAR(64), alt VARCHAR(255), title VARCHAR(255), caption TEXT NULL, description TEXT NULL, focal_x REAL DEFAULT 0.5, focal_y REAL DEFAULT 0.5, variants LONGTEXT NULL, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec('CREATE TABLE pc_redirects (id INTEGER PRIMARY KEY AUTOINCREMENT, source_path VARCHAR(255) NOT NULL UNIQUE, target_path VARCHAR(255) NOT NULL, status_code INTEGER NOT NULL DEFAULT 301, hits INTEGER NOT NULL DEFAULT 0, last_hit_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        // Table des jetons telle que décrite au point 2.4 de l'étape 2 (miroir de la migration MySQL).
        $pdo->exec('CREATE TABLE pc_api_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, name VARCHAR(100) NOT NULL, token_prefix VARCHAR(12) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, abilities TEXT NOT NULL, last_used_at DATETIME NULL, last_used_ip VARCHAR(45) NULL, expires_at DATETIME NULL, revoked_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');

        $now = self::NOW;
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, is_system, created_at, updated_at) VALUES (1, 'administrator', 'Administrateur', 1, '$now', '$now'), (2, 'contributor', 'Contributeur', 1, '$now', '$now')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (1, '*'), (2, 'dashboard.view'), (2, 'content.view'), (2, 'content.create'), (2, 'content.edit_own')");
        $pdo->exec("INSERT INTO pc_users (id, email, username, password_hash, display_name, status, locale, session_version, created_at, updated_at) VALUES (1, 'admin@prime.test', 'admin', 'hash', 'Admin Prime', 'active', 'fr', 1, '$now', '$now'), (2, 'contrib@prime.test', 'contrib', 'hash', 'Contributeur', 'active', 'fr', 1, '$now', '$now')");
        $pdo->exec('INSERT INTO pc_user_roles (user_id, role_id) VALUES (1, 1), (2, 2)');
        $entry = $pdo->prepare('INSERT INTO pc_entries (type, status, title, slug, excerpt, content, content_html, content_text, author_id, published_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $entry->execute(['post', 'published', 'Article publié', 'article-publie', 'Résumé', '{"blocks":[]}', '<p>Bonjour</p>', 'Bonjour', 1, $now, $now, $now]);
        $entry->execute(['post', 'draft', 'Brouillon admin', 'brouillon-admin', '', '{"blocks":[]}', '<p>Secret</p>', 'Secret', 1, null, $now, $now]);
        $entry->execute(['post', 'draft', 'Brouillon contributeur', 'brouillon-contributeur', '', '{"blocks":[]}', '<p>Mien</p>', 'Mien', 2, null, $now, $now]);

        $app->container()->instance(Connection::class, new Connection(['prefix' => 'pc_'], pdo: $pdo));

        if ($authenticateAs !== null) {
            $user = $this->user($app, $authenticateAs === 'admin' ? 1 : 2);
            $app->get(Auth::class)->login($user);
        }

        return $app;
    }

    private function tokens(Application $app): ApiTokenService
    {
        $service = $app->get(ApiTokenService::class);
        $this->assertInstanceOf(ApiTokenService::class, $service, 'ApiTokenService doit être enregistré dans le conteneur');

        return $service;
    }

    private function user(Application $app, int $id): \Prime\Auth\User
    {
        $user = $app->get(UserRepository::class)->find($id);
        $this->assertNotNull($user);

        return $user;
    }

    private function db(Application $app): Connection
    {
        return $app->get(Connection::class);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    private function get(Application $app, string $uri, array $headers = [], array $query = []): Response
    {
        return $app->get(Kernel::class)->handle(Request::create('GET', $uri, query: $query, headers: $headers + ['Accept' => 'application/json']));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $decoded = json_decode($response->body(), true);
        $this->assertIsArray($decoded, 'réponse non JSON : ' . substr($response->body(), 0, 200));

        return $decoded;
    }
}
