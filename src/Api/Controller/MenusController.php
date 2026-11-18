<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Site\Menu\MenuRepository;
use Prime\Site\UrlGenerator;

/**
 * Menu assigned to a theme location, as a tree of resolved links. Items pointing to an
 * unpublished entry or a deleted term are left out, exactly like on the public site.
 */
final class MenusController extends ApiController
{
    public function __construct(
        private readonly MenuRepository $menus,
        private readonly UrlGenerator $urls,
    ) {
    }

    public function show(Request $request, string $location): Response
    {
        $menu = $this->menus->findByLocation($location);
        if ($menu === null) {
            return $this->failure($request, 'not_found', 404);
        }

        return $this->json(['data' => [
            'id' => $menu['id'],
            'name' => $menu['name'],
            'location' => $menu['location'],
            'items' => $this->items($this->menus->itemsTree($menu['id'], forPublic: true)),
        ]], 200, ['Cache-Control' => 'public, max-age=60']);
    }

    /**
     * @param array<mixed> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $nodes): array
    {
        $items = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $url = is_string($node['url'] ?? null) ? $node['url'] : '';
            $items[] = [
                'id' => $node['id'] ?? null,
                'label' => $node['label'] ?? '',
                'type' => $node['type'] ?? 'url',
                'url' => str_starts_with($url, '/') ? $this->urls->absolute($url) : $url,
                'target' => $node['target'] ?? '_self',
                'css_class' => $node['css_class'] ?? '',
                'children' => $this->items(is_array($node['children'] ?? null) ? $node['children'] : []),
            ];
        }

        return $items;
    }
}
