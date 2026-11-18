<?php

declare(strict_types=1);

namespace Prime\Api\Resource;

use Prime\Content\Entry;
use Prime\Site\UrlGenerator;

/**
 * Sérialise une entrée de contenu pour l'API REST.
 *
 * Chaque ressource expose un champ `_links` avec `self`, `collection`, `author`,
 * les termes et `edit_url` pour qui peut modifier.
 */
final class EntryResource
{
    public function __construct(private readonly UrlGenerator $urlGenerator)
    {
    }

    /**
     * @param array<string, mixed>|null $fields Champs à inclure (null = tous)
     * @return array<string, mixed>
     */
    public function toArray(Entry $entry, ?array $fields = null): array
    {
        $terms = [];
        foreach ($entry->terms as $taxonomy => $termList) {
            foreach ($termList as $term) {
                $terms[] = [
                    'taxonomy' => $taxonomy,
                    'slug' => $term->slug,
                    'name' => $term->name,
                ];
            }
        }

        $data = [
            'id' => $entry->id,
            'type' => $entry->type,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'status' => $entry->status,
            'content' => $entry->content,
            'content_html' => $entry->contentHtml,
            'excerpt' => $entry->excerpt,
            'author_id' => $entry->authorId,
            'author_name' => $entry->authorName,
            'published_at' => $entry->publishedAt,
            'created_at' => $entry->createdAt,
            'updated_at' => $entry->updatedAt,
            'featured_image_id' => (int) ($entry->meta('_featured_media_id') ?? 0),
            'terms' => $terms,
            'url' => $this->urlGenerator->entryUrl($entry),
            '_links' => $this->links($entry),
        ];

        if ($fields === null) {
            return $data;
        }

        $filtered = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $filtered[$field] = $data[$field];
            }
        }

        return $filtered;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function links(Entry $entry): array
    {
        $links = [
            'self' => $this->urlGenerator->absolute('/api/v1/content/' . $entry->type . '/' . $entry->id),
            'collection' => $this->urlGenerator->absolute('/api/v1/content/' . $entry->type),
        ];

        if ($entry->authorId !== null) {
            $links['author'] = $this->urlGenerator->absolute('/api/v1/users/' . $entry->authorId);
        }

        foreach ($entry->terms as $taxonomy => $termList) {
            foreach ($termList as $term) {
                $links['term:' . $taxonomy . ':' . $term->slug] = $this->urlGenerator->absolute(
                    '/api/v1/terms/' . $taxonomy . '/' . $term->id,
                );
            }
        }

        return $links;
    }
}
