<?php

declare(strict_types=1);

namespace Prime\Jobs;

use InvalidArgumentException;
use Prime\Kernel\Container;

/**
 * Name → handler map for the queue. Extensions register their own handlers during `app.booted`,
 * which is also when `queue:work` refuses unknown names, so a job can never be dispatched into a
 * handler nobody implements.
 */
final class JobHandlerRegistry
{
    /**
     * `string` entries are class names, resolved through the container on first use.
     *
     * @var array<string, callable|JobHandlerInterface|string>
     */
    private array $handlers = [];

    public function __construct(private readonly ?Container $container = null)
    {
    }

    /**
     * @param callable|class-string<JobHandlerInterface>|JobHandlerInterface $handler
     */
    public function register(string $name, callable|object|string $handler): void

    {
        if (preg_match('/^[a-z0-9][a-z0-9_.\-]{0,190}$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Nom de traitement de tâche invalide : « %s ».', $name));
        }

        if (is_string($handler)) {
            if (!class_exists($handler) || !is_subclass_of($handler, JobHandlerInterface::class, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Le traitement « %s » doit implémenter %s.',
                    $handler,
                    JobHandlerInterface::class,
                ));
            }
        } elseif (!is_callable($handler) && !$handler instanceof JobHandlerInterface) {
            throw new InvalidArgumentException(sprintf(
                'Le traitement « %s » doit être appelable ou implémenter %s.',
                $name,
                JobHandlerInterface::class,
            ));
        }

        $this->handlers[$name] = $handler;
    }

    public function has(string $name): bool
    {
        return isset($this->handlers[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_keys($this->handlers);
        sort($names);

        return array_values($names);
    }

    /**
     * The callable to invoke for `$name`, or `null` when the handler cannot be brought to life —
     * which is how a job left over from a deactivated plugin surfaces: as a failure, not a fatal.
     *
     * @return (callable(array<string, mixed>, Job): void)|null
     */
    public function resolve(string $name): ?callable
    {
        $handler = $this->handlers[$name] ?? null;

        if ($handler === null) {
            return null;
        }

        if (is_string($handler)) {
            $resolved = $this->instantiate($handler);

            if ($resolved === null) {
                return null;
            }

            $handler = $resolved;
        }

        if ($handler instanceof JobHandlerInterface) {
            return static function (array $payload, Job $job) use ($handler): void {
                $handler->handle($payload, $job);
            };
        }

        return is_callable($handler) ? $handler : null;
    }

    /**
     * A class name is taken from the container when it is bound there — so a handler gets its
     * dependencies — and constructed with no arguments otherwise.
     */
    private function instantiate(string $handler): ?object
    {
        $instance = $this->container?->has($handler) ? $this->container->get($handler) : null;

        if (is_object($instance)) {
            return $instance;
        }

        if (!class_exists($handler)) {
            return null;
        }

        return new $handler();
    }
}
