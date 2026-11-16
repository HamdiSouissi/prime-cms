<?php

declare(strict_types=1);

namespace Prime\Themes;

/**
 * Runtime metadata view for a discovered theme in `content/themes/{slug}/`.
 */
final readonly class ThemeInfo
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public string $slug,
        public string $path,
        public ?ThemeManifest $manifest,
        public bool $valid,
        public bool $compatible,
        public bool $active,
        public bool $isParentOfActive,
        public ?string $screenshotUrl,
        public array $errors = [],
        public ?string $runtimeError = null,
    ) {
    }

    public function name(): string
    {
        return $this->manifest !== null ? $this->manifest->name : $this->slug;
    }

    public function version(): string
    {
        return $this->manifest !== null ? $this->manifest->version : '—';
    }

    public function description(): string
    {
        return $this->manifest !== null ? $this->manifest->description : '';
    }

    public function author(): string
    {
        return $this->manifest !== null ? $this->manifest->author : '';
    }

    public function parentSlug(): ?string
    {
        return $this->manifest?->parent;
    }

    public function canActivate(): bool
    {
        return $this->valid && $this->compatible && !$this->active;
    }

    public function canDelete(): bool
    {
        return !$this->active && !$this->isParentOfActive && $this->slug !== ThemeManager::DEFAULT_THEME;
    }
}
