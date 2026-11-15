<?php

declare(strict_types=1);

namespace Prime\Kernel;

use Prime\Hooks\Hooks;
use Prime\Log\Logger;
use Prime\View\View;
use RuntimeException;

/**
 * Core Prime CMS application container, path resolver, and provider lifecycle manager.
 */
final class Application
{
    public const VERSION = '0.6.0';

    private static ?self $instance = null;

    private readonly string $basePath;

    private readonly ?string $customStoragePath;

    private readonly Container $container;

    private readonly Config $config;

    /**
     * @var list<ServiceProvider>
     */
    private array $providers = [];

    private bool $booted = false;

    public function __construct(
        string $basePath,
        ?Container $container = null,
        bool $loadEnv = true,
        bool $registerErrorHandler = false,
    ) {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR . '/');
        $this->container = $container ?? new Container();

        if ($loadEnv && !((bool) Env::get('PRIME_DISABLE_DOTENV', false))) {
            Env::load($this->basePath('.env'));
        }

        $envStorage = Env::get('PRIME_STORAGE_PATH');
        $projectRoot = realpath(dirname(__DIR__, 2));
        $resolvedBase = realpath($this->basePath);
        if (
            is_string($envStorage)
            && trim($envStorage) !== ''
            && $projectRoot !== false
            && $resolvedBase === $projectRoot
        ) {
            $this->customStoragePath = rtrim(trim($envStorage), DIRECTORY_SEPARATOR . '/');
        } else {
            $this->customStoragePath = null;
        }

        $this->config = Config::loadFromDirectory($this->configPath());

        $timezone = (string) $this->config->get('app.timezone', 'UTC');
        if ($timezone !== '') {
            date_default_timezone_set($timezone);
        }

        $this->container->instance(self::class, $this);
        $this->container->instance('app', $this);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Config::class, $this->config);

        self::$instance = $this;

        if ($registerErrorHandler) {
            $this->registerErrorHandler();
        }
    }

    /**
     * Instantiate and register the global ErrorHandler with a base Logger and View
     * so failures during provider registration and booting are caught and logged.
     */
    public function registerErrorHandler(): ErrorHandler
    {
        $logPath = (string) $this->config->get('logging.path', $this->storagePath('logs'));
        $level = (string) $this->config->get('logging.level', Logger::DEBUG);
        $prefix = (string) $this->config->get('logging.prefix', 'prime');

        $logger = new Logger($logPath, $level, $prefix);
        $view = new View($this->resourcesPath('views'));
        $errorHandler = new ErrorHandler($logger, $view, $this->isDebug());

        $this->container->instance(Logger::class, $logger);
        $this->container->instance(ErrorHandler::class, $errorHandler);

        $errorHandler->register();

        return $errorHandler;
    }

    public static function setInstance(?self $app): void
    {
        self::$instance = $app;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('Application instance has not been initialized.');
        }

        return self::$instance;
    }

    public static function hasInstance(): bool
    {
        return self::$instance !== null;
    }

    /**
     * Register and boot all configured service providers.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $providerClasses = $this->config->get('app.providers', []);
        if (!is_array($providerClasses)) {
            $providerClasses = [];
        }

        foreach ($providerClasses as $providerClass) {
            if (!is_string($providerClass) || !class_exists($providerClass)) {
                throw new RuntimeException(sprintf('Service provider class [%s] does not exist.', (string) $providerClass));
            }

            $provider = new $providerClass($this);
            if (!$provider instanceof ServiceProvider) {
                throw new RuntimeException(sprintf('Service provider [%s] must extend %s.', $providerClass, ServiceProvider::class));
            }

            $this->providers[] = $provider;
        }

        foreach ($this->providers as $provider) {
            $provider->register();
        }

        $hooks = $this->resolveHooks();
        $hooks?->emit('app.registered', $this);

        foreach ($this->providers as $provider) {
            $provider->boot();
        }

        $this->booted = true;
        $hooks?->emit('app.booted', $this);
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function environment(): string
    {
        return (string) $this->config->get('app.env', 'production');
    }

    public function isDebug(): bool
    {
        return (bool) $this->config->get('app.debug', false);
    }

    public function isProduction(): bool
    {
        return $this->environment() === 'production';
    }

    public function isLocal(): bool
    {
        return $this->environment() === 'local';
    }

    public function get(string $id): mixed
    {
        return $this->container->get($id);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function make(string $class, array $params = []): object
    {
        return $this->container->make($class, $params);
    }

    public function basePath(string $path = ''): string
    {
        return $this->joinPath($this->basePath, $path);
    }

    public function configPath(string $path = ''): string
    {
        return $this->joinPath($this->basePath('config'), $path);
    }

    public function storagePath(string $path = ''): string
    {
        return $this->joinPath($this->customStoragePath ?? $this->basePath('storage'), $path);
    }

    public function contentPath(string $path = ''): string
    {
        return $this->joinPath($this->basePath('content'), $path);
    }

    public function publicPath(string $path = ''): string
    {
        return $this->joinPath($this->basePath('public'), $path);
    }

    public function resourcesPath(string $path = ''): string
    {
        return $this->joinPath($this->basePath('resources'), $path);
    }

    private function resolveHooks(): ?Hooks
    {
        if (!$this->container->has(Hooks::class)) {
            return null;
        }

        $hooks = $this->container->get(Hooks::class);

        return $hooks instanceof Hooks ? $hooks : null;
    }

    private function joinPath(string $base, string $sub): string
    {
        $sub = ltrim($sub, DIRECTORY_SEPARATOR . '/');

        return $sub === '' ? $base : $base . DIRECTORY_SEPARATOR . $sub;
    }
}
