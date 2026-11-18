<?php

declare(strict_types=1);

namespace Prime\Api;

use Prime\Api\Controller\ApiIndexController;
use Prime\Api\Controller\ContentController;
use Prime\Api\Controller\MeController;
use Prime\Api\Controller\SiteController;
use Prime\Api\Controller\TermsController;
use Prime\Api\Controller\TypesController;

/**
 * Declarations of the core `/api/v1` routes.
 *
 * Order matters for matching: the router keeps the first route whose pattern fits, so
 * literal segments are declared before parameterised ones.
 */
final class CoreApiRoutes
{
    /**
     * @return list<ApiRoute>
     */
    public static function all(): array
    {
        $type = ['type' => ['in' => 'path', 'type' => 'string', 'description' => 'Content type name, e.g. post or page', 'required' => true]];
        $id = ['id' => ['in' => 'path', 'type' => 'integer', 'description' => 'Numeric identifier', 'required' => true]];
        $taxonomy = ['taxonomy' => ['in' => 'path', 'type' => 'string', 'description' => 'Taxonomy name, e.g. category', 'required' => true]];
        $listing = [
            'page' => ['in' => 'query', 'type' => 'integer', 'description' => 'Page number, from 1'],
            'per_page' => ['in' => 'query', 'type' => 'integer', 'description' => 'Items per page, 1 to 100 (default 20)'],
            'status' => ['in' => 'query', 'type' => 'string', 'description' => 'Status; other than published requires content.edit_any'],
            'search' => ['in' => 'query', 'type' => 'string', 'description' => 'Full-text search'],
            'author' => ['in' => 'query', 'type' => 'integer', 'description' => 'Author identifier'],
            'term' => ['in' => 'query', 'type' => 'string', 'description' => 'taxonomy:slug'],
            'after' => ['in' => 'query', 'type' => 'string', 'description' => 'Published after this UTC date-time'],
            'before' => ['in' => 'query', 'type' => 'string', 'description' => 'Published before this UTC date-time'],
            'orderby' => ['in' => 'query', 'type' => 'string', 'description' => 'published_at, updated_at, created_at, title or menu_order'],
            'order' => ['in' => 'query', 'type' => 'string', 'description' => 'asc or desc'],
            'fields' => ['in' => 'query', 'type' => 'string', 'description' => 'Comma-separated members to return'],
        ];
        $entryInput = ['$ref' => '#/components/schemas/EntryInput'];
        $termInput = ['$ref' => '#/components/schemas/TermInput'];

        return [
            new ApiRoute('GET', '/', [ApiIndexController::class, '__invoke'], public: true, summary: 'API entry point', tag: 'core', responseSchema: ['$ref' => '#/components/schemas/Index'], name: 'api.index'),
            new ApiRoute('GET', '/site', [SiteController::class, 'show'], public: true, summary: 'Site identity', tag: 'site', responseSchema: ['$ref' => '#/components/schemas/Site']),
            new ApiRoute('GET', '/types', [TypesController::class, 'index'], public: true, summary: 'Content types visible to the caller', tag: 'site'),
            new ApiRoute('GET', '/me', [MeController::class, 'show'], permission: ApiRoute::AUTHENTICATED, summary: 'Current account and token abilities', tag: 'me', errors: [401]),
            new ApiRoute('PATCH', '/me', [MeController::class, 'update'], permission: ApiRoute::AUTHENTICATED, summary: 'Change display name or language', tag: 'me', requestSchema: ['$ref' => '#/components/schemas/MeInput'], errors: [401, 422], body: ApiRoute::BODY_JSON),

            new ApiRoute('GET', '/content', [ContentController::class, 'index'], public: true, summary: 'Entries of every type', tag: 'content', parameters: $listing + ['type' => ['in' => 'query', 'type' => 'string', 'description' => 'Restrict to one content type']], responseSchema: ['$ref' => '#/components/schemas/EntryList'], errors: [400]),
            new ApiRoute('GET', '/content/{type}', [ContentController::class, 'byType'], public: true, summary: 'Entries of one type', tag: 'content', parameters: $type + $listing, responseSchema: ['$ref' => '#/components/schemas/EntryList'], errors: [400, 404]),
            new ApiRoute('POST', '/content/{type}', [ContentController::class, 'store'], permission: 'content.create', summary: 'Create an entry', tag: 'content', parameters: $type, requestSchema: $entryInput, responseSchema: ['$ref' => '#/components/schemas/Entry'], errors: [401, 403, 404, 413, 415, 422], body: ApiRoute::BODY_JSON),
            new ApiRoute('POST', '/content/{type}/{id:\d+}/restore', [ContentController::class, 'restore'], permission: 'content.delete_own', summary: 'Restore an entry from the trash', tag: 'content', parameters: $type + $id, responseSchema: ['$ref' => '#/components/schemas/Entry'], errors: [401, 403, 404, 409], body: ApiRoute::BODY_JSON),
            new ApiRoute('GET', '/content/{type}/{ref}', [ContentController::class, 'show'], public: true, summary: 'One entry by identifier or slug', tag: 'content', parameters: $type + ['ref' => ['in' => 'path', 'type' => 'string', 'description' => 'Identifier or slug', 'required' => true]], responseSchema: ['$ref' => '#/components/schemas/Entry'], errors: [304, 404]),
            new ApiRoute('PATCH', '/content/{type}/{id:\d+}', [ContentController::class, 'update'], permission: 'content.edit_own', summary: 'Update an entry (If-Match supported)', tag: 'content', parameters: $type + $id + ['If-Match' => ['in' => 'header', 'type' => 'string', 'description' => 'ETag previously read']], requestSchema: $entryInput, responseSchema: ['$ref' => '#/components/schemas/Entry'], errors: [401, 403, 404, 409, 412, 413, 415, 422], body: ApiRoute::BODY_JSON),
            new ApiRoute('DELETE', '/content/{type}/{id:\d+}', [ContentController::class, 'destroy'], permission: 'content.delete_own', summary: 'Move to the trash, or delete for good with force=1', tag: 'content', parameters: $type + $id + ['force' => ['in' => 'query', 'type' => 'boolean', 'description' => 'Delete a trashed entry for good']], responseSchema: ['$ref' => '#/components/schemas/Entry'], errors: [401, 403, 404, 409]),

            new ApiRoute('GET', '/terms', [TermsController::class, 'taxonomies'], public: true, summary: 'Taxonomies visible to the caller', tag: 'terms'),
            new ApiRoute('GET', '/terms/{taxonomy}', [TermsController::class, 'index'], public: true, summary: 'Terms of a taxonomy', tag: 'terms', parameters: $taxonomy + ['parent' => ['in' => 'query', 'type' => 'integer', 'description' => 'Parent term identifier'], 'search' => ['in' => 'query', 'type' => 'string', 'description' => 'Name search']], responseSchema: ['$ref' => '#/components/schemas/TermList'], errors: [404]),
            new ApiRoute('POST', '/terms/{taxonomy}', [TermsController::class, 'store'], permission: 'content.edit_own', summary: 'Create a term (taxonomy capability required)', tag: 'terms', parameters: $taxonomy, requestSchema: $termInput, responseSchema: ['$ref' => '#/components/schemas/Term'], errors: [401, 403, 404, 422], body: ApiRoute::BODY_JSON),
            new ApiRoute('GET', '/terms/{taxonomy}/{ref}', [TermsController::class, 'show'], public: true, summary: 'One term by identifier or slug', tag: 'terms', parameters: $taxonomy + ['ref' => ['in' => 'path', 'type' => 'string', 'description' => 'Identifier or slug', 'required' => true]], responseSchema: ['$ref' => '#/components/schemas/Term'], errors: [404]),
            new ApiRoute('PATCH', '/terms/{taxonomy}/{id:\d+}', [TermsController::class, 'update'], permission: 'content.edit_own', summary: 'Update a term', tag: 'terms', parameters: $taxonomy + $id, requestSchema: $termInput, responseSchema: ['$ref' => '#/components/schemas/Term'], errors: [401, 403, 404, 422], body: ApiRoute::BODY_JSON),
            new ApiRoute('DELETE', '/terms/{taxonomy}/{id:\d+}', [TermsController::class, 'destroy'], permission: 'content.edit_own', summary: 'Delete a term', tag: 'terms', parameters: $taxonomy + $id, errors: [401, 403, 404, 409]),
        ];
    }
}
