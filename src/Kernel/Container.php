<?php

declare(strict_types=1);

namespace Prime\Kernel;

use Prime\Kernel\Exception\CircularDependencyException;
use Prime\Kernel\Exception\ContainerException;
use Prime\Kernel\Exception\NotFoundException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Dependency injection container with reflection-based autowiring
 * and circular dependency detection.
 */
final class Container
{
    /**
     * @var array<string, array{concrete: callable|string, shared: bool}>
     */
    private array $bindings = [];

    /**
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * Stack of service IDs currently being resolved (for cycle detection).
     *
     * @var array<string, true>
     */
    private array $resolving = [];

    /**
     * Register a transient binding.
     */
    public function bind(string $id, callable|string|null $concrete = null): void
    {
        unset($this->instances[$id]);

        $this->bindings[$id] = [
            'concrete' => $concrete ?? $id,
            'shared' => false,
        ];
    }

    /**
     * Register a shared (singleton) binding.
     */
    public function singleton(string $id, callable|string|null $concrete = null): void
    {
        unset($this->instances[$id]);

        $this->bindings[$id] = [
            'concrete' => $concrete ?? $id,
            'shared' => true,
        ];
    }

    /**
     * Register an already instantiated object as a shared instance.
     */
    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /**
     * Determine whether a service identifier is registered in the container.
     */
    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]);
    }

    /**
     * Retrieve a service from the container, autowiring concrete classes when needed.
     */
    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!isset($this->bindings[$id]) && !class_exists($id)) {
            throw new NotFoundException(sprintf('Service [%s] not found in container.', $id));
        }

        return $this->resolve($id, []);
    }

    /**
     * Resolve or instantiate a class with optional explicit constructor parameters.
     *
     * @param array<string, mixed> $params
     */
    public function make(string $class, array $params = []): object
    {
        if ($params === [] && isset($this->instances[$class])) {
            return $this->instances[$class];
        }

        $resolved = $this->resolve($class, $params);

        if (!is_object($resolved)) {
            throw new ContainerException(sprintf('Service [%s] did not resolve to an object.', $class));
        }

        return $resolved;
    }

    /**
     * Resolve a service identifier or concrete class name.
     *
     * @param array<string, mixed> $params
     */
    private function resolve(string $id, array $params): mixed
    {
        if (isset($this->resolving[$id])) {
            $chain = array_keys($this->resolving);
            $chain[] = $id;

            throw new CircularDependencyException(
                'Circular dependency detected: ' . implode(' -> ', $chain),
            );
        }

        $this->resolving[$id] = true;

        try {
            $binding = $this->bindings[$id] ?? null;
            $concrete = $binding !== null ? $binding['concrete'] : $id;
            $shared = $binding !== null && $binding['shared'];

            if (is_callable($concrete)) {
                $object = $concrete($this, $params);
            } elseif ($concrete !== $id) {
                $object = $this->resolve($concrete, $params);
            } else {
                $object = $this->build($concrete, $params);
            }

            if ($shared && $params === [] && is_object($object)) {
                $this->instances[$id] = $object;
            }

            return $object;
        } finally {
            unset($this->resolving[$id]);
        }
    }

    /**
     * Instantiate a concrete class via reflection and autowire its constructor.
     *
     * @param array<string, mixed> $params
     */
    private function build(string $class, array $params): object
    {
        if (!class_exists($class)) {
            throw new NotFoundException(sprintf('Class [%s] does not exist.', $class));
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf('Class [%s] is not instantiable.', $class));
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = $this->resolveParameter($parameter, $params, $class);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * Resolve a single constructor parameter.
     *
     * @param array<string, mixed> $params
     */
    private function resolveParameter(ReflectionParameter $parameter, array $params, string $class): mixed
    {
        $name = $parameter->getName();

        if (array_key_exists($name, $params)) {
            return $params[$name];
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $dependency = $type->getName();

            if ($this->has($dependency) || class_exists($dependency)) {
                return $this->get($dependency);
            }

            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            throw new ContainerException(
                sprintf('Unable to resolve class dependency [%s] for parameter [$%s] in [%s].', $dependency, $name, $class),
            );
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new ContainerException(
            sprintf('Unable to resolve parameter [$%s] in [%s].', $name, $class),
        );
    }
}
