<?php

declare(strict_types=1);

namespace Prime\Api\Resource;

use Prime\Content\Term;
use Prime\Site\UrlGenerator;

final class TermResource
{
    public function __construct(private readonly UrlGenerator $urlGenerator)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Term $term): array
    {
        return [
            'id' => $term->id,
            'taxonomy' => $term->taxonomy,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
            'parent_id' => $term->parentId,
            'menu_order' => $term->menuOrder,
            'count' => $term->count,
            'created_at' => $term->createdAt,
            'updated_at' => $term->updatedAt,
            'url' => $term->url(),
            '_links' => [
                'self' => $this->urlGenerator->absolute('/api/v1/terms/' . $term->taxonomy . '/' . $term->id),
                'collection' => $this->urlGenerator->absolute('/api/v1/terms/' . $term->taxonomy),
            ],
        ];
    }
}
