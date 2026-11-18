<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use Prime\Content\TaxonomyRegistry;
use Prime\Content\TermRepository;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * `GET /api/v1/terms` — Liste des taxonomies et de leurs termes.
 * Accepte `?taxonomy=nom` pour filtrer sur une taxonomie donnée.
 */
final class TermsController extends ApiController
{
    public function __construct(
        private readonly TaxonomyRegistry $taxonomies,
        private readonly TermRepository $terms,
    ) {
    }

    public function index(Request $request): Response
    {
        $taxonomy = (string) $request->query('taxonomy', '');

        if ($taxonomy !== '') {
            if (!$this->taxonomies->has($taxonomy)) {
                return $this->notFound("Taxonomie inconnue : {$taxonomy}");
            }

            $search = (string) $request->query('search', '');
            $termList = $this->terms->allForTaxonomy($taxonomy, $search);

            $data = [];
            foreach ($termList as $term) {
                $data[] = [
                    'id' => $term->id,
                    'taxonomy' => $term->taxonomy,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'description' => $term->description,
                    'parent_id' => $term->parentId,
                    'count' => $term->count,
                ];
            }

            return $this->json([
                'data' => $data,
                'meta' => ['taxonomy' => $taxonomy],
            ]);
        }

        // Toutes les taxonomies avec leurs termes (premier niveau)
        $all = [];
        foreach ($this->taxonomies->all() as $name => $taxonomy) {
            $termList = $this->terms->allForTaxonomy($name);
            $terms = [];
            foreach ($termList as $term) {
                $terms[] = [
                    'id' => $term->id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'parent_id' => $term->parentId,
                    'count' => $term->count,
                ];
            }

            $all[] = [
                'name' => $taxonomy->name,
                'label' => $taxonomy->label('name'),
                'public' => $taxonomy->public,
                'hierarchical' => $taxonomy->hierarchical,
                'types' => $taxonomy->contentTypes,
                'terms' => $terms,
            ];
        }

        return $this->json(['data' => $all]);
    }
}
