<?php

declare(strict_types=1);

namespace FixtureSyntaxError;

use Prime\Plugins\AbstractPlugin;

final class Plugin extends AbstractPlugin
{
    public function boot( {
        // Deliberate invalid PHP syntax to test ParseError isolation
    }
}
