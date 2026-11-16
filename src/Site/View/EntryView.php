<?php

declare(strict_types=1);

namespace Prime\Site\View;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Auth\User;
use Prime\Content\Entry;
use Prime\Content\EntryQuery;
use Prime\Media\Media;
use Prime\Media\MediaHelper;
use Prime\Site\UrlGenerator;
use Prime\Site\Zone\DynamicBlockRegistry;
use Prime\Site\Zone\ZoneRepository;

/**
 * Escaped-by-default public template wrapper around a `Prime\Content\Entry` (L4).
 *
 * Every text-returning method escapes HTML automatically (`htmlspecialchars`),
 * except `content()` and `featuredImage()` which emit pre-validated safe HTML.
 * All author, avatar, and featured image relations are batch-preloaded by `EntryViewFactory`
 * to guarantee zero N+1 queries on listings.
 */
final class EntryView
{
    private const FRENCH_MONTHS = [
        1 => 'janvier',
        2 => 'février',
        3 => 'mars',
        4 => 'avril',
        5 => 'mai',
        6 => 'juin',
        7 => 'juillet',
        8 => 'août',
        9 => 'septembre',
        10 => 'octobre',
        11 => 'novembre',
        12 => 'décembre',
    ];

    private const FRENCH_DAYS = [
        0 => 'dimanche',
        1 => 'lundi',
        2 => 'mardi',
        3 => 'mercredi',
        4 => 'jeudi',
        5 => 'vendredi',
        6 => 'samedi',
    ];

    private bool $prevLoaded = false;
    private ?self $prevCache = null;
    private bool $nextLoaded = false;
    private ?self $nextCache = null;

    public function __construct(
        private readonly Entry $entry,
        private readonly UrlGenerator $urls,
        private readonly ?User $authorUser = null,
        private readonly ?Media $featuredMedia = null,
        private readonly ?Media $authorAvatarMedia = null,
        private readonly ?EntryQuery $query = null,
        private readonly ?EntryViewFactory $factory = null,
        private readonly ?ZoneRepository $zones = null,
        private readonly ?DynamicBlockRegistry $dynamicBlocks = null,
    ) {
    }

    public function id(): int
    {
        return $this->entry->id;
    }

    public function type(): string
    {
        return $this->entry->type;
    }

    public function slug(): string
    {
        return $this->entry->slug;
    }

    public function template(): ?string
    {
        return $this->entry->template;
    }

    public function raw(): Entry
    {
        return $this->entry;
    }

    /**
     * Escaped entry title.
     */
    public function title(): string
    {
        return $this->e($this->entry->title);
    }

    public function rawTitle(): string
    {
        return $this->entry->title;
    }

    /**
     * Escaped entry excerpt (falls back to truncated plain-text content).
     */
    public function excerpt(int $maxLength = 180): string
    {
        $source = ($this->entry->excerpt !== null && trim($this->entry->excerpt) !== '')
            ? trim($this->entry->excerpt)
            : trim($this->entry->contentText);

        $clean = trim(preg_replace('/\s+/', ' ', strip_tags($source)) ?? $source);
        if ($maxLength > 0 && mb_strlen($clean, 'UTF-8') > $maxLength) {
            $clean = rtrim(mb_substr($clean, 0, $maxLength - 1, 'UTF-8')) . '…';
        }

        return $this->e($clean);
    }

    /**
     * Safe pre-rendered Prime Editor HTML (with live dynamic block evaluation when present).
     */
    public function content(): string
    {
        if (
            $this->zones !== null
            && $this->dynamicBlocks !== null
            && str_contains($this->entry->content, '"type":"dynamic"')
        ) {
            return $this->zones->renderDocumentWithDynamicBlocks(
                $this->entry->decodedContent(),
                $this->dynamicBlocks,
            );
        }

        return $this->entry->contentHtml;
    }

    /**
     * Escaped public URL of this entry.
     */
    public function url(): string
    {
        return $this->e($this->urls->entryUrl($this->entry));
    }

    public function rawUrl(): string
    {
        return $this->urls->entryUrl($this->entry);
    }

    /**
     * French-localized formatted publication date (e.g. `15 novembre 2026`).
     */
    public function date(string $format = 'j F Y'): string
    {
        $source = $this->entry->publishedAt ?? $this->entry->createdAt;
        $ts = $source !== '' ? strtotime($source) : false;
        if ($ts === false) {
            return '';
        }

        return $this->e(self::formatFrenchDate($ts, $format));
    }

