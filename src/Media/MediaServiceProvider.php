<?php

declare(strict_types=1);

namespace Prime\Media;

use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\Kernel\ServiceProvider;

/**
 * Registers all Phase 7 Media Library services and the PHP upload serving fallback route.
 */
final class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $c = $app->container();

        $c->singleton(MediaConfig::class, static fn (): MediaConfig => MediaConfig::fromApplication($app));

        $c->singleton(UploadsGuard::class, static function () use ($c): UploadsGuard {
            /** @var MediaConfig $cfg */
            $cfg = $c->make(MediaConfig::class);

            return new UploadsGuard($cfg);
        });

        $c->singleton(ImageProcessor::class, static function () use ($c): ImageProcessor {
            /** @var MediaConfig $cfg */
            $cfg = $c->make(MediaConfig::class);

            return new ImageProcessor($cfg);
        });

        $c->singleton(MediaFolderRepository::class, static function () use ($c): MediaFolderRepository {
            /** @var Connection $db */
            $db = $c->make(Connection::class);

            return new MediaFolderRepository($db);
        });

        $c->singleton(MediaUsageRepository::class, static function () use ($c): MediaUsageRepository {
            /** @var Connection $db */
            $db = $c->make(Connection::class);

            return new MediaUsageRepository($db);
        });

        $c->singleton(MediaRepository::class, static function () use ($c): MediaRepository {
            /** @var Connection $db */
            $db = $c->make(Connection::class);
            /** @var MediaUsageRepository $usages */
            $usages = $c->make(MediaUsageRepository::class);

            return new MediaRepository($db, $usages);
        });

        $c->singleton(UploadService::class, static function () use ($c): UploadService {
            /** @var MediaConfig $cfg */
            $cfg = $c->make(MediaConfig::class);
            /** @var MediaRepository $media */
            $media = $c->make(MediaRepository::class);
            /** @var MediaFolderRepository $folders */
            $folders = $c->make(MediaFolderRepository::class);
            /** @var ImageProcessor $images */
            $images = $c->make(ImageProcessor::class);
            /** @var UploadsGuard $guard */
            $guard = $c->make(UploadsGuard::class);
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            return new UploadService(
                config: $cfg,
                media: $media,
                folders: $folders,
                images: $images,
                guard: $guard,
                hooks: $hooks instanceof Hooks ? $hooks : null,
            );
        });

        $c->singleton(MediaServingController::class, static function () use ($c): MediaServingController {
            /** @var MediaConfig $cfg */
            $cfg = $c->make(MediaConfig::class);

            return new MediaServingController($cfg);
        });
    }

    public function boot(): void
    {
        if (!$this->app->container()->has(Router::class)) {
            return;
        }

        /** @var Router $router */
        $router = $this->app->get(Router::class);

        // Public fallback serving uploaded files when `public/uploads` symlink is unavailable.
        $router->match(['GET', 'HEAD'], '/uploads/{path:.+}', [MediaServingController::class, 'serve'])
            ->name('media.serve');
    }
}
