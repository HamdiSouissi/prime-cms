<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use Prime\Api\Resource\UserResource;
use Prime\Auth\User;
use Prime\Auth\UserRepository;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * Read-only accounts endpoint (`users.view`). Never exposes a hash, a 2FA secret or a token.
 */
final class UsersController extends ApiController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserResource $resource,
    ) {
    }

    public function index(Request $request): Response
    {
        $sort = (string) $request->query('orderby', 'created_at');
        $result = $this->users->paginate(
            page: max(1, (int) $request->query('page', 1)),
            perPage: max(1, min(100, (int) $request->query('per_page', 20))),
            search: trim((string) $request->query('search', '')),
            roleSlug: trim((string) $request->query('role', '')),
            status: trim((string) $request->query('status', '')),
            sort: $sort,
            direction: (string) $request->query('order', 'desc'),
        );

        $viewer = $this->currentUser($request);

        return $this->json([
            'data' => array_map(fn (User $user): array => $this->resource->toArray($user, $viewer), $result['items']),
            'meta' => [
                'page' => $result['page'],
                'per_page' => $result['perPage'],
                'total' => $result['total'],
                'total_pages' => $result['totalPages'],
            ],
        ], 200, ['X-Total-Count' => (string) $result['total']]);
    }

    public function show(Request $request, string $id): Response
    {
        $user = $this->users->find((int) $id);

        return $user === null
            ? $this->failure($request, 'not_found', 404)
            : $this->json(['data' => $this->resource->toArray($user, $this->currentUser($request))]);
    }
}
