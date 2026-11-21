<?php

declare(strict_types=1);

namespace Prime\Api\Resource;

use Prime\Auth\User;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryService;
use Prime\Site\UrlGenerator;

/**
 * Public representation of a content entry.
 *
 * `content` is the Prime Editor document (blocks), `content_html` its rendered, sanitised
 * HTML and is read-only. The author e-mail is never exposed. `_links.edit_url` is present
 * only for a caller allowed to edit the entry.
 */
final class EntryResource
{
    public function __construct(
        private readonly UrlGenerator $urls,
        private readonly EntryService $service,
        private readonly ContentTypeRegistry $types,
        private readonly ResourceFilter $filter,
    ) {
    }

    /**
     * @param list<string>|null $fields Restricts the members returned (`id` and `_links` are always kept)
     *
     * @return array<string, mixed>
     */
    public function toArray(Entry $entry, ?User $viewer, ?array $fields = null): array
    {
        $terms = [];
        foreach ($entry->terms as $taxonomy => $list) {
            foreach ($list as $term) {
                $terms[] = ['id' => $term->id, 'taxonomy' => (string) $taxonomy, 'slug' => $term->slug, 'name' => $term->name];
            }
        }

        $document = json_decode($entry->content, true);

        $data = [
            'id' => $entry->id,
            'type' => $entry->type,
            'status' => $entry->status,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'excerpt' => $entry->excerpt,
            'content' => is_array($document) ? $document : ['blocks' => []],
            'content_html' => $entry->contentHtml,
            'author' => $entry->authorId !== null ? ['id' => $entry->authorId, 'name' => $entry->authorName] : null,
            'parent_id' => $entry->parentId,
            'menu_order' => $entry->menuOrder,
            'template' => $entry->template,
            'featured_media_id' => (int) ($entry->meta('_featured_media_id') ?? 0) ?: null,
            'terms' => $terms,
            'published_at' => $entry->publishedAt,
            'created_at' => $entry->createdAt,
            'updated_at' => $entry->updatedAt,
            'url' => $this->urls->entryUrl($entry),
            '_links' => $this->links($entry, $viewer),
        ];

        if ($fields !== null && $fields !== []) {
            $data = array_intersect_key($data, array_flip(array_merge($fields, ['id', '_links'])));
        }

        return $this->filter->apply('entry', $data, $entry, $viewer);
    }

    public static function etag(Entry $entry): string
    {
        return 'W/"' . $entry->id . '-' . sha1($entry->updatedAt . '|' . $entry->status) . '"';
    }

    /**
     * @return array<string, mixed>
     */
    private function links(Entry $entry, ?User $viewer): array
    {
        $base = '/api/v1/content/' . rawurlencode($entry->type);
        $links = [
            'self' => $this->urls->absolute($base . '/' . $entry->id),
            'collection' => $this->urls->absolute($base),
        ];

        if ($entry->authorId !== null) {
            $links['author'] = $this->urls->absolute('/api/v1/users/' . $entry->authorId);
        }

        foreach (array_keys($entry->terms) as $taxonomy) {
            $links['terms'][(string) $taxonomy] = $this->urls->absolute('/api/v1/terms/' . rawurlencode((string) $taxonomy));
        }

        $type = $this->types->get($entry->type);
        if ($viewer !== null && $type !== null && $this->service->canEdit($viewer, $entry, $type)) {
            $links['edit_url'] = $this->urls->absolute('/admin/content/' . rawurlencode($entry->type) . '/' . $entry->id . '/edit');
        }

        return $links;
    }
}
