<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use Prime\Content\Exception\ContentException;
use Prime\Content\TaxonomyRegistry;
use Prime\Content\TermRepository;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Site\UrlGenerator;

/**
 * `GET /api/v1/terms` — Liste des taxonomies et de leurs termes.
 * `GET /api/v1/terms/{taxonomy}` — Termes d'une taxonomie (avec parent).
 * `GET /api/v1/terms/{taxonomy}/{id}` — Terme unique.
 * `POST /api/v1/terms/{taxonomy}` — Créer un terme.
 * `PATCH /api/v1/terms/{taxonomy}/{id}` — Modifier un terme.
 * `DELETE /api/v1/terms/{taxonomy}/{id}` — Supprimer un terme.
 */
final class TermsController extends ApiController
{
    public function __construct(
        private readonly TaxonomyRegistry $taxonomies,
        private readonly TermRepository $terms,
        private readonly UrlGenerator $urlGenerator,
    ) {
    }

    public function index(Request $request): Response
    {
        $taxonomy = (string) $request->query('taxonomy', '');

        if ($taxonomy !== '') {
            if (!$this->taxonomies->has($taxonomy)) {
                return $this->notFound("Taxonomie inconnue : {$taxonomy}");
            }

            return $this->byTaxonomy($request, $taxonomy);
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

    public function byTaxonomy(Request $request, string $taxonomy): Response
    {
        if (!$this->taxonomies->has($taxonomy)) {
            return $this->notFound("Taxonomie inconnue : {$taxonomy}");
        }

        $search = (string) $request->query('search', '');
        $parent = (string) $request->query('parent', '');

        $flat = $this->terms->flatTreeForTaxonomy($taxonomy);

        $data = [];
        foreach ($flat as $item) {
            $term = $item['term'];
            $depth = $item['depth'];

            if ($parent !== '' && (int) $term->parentId !== (int) $parent) {
                continue;
            }

            if ($search !== '') {
                $needle = mb_strtolower($search);
                if (mb_strpos(mb_strtolower($term->name), $needle) === false
                    && mb_strpos(mb_strtolower($term->slug), $needle) === false) {
                    continue;
                }
            }

            $data[] = [
                'id' => $term->id,
                'taxonomy' => $term->taxonomy,
                'name' => $term->name,
                'slug' => $term->slug,
                'description' => $term->description,
                'parent_id' => $term->parentId,
                'depth' => $depth,
                'menu_order' => $term->menuOrder,
                'count' => $term->count,
                'url' => $term->url(),
            ];
        }

        return $this->json([
            'data' => $data,
            'meta' => ['taxonomy' => $taxonomy],
        ]);
    }

    public function show(Request $request, string $taxonomy, string $id): Response
    {
        if (!$this->taxonomies->has($taxonomy)) {
            return $this->notFound("Taxonomie inconnue : {$taxonomy}");
        }

        $term = ctype_digit($id)
            ? $this->terms->find((int) $id)
            : $this->terms->findBySlug($taxonomy, $id);

        if ($term === null || $term->taxonomy !== $taxonomy) {
            return $this->notFound('not_found');
        }

        return $this->json([
            'data' => [
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
            ],
        ]);
    }

    public function create(Request $request, string $taxonomy): Response
    {
        if (!$this->taxonomies->has($taxonomy)) {
            return $this->notFound("Taxonomie inconnue : {$taxonomy}");
        }

        $body = $request->attribute('api.body');
        if (!is_array($body)) {
            return $this->error('invalid_request', [], 400, 'invalid_request');
        }

        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if ($name === '') {
            return $this->validationFailed(['name' => 'Le nom du terme est obligatoire.']);
        }

        $slug = isset($body['slug']) && is_string($body['slug']) ? trim($body['slug']) : null;
        $description = isset($body['description']) && is_string($body['description']) ? trim($body['description']) : null;
        $parentId = isset($body['parent_id']) && is_numeric($body['parent_id']) ? (int) $body['parent_id'] : 0;
        $menuOrder = isset($body['menu_order']) && is_numeric($body['menu_order']) ? (int) $body['menu_order'] : 0;

        try {
            $term = $this->terms->create($taxonomy, $name, $slug, $description, $parentId, $menuOrder);
        } catch (ContentException $e) {
            return Response::json([
                'error' => [
                    'code' => 'invalid_request',
                    'message' => $e->getMessage(),
                    'status' => 400,
                ],
            ], 400, ['Content-Type' => 'application/json']);
        }

        return $this->json([
            'data' => [
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
            ],
        ], 201);
    }

    public function update(Request $request, string $taxonomy, string $id): Response
    {
        if (!$this->taxonomies->has($taxonomy)) {
            return $this->notFound("Taxonomie inconnue : {$taxonomy}");
        }

        $term = ctype_digit($id)
            ? $this->terms->find((int) $id)
            : $this->terms->findBySlug($taxonomy, $id);

        if ($term === null || $term->taxonomy !== $taxonomy) {
            return $this->notFound('not_found');
        }

        $body = $request->attribute('api.body');
        if (!is_array($body)) {
            return $this->error('invalid_request', [], 400, 'invalid_request');
        }

        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : $term->name;
        if ($name === '') {
            return $this->validationFailed(['name' => 'Le nom du terme est obligatoire.']);
        }

        $slug = isset($body['slug']) && is_string($body['slug']) ? trim($body['slug']) : null;
        $description = isset($body['description']) && is_string($body['description']) ? trim($body['description']) : null;
        $parentId = isset($body['parent_id']) && is_numeric($body['parent_id']) ? (int) $body['parent_id'] : $term->parentId;
        $menuOrder = isset($body['menu_order']) && is_numeric($body['menu_order']) ? (int) $body['menu_order'] : $term->menuOrder;

        try {
            $updated = $this->terms->update((int) $term->id, $name, $slug, $description, $parentId, $menuOrder);
        } catch (ContentException $e) {
            return Response::json([
                'error' => [
                    'code' => 'invalid_request',
                    'message' => $e->getMessage(),
                    'status' => 400,
                ],
            ], 400, ['Content-Type' => 'application/json']);
        }

        return $this->json([
            'data' => [
                'id' => $updated->id,
                'taxonomy' => $updated->taxonomy,
                'name' => $updated->name,
                'slug' => $updated->slug,
                'description' => $updated->description,
                'parent_id' => $updated->parentId,
                'menu_order' => $updated->menuOrder,
                'count' => $updated->count,
                'created_at' => $updated->createdAt,
                'updated_at' => $updated->updatedAt,
                'url' => $updated->url(),
            ],
        ]);
    }

    public function delete(Request $request, string $taxonomy, string $id): Response
    {
        if (!$this->taxonomies->has($taxonomy)) {
            return $this->notFound("Taxonomie inconnue : {$taxonomy}");
        }

        $term = ctype_digit($id)
            ? $this->terms->find((int) $id)
            : $this->terms->findBySlug($taxonomy, $id);

        if ($term === null || $term->taxonomy !== $taxonomy) {
            return $this->notFound('not_found');
        }

        try {
            $this->terms->delete((int) $term->id);
        } catch (ContentException $e) {
            return Response::json([
                'error' => [
                    'code' => 'conflict',
                    'message' => $e->getMessage(),
                    'status' => 409,
                ],
            ], 409, ['Content-Type' => 'application/json']);
        }

        return Response::noContent()->withStatus(204);
    }
}
