<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Http;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prime\Http\Exception\MethodNotAllowedException;
use Prime\Http\Exception\NotFoundException;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Router;

final class RouterTest extends TestCase
{
    public function testMatchesBasicParametersConstraintsAndOptionalSegments(): void
    {
        $router = new Router();
        $handler = static fn (): Response => Response::text('ok');

        $router->get('/users/{id:\d+}', $handler)->name('users.show');
        $router->get('/archive/{year:\d{4}}/{slug}', $handler)->name('archive.entry');
        $router->get('/posts/{page?:\d+}', $handler)->name('posts.index');

        $matchedUser = $router->dispatch(Request::create('GET', '/users/42'));
        $this->assertSame(['id' => '42'], $matchedUser['params']);
        $this->assertSame('users.show', $matchedUser['route']->getName());

        $matchedArchive = $router->dispatch(Request::create('GET', '/archive/2026/prime-cms'));
        $this->assertSame(['year' => '2026', 'slug' => 'prime-cms'], $matchedArchive['params']);

        $matchedPostsDefault = $router->dispatch(Request::create('GET', '/posts'));
        $this->assertSame([], $matchedPostsDefault['params']);

        $matchedPostsPage = $router->dispatch(Request::create('GET', '/posts/3'));
        $this->assertSame(['page' => '3'], $matchedPostsPage['params']);
    }

    public function testRejectsParameterConstraintMismatchWithNotFound(): void
    {
        $router = new Router();
        $router->get('/users/{id:\d+}', static fn (): Response => Response::text('ok'));

        $this->expectException(NotFoundException::class);
        $router->dispatch(Request::create('GET', '/users/not-a-number'));
    }

    public function testSupportsNestedGroupsWithPrefixMiddlewareAndNamePrefix(): void
    {
        $router = new Router();
        $handler = static fn (): Response => Response::text('ok');

        $router->group(['prefix' => '/admin', 'middleware' => ['auth'], 'name' => 'admin.'], static function (Router $r) use ($handler): void {
            $r->get('/', $handler)->name('dashboard');
            $r->group(['prefix' => '/users', 'middleware' => ['can:manage']], static function (Router $r) use ($handler): void {
                $r->get('/{id:\d+}', $handler)->name('users.edit');
            });
        });

        $dash = $router->dispatch(Request::create('GET', '/admin'));
        $this->assertSame('admin.dashboard', $dash['route']->getName());
        $this->assertSame(['auth'], $dash['route']->middlewares());

        $edit = $router->dispatch(Request::create('GET', '/admin/users/7'));
        $this->assertSame('admin.users.edit', $edit['route']->getName());
        $this->assertSame(['auth', 'can:manage'], $edit['route']->middlewares());
        $this->assertSame(['id' => '7'], $edit['params']);
    }

    public function testGeneratesUrlsFromNamedRoutes(): void
    {
        $router = new Router();
        $handler = static fn (): Response => Response::text('ok');

        $router->get('/users/{id:\d+}', $handler)->name('users.show');
        $router->get('/posts/{page?}', $handler)->name('posts.list');

        $this->assertSame('/users/15', $router->url('users.show', ['id' => 15]));
        $this->assertSame('/users/15?tab=profile', $router->url('users.show', ['id' => 15, 'tab' => 'profile']));
        $this->assertSame('/posts', $router->url('posts.list'));
        $this->assertSame('/posts/4', $router->url('posts.list', ['page' => 4]));
    }

    public function testUrlGenerationThrowsOnMissingOrInvalidParameter(): void
    {
        $router = new Router();
        $router->get('/users/{id:\d+}', static fn (): Response => Response::text('ok'))->name('users.show');

        try {
            $router->url('users.show');
            $this->fail('Expected InvalidArgumentException for missing parameter.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $router->url('users.show', ['id' => 'abc']);
    }

    public function testThrowsMethodNotAllowedWithExactAllowHeader(): void
    {
        $router = new Router();
        $handler = static fn (): Response => Response::text('ok');

        $router->get('/items', $handler);
        $router->post('/items', $handler);

        try {
            $router->dispatch(Request::create('DELETE', '/items'));
            $this->fail('Expected MethodNotAllowedException.');
        } catch (MethodNotAllowedException $e) {
            $this->assertSame(405, $e->statusCode());
            $this->assertSame(['GET', 'POST'], $e->allowedMethods());
            $this->assertSame('GET, POST', $e->headers()['Allow']);
        }
    }

    public function testSupportsAllVerbShortcutsAndAny(): void
    {
        $router = new Router();
        $handler = static fn (): Response => Response::text('ok');

        $router->put('/put', $handler);
        $router->patch('/patch', $handler);
        $router->delete('/delete', $handler);
        $router->any('/any', $handler);

        $this->assertSame('/put', $router->dispatch(Request::create('PUT', '/put'))['route']->path());
        $this->assertSame('/patch', $router->dispatch(Request::create('PATCH', '/patch'))['route']->path());
        $this->assertSame('/delete', $router->dispatch(Request::create('DELETE', '/delete'))['route']->path());
        $this->assertSame('/any', $router->dispatch(Request::create('PATCH', '/any'))['route']->path());
    }
}
