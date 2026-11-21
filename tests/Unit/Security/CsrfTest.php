<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Prime\Http\Middleware\VerifyCsrfToken;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Session;
use Prime\Security\Csrf;
use Prime\View\View;

final class CsrfTest extends TestCase
{
    public function testGeneratesValidatesAndRejectsTokensInConstantTime(): void
    {
        $session = new Session(sys_get_temp_dir(), useNativeSession: false);
        $csrf = new Csrf($session);

        $token = $csrf->token();
        $this->assertSame(64, strlen($token));
        $this->assertTrue(ctype_xdigit($token));
        $this->assertSame($token, $csrf->token());
        $this->assertTrue($csrf->validate($token));
        $this->assertFalse($csrf->validate(null));
        $this->assertFalse($csrf->validate(''));
        $this->assertFalse($csrf->validate(str_repeat('0', 64)));

        $field = $csrf->field();
        $this->assertStringContainsString('type="hidden"', $field);
        $this->assertStringContainsString('name="_csrf_token"', $field);
        $this->assertStringContainsString($token, $field);

        $regenerated = $csrf->regenerate();
        $this->assertNotSame($token, $regenerated);
        $this->assertFalse($csrf->validate($token));
        $this->assertTrue($csrf->validate($regenerated));
    }

    public function testVerifyCsrfTokenMiddlewareProtectsMutatingMethodsAndAllowsSafeMethods(): void
    {
        $session = new Session(sys_get_temp_dir(), useNativeSession: false);
        $csrf = new Csrf($session);
        $view = new View(dirname(__DIR__, 3) . '/resources/views');
        $middleware = new VerifyCsrfToken($csrf, $view);

        $okHandler = static fn (): Response => Response::text('ok');

        // Safe GET passes through without a token.
        $getResponse = $middleware->process(Request::create('GET', '/install'), $okHandler);
        $this->assertSame(200, $getResponse->status());

        // POST, PUT, PATCH, DELETE without token return 419 with styled HTML page.
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $rejected = $middleware->process(Request::create($method, '/submit'), $okHandler);
            $this->assertSame(419, $rejected->status(), sprintf('%s without token must return 419.', $method));
            $this->assertStringContainsString('Session expirée', $rejected->body());
        }

        // Invalid token returns 419.
        $token = $csrf->token();
        $badPost = $middleware->process(
            Request::create('POST', '/submit', body: ['_csrf_token' => 'invalid-token']),
            $okHandler,
        );
        $this->assertSame(419, $badPost->status());

        // Valid body token succeeds.
        $validPost = $middleware->process(
            Request::create('POST', '/submit', body: ['_csrf_token' => $token]),
            $okHandler,
        );
        $this->assertSame(200, $validPost->status());

        // Valid X-CSRF-Token header succeeds on JSON DELETE.
        $validHeader = $middleware->process(
            Request::create('DELETE', '/items/1', headers: ['X-CSRF-Token' => $token]),
            $okHandler,
        );
        $this->assertSame(200, $validHeader->status());

        // JSON request without token returns structured 419 JSON.
        $jsonRejected = $middleware->process(
            Request::create('POST', '/admin/test', headers: ['Accept' => 'application/json']),
            $okHandler,
        );
        $this->assertSame(419, $jsonRejected->status());
        $this->assertStringContainsString('"code":419', $jsonRejected->body());

        // The REST API enforces its own rule (bearer tokens exempt, sessions need X-Prime-CSRF),
        // so the form-token middleware steps aside for /api/*.
        $api = $middleware->process(Request::create('POST', '/api/v1/content/post'), $okHandler);
        $this->assertSame(200, $api->status());
    }
}
