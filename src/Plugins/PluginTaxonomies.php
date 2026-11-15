<?php

declare(strict_types=1);

namespace Prime\Plugins;

use Prime\Content\Taxonomy;
use Prime\Content\TaxonomyRegistry;

/**
 * Plugin-scoped facade for registering custom taxonomies with automatic source attribution.
 */
final class PluginTaxonomies
{
    public function __construct(
        private readonly TaxonomyRegistry $registry,
        private readonly string $pluginSlug,
    ) {
    }

    public function register(Taxonomy $taxonomy): void
    {
        $this->registry->register($taxonomy->withSource('plugin:' . $this->pluginSlug));
    }

    public function get(string $name): ?Taxonomy
    {
        return $this->registry->get($name);
    }

    public function has(string $name): bool
    {
        return $this->registry->has($name);
    }
}
