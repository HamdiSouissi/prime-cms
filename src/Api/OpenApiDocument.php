<?php

declare(strict_types=1);

namespace Prime\Api;

use Prime\Auth\User;
use Prime\Kernel\Application;
use Prime\Site\UrlGenerator;

/**
 * Builds the OpenAPI 3.1 description of the REST API from the declared ApiRoutes, core and
 * plugins alike. Only the operations the caller may use are listed: an anonymous caller sees
 * the public surface, a token sees what its abilities allow.
 */
final class OpenApiDocument
{
    private const STATUS_TEXT = [
        304 => 'Not modified (If-None-Match matched)',
        400 => 'Invalid request',
        401 => 'Authentication required',
        403 => 'Missing capability',
        404 => 'Not found',
        409 => 'Conflict: locked, in use or in a state that forbids the operation',
        412 => 'Precondition failed: the resource changed since it was read (If-Match)',
        413 => 'Request body too large',
        415 => 'Unsupported media type',
        422 => 'Validation failed',
        429 => 'Too many requests',
    ];

    public function __construct(
        private readonly ApiRouteRegistry $registry,
        private readonly UrlGenerator $urls,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?User $caller): array
    {
        $paths = [];
        $tags = [];

        foreach ($this->registry->all() as ['prefix' => $prefix, 'route' => $route]) {
            if (!$this->visible($route, $caller)) {
                continue;
            }

            $path = $route->documentedPath() === '/' ? $prefix : $prefix . $route->documentedPath();
            $paths[$path][strtolower($route->method)] = $this->operation($route);
            $tags[$route->tag] = true;
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Prime CMS REST API',
                'version' => Application::VERSION,
                'description' => 'Authenticate with `Authorization: Bearer <token>` (create one in Profile › API tokens). Errors share one shape: `{"error": {"code", "message", "status"}}`.',
            ],
            'servers' => [['url' => rtrim($this->urls->absolute('/'), '/')]],
            'tags' => array_map(static fn (string $tag): array => ['name' => $tag], array_keys($tags)),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'pcat_<prefix>_<secret>'],
                ],
                'schemas' => $this->registry->schemas() + self::coreSchemas(),
                'responses' => [
                    'Error' => [
                        'description' => 'Error',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
                    ],
                ],
            ],
        ];
    }

    private function visible(ApiRoute $route, ?User $caller): bool
    {
        if ($route->public) {
            return true;
        }

        if ($caller === null) {
            return false;
        }

        return $route->permission === ApiRoute::AUTHENTICATED || $caller->can((string) $route->permission);
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(ApiRoute $route): array
    {
        $operation = [
            'summary' => $route->summary !== '' ? $route->summary : $route->method . ' ' . $route->documentedPath(),
            'tags' => [$route->tag],
            'security' => $route->public ? [] : [['bearerAuth' => []]],
        ];

        if ($route->name !== null) {
            $operation['operationId'] = $route->name;
        }

        if (!$route->public && $route->permission !== ApiRoute::AUTHENTICATED) {
            $operation['x-prime-capability'] = $route->permission;
        }

        $parameters = [];
        $declared = $route->parameters;
        foreach ($route->pathParameters() as $name) {
            $declared[$name] = ($declared[$name] ?? []) + ['in' => 'path', 'type' => 'string', 'required' => true];
        }
        foreach ($declared as $name => $spec) {
            $in = $spec['in'] ?? 'query';
            $parameters[] = [
                'name' => $name,
                'in' => $in,
                'required' => $in === 'path' ? true : (bool) ($spec['required'] ?? false),
                'description' => $spec['description'] ?? '',
                'schema' => ['type' => $spec['type'] ?? 'string'],
            ];
        }
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($route->body !== ApiRoute::BODY_NONE && $route->requestSchema !== []) {
            $mime = $route->body === ApiRoute::BODY_MULTIPART ? 'multipart/form-data' : 'application/json';
            $operation['requestBody'] = ['required' => true, 'content' => [$mime => ['schema' => $route->requestSchema]]];
        }

        $success = match (true) {
            $route->method === 'POST' && !str_ends_with($route->path, '/restore') => '201',
            $route->method === 'DELETE' && $route->responseSchema === [] => '204',
            default => '200',
        };

        $operation['responses'][$success] = $success === '204'
            ? ['description' => 'Done']
            : ['description' => 'Success', 'content' => ['application/json' => ['schema' => $route->responseSchema !== [] ? $route->responseSchema : ['type' => 'object']]]];

        $errors = array_unique(array_merge($route->errors, [429]));
        sort($errors);
        foreach ($errors as $status) {
            $operation['responses'][(string) $status] = $status === 304
                ? ['description' => self::STATUS_TEXT[304]]
                : ['$ref' => '#/components/responses/Error', 'description' => self::STATUS_TEXT[$status] ?? 'Error'];
        }

        return $operation;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function coreSchemas(): array
    {
        $string = ['type' => 'string'];
        $int = ['type' => 'integer'];
        $nullableString = ['type' => ['string', 'null']];
        $meta = ['type' => 'object', 'properties' => ['page' => $int, 'per_page' => $int, 'total' => $int, 'total_pages' => $int]];
        $links = ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'null']]];

        return [
            'Error' => [
                'type' => 'object',
                'required' => ['error'],
                'properties' => ['error' => [
                    'type' => 'object',
                    'required' => ['code', 'message', 'status'],
                    'properties' => [
                        'code' => $string,
                        'message' => $string,
                        'status' => $int,
                        'fields' => ['type' => 'object', 'additionalProperties' => $string],
                    ],
                ]],
            ],
            'Index' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object', 'properties' => ['api_version' => $string, 'authenticated' => ['type' => 'boolean']]]]],
            'Site' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object', 'properties' => ['name' => $string, 'tagline' => $string, 'url' => $string, 'locale' => $string, 'timezone' => $string]]]],
            'MeInput' => ['type' => 'object', 'properties' => ['display_name' => $string, 'locale' => $string]],
            'EntryData' => [
                'type' => 'object',
                'properties' => [
                    'id' => $int, 'type' => $string, 'status' => $string, 'title' => $string, 'slug' => $string,
                    'excerpt' => $nullableString, 'content' => ['type' => 'object'], 'content_html' => ['type' => 'string', 'readOnly' => true],
                    'author' => ['type' => ['object', 'null']], 'terms' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'published_at' => $nullableString, 'updated_at' => $string, 'url' => $string, '_links' => $links,
                ],
            ],
            'Entry' => ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/EntryData']]],
            'EntryList' => ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/EntryData']], 'meta' => $meta, 'links' => $links]],
            'EntryInput' => [
                'type' => 'object',
                'properties' => [
                    'title' => $string, 'slug' => $string, 'status' => $string, 'excerpt' => $string,
                    'content' => ['type' => 'object', 'description' => 'Prime Editor document: {"blocks": [...]}'],
                    'published_at' => $string, 'parent_id' => $int, 'menu_order' => $int, 'template' => $string,
                    'terms' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => $int]],
                    'featured_media_id' => $int, 'fields' => ['type' => 'object'],
                ],
            ],
            'TermData' => ['type' => 'object', 'properties' => ['id' => $int, 'taxonomy' => $string, 'name' => $string, 'slug' => $string, 'description' => $nullableString, 'parent_id' => $int, 'count' => $int, 'url' => $string, '_links' => $links]],
            'Term' => ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/TermData']]],
            'TermList' => ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/TermData']]]],
            'TermInput' => ['type' => 'object', 'properties' => ['name' => $string, 'slug' => $string, 'description' => $string, 'parent_id' => $int, 'menu_order' => $int]],
            'MediaData' => ['type' => 'object', 'properties' => ['id' => $int, 'kind' => $string, 'mime_type' => $string, 'title' => $string, 'alt' => $string, 'width' => ['type' => ['integer', 'null']], 'height' => ['type' => ['integer', 'null']], 'url' => $string, 'srcset' => $string, '_links' => $links]],
            'Media' => ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/MediaData']]],
            'MediaList' => ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/MediaData']], 'meta' => $meta]],
            'MediaInput' => ['type' => 'object', 'properties' => ['title' => $string, 'alt' => $string, 'caption' => $string, 'description' => $string, 'folder_id' => $int, 'focal_x' => ['type' => 'number'], 'focal_y' => ['type' => 'number']]],
            'MediaUpload' => ['type' => 'object', 'required' => ['file'], 'properties' => ['file' => ['type' => 'string', 'format' => 'binary'], 'title' => $string, 'alt' => $string, 'folder_id' => $int]],
            'UserData' => ['type' => 'object', 'properties' => ['id' => $int, 'username' => $nullableString, 'display_name' => $string, 'email' => $string, 'roles' => ['type' => 'array', 'items' => $string], '_links' => $links]],
            'User' => ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/UserData']]],
            'UserList' => ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/UserData']], 'meta' => $meta]],
        ];
    }
}
