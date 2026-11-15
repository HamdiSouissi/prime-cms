<?php

declare(strict_types=1);

namespace Prime\Mail;

use Prime\Kernel\ServiceProvider;
use Prime\View\View;

/**
 * Registers the `mail::` view namespace and `MailerInterface` driver (`log` or `native`).
 */
final class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;

        $app->container()->singleton(MailerInterface::class, static function () use ($app): MailerInterface {
            $driver = strtolower(trim((string) $app->config()->get('mail.driver', 'log')));
            $fromAddress = (string) $app->config()->get('mail.from.address', 'no-reply@prime-cms.local');
            $fromName = (string) $app->config()->get('mail.from.name', (string) $app->config()->get('app.name', 'Prime CMS'));

            if ($driver === 'native') {
                return new NativeMailer($fromAddress, $fromName);
            }

            return new LogMailer($app->storagePath('logs'), $fromAddress, $fromName);
        });
    }

    public function boot(): void
    {
        /** @var View $view */
        $view = $this->app->get(View::class);
        $view->addNamespace('mail', $this->app->basePath('resources/views/mail'));
    }
}
