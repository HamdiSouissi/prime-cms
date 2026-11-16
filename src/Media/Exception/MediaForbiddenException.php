<?php

declare(strict_types=1);

namespace Prime\Media\Exception;

/**
 * Raised when an authenticated user lacks permission for a media action.
 */
final class MediaForbiddenException extends MediaException
{
}
