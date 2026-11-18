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

/**
 * Enregistre les routes et middlewares de l'API REST Prime CMS.
 *
 * Toutes les routes `/api/v1` sont déclarées de manière déclarative via `ApiRoute`
 * (permission requise ou `public: true` explicite). Une route sans l'un ni l'autre
 * provoque une exception au démarrage.
 */
final class ApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Le repository de jetons et le service métier sont résolus par autowiring.
        $this->app->container()->singleton(ApiTokenRepository::class);
        $this->app->container()->singleton(ApiTokenService::class);
    }

    /**
     * @return list<ApiRoute>
     */
    public function routes(): array
    {
        return [
            // Racine publique de l'API (aucune authentification requise).
            new ApiRoute(
                method: 'GET',
                path: '/',
                handler: [ApiIndexController::class, '__invoke'],
                permission: null,
                public: true,
                summary: 'Racine de l’API REST.',
                parameters: [],
                response: [],
            ),

            // Types de contenu et taxonomies (public).
            new ApiRoute(
                method: 'GET',
                path: '/types',
                handler: [TypesController::class, 'index'],
                permission: null,
                public: true,
                summary: 'Liste des types de contenu et taxonomies visibles.',
                parameters: [],
                response: [],
            ),

            // Informations publiques du site.
            new ApiRoute(
                method: 'GET',
                path: '/site',
                handler: [SiteController::class, 'show'],
                permission: null,
                public: true,
                summary: 'Réglages publics du site.',
                parameters: [],
                response: [],
            ),

            // Profil de l’utilisateur connecté (authentification requise).
            new ApiRoute(
                method: 'GET',
                path: '/me',
                handler: [MeController::class, 'show'],
                permission: null,
                public: false,
                summary: 'Profil de l’utilisateur courant.',
                parameters: [],
                response: [],
            ),

            // Liste paginée des contenus (lecture publique, filtres optionnels).
            new ApiRoute(
                method: 'GET',
                path: '/content',
                handler: [ContentController::class, 'index'],
                permission: null,
                public: true,
                summary: 'Liste paginée des contenus.',
                parameters: [
                    'type' => 'string',
                    'status' => 'string',
                    'search' => 'string',
                    'author' => 'string',
                    'term' => 'string',
                    'after' => 'string',
                    'before' => 'string',
                    'orderby' => 'string',
                    'order' => 'string',
                    'fields' => 'string',
                    'page' => 'int',
                    'per_page' => 'int',
                ],
                response: [],
            ),

            // Contenus d’un type donné (lecture publique, filtres optionnels).
            new ApiRoute(
                method: 'GET',
                path: '/content/{type}',
                handler: [ContentController::class, 'byType'],
                permission: null,
                public: true,
                summary: 'Contenus d’un type donné.',
                parameters: [
                    'type' => 'string',
                    'status' => 'string',
                    'search' => 'string',
                    'author' => 'string',
                    'term' => 'string',
                    'after' => 'string',
                    'before' => 'string',
                    'orderby' => 'string',
                    'order' => 'string',
                    'fields' => 'string',
                    'page' => 'int',
                    'per_page' => 'int',
                ],
                response: [],
            ),

            // Contenu unique par slug (lecture publique).
            new ApiRoute(
                method: 'GET',
                path: '/content/{type}/{slug}',
                handler: [ContentController::class, 'show'],
                permission: null,
                public: true,
                summary: 'Contenu unique par slug.',
                parameters: [
                    'type' => 'string',
                    'slug' => 'string',
                ],
                response: [],
            ),

            // Liste des taxonomies et de leurs termes (public).
            new ApiRoute(
                method: 'GET',
                path: '/terms',
                handler: [TermsController::class, 'index'],
                permission: null,
                public: true,
                summary: 'Liste des taxonomies et de leurs termes.',
                parameters: [
                    'taxonomy' => 'string',
                    'search' => 'string',
                ],
                response: [],
            ),
        ];
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

        // Alias de middleware `api.can:<capabilité>` pour l'autorisation API.
        // Lit l'attribut `api.user` défini par `ApiAuthenticate`.
        $kernel->registerMiddlewareAlias('api.can', static function (?string $capability): ApiAuthorize {
            return new ApiAuthorize($capability ?? '');
        });

        $router = $this->app->get(Router::class);

        // CORS comme middleware GLOBAL : il s'exécute avant le routeur afin d'intercepter
        // les préflights OPTIONS et de retourner 204 avant que le routeur ne lève une 405
        // "Method Not Allowed" sur les routes qui n'enregistrent que GET.
        $kernel->registerMiddlewareAlias('api.cors', function (): ApiCors {
            return new ApiCors($this->app->get(Settings::class));
        });
        $kernel->addMiddleware('api.cors');

        // Enregistrement déclaratif de toutes les routes de l'API.
        $this->registerRoutes($router);
    }

    /**
     * Enregistre toutes les `ApiRoute` déclarées sur le routeur.
     *
     * Toutes les routes sont placées dans un groupe avec le préfixe `/api/v1` et les
     * middlewares `api.enabled`, `api.auth` et `api.rate_limit`. Les routes non
     * publiques reçoivent en outre le middleware d'autorisation `api.can`.
     *
     * @param list<ApiRoute> $routes
     */
    private function registerRoutes(Router $router): void
    {
        $routes = $this->routes();

        // Vérification au démarrage : toute route doit avoir une permission ou public: true.
        foreach ($routes as $route) {
            if ($route->permission === null && !$route->public) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'La route %s %s doit déclarer une permission ou public: true.',
                        $route->method,
                        $route->path,
                    ),
                );
            }
        }

        $apiMiddleware = ['api.enabled', 'api.auth', 'api.rate_limit'];

        $router->group(
            [
                'prefix' => '/api/v1',
                'middleware' => $apiMiddleware,
                'name' => 'api.',
            ],
            static function (Router $r) use ($routes, $apiMiddleware): void {
                foreach ($routes as $route) {
                    $middlewares = $apiMiddleware;
                    if (!$route->public && $route->permission !== null) {
                        $middlewares[] = 'api.can:' . $route->permission;
                    }

                    $this->registerRoute($r, $route, $middlewares);
                }
            },
        );
    }

    /**
     * Enregistre une `ApiRoute` sur le routeur avec les middlewares donnés.
     *
     * @param list<string> $middlewares
     */
    private function registerRoute(Router $r, ApiRoute $route, array $middlewares): void
    {
        $handler = $route->handler;
        if (is_array($handler)) {
            [$controller, $method] = $handler;
        } else {
            $controller = $handler;
            $method = '__invoke';
        }

        match ($route->method) {
            'GET' => $r->get($route->path, [$controller, $method])->middleware($middlewares),
            'POST' => $r->post($route->path, [$controller, $method])->middleware($middlewares),
            'PATCH' => $r->patch($route->path, [$controller, $method])->middleware($middlewares),
            'DELETE' => $r->delete($route->path, [$controller, $method])->middleware($middlewares),
            'PUT' => $r->put($route->path, [$controller, $method])->middleware($middlewares),
            default => throw new \InvalidArgumentException(
                sprintf('Méthode HTTP non supportée : %s', $route->method),
            ),
        };
    }
}
