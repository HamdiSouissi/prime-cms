<?php

declare(strict_types=1);

namespace Prime\Plugins;

use Closure;
use LogicException;
use Prime\Editor\EditorDefinition;
use Prime\Editor\EditorRegistry;

/**
 * Editor registrar exposed via `PluginContext::editors()`.
 *
 * Asset paths are relative to the plugin's `assets/` folder (published with a version query).
 */
final class PluginEditors
{
    public function __construct(
        private readonly EditorRegistry $registry,
        private readonly string $slug,
        private readonly Closure $assetUrl,
    ) {
    }

    /**
     * @param Closure(array{entry: ?\Prime\Content\Entry, type: \Prime\Content\ContentType, document: array<string, mixed>, contentJson: string}): string $render
     * @param list<string>                                                                                                                                 $scripts e.g. ['editor.js']
     * @param list<string>                                                                                                                                 $styles  e.g. ['editor.css']
     */
    public function register(string $name, string $label, Closure $render, array $scripts = [], array $styles = []): void
    {
        // Same rule as job handlers and scheduled tasks: the slug is the namespace, so two plugins
        // can never fight over an editor name and the core `blocks` editor stays out of reach.
        if ($name !== $this->slug && !str_starts_with($name, $this->slug . '-')) {
            throw new LogicException(sprintf('Plugin "%s" must name its editors "%s" or "%s-…", "%s" given.', $this->slug, $this->slug, $this->slug, $name));
        }

        $this->registry->register(new EditorDefinition(
            name: $name,
            label: $label,
            render: $render,
            scripts: array_map(fn (string $path): string => $this->asset($path), $scripts),
            styles: array_map(fn (string $path): string => $this->asset($path), $styles),
            owner: $this->slug,
        ));
    }

    private function asset(string $path): string
    {
        return str_starts_with($path, '/') ? $path : (string) ($this->assetUrl)($path);
    }
}
