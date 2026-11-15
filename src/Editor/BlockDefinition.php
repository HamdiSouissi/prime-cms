<?php

declare(strict_types=1);

namespace Prime\Editor;

use Closure;
use InvalidArgumentException;
use Prime\Security\HtmlSanitizer;

/**
 * Immutable definition of a Prime Editor block type (core or plugin-supplied).
 */
final readonly class BlockDefinition
{
    /**
     * Valid block type format: lowercase letters/digits/hyphens with optional `plugin/` namespace.
     */
    public const TYPE_PATTERN = '/^[a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?$/';

    /**
     * @param Closure(array<string, mixed>, HtmlSanitizer): array{data: array<string, mixed>, errors: list<string>} $validator
     * @param Closure(array<string, mixed>, HtmlSanitizer, array<string, mixed>): string $renderer
     * @param Closure(array<string, mixed>): string $textExtractor
     * @param list<string> $keywords
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $description,
        public string $icon,
        public string $category,
        public Closure $validator,
        public Closure $renderer,
        public Closure $textExtractor,
        public ?string $requiresCapability = null,
        public array $keywords = [],
        public string $source = 'core',
    ) {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid editor block type [%s]. Expected lowercase identifier or "plugin/block".',
                $type,
            ));
        }

        if (trim($title) === '') {
            throw new InvalidArgumentException(sprintf('Editor block [%s] must have a non-empty title.', $type));
        }
    }

    /**
     * Export client-facing metadata for the Prime Editor slash menu and inserter.
     *
     * @return array{
     *   type: string,
     *   title: string,
     *   description: string,
     *   icon: string,
     *   category: string,
     *   keywords: list<string>,
     *   requiresCapability: ?string,
     *   source: string
     * }
     */
    public function toClientMeta(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'icon' => $this->icon,
            'category' => $this->category,
            'keywords' => $this->keywords,
            'requiresCapability' => $this->requiresCapability,
            'source' => $this->source,
        ];
    }
}
