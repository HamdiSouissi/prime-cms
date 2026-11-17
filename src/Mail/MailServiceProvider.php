<?php

declare(strict_types=1);

namespace Prime\Mail;

use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Jobs\JobHandlerRegistry;
use Prime\Jobs\JobQueue;
use Prime\Kernel\Container;
use Prime\Kernel\ServiceProvider;
use Prime\Log\Logger;
use Prime\Settings\Settings;
use Prime\View\View;
use Throwable;

/**
 * Wires mail: the resolved configuration, the transport the driver names, the log, the queue handler,
 * and the `mail::` view namespace themes can override.
 *
 * Two rules shape this provider. Nothing here opens a socket or reads a table while registering — a
 * service provider runs on every request, including the installer's, so `Connection` and `Settings`
 * are only ever resolved inside a closure. And `MailerInterface` keeps pointing at something that can
 * only send: the queue, the log and the filters live in `Mailer`, which decorators can replace without
 * touching `PasswordResetService` or a plugin's `$mailer->send($message)` call.
 */
final class MailServiceProvider extends ServiceProvider
{
    /**
     * Container id of the raw transport, so a decorator can send without going through the queue.
     */
    public const TRANSPORT = 'mail.transport';

    public function register(): void
    {
        $app = $this->app;
        $c = $app->container();

        $c->singleton(MailConfig::class, static function (Container $c) use ($app): MailConfig {
            $settings = $c->has(Settings::class) ? $c->get(Settings::class) : null;

            return MailConfig::resolve($settings instanceof Settings ? $settings : null, $app->config());
        });

        $c->singleton(self::TRANSPORT, function (Container $c) use ($app): MailerInterface {
            /** @var MailConfig $config */
            $config = $c->get(MailConfig::class);
            $driver = $config->driver();

            if ($driver === MailConfig::DRIVER_SMTP || $driver === MailConfig::DRIVER_QUEUE) {
                return new SmtpTransport($config);
            }

            if ($driver === MailConfig::DRIVER_NATIVE && function_exists('mail')) {
                return new NativeMailer($config->fromAddress(), $config->fromName());
            }

            return new LogMailer($app->storagePath('logs'), $config->fromAddress(), $config->fromName());
        });

        $c->singleton(MailLogRepository::class, static function (Container $c): ?MailLogRepository {
            if (!$c->has(Connection::class)) {
                return null;
            }

            $connection = $c->get(Connection::class);
            if (!$connection instanceof Connection) {
                return null;
            }

            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;

            return new MailLogRepository($connection, $hooks instanceof Hooks ? $hooks : null);
        });

        $c->singleton(Mailer::class, static function (Container $c): Mailer {
            /** @var MailConfig $config */
            $config = $c->get(MailConfig::class);
            /** @var MailerInterface $transport */
            $transport = $c->get(self::TRANSPORT);

            $logs = $c->has(MailLogRepository::class) ? $c->get(MailLogRepository::class) : null;
            $hooks = $c->has(Hooks::class) ? $c->get(Hooks::class) : null;
            $queue = $c->has(JobQueue::class) ? $c->get(JobQueue::class) : null;
            $view = $c->has(View::class) ? $c->get(View::class) : null;
            $logger = $c->has(Logger::class) ? $c->get(Logger::class) : null;

            return new Mailer(
                $config,
                $transport,
                $logs instanceof MailLogRepository ? $logs : null,
                $hooks instanceof Hooks ? $hooks : null,
                $queue instanceof JobQueue ? $queue : null,
                $view instanceof View ? $view : null,
                $logger instanceof Logger ? $logger : null,
            );
        });

        $c->singleton(MailerInterface::class, static function (Container $c): MailerInterface {
            $mailer = $c->get(Mailer::class);

            return $mailer instanceof Mailer ? $mailer : new LogMailer('storage/logs');
        });

        $c->singleton(SendMailJob::class, static function (Container $c): SendMailJob {
            /** @var Mailer $mailer */
            $mailer = $c->get(Mailer::class);

            return new SendMailJob($mailer);
        });
    }

    public function boot(): void
    {
        /** @var View $view */
        $view = $this->app->get(View::class);
        $view->addNamespace('mail', $this->app->basePath('resources/views/mail'));

        $c = $this->app->container();

        if (!$c->has(JobHandlerRegistry::class)) {
            return;
        }

        $registry = $c->get(JobHandlerRegistry::class);
        if (!$registry instanceof JobHandlerRegistry) {
            return;
        }

        try {
            $registry->register(SendMailJob::NAME, SendMailJob::class);
        } catch (Throwable) {
            // A site without the queue tables cannot accept mail jobs: the mailer falls back to
            // sending inline, which is worse for latency and better than losing the message.
        }
    }
}
