<?php

declare(strict_types=1);

namespace Aurora;

use Prime\Themes\AbstractTheme;
use Prime\Themes\ThemeContext;

/**
 * Aurora — default editorial theme for Prime CMS.
 */
final class Theme extends AbstractTheme
{
    public function boot(ThemeContext $context): void
    {
        $context->style('aurora-main', 'css/aurora.css');
        $context->script('aurora-main', 'js/aurora.js', defer: true);
    }
}
