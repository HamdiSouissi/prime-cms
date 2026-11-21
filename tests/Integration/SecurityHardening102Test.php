<?php

declare(strict_types=1);

namespace Prime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prime\Auth\RoleRepository;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Kernel\Application;
use Prime\Settings\SettingsRegistry;
use Prime\Tests\Support\ApiTestFixture;
use ReflectionProperty;

/**
 * Prime CMS 1.0.2 — security hardening reported by the Prime Builder plugin review:
 * API tokens never outlive the account's current permissions, and a settings section
 * declaring its own capability is enforced by the settings screens and the API.
 */
final class SecurityHardening102Test extends TestCase
{
    use ApiTestFixture;

    public function testATokenLosesACapabilityRemovedFromItsAccount(): void
    {
        $app = $this->createApp(null);
        $issued = $this->tokens($app)->issue($this->user($app, 1), 'Intégration', ['settings.manage', 'content.view']);

        $before = $this->tokens($app)->authenticate($issued['token']);
        $this->assertNotNull($before);
        $this->assertContains('settings.manage', $before['abilities']);

        // The administrator is demoted to contributor after the token was issued.
        $this->db($app)->table('user_roles')->where('user_id', '=', 1)->update(['role_id' => 2]);
        $this->forgetRoles($app);

        $after = $this->tokens($app)->authenticate($issued['token']);
        $this->assertNotNull($after);
        $this->assertSame(['content.view'], $after['abilities']);
        $this->assertFalse($after['user']->can('settings.manage'));
        $this->assertFalse($after['user']->isAdministrator());
    }

    public function testALiteralWildcardStoredInATokenNeverGrantsAdministration(): void
    {
        $app = $this->createApp(null);
        $issued = $this->tokens($app)->issue($this->user($app, 2), 'Contributeur', ['content.view']);
        $this->db($app)->table('api_tokens')->where('id', '=', $issued['id'])->update(['abilities' => '["*","settings.manage",42,"content.view"]']);

        $auth = $this->tokens($app)->authenticate($issued['token']);

        $this->assertNotNull($auth);
        $this->assertSame(['content.view'], $auth['abilities']);
        $this->assertFalse($auth['user']->isAdministrator());
        $this->assertFalse($auth['user']->can('settings.manage'));
    }

    public function testTheApiRefusesASectionThatNeedsAnotherCapability(): void
    {
        $app = $this->createApp(null);
        $this->declareGuardedSection($app);
        $this->grantContributor($app, ['settings.manage']);
        $token = $this->tokens($app)->issue($this->user($app, 2), 'Réglages', ['settings.manage'])['token'];

        $read = $this->get($app, '/api/v1/settings', ['Authorization' => 'Bearer ' . $token]);
        $this->assertSame(200, $read->status());
        $this->assertArrayNotHasKey('guarded.value', $this->json($read)['data']);
        $this->assertArrayHasKey('site.name', $this->json($read)['data']);

        $write = $this->patch($app, '/api/v1/settings', ['guarded.value' => 'pirate'], $token);
        $this->assertSame(403, $write->status(), $write->body());
        $this->assertNotSame('pirate', $app->get(\Prime\Settings\Settings::class)->get('guarded.value'));
    }

    public function testTheApiAcceptsTheSectionWithItsCapability(): void
    {
        $app = $this->createApp(null);
        $this->declareGuardedSection($app);
        $this->grantContributor($app, ['settings.manage', 'guarded.manage']);
        $token = $this->tokens($app)->issue($this->user($app, 2), 'Réglages', ['settings.manage', 'guarded.manage'])['token'];

        $write = $this->patch($app, '/api/v1/settings', ['guarded.value' => 'autorisé'], $token);

        $this->assertSame(200, $write->status(), $write->body());
        $this->assertSame('autorisé', $this->json($write)['data']['guarded.value'] ?? null);
    }

    public function testTheSettingsScreenHidesAndIgnoresASectionWithoutItsCapability(): void
    {
        $app = $this->createApp(null);
        $this->declareGuardedSection($app);
        $this->grantContributor($app, ['settings.manage']);
        $app->get(\Prime\Auth\Auth::class)->login($this->user($app, 2));

        $page = $app->get(Kernel::class)->handle(Request::create('GET', '/admin/settings/security', headers: ['Accept' => 'text/html']));
        $this->assertSame(200, $page->status());
        $this->assertStringNotContainsString('guarded.value', $page->body());

        $csrf = $app->get(\Prime\Security\Csrf::class)->token();
        $save = $app->get(Kernel::class)->handle(Request::create('POST', '/admin/settings/security', body: [
            '_csrf_token' => $csrf,
            'settings' => ['guarded.value' => 'pirate'],
        ], headers: ['Accept' => 'text/html']));
        $this->assertSame(302, $save->status());
        $this->assertSame('initiale', $app->get(\Prime\Settings\Settings::class)->get('guarded.value', 'initiale'));
    }

    public function testTheSettingsScreenShowsTheSectionWithItsCapability(): void
    {
        $app = $this->createApp(null);
        $this->declareGuardedSection($app);
        $this->grantContributor($app, ['settings.manage', 'guarded.manage']);
        $app->get(\Prime\Auth\Auth::class)->login($this->user($app, 2));

        $page = $app->get(Kernel::class)->handle(Request::create('GET', '/admin/settings/security', headers: ['Accept' => 'text/html']));

        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('guarded.value', $page->body());
    }

    private function declareGuardedSection(Application $app): void
    {
        $registry = $app->get(SettingsRegistry::class);
        $locked = new ReflectionProperty(SettingsRegistry::class, 'locked');
        $locked->setValue($registry, false);
        $registry->section('guarded', ['label' => 'Protégé', 'capability' => 'guarded.manage', 'page' => 'security']);
        $registry->add(['name' => 'guarded.value', 'section' => 'guarded', 'type' => 'text', 'label' => 'Valeur', 'default' => 'initiale']);
        $registry->lock();
    }

    /**
     * @param list<string> $capabilities
     */
    private function grantContributor(Application $app, array $capabilities): void
    {
        foreach ($capabilities as $capability) {
            $this->db($app)->table('role_capabilities')->insert(['role_id' => 2, 'capability' => $capability]);
        }
        $this->forgetRoles($app);
    }

    private function forgetRoles(Application $app): void
    {
        $roles = $app->get(RoleRepository::class);
        if (method_exists($roles, 'forget')) {
            $roles->forget();
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(Application $app, string $uri, array $body, string $token): \Prime\Http\Response
    {
        return $app->get(Kernel::class)->handle(Request::create(
            'PATCH',
            $uri,
            headers: ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token],
            rawBody: (string) json_encode($body),
        ));
    }
}
