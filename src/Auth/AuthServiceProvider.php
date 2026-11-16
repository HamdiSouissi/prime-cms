<?php

declare(strict_types=1);

namespace Prime\Auth;

use Prime\Auth\Middleware\Authenticate;
use Prime\Auth\Middleware\Authorize;
use Prime\Auth\Middleware\NoStoreHeaders;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Session;
use Prime\Kernel\ServiceProvider;
use Prime\Log\Logger;
use Prime\Mail\MailerInterface;
use Prime\Security\Csrf;
use Prime\Security\Encrypter;
use Prime\Security\Hasher;
use Prime\Security\PasswordPolicy;
use Prime\Security\RateLimiter;
use Prime\View\View;

/**
 * Registers authentication, RBAC, 2FA, password reset, security primitives,
 * route middleware aliases (`auth`, `can:<cap>`, `no-store`), and remember-me cookie flushing.
 */
final class AuthServiceProvider extends ServiceProvider
{
    private bool $authInstantiated = false;

    public function register(): void
    {
        $app = $this->app;
        $container = $app->container();

        $container->singleton(PasswordPolicy::class, static fn (): PasswordPolicy => new PasswordPolicy());

        $container->singleton(Hasher::class, static function () use ($app): Hasher {
            $cfg = $app->config()->get('auth', []);

            return new Hasher(
                config: is_array($cfg) ? $cfg : [],
                cacheDir: $app->storagePath('cache'),
            );
        });

        $container->singleton(Encrypter::class, static function () use ($app): Encrypter {
            $key = (string) $app->config()->get('app.key', '');

            return new Encrypter($key);
        });

        $container->singleton(RateLimiter::class, static function () use ($container): RateLimiter {
            return new RateLimiter($container->get(Connection::class));
        });

        $container->singleton(Capabilities::class, static function () use ($container): Capabilities {
            return new Capabilities($container->get(Hooks::class));
        });

        $container->singleton(RoleRepository::class, static function () use ($container): RoleRepository {
            return new RoleRepository($container->get(Connection::class));
        });

        $container->singleton(UserRepository::class, static function () use ($container): UserRepository {
            return new UserRepository(
                $container->get(Connection::class),
                $container->get(RoleRepository::class),
                $container->get(Hasher::class),
            );
        });

        $container->singleton(UserGuard::class, static function () use ($container): UserGuard {
            return new UserGuard(
                $container->get(UserRepository::class),
                $container->get(RoleRepository::class),
            );
        });

        $container->singleton(TwoFactorService::class, static function () use ($container): TwoFactorService {
            return new TwoFactorService(
                connection: $container->get(Connection::class),
                users: $container->get(UserRepository::class),
                encrypter: $container->get(Encrypter::class),
                hooks: $container->get(Hooks::class),
                logger: $container->get(Logger::class),
                hasher: $container->get(Hasher::class),
            );
        });

        $container->singleton(PasswordResetService::class, static function () use ($app, $container): PasswordResetService {
            $cfg = $app->config()->get('auth', []);
            $cfgArr = is_array($cfg) ? $cfg : [];

            return new PasswordResetService(
                connection: $container->get(Connection::class),
                users: $container->get(UserRepository::class),
                rateLimiter: $container->get(RateLimiter::class),
                mailer: $container->get(MailerInterface::class),
                view: $container->get(View::class),
                passwordPolicy: $container->get(PasswordPolicy::class),
                hooks: $container->get(Hooks::class),
                logger: $container->get(Logger::class),
                emailMaxAttempts: (int) ($cfgArr['reset_email_max_attempts'] ?? 3),
                ipMaxAttempts: (int) ($cfgArr['reset_ip_max_attempts'] ?? 10),
                decaySeconds: (int) ($cfgArr['reset_decay_seconds'] ?? 3600),
                resetTtlSeconds: (int) ($cfgArr['reset_token_ttl'] ?? 3600),
                inviteTtlSeconds: (int) ($cfgArr['invite_token_ttl'] ?? 172800),
            );
        });

        $container->singleton(Auth::class, function () use ($app, $container): Auth {
            $cfg = $app->config()->get('auth', []);

            $auth = new Auth(
                connection: $container->get(Connection::class),
                users: $container->get(UserRepository::class),
                session: $container->get(Session::class),
                csrf: $container->get(Csrf::class),
                rateLimiter: $container->get(RateLimiter::class),
                twoFactor: $container->get(TwoFactorService::class),
                hooks: $container->get(Hooks::class),
                logger: $container->get(Logger::class),
                config: is_array($cfg) ? $cfg : [],
                hasher: $container->get(Hasher::class),
            );
            $this->authInstantiated = true;

            return $auth;
        });
    }

    public function boot(): void
    {
        $container = $this->app->container();

        /** @var View $view */
        $view = $this->app->get(View::class);
        $view->addNamespace('auth', $this->app->basePath('resources/views/auth'));

        /** @var Kernel $kernel */
        $kernel = $this->app->get(Kernel::class);

        $kernel->registerMiddlewareAlias('auth', static function () use ($container): Authenticate {
            return new Authenticate(
                $container->get(Auth::class),
                $container->get(Session::class),
            );
        });

        $kernel->registerMiddlewareAlias('can', static function (?string $capability) use ($container): Authorize {
            return new Authorize(
                $container->get(Auth::class),
                (string) $capability,
            );
        });

        $kernel->registerMiddlewareAlias('no-store', static fn (): NoStoreHeaders => new NoStoreHeaders());

        // Flush any queued remember-me `Set-Cookie` headers onto outgoing HTTP responses
        // (only when Auth was actually used during this request — safe during `/install` and `/health`).
        /** @var Hooks $hooks */
        $hooks = $this->app->get(Hooks::class);
        $hooks->filter('http.response', function (Response $response, Request $request) use ($container): Response {
            if ($this->authInstantiated) {
                /** @var Auth $auth */
                $auth = $container->get(Auth::class);

                return $auth->applyPendingCookies($response, $request);
            }

            return $response;
        });
    }
}
