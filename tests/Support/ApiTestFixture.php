<?php

declare(strict_types=1);

namespace Prime\Tests\Support;

use PDO;
use Prime\Api\Token\ApiTokenService;
use Prime\Auth\Auth;
use Prime\Auth\UserRepository;
use Prime\Database\Connection;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Install\InstallCheckerInterface;
use Prime\Kernel\Application;

/**
 * Base SQLite et helpers HTTP partagés par les tests de contrat de l'API (écrits par la revue).
 * Ne pas modifier : les contrats en dépendent.
 */
trait ApiTestFixture
{
    protected function createApp(?string $authenticateAs): Application
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

        $now = '2026-11-15 10:00:00';
        $pdo->exec("INSERT INTO pc_roles (id, slug, name, is_system, created_at, updated_at) VALUES (1, 'administrator', 'Administrateur', 1, '$now', '$now'), (2, 'contributor', 'Contributeur', 1, '$now', '$now')");
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (1, '*'), (2, 'dashboard.view'), (2, 'content.view'), (2, 'content.create'), (2, 'content.edit_own')");
        $pdo->exec("INSERT INTO pc_users (id, email, username, password_hash, display_name, status, locale, session_version, created_at, updated_at) VALUES (1, 'admin@prime.test', 'admin', 'hash', 'Admin Prime', 'active', 'fr', 1, '$now', '$now'), (2, 'contrib@prime.test', 'contrib', 'hash', 'Contributeur', 'active', 'fr', 1, '$now', '$now')");
        $pdo->exec('INSERT INTO pc_user_roles (user_id, role_id) VALUES (1, 1), (2, 2)');
        $entry = $pdo->prepare('INSERT INTO pc_entries (type, status, title, slug, excerpt, content, content_html, content_text, author_id, published_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $entry->execute(['post', 'publish', 'Article publié', 'article-publie', 'Résumé', '{"blocks":[]}', '<p>Bonjour</p>', 'Bonjour', 1, $now, $now, $now]);
        $entry->execute(['post', 'draft', 'Brouillon admin', 'brouillon-admin', '', '{"blocks":[]}', '<p>Secret</p>', 'Secret', 1, null, $now, $now]);
        $entry->execute(['post', 'draft', 'Brouillon contributeur', 'brouillon-contributeur', '', '{"blocks":[]}', '<p>Mien</p>', 'Mien', 2, null, $now, $now]);

        $app->container()->instance(Connection::class, new Connection(['prefix' => 'pc_'], pdo: $pdo));

        if ($authenticateAs !== null) {
            $user = $this->user($app, $authenticateAs === 'admin' ? 1 : 2);
            $app->get(Auth::class)->login($user);
        }

        return $app;
    }

    protected function tokens(Application $app): ApiTokenService
    {
        $service = $app->get(ApiTokenService::class);
        $this->assertInstanceOf(ApiTokenService::class, $service, 'ApiTokenService doit être enregistré dans le conteneur');

        return $service;
    }

    protected function user(Application $app, int $id): \Prime\Auth\User
    {
        $user = $app->get(UserRepository::class)->find($id);
        $this->assertNotNull($user);

        return $user;
    }

    protected function db(Application $app): Connection
    {
        return $app->get(Connection::class);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    protected function get(Application $app, string $uri, array $headers = [], array $query = []): Response
    {
        return $app->get(Kernel::class)->handle(Request::create('GET', $uri, query: $query, headers: $headers + ['Accept' => 'application/json']));
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(Response $response): array
    {
        $decoded = json_decode($response->body(), true);
        $this->assertIsArray($decoded, 'réponse non JSON : ' . substr($response->body(), 0, 200));

        return $decoded;
    }
}
