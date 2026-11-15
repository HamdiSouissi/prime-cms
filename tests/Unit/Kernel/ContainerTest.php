<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Kernel;

use PHPUnit\Framework\TestCase;
use Prime\Kernel\Container;
use Prime\Kernel\Exception\CircularDependencyException;
use Prime\Kernel\Exception\ContainerException;
use Prime\Kernel\Exception\NotFoundException;
use stdClass;

final class ContainerTest extends TestCase
{
    public function testBindReturnsNewInstanceEachTimeWhileSingletonSharesInstance(): void
    {
        $container = new Container();
        $container->bind('transient', static fn (): stdClass => new stdClass());
        $container->singleton('shared', static fn (): stdClass => new stdClass());

        $this->assertTrue($container->has('transient'));
        $this->assertTrue($container->has('shared'));
        $this->assertNotSame($container->get('transient'), $container->get('transient'));
        $this->assertSame($container->get('shared'), $container->get('shared'));
    }

    public function testInstanceRegistersPrebuiltObject(): void
    {
        $container = new Container();
        $service = new stdClass();
        $service->tag = 'prime';

        $container->instance('custom', $service);

        $this->assertTrue($container->has('custom'));
        $this->assertSame($service, $container->get('custom'));
    }

    public function testAutowiresNestedClassDependenciesAndScalarDefaults(): void
    {
        $container = new Container();
        $container->singleton(FixtureContract::class, FixtureLeaf::class);

        $service = $container->make(FixtureService::class);

        $this->assertInstanceOf(FixtureService::class, $service);
        $this->assertInstanceOf(FixtureLeaf::class, $service->leaf);
        $this->assertInstanceOf(FixtureDependency::class, $service->dependency);
        $this->assertSame('default-label', $service->label);
    }

    public function testMakeSupportsExplicitScalarParameters(): void
    {
        $container = new Container();

        $obj = $container->make(FixtureScalarRequired::class, ['dsn' => 'mysql:host=db']);

        $this->assertInstanceOf(FixtureScalarRequired::class, $obj);
        $this->assertSame('mysql:host=db', $obj->dsn);
        $this->assertSame(3306, $obj->port);
    }

    public function testThrowsOnUnresolvableRequiredScalarParameter(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $container->make(FixtureScalarRequired::class);
    }

    public function testThrowsNotFoundExceptionForUnknownIdentifier(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $container->get('nonexistent.service');
    }

    public function testDetectsCircularDependencies(): void
    {
        $container = new Container();

        $this->expectException(CircularDependencyException::class);
        $this->expectExceptionMessage('Circular dependency detected');
        $container->get(FixtureCycleA::class);
    }
}

interface FixtureContract
{
}

final class FixtureLeaf implements FixtureContract
{
}

final class FixtureDependency
{
    public function __construct(public readonly FixtureLeaf $leaf)
    {
    }
}

final class FixtureService
{
    public function __construct(
        public readonly FixtureContract $leaf,
        public readonly FixtureDependency $dependency,
        public readonly string $label = 'default-label',
    ) {
    }
}

final class FixtureScalarRequired
{
    public function __construct(
        public readonly string $dsn,
        public readonly int $port = 3306,
    ) {
    }
}

final class FixtureCycleA
{
    public function __construct(public readonly FixtureCycleB $b)
    {
    }
}

final class FixtureCycleB
{
    public function __construct(public readonly FixtureCycleA $a)
    {
    }
}
