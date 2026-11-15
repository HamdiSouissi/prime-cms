<?php

declare(strict_types=1);

namespace Prime\Plugins\Exception;

use RuntimeException;

/**
 * Base exception for plugin lifecycle, validation, and dependency errors.
 */
class PluginException extends RuntimeException
{
}
