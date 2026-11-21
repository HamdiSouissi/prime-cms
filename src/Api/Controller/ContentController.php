<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use InvalidArgumentException;
use Prime\Api\Resource\EntryResource;
use Prime\Auth\User;
use Prime\Content\ContentType;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryQuery;
use Prime\Content\EntryRepository;
use Prime\Content\EntryService;
use Prime\Content\Exception\ContentConflictException;
use Prime\Content\Exception\ContentException;
use Prime\Content\Exception\ContentForbiddenException;
use Prime\Content\Exception\ContentLockedException;
use Prime\Content\Exception\ContentValidationException;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Settings\Settings;
use Prime\Site\UrlGenerator;

/**
 * Content endpoints of the REST API.
 *
 * Reads follow the public site rules (anonymous callers see published entries only, a
 * draft the caller may not preview is a 404). Writes go through EntryService, so
 * permissions, statuses, locks and sanitising are exactly those of the administration.
 */
final class ContentController extends ApiController
{
    /**
     * Members a client may send on create/update; anything else is ignored.
     */
    private const WRITABLE = [
        'title', 'slug', 'status', 'excerpt', 'content', 'published_at', 'parent_id', 'menu_order',
        'template', 'terms', 'new_terms', 'featured_media_id', 'fields', 'seo', 'author_id',
    ];

    private const ORDERABLE = ['published_at', 'updated_at', 'created_at', 'title', 'menu_order'];

    public function __construct(
        private readonly ContentTypeRegistry $types,
        private readonly EntryQuery $query,
        private readonly EntryRepository $entries,
        private readonly EntryService $service,
        private readonly EntryResource $resource,
        private readonly UrlGenerator $urls,
        private readonly Settings $settings,
    ) {
    }

    // --- Reading ------------------------------------------------------------------------

    public function index(Request $request): Response
    {
        $type = (string) $request->query('type', '');
        if ($type !== '' && !$this->types->has($type)) {
            return $this->failure($request, 'not_found', 404);
        }

        return $this->listing($request, $type !== '' ? $type : null);
    }

    public function byType(Request $request, string $type): Response
    {
        if (!$this->types->has($type)) {
            return $this->failure($request, 'not_found', 404);
        }

        return $this->listing($request, $type);
    }

    public function show(Request $request, string $type, string $ref): Response
    {
        if (!$this->types->has($type)) {
            return $this->failure($request, 'not_found', 404);
        }

        $user = $this->currentUser($request);
        if ($user === null && !$this->settings->getBool('api.public_read', true)) {
            return $this->failure($request, 'unauthenticated', 401);
        }

        $entry = ctype_digit($ref)
            ? $this->entries->find((int) $ref)
            : $this->entries->findBySlug($type, $ref);

        if ($entry === null || $entry->type !== $type || !$this->isReadable($entry, $user)) {
            return $this->failure($request, 'not_found', 404);
        }

        return $this->entryResponse($request, $entry, $user);
    }

    // --- Writing ------------------------------------------------------------------------

