<?php

declare(strict_types=1);

namespace Prime\Kernel\Exception;

/**
 * Thrown when a requested service cannot be found in the container.
 */
final class NotFoundException extends ContainerException
{
}
