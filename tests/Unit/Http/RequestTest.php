<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Prime\Http\Request;

final class RequestTest extends TestCase
{
    public function testParsesUriQueryHeadersCookiesFilesAndIp(): void
    {
        $request = Request::create(
            method: 'GET',
            uri: '/articles?page=2&sort=asc',
            query: ['sort' => 'desc'],
            headers: ['X-Custom-Header' => 'Prime', 'Accept' => 'text/html'],
            cookies: ['session' => 'abc123'],
            files: ['avatar' => ['name' => 'pic.png']],
            server: ['REMOTE_ADDR' => '192.168.1.42'],
        );

        $this->assertSame('GET', $request->method());
        $this->assertTrue($request->isMethod('get'));
        $this->assertSame('/articles', $request->path());
        $this->assertSame('2', $request->query('page'));
        $this->assertSame('desc', $request->query('sort'));
        $this->assertSame('Prime', $request->header('x-custom-header'));
        $this->assertSame('abc123', $request->cookie('session'));
        $this->assertSame(['name' => 'pic.png'], $request->file('avatar'));
        $this->assertSame('192.168.1.42', $request->ip());
        $this->assertFalse($request->isAjax());
        $this->assertFalse($request->wantsJson());
    }

    public function testSupportsMethodOverrideOnlyForPostForms(): void
    {
        $put = Request::create('POST', '/posts/1', body: ['_method' => 'PUT', 'title' => 'Updated']);
        $patch = Request::create('POST', '/posts/1', body: ['_method' => 'patch']);
        $delete = Request::create('POST', '/posts/1', body: ['_method' => 'DELETE']);
        $ignoredOnGet = Request::create('GET', '/posts/1', body: ['_method' => 'DELETE']);
        $ignoredInvalid = Request::create('POST', '/posts/1', body: ['_method' => 'TRACE']);

        $this->assertSame('PUT', $put->method());
        $this->assertSame('PATCH', $patch->method());
        $this->assertSame('DELETE', $delete->method());
        $this->assertSame('GET', $ignoredOnGet->method());
        $this->assertSame('POST', $ignoredInvalid->method());
    }

    public function testDecodesJsonRequestBodyAndDetectsAjaxAndJsonAccept(): void
    {
        $request = Request::create(
            method: 'POST',
            uri: '/api/items',
            headers: [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ],
            rawBody: '{"name":"Prime","count":3}',
        );

        $this->assertSame('Prime', $request->body('name'));
        $this->assertSame(3, $request->input('count'));
        $this->assertTrue($request->isAjax());
        $this->assertTrue($request->wantsJson());
    }

    public function testAttributesAreImmutable(): void
    {
        $original = Request::create('GET', '/users/42');
        $withId = $original->withAttribute('id', '42');
        $withMore = $withId->withAttributes(['role' => 'admin']);

        $this->assertNull($original->attribute('id'));
        $this->assertSame('42', $withId->attribute('id'));
        $this->assertNull($withId->attribute('role'));
        $this->assertSame('42', $withMore->attribute('id'));
        $this->assertSame('admin', $withMore->attribute('role'));
    }
}
