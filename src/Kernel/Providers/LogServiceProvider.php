<?php

declare(strict_types=1);

namespace Prime\Kernel\Providers;

use Prime\Kernel\Config;
use Prime\Kernel\Container;
use Prime\Kernel\ErrorHandler;
use Prime\Kernel\ServiceProvider;
use Prime\Log\Logger;
use Prime\View\View;

/**
 * Registers the file logger and global error handler.
 */
final class LogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;

        $app->container()->singleton(Logger::class, static function (Container $container) use ($app): Logger {
            /** @var Config $config */
            $config = $container->get(Config::class);
            $logPath = (string) $config->get('logging.path', $app->storagePath('logs'));
            $level = (string) $config->get('logging.level', Logger::DEBUG);
            $prefix = (string) $config->get('logging.prefix', 'prime');

            return new Logger($logPath, $level, $prefix);
        });

        $app->container()->singleton(ErrorHandler::class, static function (Container $container) use ($app): ErrorHandler {
            /** @var Logger $logger */
            $logger = $container->get(Logger::class);
            /** @var View $view */
            $view = $container->get(View::class);

            return new ErrorHandler($logger, $view, $app->isDebug());
        });
    }
}
