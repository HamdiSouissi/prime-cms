<?php

declare(strict_types=1);

namespace Prime\Kernel\Exception;

/**
 * Thrown when a circular dependency is encountered during resolution.
 */
final class CircularDependencyException extends ContainerException
{
}
