<?php

declare(strict_types=1);

namespace Prime\Plugins;

use Prime\Content\Fields\FieldGroup;
use Prime\Content\Fields\FieldRegistry;

/**
 * Plugin-scoped facade for registering custom field groups with automatic source attribution.
 */
final class PluginFields
{
    public function __construct(
        private readonly FieldRegistry $registry,
        private readonly string $pluginSlug,
    ) {
    }

    public function registerGroup(FieldGroup $group): void
    {
        $this->registry->registerGroup($group->withSource('plugin:' . $this->pluginSlug));
    }
}
