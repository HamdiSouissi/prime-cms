<?php

declare(strict_types=1);

namespace Prime\Plugins;

use Prime\Editor\BlockDefinition;
use Prime\Editor\BlockRegistry;

/**
 * Plugin-scoped facade for registering custom Prime Editor blocks and their ES module scripts.
 */
final class PluginBlocks
{
    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly string $pluginSlug,
    ) {
    }

    public function register(BlockDefinition $block): void
    {
        $attributed = new BlockDefinition(
            type: $block->type,
            title: $block->title,
            description: $block->description,
            icon: $block->icon,
            category: $block->category,
            validator: $block->validator,
            renderer: $block->renderer,
            textExtractor: $block->textExtractor,
            requiresCapability: $block->requiresCapability,
            keywords: $block->keywords,
            source: 'plugin:' . $this->pluginSlug,
        );

        $this->registry->register($attributed);
    }

    /**
     * Enqueue a published plugin ES module script on the Prime Editor screen
     * (must be a local relative URL like `/assets/plugins/{slug}/...`).
     */
    public function enqueueScript(string $url): void
    {
        $this->registry->enqueueScript($url);
    }
}
