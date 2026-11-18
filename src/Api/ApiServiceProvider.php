<?php

declare(strict_types=1);

namespace Prime\Api;

use Prime\Api\Controller\ApiIndexController;
use Prime\Api\Controller\ContentController;
use Prime\Api\Controller\MeController;
use Prime\Api\Controller\SiteController;
use Prime\Api\Controller\TermsController;
use Prime\Api\Controller\TypesController;
use Prime\Api\Repository\ApiTokenRepository;
use Prime\Api\Token\ApiTokenService;
use Prime\Auth\Middleware\ApiAuthenticate;
use Prime\Http\Kernel;
use Prime\Http\Router;
use Prime\Kernel\ServiceProvider;

final class ApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Le repository de jetons et le service métier sont résolus par autowiring.
        $this->app->container()->singleton(ApiTokenRepository::class);
        $this->app->container()->singleton(ApiTokenService::class);
    }

    public function boot(): void
    {
        $kernel = $this->app->get(Kernel::class);

        // Alias de middleware `api.auth` pour l'authentification par jeton Bearer.
        $kernel->registerMiddlewareAlias('api.auth', function (): ApiAuthenticate {
            return new ApiAuthenticate(
                $this->app->get(ApiTokenService::class),
            );
        });

        $router = $this->app->get(Router::class);

        // Racine publique de l'API (aucune authentification requise).
        $router->get('/api/v1', [ApiIndexController::class, '__invoke']);

        $router->group(
            [
                'prefix' => '/api/v1',
                'middleware' => ['api.auth'],
                'name' => 'api.',
            ],
            static function (Router $r): void {
                $r->get('/types', [TypesController::class, 'index'])->name('types.index');
                $r->get('/site', [SiteController::class, 'show'])->name('site.show');
                $r->get('/me', [MeController::class, 'show'])->name('me.show');

                $r->get('/content', [ContentController::class, 'index'])->name('content.index');
                $r->get('/content/{type}', [ContentController::class, 'byType'])->name('content.by_type');
                $r->get('/content/{type}/{slug}', [ContentController::class, 'show'])->name('content.show');

                $r->get('/terms', [TermsController::class, 'index'])->name('terms.index');
            },
        );
    }
}
