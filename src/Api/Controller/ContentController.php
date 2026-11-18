<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use Prime\Auth\User;
use Prime\Api\Resource\EntryResource;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryQuery;
use Prime\Content\EntryRepository;
use Prime\Content\EntryService;
use Prime\Content\Exception\ContentConflictException;
use Prime\Content\Exception\ContentForbiddenException;
use Prime\Content\Exception\ContentLockedException;
use Prime\Content\Exception\ContentValidationException;
use Prime\Hooks\Hooks;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Settings\Settings;
use Prime\Site\UrlGenerator;

/**
 * `GET /api/v1/content` — Liste des contenus.
 * `GET /api/v1/content/{type}` — Contenus d'un type donné.
 * `GET /api/v1/content/{type}/{id}` — Contenu unique par ID ou slug.
 * `POST /api/v1/content/{type}` — Créer un contenu.
 * `PATCH /api/v1/content/{type}/{id}` — Modifier un contenu.
 * `DELETE /api/v1/content/{type}/{id}` — Corbeille ou suppression définitive.
 * `POST /api/v1/content/{type}/{id}/restore` — Restaurer depuis la corbeille.
 */
final class ContentController extends ApiController
{
    public function __construct(
        private readonly ContentTypeRegistry $types,
        private readonly EntryQuery $query,
        private readonly EntryRepository $entries,
        private readonly EntryService $entryService,
        private readonly UrlGenerator $urlGenerator,
        private readonly Settings $settings,
        private readonly Hooks $hooks,
        private readonly EntryResource $entryResource,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->listEntries($request, null);
    }

    public function byType(Request $request, string $type): Response
    {
        if (!$this->types->has($type)) {
            return $this->notFound('unknown_type', ['type' => $type]);
        }

        return $this->listEntries($request, $type);
    }

    public function show(Request $request, string $type, string $id): Response
    {
        $user = $this->currentUser($request);
        $locale = $user !== null ? $user->locale : 'fr';

        if (!$this->types->has($type)) {
            return $this->notFound('unknown_type', ['type' => $type], locale: $locale);
        }

        $entry = ctype_digit($id)
            ? $this->entries->find((int) $id)
            : $this->entries->findBySlug($type, $id);

        if ($entry === null || $entry->type !== $type) {
            return $this->notFound('not_found', locale: $locale);
        }

        if (!$this->isReadable($entry, $user)) {
            return $this->notFound('not_found', locale: $locale);
        }

        $unauthorized = $this->requirePublicRead($user);
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        return $this->respondWithEntry($request, $entry, $user);
    }

