<?php

declare(strict_types=1);

namespace Prime\Site;

use Prime\Auth\User;
use Prime\Content\ContentType;
use Prime\Content\Entry;
use Prime\Content\Taxonomy;
use Prime\Content\Term;

/**
 * Immutable resolved context for any public site HTTP request.
 */
final readonly class PageContext
{
    public const TYPE_FRONT = 'front';
    public const TYPE_BLOG = 'blog';
    public const TYPE_ENTRY = 'entry';
    public const TYPE_ARCHIVE = 'archive';
    public const TYPE_TERM = 'term';
    public const TYPE_AUTHOR = 'author';
    public const TYPE_SEARCH = 'search';
    public const TYPE_NOT_FOUND = 'not_found';

    /** A page a plugin renders itself (`PluginContext::renderPage()`), inside the theme layout. */
    public const TYPE_PLUGIN = 'plugin';

    /**
     * @param list<Entry> $entries
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $type,
        public string $path = '/',
        public int $page = 1,
        public int $perPage = 10,
        public ?Entry $entry = null,
        public array $entries = [],
        public int $totalEntries = 0,
        public int $totalPages = 1,
        public ?ContentType $contentType = null,
        public ?Taxonomy $taxonomy = null,
        public ?Term $term = null,
        public ?User $author = null,
        public ?string $searchQuery = null,
        public bool $isPreview = false,
        public bool $isCustomizerPreview = false,
        public int $httpStatus = 200,
        public ?string $redirectTo = null,
        public int $redirectStatus = 301,
        public array $extra = [],
    ) {
    }

    public static function notFound(string $path = '/'): self
    {
        return new self(
            type: self::TYPE_NOT_FOUND,
            path: $path,
            httpStatus: 404,
        );
    }

    /**
     * A plugin page: `$html` is placed in the theme's `layouts/base.php`, `$title` feeds `<title>`.
     */
    public static function plugin(string $path, string $title, string $html, string $description = '', int $status = 200): self
    {
        return new self(
            type: self::TYPE_PLUGIN,
            path: $path,
            httpStatus: $status,
            extra: ['title' => $title, 'html' => $html, 'description' => $description],
        );
    }

    public function isPlugin(): bool
    {
        return $this->type === self::TYPE_PLUGIN;
    }

    public static function redirect(string $path, string $target, int $status = 301): self
    {
        return new self(
            type: self::TYPE_NOT_FOUND,
            path: $path,
            httpStatus: $status,
            redirectTo: $target,
            redirectStatus: $status,
        );
    }

    public function isRedirect(): bool
    {
        return $this->redirectTo !== null && $this->redirectTo !== '';
    }

    public function isFront(): bool
    {
        return $this->type === self::TYPE_FRONT;
    }

    public function isBlog(): bool
    {
        return $this->type === self::TYPE_BLOG;
    }

    public function isEntry(): bool
    {
        return $this->type === self::TYPE_ENTRY;
    }

    public function isArchive(): bool
    {
        return $this->type === self::TYPE_ARCHIVE;
    }

    public function isTerm(): bool
    {
        return $this->type === self::TYPE_TERM;
    }

    public function isAuthor(): bool
    {
        return $this->type === self::TYPE_AUTHOR;
    }

    public function isSearch(): bool
    {
        return $this->type === self::TYPE_SEARCH;
    }

    public function isNotFound(): bool
    {
        return $this->type === self::TYPE_NOT_FOUND;
    }

    /**
     * Return a copy with an explicit preview flag (used for authenticated editor previews).
     */
    public function withPreview(bool $isPreview = true, bool $isCustomizerPreview = false): self
    {
        return new self(
            type: $this->type,
            path: $this->path,
            page: $this->page,
            perPage: $this->perPage,
            entry: $this->entry,
            entries: $this->entries,
            totalEntries: $this->totalEntries,
            totalPages: $this->totalPages,
            contentType: $this->contentType,
            taxonomy: $this->taxonomy,
            term: $this->term,
            author: $this->author,
            searchQuery: $this->searchQuery,
            isPreview: $isPreview,
            isCustomizerPreview: $isCustomizerPreview,
            httpStatus: $this->httpStatus,
            redirectTo: $this->redirectTo,
            redirectStatus: $this->redirectStatus,
            extra: $this->extra,
        );
    }
}
