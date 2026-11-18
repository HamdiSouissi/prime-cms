<?php

declare(strict_types=1);

namespace Prime\Api;

use Prime\Api\Controller\ApiIndexController;
use Prime\Api\Controller\ContentController;
use Prime\Api\Controller\MeController;
use Prime\Api\Controller\SiteController;
use Prime\Api\Controller\TermsController;
use Prime\Api\Controller\TypesController;
use Prime\Api\Middleware\ApiCors;
use Prime\Api\Middleware\ApiEnabled;
use Prime\Api\Middleware\ApiRateLimit;
use Prime\Api\Repository\ApiTokenRepository;
use Prime\Api\Token\ApiTokenService;
use Prime\Auth\Auth;
use Prime\Auth\Middleware\ApiAuthenticate;
use Prime\Cache\CacheManager;
use Prime\Http\Kernel;
use Prime\Http\Router;
use Prime\I18n\Translator;
use Prime\Kernel\ServiceProvider;
use Prime\Security\Csrf;
use Prime\Settings\Settings;

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

        // L'API est activée par défaut ; `api.enabled=false` rend toute route API 404.
        $kernel->registerMiddlewareAlias('api.enabled', function (): ApiEnabled {
            return new ApiEnabled($this->app->get(Settings::class));
        });

        // Limite de débit par jeton (authentifié) ou par IP (anonyme).
        $kernel->registerMiddlewareAlias('api.rate_limit', function (): ApiRateLimit {
            return new ApiRateLimit(
                $this->app->get(Settings::class),
                $this->app->get(CacheManager::class),
            );
        });

        // CORS selon `api.cors_origins` (aucun en-tête par défaut).
        $kernel->registerMiddlewareAlias('api.cors', function (): ApiCors {
            return new ApiCors($this->app->get(Settings::class));
        });

        // Alias de middleware `api.auth` pour l'authentification par jeton Bearer ou session.
        $kernel->registerMiddlewareAlias('api.auth', function (): ApiAuthenticate {
            return new ApiAuthenticate(
                $this->app->get(ApiTokenService::class),
                $this->app->get(Auth::class),
                $this->app->get(Csrf::class),
                $this->app->get(Translator::class),
            );
        });

        $router = $this->app->get(Router::class);
        $apiMiddleware = ['api.enabled', 'api.cors', 'api.auth', 'api.rate_limit'];

        // Racine publique de l'API (aucune authentification requise), soumise à l'activation,
        // au CORS et à la limitation de débit.
        $router->group(
            ['middleware' => ['api.enabled', 'api.cors', 'api.rate_limit']],
            static function (Router $r): void {
                $r->get('/api/v1', [ApiIndexController::class, '__invoke']);
            },
        );

        $router->group(
            [
                'prefix' => '/api/v1',
                'middleware' => $apiMiddleware,
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
