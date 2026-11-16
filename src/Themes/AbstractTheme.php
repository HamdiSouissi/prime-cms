<?php

declare(strict_types=1);

namespace Prime\Themes;

/**
 * Convenience base class for themes implementing `ThemeInterface`.
 */
abstract class AbstractTheme implements ThemeInterface
{
    public function boot(ThemeContext $ctx): void
    {
    }
}
