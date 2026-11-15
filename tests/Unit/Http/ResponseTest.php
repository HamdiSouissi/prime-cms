<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Prime\Http\Response;

final class ResponseTest extends TestCase
{
    public function testFactoryMethodsProduceExpectedStatusHeadersAndBody(): void
    {
        $html = Response::html('<h1>Prime</h1>', 201);
        $this->assertSame(201, $html->status());
        $this->assertSame('text/html; charset=UTF-8', $html->header('content-type'));
        $this->assertSame('<h1>Prime</h1>', $html->body());

        $json = Response::json(['status' => 'ok', 'label' => 'éàü']);
        $this->assertSame(200, $json->status());
        $this->assertSame('application/json; charset=UTF-8', $json->header('Content-Type'));
        $this->assertSame('{"status":"ok","label":"éàü"}', $json->body());

        $redirect = Response::redirect('/home', 301);
        $this->assertSame(301, $redirect->status());
        $this->assertSame('/home', $redirect->header('Location'));

        $noContent = Response::noContent();
        $this->assertSame(204, $noContent->status());
        $this->assertSame('', $noContent->body());
    }

    public function testImmutableHeaderAndStatusModifiers(): void
    {
        $original = Response::text('hello');
        $modified = $original
            ->withStatus(202)
            ->withHeader('X-Prime', '1')
            ->withHeader('x-prime', '2')
            ->withBody('updated');

        $this->assertSame(200, $original->status());
        $this->assertFalse($original->hasHeader('X-Prime'));
        $this->assertSame('hello', $original->body());

        $this->assertSame(202, $modified->status());
        $this->assertSame('2', $modified->header('X-Prime'));
        $this->assertSame('updated', $modified->body());

        $stripped = $modified->withoutHeader('X-Prime');
        $this->assertFalse($stripped->hasHeader('X-Prime'));
    }
}
