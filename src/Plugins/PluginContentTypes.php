<?php

declare(strict_types=1);

namespace Prime\Plugins;

use Prime\Content\ContentType;
use Prime\Content\ContentTypeRegistry;

/**
 * Plugin-scoped facade for registering custom content types with automatic source attribution.
 */
final class PluginContentTypes
{
    public function __construct(
        private readonly ContentTypeRegistry $registry,
        private readonly string $pluginSlug,
    ) {
    }

    public function register(ContentType $type): void
    {
        $this->registry->register($type->withSource('plugin:' . $this->pluginSlug));
    }

    public function get(string $name): ?ContentType
    {
        return $this->registry->get($name);
    }

    public function has(string $name): bool
    {
        return $this->registry->has($name);
    }
}
