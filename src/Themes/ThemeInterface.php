<?php

declare(strict_types=1);

namespace Prime\Themes;

/**
 * Contract implemented by every Prime CMS theme's `src/Theme.php` entrypoint.
 */
interface ThemeInterface
{
    /**
     * Boot the theme, registering styles, scripts, hooks, and custom runtime behavior.
     */
    public function boot(ThemeContext $ctx): void;
}
