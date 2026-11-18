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
        $data = [];
        foreach ($this->types->all() as $name => $type) {
            $data[] = [
                'name' => $type->name,
                'label' => [
                    'singular' => $type->labels['singular'] ?? ucfirst($type->name),
                    'plural' => $type->labels['plural'] ?? ucfirst($type->name) . 's',
                ],
                'description' => $type->labels['description'] ?? '',
                'icon' => $type->icon,
                'accent' => $type->accent,
                'public' => $type->public,
                'hierarchical' => $type->hierarchical,
                'supports' => $type->supports,
                'taxonomies' => $type->taxonomies,
                'menu_position' => $type->menuPosition,
                'editor' => $type->editor,
            ];
        }

        return $this->json(['data' => $data]);
    }
}