    public function create(Request $request, string $type): Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return $this->unauthorized();
        }

        $locale = $user->locale;
        $typeDef = $this->types->get($type);
        if ($typeDef === null) {
            return $this->notFound('unknown_type', ['type' => $type], locale: $locale);
        }

        $body = $request->attribute('api.body');
        if (!is_array($body)) {
            return $this->error('invalid_request', [], 400, 'invalid_request', $locale);
        }

        try {
            $entry = $this->entryService->create($type, $body, $user);
        } catch (ContentValidationException $e) {
            return $this->validationFailed($this->flattenValidationErrors($e));
        } catch (ContentForbiddenException) {
            return $this->forbidden();
        }

        return $this->respondWithEntry($request, $entry, $user, 201);
    }

    public function update(Request $request, string $type, string $id): Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return $this->unauthorized();
        }

        $locale = $user->locale;
        $typeDef = $this->types->get($type);
        if ($typeDef === null) {
            return $this->notFound('unknown_type', ['type' => $type], locale: $locale);
        }

        $entry = ctype_digit($id)
            ? $this->entries->find((int) $id)
            : $this->entries->findBySlug($type, $id);

        if ($entry === null || $entry->type !== $type) {
            return $this->notFound('not_found', locale: $locale);
        }

        // Concurrence : If-Match périmé → 412.
        $ifMatch = $request->header('if-match');
        $currentEtag = '"' . $entry->updatedAt . '"';
        if ($ifMatch !== null && $ifMatch !== '' && $ifMatch !== $currentEtag) {
            return $this->error('precondition_failed', [], 412, 'precondition_failed', $locale);
        }

        $body = $request->attribute('api.body');
        if (!is_array($body)) {
            return $this->error('invalid_request', [], 400, 'invalid_request', $locale);
        }

        try {
            $updated = $this->entryService->update((int) $entry->id, $body, $user);
        } catch (ContentValidationException $e) {
            return $this->validationFailed($this->flattenValidationErrors($e));
        } catch (ContentForbiddenException) {
            return $this->forbidden();
        } catch (ContentLockedException $e) {
            return Response::json([
                'error' => [
                    'code' => 'conflict',
                    'message' => $e->getMessage(),
                    'status' => 409,
                ],
            ], 409, ['Content-Type' => 'application/json']);
        } catch (ContentConflictException $e) {
            return Response::json([
                'error' => [
                    'code' => 'conflict',
                    'message' => $e->getMessage(),
                    'status' => 409,
                ],
            ], 409, ['Content-Type' => 'application/json']);
        }

        return $this->respondWithEntry($request, $updated, $user);
    }

    public function trash(Request $request, string $type, string $id): Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return $this->unauthorized();
        }

        $locale = $user->locale;
        $typeDef = $this->types->get($type);
        if ($typeDef === null) {
            return $this->notFound('unknown_type', ['type' => $type], locale: $locale);
        }

        $entry = ctype_digit($id)
            ? $this->entries->find((int) $id)
            : $this->entries->findBySlug($type, $id);

        if ($entry === null || $entry->type !== $type) {
            return $this->notFound('not_found', locale: $locale);
        }

        $force = (string) $request->query('force', '0') === '1';

        try {
            if ($force) {
                $this->entryService->delete((int) $entry->id, $user);
            } else {
                $this->entryService->trash((int) $entry->id, $user);
            }
        } catch (ContentForbiddenException) {
            return $this->forbidden();
        } catch (ContentConflictException $e) {
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

    public function restore(Request $request, string $type, string $id): Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return $this->unauthorized();
        }

        $locale = $user->locale;
        $typeDef = $this->types->get($type);
        if ($typeDef === null) {
            return $this->notFound('unknown_type', ['type' => $type], locale: $locale);
        }

        $entry = ctype_digit($id)
            ? $this->entries->find((int) $id)
            : $this->entries->findBySlug($type, $id);

        if ($entry === null || $entry->type !== $type) {
            return $this->notFound('not_found', locale: $locale);
        }

        try {
            $this->entryService->restore((int) $entry->id, $user);
        } catch (ContentForbiddenException) {
            return $this->forbidden();
        }

        return Response::noContent()->withStatus(204);
    }

    /**
     * @param string|null $type
     */
    private function listEntries(Request $request, ?string $type): Response
    {
        $user = $this->currentUser($request);
        $locale = $user !== null ? $user->locale : 'fr';

        $perPage = (int) $request->query('per_page', 20);
        $page = (int) $request->query('page', 1);
        $status = (string) $request->query('status', Entry::STATUS_PUBLISHED);
        $search = (string) $request->query('search', '');
        $orderby = (string) $request->query('orderby', 'published_at');
        $order = (string) $request->query('order', 'DESC');
        $fieldsParam = (string) $request->query('fields', '');
        $after = (string) $request->query('after', '');
        $before = (string) $request->query('before', '');

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $resolved = $this->resolveStatus($user, $status, $locale);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        $status = $resolved;

        $unauthorized = $this->requirePublicRead($user);
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $q = $this->query->newQuery();

        if ($type !== null && $this->types->has($type)) {
            $q = $q->type($type);
        }

        $q = $q->status($status);

        if ($search !== '') {
            $q = $q->search($search);
        }

        $author = (string) $request->query('author', '');
        if ($author !== '' && ctype_digit($author)) {
            $q = $q->author((int) $author);
        }

        $term = (string) $request->query('term', '');
        if ($term !== '' && str_contains($term, ':') && $type !== null) {
            [$taxonomy, $slug] = explode(':', $term, 2);
            if ($this->isTaxonomyValid($taxonomy)) {
                $q = $q->whereTerm($taxonomy, [$slug]);
            }
        }

        if ($after !== '') {
            $q = $q->after($after);
        }

        if ($before !== '') {
            $q = $q->before($before);
        }

        if ($orderby !== '') {
            $q = $q->orderBy($orderby, $order);
        }

        // Un utilisateur authentifié peut prévisualiser ses propres brouillons.
        if ($user !== null) {
            $q = $q->includePrivateFor($user);
        }

        $paginated = $q->paginate($page, $perPage);

        $data = [];
        foreach ($paginated['items'] as $entry) {
            if (!$this->isReadable($entry, $user)) {
                continue;
            }

            $fields = $fieldsParam !== ''
                ? array_filter(array_map('trim', explode(',', $fieldsParam)))
                : null;

            $data[] = $this->applyResourceFilter(
                $this->entryResource->toArray($entry, $fields),
                $entry,
            );
        }

        $lastPage = $paginated['last_page'];

        return $this->paginated($request, $type ?? 'content', $data, $paginated['total'], $page, $perPage, $lastPage, $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function entryToArray(Entry $entry, ?array $fields = null): array
    {
        return $this->applyResourceFilter(
            $this->entryResource->toArray($entry, $fields),
            $entry,
        );
    }

    /**
     * Applique le filtre `api.resource.entry` avec protection contre les exceptions de plugin.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function applyResourceFilter(array $data, Entry $entry): array
    {
        $filtered = $data;

        try {
            $result = $this->hooks->apply('api.resource.entry', $data, $entry);
            if (is_array($result)) {
                $filtered = $result;
            }
        } catch (\Throwable) {
            // Une erreur de plugin ne casse jamais la réponse API.
        }

        return $filtered;
    }

    /**
     * @param int $status
     */
    private function respondWithEntry(Request $request, Entry $entry, ?User $user, int $status = 200): Response
    {
        $response = $this->json([
            'data' => $this->applyResourceFilter(
                $this->entryResource->toArray($entry),
                $entry,
            ),
        ], $status);

        // ETag basé sur la date de mise à jour (révision du contenu).
        $etag = '"' . $entry->updatedAt . '"';
        $response = $response->withHeader('ETag', $etag);

        if ($user !== null) {
            return $response->withHeader('Cache-Control', 'no-store');
        }

        if ($request->header('if-none-match') === $etag) {
            return Response::noContent(['ETag' => $etag])->withStatus(304);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function flattenValidationErrors(ContentValidationException $e): array
    {
        $fields = [];
        foreach ($e->errors() as $field => $messages) {
            $fields[$field] = is_array($messages) ? implode('; ', $messages) : (string) $messages;
        }

        return $fields;
    }

    private function isTaxonomyValid(string $taxonomy): bool
    {
        $taxonomies = $this->types->all();
        foreach ($taxonomies as $type) {
            foreach ($type->taxonomies as $tax) {
                if ($tax === $taxonomy) {
                    return true;
                }
            }
        }

        return false;
    }
}
