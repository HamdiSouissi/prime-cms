<?php

declare(strict_types=1);

namespace Prime\Api\Resource;

use Prime\Content\Term;
use Prime\Site\UrlGenerator;

final class TermResource
{
    public function __construct(
        private readonly UrlGenerator $urls,
        private readonly ResourceFilter $filter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Term $term): array
    {
        $base = '/api/v1/terms/' . rawurlencode($term->taxonomy);

        return $this->filter->apply('term', [
            'id' => $term->id,
            'taxonomy' => $term->taxonomy,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
            'parent_id' => $term->parentId,
            'menu_order' => $term->menuOrder,
            'count' => $term->count,
            'url' => $this->urls->termUrl($term),
            '_links' => [
                'self' => $this->urls->absolute($base . '/' . $term->id),
                'collection' => $this->urls->absolute($base),
            ],
        ], $term);
    }
}