    public function store(Request $request, string $type): Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return $this->failure($request, 'unauthenticated', 401);
        }

        if (!$this->types->has($type)) {
            return $this->failure($request, 'not_found', 404);
        }

        try {
            $entry = $this->service->create($type, $this->input($request), $user);
        } catch (ContentException $exception) {
            return $this->fromException($request, $exception);
        }

        $response = $this->entryResponse($request, $entry, $user)->withStatus(201);

        return $response->withHeader('Location', $this->urls->absolute('/api/v1/content/' . rawurlencode($type) . '/' . $entry->id));
    }

    public function update(Request $request, string $type, string $id): Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return $this->failure($request, 'unauthenticated', 401);
        }

        $entry = $this->editable($type, (int) $id, $user);
        if ($entry === null) {
            return $this->failure($request, 'not_found', 404);
        }

        $ifMatch = $request->header('if-match');
        if ($ifMatch !== null && trim($ifMatch) !== '*' && !in_array(EntryResource::etag($entry), array_map('trim', explode(',', $ifMatch)), true)) {
            return $this->failure($request, 'precondition_failed', 412, ['ETag' => EntryResource::etag($entry)]);
        }

        try {
            $updated = $this->service->update($entry->id, $this->input($request), $user);
        } catch (ContentException $exception) {
            return $this->fromException($request, $exception);
        }

        return $this->entryResponse($request, $updated, $user);
    }

    public function destroy(Request $request, string $type, string $id): Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return $this->failure($request, 'unauthenticated', 401);
        }

        $entry = $this->editable($type, (int) $id, $user);
        if ($entry === null) {
            return $this->failure($request, 'not_found', 404);
        }

        try {
            if ($this->truthy($request->query('force'))) {
                $this->service->delete($entry->id, $user);

                return $this->noContent();
            }

            $trashed = $entry->status === Entry::STATUS_TRASH ? $entry : $this->service->trash($entry->id, $user);
        } catch (ContentException $exception) {
            return $this->fromException($request, $exception);
        }

        return $this->entryResponse($request, $trashed, $user);
    }

    public function restore(Request $request, string $type, string $id): Response
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return $this->failure($request, 'unauthenticated', 401);
        }

        $entry = $this->editable($type, (int) $id, $user);
        if ($entry === null) {
            return $this->failure($request, 'not_found', 404);
        }

        try {
            $restored = $this->service->restore($entry->id, $user);
        } catch (ContentException $exception) {
            return $this->fromException($request, $exception);
        }

        return $this->entryResponse($request, $restored, $user);
    }

    // --- Internals ----------------------------------------------------------------------

    private function listing(Request $request, ?string $type): Response
    {
        $user = $this->currentUser($request);
        if ($user === null && !$this->settings->getBool('api.public_read', true)) {
            return $this->failure($request, 'unauthenticated', 401);
        }

        $status = (string) $request->query('status', Entry::STATUS_PUBLISHED);
        if (!in_array($status, Entry::ALL_STATUSES, true)) {
            return $this->failure($request, 'invalid_status', 400);
        }
        if ($user === null || !$user->can('content.edit_any')) {
            $status = Entry::STATUS_PUBLISHED;
        }

        $orderBy = (string) $request->query('orderby', 'published_at');
        if (!in_array($orderBy, self::ORDERABLE, true)) {
            return $this->failure($request, 'invalid_request', 400);
        }
        $direction = strtolower((string) $request->query('order', $orderBy === 'title' || $orderBy === 'menu_order' ? 'asc' : 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));

        $query = $this->query->newQuery()->status($status);
        if ($type !== null) {
            $query = $query->type($type);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query = $query->search($search);
        }

        $author = (string) $request->query('author', '');
        if ($author !== '' && ctype_digit($author)) {
            $query = $query->author((int) $author);
        }

        $term = (string) $request->query('term', '');
        if ($term !== '') {
            [$taxonomy, $slug] = array_pad(explode(':', $term, 2), 2, '');
            if ($taxonomy === '' || $slug === '') {
                return $this->failure($request, 'invalid_request', 400);
            }
            $query = $query->whereTerm($taxonomy, $slug);
        }

        $after = (string) $request->query('after', '');
        if ($after !== '') {
            $query = $query->after($after);
        }

        $before = (string) $request->query('before', '');
        if ($before !== '') {
            $query = $query->before($before);
        }

        if ($user !== null) {
            $query = $query->includePrivateFor($user);
        }

        try {
            $total = $query->count();
            $entries = $query->orderBy($orderBy, $direction)->limit($perPage)->offset(($page - 1) * $perPage)->get();
        } catch (InvalidArgumentException) {
            return $this->failure($request, 'invalid_request', 400);
        }

        $fields = $this->fields($request);
        $data = [];
        foreach ($entries as $entry) {
            if ($this->isReadable($entry, $user)) {
                $data[] = $this->resource->toArray($entry, $user, $fields);
            }
        }

        $lastPage = max(1, (int) ceil($total / $perPage));
        $response = $this->json([
            'data' => $data,
            'meta' => ['type' => $type, 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $lastPage],
            'links' => $this->paginationLinks($request, $page, $lastPage),
        ], 200, ['X-Total-Count' => (string) $total]);

        return $this->withCache($request, $response, $user, 'W/"' . sha1($response->body()) . '"');
    }

    private function entryResponse(Request $request, Entry $entry, ?User $user): Response
    {
        $response = $this->json(['data' => $this->resource->toArray($entry, $user, $this->fields($request))]);

        return $this->withCache($request, $response, $user, EntryResource::etag($entry));
    }

    /**
     * Authenticated answers are never stored by a shared cache; every read carries an ETag
     * and a GET whose `If-None-Match` matches answers 304 without a body.
     */
    private function withCache(Request $request, Response $response, ?User $user, string $etag): Response
    {
        $response = $response
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', $user !== null ? 'private, no-store' : 'public, max-age=0, must-revalidate');

        if (strtoupper($request->method()) === 'GET' && $response->status() === 200) {
            $candidates = array_map('trim', explode(',', (string) $request->header('if-none-match', '')));
            if (in_array($etag, $candidates, true)) {
                return Response::noContent([
                    'ETag' => $etag,
                    'Cache-Control' => (string) $response->header('Cache-Control'),
                ])->withStatus(304);
            }
        }

        return $response;
    }

    /**
     * Entry of the given type the user may preview; null for anything else, so that a
     * draft belonging to someone else is indistinguishable from a missing entry.
     */
    private function editable(string $type, int $id, User $user): ?Entry
    {
        if (!$this->types->has($type)) {
            return null;
        }

        $entry = $this->entries->find($id);
        if ($entry === null || $entry->type !== $type) {
            return null;
        }

        $definition = $this->types->get($type);
        if (!$definition instanceof ContentType) {
            return null;
        }

        if ($entry->status === Entry::STATUS_TRASH) {
            return $this->service->canEdit($user, $entry, $definition) ? $entry : null;
        }

        return $this->service->canPreview($user, $entry, $definition) ? $entry : null;
    }

    private function isReadable(Entry $entry, ?User $user): bool
    {
        if ($entry->status === Entry::STATUS_PUBLISHED) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        $type = $this->types->get($entry->type);

        return $type !== null && $this->service->canPreview($user, $entry, $type);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        $input = [];
        foreach ($this->body($request) as $key => $value) {
            if (is_string($key) && in_array($key, self::WRITABLE, true)) {
                $input[$key] = $value;
            }
        }

        // The editor document may be sent as a JSON object; the service expects the same
        // shape the admin form posts (a JSON string or an array), both are accepted.
        return $input;
    }

    /**
     * @return list<string>|null
     */
    private function fields(Request $request): ?array
    {
        $raw = trim((string) $request->query('fields', ''));
        if ($raw === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $f): bool => $f !== ''));
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower((string) (is_scalar($value) ? $value : '')), ['1', 'true', 'yes'], true);
    }

    private function fromException(Request $request, ContentException $exception): Response
    {
        return match (true) {
            $exception instanceof ContentValidationException => $this->validationFailed($request, $exception->errors()),
            $exception instanceof ContentForbiddenException => $this->failure($request, 'forbidden', 403),
            $exception instanceof ContentLockedException, $exception instanceof ContentConflictException => $this->failure($request, 'conflict', 409),
            default => $this->failure($request, 'not_found', 404),
        };
    }

    /**
     * @return array<string, string|null>
     */
    private function paginationLinks(Request $request, int $page, int $lastPage): array
    {
        $path = $request->path();
        $query = $request->query();
        $url = function (int $target) use ($path, $query): string {
            $query['page'] = $target;

            return $this->urls->absolute($path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        };

        return [
            'self' => $url($page),
            'first' => $url(1),
            'prev' => $page > 1 ? $url($page - 1) : null,
            'next' => $page < $lastPage ? $url($page + 1) : null,
            'last' => $url($lastPage),
        ];
    }
}
