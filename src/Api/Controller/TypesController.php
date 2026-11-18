<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use Prime\Content\ContentTypeRegistry;
use Prime\Http\Request;
use Prime\Http\Response;

/**
 * `GET /api/v1/types` — Liste tous les types de contenu enregistrés.
 */
final class TypesController extends ApiController
{
    public function __construct(
        private readonly ContentTypeRegistry $types,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->currentUser($request);
        $data = [];
        foreach ($this->types->all() as $type) {
            // Private types (internal stores, plugin data) are only described to editors.
            if (!$type->public && ($user === null || !$user->can('content.view'))) {
                continue;
            }

            $data[] = [
                'name' => $type->name,
                'label' => [
                    'singular' => $type->label('singular'),
                    'plural' => $type->label('plural'),
                ],
                'description' => isset($type->labels['description']) ? $type->label('description') : '',
                'icon' => $type->icon,
                'accent' => $type->accent,
                'public' => $type->public,
                'hierarchical' => $type->hierarchical,
                'supports' => $type->supports,
                'taxonomies' => $type->taxonomies,
                'menu_position' => $type->menuPosition,
                'editor' => $type->editor,
                '_links' => ['collection' => '/api/v1/content/' . rawurlencode($type->name)],
            ];
        }

        return $this->json(['data' => $data]);
    }
}
