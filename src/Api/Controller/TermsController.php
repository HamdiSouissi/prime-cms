<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use InvalidArgumentException;
use Prime\Api\Resource\TermResource;
use Prime\Auth\User;
use Prime\Content\Exception\ContentException;
use Prime\Content\Taxonomy;
use Prime\Content\TaxonomyRegistry;
use Prime\Content\Term;
use Prime\Content\TermRepository;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Taxonomy terms of the REST API.
 *
 * Reading a public taxonomy is open to everyone; a private one needs `content.view`.
 * Writing needs the capability the taxonomy declares (`content.edit_any` by default),
 * exactly like the administration screens.
 */
final class TermsController extends ApiController
{
    public function __construct(
        private readonly TaxonomyRegistry $taxonomies,
        private readonly TermRepository $terms,
        private readonly TermResource $resource,
    ) {
    }

    /**
     * Every taxonomy the caller can read, with its labels.
     */
    public function taxonomies(Request $request): Response
    {
        $user = $this->currentUser($request);
        $data = [];
        foreach ($this->taxonomies->all() as $taxonomy) {
            if (!$this->canRead($taxonomy, $user)) {
                continue;
            }

            $data[] = [
                'name' => $taxonomy->name,
                'label' => $taxonomy->label('plural'),
                'singular' => $taxonomy->label('singular'),
                'public' => $taxonomy->public,
                'hierarchical' => $taxonomy->hierarchical,
                'types' => $taxonomy->contentTypes,
                '_links' => ['terms' => '/api/v1/terms/' . rawurlencode($taxonomy->name)],
            ];
        }

        return $this->json(['data' => $data]);
    }

    public function index(Request $request, string $taxonomy): Response
    {
        $definition = $this->readable($request, $taxonomy);
        if ($definition instanceof Response) {
            return $definition;
        }

        $parent = $request->query('parent');
        $data = [];
        foreach ($this->terms->allForTaxonomy($definition->name, trim((string) $request->query('search', ''))) as $term) {
            if (is_scalar($parent) && (string) $parent !== '' && $term->parentId !== (int) $parent) {
                continue;
            }
            $data[] = $this->resource->toArray($term);
        }

        return $this->json(['data' => $data, 'meta' => ['taxonomy' => $definition->name, 'total' => count($data)]]);
    }

    public function show(Request $request, string $taxonomy, string $ref): Response
    {
        $definition = $this->readable($request, $taxonomy);
        if ($definition instanceof Response) {
            return $definition;
        }

        $term = $this->find($definition, $ref);

        return $term === null
            ? $this->failure($request, 'not_found', 404)
            : $this->json(['data' => $this->resource->toArray($term)]);
    }

    public function store(Request $request, string $taxonomy): Response
    {
        $definition = $this->writable($request, $taxonomy);
        if ($definition instanceof Response) {
            return $definition;
        }

        $body = $this->body($request);

        try {
            $term = $this->terms->create(
                taxonomy: $definition->name,
                name: is_string($body['name'] ?? null) ? $body['name'] : '',
                slug: is_string($body['slug'] ?? null) ? $body['slug'] : null,
                description: is_string($body['description'] ?? null) ? $body['description'] : null,
                parentId: is_int($body['parent_id'] ?? null) ? $body['parent_id'] : 0,
                menuOrder: is_int($body['menu_order'] ?? null) ? $body['menu_order'] : 0,
            );
        } catch (ContentException $exception) {
            return $this->validationFailed($request, ['name' => $exception->getMessage()]);
        } catch (InvalidArgumentException) {
            return $this->failure($request, 'not_found', 404);
        }

        return $this->json(['data' => $this->resource->toArray($term)], 201, [
            'Location' => '/api/v1/terms/' . rawurlencode($definition->name) . '/' . $term->id,
        ]);
    }

    public function update(Request $request, string $taxonomy, string $id): Response
    {
        $definition = $this->writable($request, $taxonomy);
        if ($definition instanceof Response) {
            return $definition;
        }

        $term = $this->find($definition, $id);
        if ($term === null) {
            return $this->failure($request, 'not_found', 404);
        }

        $body = $this->body($request);

        try {
            $updated = $this->terms->update(
                id: $term->id,
                name: is_string($body['name'] ?? null) ? $body['name'] : $term->name,
                slug: is_string($body['slug'] ?? null) ? $body['slug'] : $term->slug,
                description: array_key_exists('description', $body) ? (is_string($body['description']) ? $body['description'] : null) : $term->description,
                parentId: is_int($body['parent_id'] ?? null) ? $body['parent_id'] : $term->parentId,
                menuOrder: is_int($body['menu_order'] ?? null) ? $body['menu_order'] : $term->menuOrder,
            );
        } catch (ContentException $exception) {
            return $this->validationFailed($request, ['name' => $exception->getMessage()]);
        }

        return $this->json(['data' => $this->resource->toArray($updated)]);
    }

    public function destroy(Request $request, string $taxonomy, string $id): Response
    {
        $definition = $this->writable($request, $taxonomy);
        if ($definition instanceof Response) {
            return $definition;
        }

        $term = $this->find($definition, $id);
        if ($term === null) {
            return $this->failure($request, 'not_found', 404);
        }

        try {
            $this->terms->delete($term->id);
        } catch (ContentException) {
            return $this->failure($request, 'conflict', 409);
        }

        return $this->noContent();
    }

    private function readable(Request $request, string $taxonomy): Taxonomy|Response
    {
        $definition = $this->taxonomies->get($taxonomy);
        if ($definition === null || !$this->canRead($definition, $this->currentUser($request))) {
            return $this->failure($request, 'not_found', 404);
        }

        return $definition;
    }

    private function writable(Request $request, string $taxonomy): Taxonomy|Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return $this->failure($request, 'unauthenticated', 401);
        }

        $definition = $this->taxonomies->get($taxonomy);
        if ($definition === null) {
            return $this->failure($request, 'not_found', 404);
        }

        if (!$user->can($definition->capability)) {
            return $this->failure($request, 'forbidden', 403);
        }

        return $definition;
    }

    private function canRead(Taxonomy $taxonomy, ?User $user): bool
    {
        return $taxonomy->public || ($user !== null && $user->can('content.view'));
    }

    private function find(Taxonomy $taxonomy, string $id): ?Term
    {
        if (!ctype_digit($id)) {
            return $this->terms->findBySlug($taxonomy->name, $id);
        }

        $term = $this->terms->find((int) $id);

        return $term !== null && $term->taxonomy === $taxonomy->name ? $term : null;
    }
}
