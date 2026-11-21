<?php

declare(strict_types=1);

namespace Prime\Api;

use Prime\Api\Middleware\ApiAuthorize;
use Prime\Api\Middleware\ApiBody;
use Prime\Api\Middleware\ApiCors;
use Prime\Api\Middleware\ApiEnabled;
use Prime\Api\Middleware\ApiLifecycle;
use Prime\Api\Middleware\ApiRateLimit;
use Prime\Api\Repository\ApiTokenRepository;
use Prime\Api\Token\ApiTokenService;
use Prime\Auth\Auth;
use Prime\Auth\Middleware\ApiAuthenticate;
use Prime\Cache\CacheManager;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\I18n\Translator;
use Prime\Kernel\ServiceProvider;
use Prime\Security\Csrf;
use Prime\Settings\Settings;

final class ApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();
        $container->singleton(ApiTokenRepository::class);
        $container->singleton(ApiTokenService::class);
        $container->singleton(ApiRouteRegistry::class);
    }

    public function boot(): void
    {
        $kernel = $this->app->get(Kernel::class);

        $kernel->registerMiddlewareAlias('api.lifecycle', fn (): ApiLifecycle => new ApiLifecycle($this->app->get(Hooks::class)));
        $kernel->registerMiddlewareAlias('api.enabled', fn (): ApiEnabled => new ApiEnabled($this->app->get(Settings::class)));
        $kernel->registerMiddlewareAlias('api.rate_limit', fn (): ApiRateLimit => new ApiRateLimit(
            $this->app->get(Settings::class),
            $this->app->get(CacheManager::class),
        ));
        $kernel->registerMiddlewareAlias('api.cors', fn (): ApiCors => new ApiCors($this->app->get(Settings::class)));
        $kernel->registerMiddlewareAlias('api.auth', fn (): ApiAuthenticate => new ApiAuthenticate(
            $this->app->get(ApiTokenService::class),
            $this->app->get(Auth::class),
            $this->app->get(Csrf::class),
            $this->app->get(Translator::class),
        ));
        $kernel->registerMiddlewareAlias('api.can', static fn (?string $capability): ApiAuthorize => new ApiAuthorize((string) $capability));
        $kernel->registerMiddlewareAlias('api.body', fn (?string $mode): ApiBody => new ApiBody(
            $this->app->get(Settings::class),
            $mode ?? ApiRoute::BODY_NONE,
        ));

        // CORS runs before routing so that preflight requests are answered even for routes
        // that do not declare OPTIONS (see ApiCors).
        $kernel->addMiddleware('api.cors');

        $registry = $this->app->get(ApiRouteRegistry::class);
        foreach (CoreApiRoutes::all() as $route) {
            $registry->add($route);
        }
    }
}
