<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use Prime\Admin\AdminContext;
use Prime\Content\Exception\ContentException;
use Prime\Content\Taxonomy;
use Prime\Content\TaxonomyRegistry;
use Prime\Content\Term;
use Prime\Content\TermRepository;
use Prime\Http\Exception\NotFoundException;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\View\View;

/**
 * Administration controller for all registered taxonomies (`/admin/taxonomies/{taxonomy}`).
 */
final class TaxonomyController
{
    public function __construct(
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly TaxonomyRegistry $taxonomies,
        private readonly TermRepository $terms,
    ) {
    }

    /**
     * Split view: create term form + searchable terms table (`GET /admin/taxonomies/{taxonomy}`).
     */
    public function index(Request $request, string $taxonomy): Response
    {
        $tax = $this->requireTaxonomy($taxonomy);
        $editId = $request->query('edit');
        $editingTerm = null;
        if (is_string($editId) && ctype_digit($editId)) {
            $candidate = $this->terms->find((int) $editId);
            if ($candidate !== null && $candidate->taxonomy === $tax->name) {
                $editingTerm = $candidate;
            }
        }

        return $this->renderIndex($request, $tax, $editingTerm);
    }

    /**
     * Create a new term (`POST /admin/taxonomies/{taxonomy}`).
     * Supports both standard form POST and inline AJAX quick-creation from the editor sidebar.
     */
    public function store(Request $request, string $taxonomy): Response
    {
        $tax = $this->requireTaxonomy($taxonomy);
        $wantsJson = $this->wantsJson($request);

        $payload = $wantsJson ? $this->decodeJsonBody($request) : [];
        $name = isset($payload['name']) && is_string($payload['name'])
            ? trim($payload['name'])
            : trim((string) $request->body('name', ''));
        $slug = isset($payload['slug']) && is_string($payload['slug'])
            ? trim($payload['slug'])
            : trim((string) $request->body('slug', ''));
        $description = isset($payload['description']) && is_string($payload['description'])
            ? trim($payload['description'])
            : trim((string) $request->body('description', ''));
        $parentRaw = $payload['parent_id'] ?? $request->body('parent_id', '0');
        $parentId = $tax->hierarchical && is_numeric($parentRaw) && (int) $parentRaw > 0 ? (int) $parentRaw : 0;

        try {
            $term = $this->terms->create(
                taxonomy: $tax->name,
                name: $name,
                slug: $slug !== '' ? $slug : null,
                description: $description !== '' ? $description : null,
                parentId: $parentId,
            );

            if ($wantsJson) {
                return Response::json([
                    'ok' => true,
                    'term' => [
                        'id' => $term->id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                        'parent_id' => $term->parentId,
                    ],
                ], 201);
            }

            $this->context->flashToast(
                sprintf('« %s » a été ajouté à %s.', $term->name, $tax->label('plural')),
                'success',
            );
        } catch (ContentException $exception) {
            if ($wantsJson) {
                return Response::json(['ok' => false, 'message' => $exception->getMessage()], 422);
            }

            $this->context->flashToast($exception->getMessage(), 'danger', 'Création impossible');
        }

        return Response::redirect($tax->adminUrl());
    }

    /**
     * Edit term screen (`GET /admin/taxonomies/{taxonomy}/{id}/edit`).
     */
    public function edit(Request $request, string $taxonomy, int $id): Response
    {
        $tax = $this->requireTaxonomy($taxonomy);
        $term = $this->requireTerm($tax->name, $id);

        return $this->renderIndex($request, $tax, $term);
    }

    /**
     * Update an existing term (`POST /admin/taxonomies/{taxonomy}/{id}`).
     */
    public function update(Request $request, string $taxonomy, int $id): Response
    {
        $tax = $this->requireTaxonomy($taxonomy);
        $term = $this->requireTerm($tax->name, $id);

        $name = trim((string) $request->body('name', $term->name));
        $slug = trim((string) $request->body('slug', $term->slug));
        $description = trim((string) $request->body('description', $term->description ?? ''));
        $parentRaw = $request->body('parent_id', '0');
        $parentId = $tax->hierarchical && is_numeric($parentRaw) && (int) $parentRaw > 0 ? (int) $parentRaw : 0;

        try {
            $updated = $this->terms->update(
                id: $term->id,
                name: $name,
                slug: $slug !== '' ? $slug : null,
                description: $description !== '' ? $description : null,
                parentId: $parentId,
            );
            $this->context->flashToast(sprintf('« %s » a été mis à jour.', $updated->name), 'success');
        } catch (ContentException $exception) {
            $this->context->flashToast($exception->getMessage(), 'danger', 'Mise à jour impossible');

            return Response::redirect(sprintf('%s/%d/edit', $tax->adminUrl(), $term->id));
        }

        return Response::redirect($tax->adminUrl());
    }

    /**
     * Delete a term (`POST /admin/taxonomies/{taxonomy}/{id}/delete`).
     */
    public function delete(Request $request, string $taxonomy, int $id): Response
    {
        $tax = $this->requireTaxonomy($taxonomy);
        $term = $this->requireTerm($tax->name, $id);

        try {
            $this->terms->delete($term->id);
            $this->context->flashToast(sprintf('« %s » a été supprimé.', $term->name), 'info');
        } catch (ContentException $exception) {
            $this->context->flashToast($exception->getMessage(), 'danger', 'Suppression impossible');
        }

        return Response::redirect($tax->adminUrl());
    }

    private function renderIndex(Request $request, Taxonomy $tax, ?Term $editing): Response
    {
        $search = trim((string) $request->query('q', ''));
        $allTerms = $this->terms->allForTaxonomy($tax->name);
        $filtered = $search !== '' ? $this->terms->allForTaxonomy($tax->name, $search) : $allTerms;

        $depths = [];
        if ($tax->hierarchical) {
            foreach ($this->terms->flatTreeForTaxonomy($tax->name) as $node) {
                $depths[$node['term']->id] = $node['depth'];
            }
        }

        $html = $this->view->render('admin::taxonomies/index', $this->context->build($request, $tax->label('plural'), [
            'taxonomy' => $tax,
            'terms' => $filtered,
            'allTerms' => $allTerms,
            'depths' => $depths,
            'editing' => $editing,
            'search' => $search,
            'primaryCtaInContent' => true,
            'breadcrumbs' => [
                ['label' => 'Tableau de bord', 'url' => '/admin'],
                ['label' => $tax->label('plural'), 'url' => null],
            ],
        ]));

        return Response::html($html);
    }

    private function requireTaxonomy(string $slug): Taxonomy
    {
        $taxonomy = $this->taxonomies->get($slug);
        if ($taxonomy === null) {
            throw new NotFoundException(sprintf('Taxonomie « %s » introuvable.', $slug));
        }

        return $taxonomy;
    }

    private function requireTerm(string $taxonomy, int $id): Term
    {
        $term = $this->terms->find($id);
        if ($term === null || $term->taxonomy !== $taxonomy) {
            throw new NotFoundException('Terme introuvable.');
        }

        return $term;
    }

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('accept', ''));
        $contentType = strtolower((string) $request->header('content-type', ''));

        return str_contains($accept, 'application/json') || str_contains($contentType, 'application/json');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(Request $request): array
    {
        $raw = $request->rawBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
