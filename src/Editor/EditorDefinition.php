<?php

declare(strict_types=1);

namespace Prime\Editor;

use Closure;
use InvalidArgumentException;

/**
 * An alternative content editor contributed by a plugin.
 *
 * The admin edit screen keeps its frame (title, status, terms, fields, saving) and only
 * swaps the block canvas for the HTML returned by `$render`. That HTML must contain a
 * `<textarea name="content_json" data-prime-custom-editor-input hidden>` kept in sync with a
 * Prime Editor document (`{"version": 1, "blocks": […]}`), so saving, revisions,
 * validation and rendering go through the exact same path as the default editor.
 */
final class EditorDefinition
{
    /**
     * @param Closure(array{entry: ?\Prime\Content\Entry, type: \Prime\Content\ContentType, document: array<string, mixed>, contentJson: string}): string $render
     * @param list<string>                                                                                                                                 $scripts Public URLs loaded on the edit screen
     * @param list<string>                                                                                                                                 $styles  Public URLs loaded on the edit screen
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly Closure $render,
        public readonly array $scripts = [],
        public readonly array $styles = [],
        public readonly string $owner = 'core',
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $this->name) !== 1) {
            throw new InvalidArgumentException(sprintf('Editor name [%s] must be a lowercase slug.', $this->name));
        }

        if ($this->name === EditorRegistry::DEFAULT) {
            throw new InvalidArgumentException('The "blocks" editor is the built-in one and cannot be replaced.');
        }
    }
}
