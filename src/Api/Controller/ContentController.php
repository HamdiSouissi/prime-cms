<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use Prime\Auth\User;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryQuery;
use Prime\Content\EntryRepository;
use Prime\Content\EntryService;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Site\UrlGenerator;

/**
 * `GET /api/v1/content` — Liste des contenus.
 * `GET /api/v1/content/{type}` — Contenus d'un type donné (avec `?status=`, `?page=`, `?per_page=`).
 * `GET /api/v1/content/{type}/{slug}` — Contenu unique par slug.
 */
final class ContentController extends ApiController
{
    public function __construct(
        private readonly ContentTypeRegistry $types,
        private readonly EntryQuery $query,
        private readonly EntryRepository $entries,
        private readonly EntryService $entryService,
        private readonly UrlGenerator $urlGenerator,
    ) {
    }

    public function index(Request $request): Response
    {
        $type = (string) $request->query('type', '');
        $perPage = (int) $request->query('per_page', 20);
        $page = (int) $request->query('page', 1);
        $search = (string) $request->query('search', '');
        $status = (string) $request->query('status', 'publish');
        $user = $this->currentUser($request);
        $locale = $user?->locale ?? 'fr';

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        // Un utilisateur anonyme ne voit jamais que les entrées publiées,
        // quel que soit le filtre `status` passé dans l'URL.
        if ($user === null) {
            $status = 'publish';
        }

        $q = $this->query->newQuery();

        if ($type !== '' && $this->types->has($type)) {
            $q = $q->type($type);
        }

        $q = $q->status($status);

        if ($search !== '') {
            $q = $q->search($search);
        }

        // Un utilisateur authentifié peut prévisualiser ses propres brouillons via EntryService.
        if ($user !== null) {
            $q = $q->includePrivateFor($user);
        }

        // Total sur l'ensemble des entrées correspondantes (avant limit/offset).
        $total = $q->count();

        $q = $q->orderBy('published_at', 'DESC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage);

        $items = $q->get();

        $data = [];
        foreach ($items as $entry) {
            if (!$this->isReadable($entry, $user)) {
                continue;
            }

            $data[] = $this->entryToArray($entry);
        }

        $lastPage = max(1, (int) ceil($total / $perPage));

        return $this->json([
            'data' => $data,
            'meta' => [
                'type' => $type,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $lastPage,
            ],
            'links' => $this->paginationLinks($request, $page, $lastPage),
        ], 200, [
            'X-Total-Count' => (string) $total,
        ]);
    }

    public function byType(Request $request, string $type): Response
    {
        $user = $this->currentUser($request);
        $locale = $user?->locale ?? 'fr';

        if (!$this->types->has($type)) {
            return $this->notFound('unknown_type', ['type' => $type], locale: $locale);
        }

        $perPage = (int) $request->query('per_page', 20);
        $page = (int) $request->query('page', 1);
        $status = (string) $request->query('status', 'publish');

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        // Un utilisateur anonyme ne voit jamais que les entrées publiées,
        // quel que soit le filtre `status` passé dans l'URL.
        if ($user === null) {
            $status = 'publish';
        }

        $q = $this->query->newQuery()
            ->type($type)
            ->status($status);

        if ($user !== null) {
            $q = $q->includePrivateFor($user);
        }

        // Total sur l'ensemble des entrées correspondantes (avant limit/offset).
        $total = $q->count();

        $q = $q->orderBy('published_at', 'DESC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage);

        $rows = $q->get();

        $data = [];
        foreach ($rows as $entry) {
            if (!$this->isReadable($entry, $user)) {
                continue;
            }

            $data[] = $this->entryToArray($entry);
        }

        $lastPage = max(1, (int) ceil($total / $perPage));

        return $this->json([
            'data' => $data,
            'meta' => [
                'type' => $type,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $lastPage,
            ],
            'links' => $this->paginationLinks($request, $page, $lastPage),
        ], 200, [
            'X-Total-Count' => (string) $total,
        ]);
    }

    public function show(Request $request, string $type, string $slug): Response
    {
        if (!$this->types->has($type)) {
            return $this->notFound('unknown_type', ['type' => $type]);
        }

        $entry = $this->entries->findBySlug($type, $slug);
        if ($entry === null) {
            return $this->notFound('not_found');
        }

        $user = $this->currentUser($request);
        if (!$this->isReadable($entry, $user)) {
            return $this->notFound('not_found');
        }

        return $this->json([
            'data' => $this->entryToArray($entry),
        ]);
    }

    /**
     * Détermine si un utilisateur peut lire une entrée.
     *
     * - Anonyme : uniquement `publish`.
     * - Authentifié : les règles `EntryService::canPreview` / `canEdit` s'appliquent.
     */
    private function isReadable(Entry $entry, ?User $user): bool
    {
        if ($entry->status === 'publish') {
            return true;
        }

        if ($user === null) {
            return false;
        }

        $type = $this->types->get($entry->type);
        if ($type === null) {
            return false;
        }

        return $this->entryService->canPreview($user, $entry, $type);
    }

    /**
     * @param Entry $entry
     * @return array<string, mixed>
     */
    private function entryToArray(Entry $entry): array
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

        return [
            'id' => $entry->id,
            'type' => $entry->type,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'status' => $entry->status,
            'content' => $entry->content,
            'excerpt' => $entry->excerpt,
            'author_id' => $entry->authorId,
            'author_name' => $entry->authorName,
            'published_at' => $entry->publishedAt,
            'created_at' => $entry->createdAt,
            'updated_at' => $entry->updatedAt,
            'featured_image_id' => (int) ($entry->meta('_featured_media_id') ?? 0),
            'terms' => $terms,
            'url' => $this->urlGenerator->entryUrl($entry),
        ];
    }

    /**
     * @param int $page
     * @param int $lastPage
     * @return array<string, array{href: string|null, rel: string}>
     */
    private function paginationLinks(Request $request, int $page, int $lastPage): array
    {
        $base = $request->uri();
        $query = $request->query();

        $setPage = function (array $q, int $p): array {
            $q['page'] = $p;
            return $q;
        };

        $queryString = function (array $q): string {
            $pairs = [];
            foreach ($q as $k => $v) {
                if (is_array($v)) {
                    foreach ($v as $vv) {
                        $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $vv);
                    }
                } else {
                    $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
                }
            }

            return $pairs !== [] ? '?' . implode('&', $pairs) : '';
        };

        $first = $page > 1 ? $base . $queryString($setPage($query, 1)) : null;
        $prev = $page > 1 ? $base . $queryString($setPage($query, $page - 1)) : null;
        $next = $page < $lastPage ? $base . $queryString($setPage($query, $page + 1)) : null;
        $last = $lastPage > 1 && $page < $lastPage ? $base . $queryString($setPage($query, $lastPage)) : null;

        return [
            'first' => ['href' => $first, 'rel' => 'first'],
            'prev' => ['href' => $prev, 'rel' => 'prev'],
            'next' => ['href' => $next, 'rel' => 'next'],
            'last' => ['href' => $last, 'rel' => 'last'],
        ];
    }
}