    /**
     * ISO-8601 publication date string for `<time datetime="...">`.
     */
    public function isoDate(): string
    {
        $source = $this->entry->publishedAt ?? $this->entry->createdAt;
        $ts = $source !== '' ? strtotime($source) : false;
        if ($ts === false) {
            return '';
        }

        return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    /**
     * Estimated reading time in minutes (minimum 1).
     */
    public function readingTime(): int
    {
        $text = trim($this->entry->contentText);
        if ($text === '') {
            return 1;
        }
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $count = is_array($words) ? count($words) : 0;

        return max(1, (int) ceil($count / 220));
    }

    public function hasFeaturedImage(): bool
    {
        return $this->featuredMedia !== null && $this->featuredMedia->isImage();
    }

    public function featuredImageUrl(string $size = 'large'): ?string
    {
        if ($this->featuredMedia === null) {
            return null;
        }

        return $this->featuredMedia->variantUrl($size) ?? $this->featuredMedia->url();
    }

    /**
     * Safe responsive `<picture>` / `<img>` HTML for the featured image (zero N+1).
     *
     * @param array<string, mixed> $attrs
     */
    public function featuredImage(string $size = 'large', array $attrs = []): string
    {
        if ($this->featuredMedia === null || !$this->featuredMedia->isImage()) {
            return '';
        }

        $merged = array_merge([
            'alt' => $this->featuredMedia->alt !== '' ? $this->featuredMedia->alt : $this->entry->title,
        ], $attrs);

        return MediaHelper::image($this->featuredMedia, $size, $merged);
    }

    /**
     * Escaped author metadata (zero N+1 from batch-preloaded user + avatar media).
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     raw_name: string,
     *     slug: string,
     *     bio: string,
     *     url: string,
     *     avatar: string,
     *     initials: string
     * }
     */
    public function author(): array
    {
        $rawName = $this->authorUser?->displayName
            ?? ($this->entry->authorName !== null && $this->entry->authorName !== '' ? $this->entry->authorName : 'Équipe éditoriale');
        $slug = $this->authorUser !== null ? $this->authorUser->authorSlug() : 'auteur-' . $this->entry->authorId;
        $bio = $this->authorUser?->bio ?? '';
        $url = $this->urls->authorUrl($slug);

        $avatarHtml = '';
        if ($this->authorAvatarMedia !== null && $this->authorAvatarMedia->isImage()) {
            $avatarHtml = MediaHelper::image($this->authorAvatarMedia, 'thumbnail', [
                'alt' => $rawName,
                'class' => 'site-avatar__img',
                'loading' => 'lazy',
            ]);
        }

        $initials = $this->computeInitials($rawName);

        return [
            'id' => $this->entry->authorId,
            'name' => $this->e($rawName),
            'raw_name' => $rawName,
            'slug' => $this->e($slug),
            'bio' => $this->e($bio),
            'url' => $this->e($url),
            'avatar' => $avatarHtml,
            'initials' => $this->e($initials),
        ];
    }

    /**
     * Escaped taxonomy terms attached to this entry (zero N+1).
     *
     * @return list<array{id: int, name: string, slug: string, taxonomy: string, url: string, count: int}>
     */
    public function terms(?string $taxonomy = null): array
    {
        $list = [];
        $buckets = $taxonomy !== null
            ? [$taxonomy => $this->entry->terms($taxonomy)]
            : $this->entry->terms;

        foreach ($buckets as $taxName => $terms) {
            foreach ($terms as $term) {
                $list[] = [
                    'id' => $term->id,
                    'name' => $this->e($term->name),
                    'slug' => $this->e($term->slug),
                    'taxonomy' => $this->e((string) $taxName),
                    'url' => $this->e($this->urls->termUrl($term)),
                    'count' => $term->count,
                ];
            }
        }

        return $list;
    }

    /**
     * Escaped custom field value (strings escaped automatically; arrays/numbers preserved).
     */
    public function field(string $key, mixed $default = null): mixed
    {
        $val = $this->entry->meta($key, $default);
        if (is_string($val)) {
            return $this->e($val);
        }

        return $val;
    }

    public function rawField(string $key, mixed $default = null): mixed
    {
        return $this->entry->meta($key, $default);
    }

    /**
     * Previous published entry of the same content type (older by `published_at`).
     */
    public function previous(): ?self
    {
        if ($this->prevLoaded) {
            return $this->prevCache;
        }
        $this->prevLoaded = true;

        if ($this->query === null || $this->factory === null) {
            return null;
        }

        $refDate = $this->entry->publishedAt ?? $this->entry->createdAt;
        $candidates = $this->query->newQuery()
            ->type($this->entry->type)
            ->orderBy('published_at', 'DESC')
            ->get();

        foreach ($candidates as $cand) {
            if ($cand->id === $this->entry->id) {
                continue;
            }
            $candDate = $cand->publishedAt ?? $cand->createdAt;
            if ($candDate < $refDate || ($candDate === $refDate && $cand->id < $this->entry->id)) {
                $this->prevCache = $this->factory->makeOne($cand);
                break;
            }
        }

        return $this->prevCache;
    }

    /**
     * Next published entry of the same content type (newer by `published_at`).
     */
    public function next(): ?self
    {
        if ($this->nextLoaded) {
            return $this->nextCache;
        }
        $this->nextLoaded = true;

        if ($this->query === null || $this->factory === null) {
            return null;
        }

        $refDate = $this->entry->publishedAt ?? $this->entry->createdAt;
        $candidates = $this->query->newQuery()
            ->type($this->entry->type)
            ->orderBy('published_at', 'ASC')
            ->get();

        foreach ($candidates as $cand) {
            if ($cand->id === $this->entry->id) {
                continue;
            }
            $candDate = $cand->publishedAt ?? $cand->createdAt;
            if ($candDate > $refDate || ($candDate === $refDate && $cand->id > $this->entry->id)) {
                $this->nextCache = $this->factory->makeOne($cand);
                break;
            }
        }

        return $this->nextCache;
    }

    /**
     * Batch-hydrated related published entries (preferring shared category/tag terms, then recent same-type entries).
     *
     * @return list<self>
     */
    public function related(int $limit = 3): array
    {
        $limit = max(1, min(12, $limit));
        if ($this->query === null || $this->factory === null) {
            return [];
        }

        $selected = [];
        $seen = [$this->entry->id => true];

        // First: entries sharing a category or tag term
        foreach (['category', 'tag'] as $tax) {
            $terms = $this->entry->terms($tax);
            if ($terms === []) {
                continue;
            }
            $found = $this->query->newQuery()
                ->type($this->entry->type)
                ->whereTerm($tax, $terms[0]->id)
                ->orderBy('published_at', 'DESC')
                ->limit($limit + 2)
                ->get();

            foreach ($found as $item) {
                if (isset($seen[$item->id])) {
                    continue;
                }
                $seen[$item->id] = true;
                $selected[] = $item;
                if (count($selected) >= $limit) {
                    return $this->factory->makeMany($selected);
                }
            }
        }

        // Fallback: latest entries of the same type
        $recent = $this->query->newQuery()
            ->type($this->entry->type)
            ->orderBy('published_at', 'DESC')
            ->limit($limit + 2)
            ->get();

        foreach ($recent as $item) {
            if (isset($seen[$item->id])) {
                continue;
            }
            $seen[$item->id] = true;
            $selected[] = $item;
            if (count($selected) >= $limit) {
                break;
            }
        }

        return $this->factory->makeMany($selected);
    }

    public static function formatFrenchDate(int $timestamp, string $format = 'j F Y'): string
    {
        $dt = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
        $monthIdx = (int) $dt->format('n');
        $dayIdx = (int) $dt->format('w');

        $out = '';
        $len = strlen($format);
        $escaped = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $format[$i];
            if ($escaped) {
                $out .= $ch;
                $escaped = false;
                continue;
            }
            if ($ch === '\\') {
                $escaped = true;
                continue;
            }
            if ($ch === 'F') {
                $out .= self::FRENCH_MONTHS[$monthIdx] ?? $dt->format('F');
            } elseif ($ch === 'M') {
                $full = self::FRENCH_MONTHS[$monthIdx] ?? $dt->format('M');
                $out .= mb_substr($full, 0, 4, 'UTF-8') . '.';
            } elseif ($ch === 'l') {
                $out .= self::FRENCH_DAYS[$dayIdx] ?? $dt->format('l');
            } elseif ($ch === 'D') {
                $full = self::FRENCH_DAYS[$dayIdx] ?? $dt->format('D');
                $out .= mb_substr($full, 0, 3, 'UTF-8') . '.';
            } else {
                $out .= $dt->format($ch);
            }
        }

        return $out;
    }

    private function computeInitials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || $parts === []) {
            return 'P';
        }
        $first = mb_strtoupper(mb_substr($parts[0], 0, 1, 'UTF-8'), 'UTF-8');
        $second = count($parts) > 1 ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8'), 'UTF-8') : '';

        return $first . $second;
    }

    private function e(string $val): string
    {
        return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
