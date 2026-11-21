<?php

declare(strict_types=1);

namespace Prime\Editor;

use LogicException;

/**
 * Editors available on the admin edit screen. `blocks` (Prime Editor) is built in and is
 * the fallback whenever a content type asks for an editor nobody registered, e.g. because
 * the plugin providing it was deactivated.
 */
final class EditorRegistry
{
    public const DEFAULT = 'blocks';

    /**
     * @var array<string, EditorDefinition>
     */
    private array $editors = [];

    public function register(EditorDefinition $editor): void
    {
        if (isset($this->editors[$editor->name])) {
            throw new LogicException(sprintf(
                'Editor "%s" is already registered by "%s".',
                $editor->name,
                $this->editors[$editor->name]->owner,
            ));
        }

        $this->editors[$editor->name] = $editor;
    }

    public function get(string $name): ?EditorDefinition
    {
        return $this->editors[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return $name === self::DEFAULT || isset($this->editors[$name]);
    }

    /**
     * @return array<string, EditorDefinition>
     */
    public function all(): array
    {
        return $this->editors;
    }
}
