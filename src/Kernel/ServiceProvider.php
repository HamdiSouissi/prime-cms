<?php

declare(strict_types=1);

namespace Prime\Kernel;

/**
 * Base class for service providers registering and booting core services.
 */
abstract class ServiceProvider
{
    public function __construct(protected readonly Application $app)
    {
    }

    /**
     * Bind services into the application container.
     */
    abstract public function register(): void;

    /**
     * Perform post-registration initialization once all providers are registered.
     */
    public function boot(): void
    {
    }
}
