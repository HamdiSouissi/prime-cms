<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Prime\Http\Session;

final class SessionTest extends TestCase
{
    public function testCookieDefaultsLazyStartRegenerateAndFlashData(): void
    {
        $session = new Session(
            savePath: sys_get_temp_dir(),
            secureCookie: true,
            useNativeSession: false,
        );

        $this->assertFalse($session->isStarted());
        $this->assertSame('prime_session', $session->name());

        $params = $session->cookieParams();
        $this->assertTrue($params['httponly']);
        $this->assertSame('Lax', $params['samesite']);
        $this->assertTrue($params['secure']);

        $session->set('step', 'database');
        $this->assertTrue($session->isStarted());
        $this->assertSame('database', $session->get('step'));
        $this->assertTrue($session->has('step'));

        $oldId = $session->id();
        $session->regenerate();
        $this->assertNotSame($oldId, $session->id());

        $session->flash('notice', 'Connexion réussie');
        $this->assertTrue($session->hasFlash('notice'));
        $this->assertSame('Connexion réussie', $session->getFlash('notice'));
        $this->assertFalse($session->hasFlash('notice'));
        $this->assertNull($session->getFlash('notice'));

        // Sensitive fields must never be flashed in old input.
        $session->flashInput([
            'email' => 'admin@example.com',
            'password' => 'SecretPass#1234',
            'password_confirmation' => 'SecretPass#1234',
            'db_password' => 'DbSecret!999',
            '_csrf_token' => 'abc123',
        ]);

        $old = $session->pullOldInput();
        $this->assertSame('admin@example.com', $old['email'] ?? null);
        $this->assertArrayNotHasKey('password', $old);
        $this->assertArrayNotHasKey('password_confirmation', $old);
        $this->assertArrayNotHasKey('db_password', $old);
        $this->assertArrayNotHasKey('_csrf_token', $old);
    }
}
