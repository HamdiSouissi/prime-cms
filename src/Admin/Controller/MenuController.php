<?php

declare(strict_types=1);

namespace Prime\Admin\Controller;

use InvalidArgumentException;
use Prime\Admin\AdminContext;
use Prime\Admin\TranslatesAdminCopy;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\EntryQuery;
use Prime\Content\TermRepository;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Site\Menu\MenuRepository;
use Prime\Themes\ThemeManager;
use Prime\View\View;

/**
 * Admin controller for « Apparence › Menus » (`themes.manage`).
 */
final class MenuController
{
    use TranslatesAdminCopy;

    public function __construct(
        private readonly View $view,
        private readonly AdminContext $context,
        private readonly MenuRepository $menus,
        private readonly ThemeManager $themes,
        private readonly EntryQuery $query,
        private readonly TermRepository $terms,
        private readonly ContentTypeRegistry $types,
    ) {
    }

    /**
     * Render the visual Menus editor (`GET /admin/appearance/menus`).
     */
    public function index(Request $request): Response
    {
        $allMenus = $this->menus->all();
        $requestedId = $request->query('menu');
        $currentMenu = null;

        if (is_numeric($requestedId) && (int) $requestedId > 0) {
            $currentMenu = $this->menus->find((int) $requestedId);
        }
        if ($currentMenu === null && $allMenus !== []) {
            $currentMenu = $this->menus->find($allMenus[0]['id']);
        }

        $itemsTree = $currentMenu !== null
            ? $this->menus->itemsTree($currentMenu['id'], forPublic: false)
            : [];

        $pages = $this->query->newQuery()->type('page')->orderBy('title', 'ASC')->limit(50)->get();
        $posts = $this->query->newQuery()->type('post')->orderBy('published_at', 'DESC')->limit(30)->get();
        $categories = $this->terms->allForTaxonomy('category');
        $tags = $this->terms->allForTaxonomy('tag');

        $archives = [
            ['type' => 'post', 'label' => $this->t('admin.ctrl_text_menu.blog_tous_les_articles')],
        ];
        foreach ($this->types->all() as $ct) {
            if ($ct->public && !in_array($ct->name, ['page', 'post'], true)) {
                $archives[] = ['type' => $ct->name, 'label' => $ct->label('plural')];
            }
        }

        $locations = $this->themes->menuLocations();

        $html = $this->view->render('admin::appearance/menus', $this->context->build($request, t('admin.nav.menus'), [
            'allMenus' => $allMenus,
            'currentMenu' => $currentMenu,
            'itemsTree' => $itemsTree,
            'itemsJson' => json_encode($itemsTree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            'locations' => $locations,
            'pages' => $pages,
            'posts' => $posts,
            'categories' => $categories,
            'tags' => $tags,
            'archives' => $archives,
            'primaryCtaInContent' => $currentMenu !== null,
            'breadcrumbs' => [
                ['label' => $this->t('admin.ctrl_text_menu.tableau_de_bord'), 'url' => '/admin'],
                ['label' => t('admin.nav.appearance'), 'url' => '/admin/appearance/themes'],
                ['label' => t('admin.nav.menus'), 'url' => null],
            ],
        ]));

        return Response::html($html);
    }

    /**
     * Create a new menu (`POST /admin/appearance/menus/create`).
     */
    public function create(Request $request): Response
    {
        $name = is_string($request->input('name')) ? trim((string) $request->input('name')) : '';
        $location = is_string($request->input('location')) && trim((string) $request->input('location')) !== ''
            ? trim((string) $request->input('location'))
            : null;

        try {
            $id = $this->menus->create($name, $location);
            $this->context->flashToast(sprintf($this->t('admin.ctrl_text_menu.le_menu_s_a_ete_cree'), $name), 'success');

            return Response::redirect('/admin/appearance/menus?menu=' . $id);
        } catch (InvalidArgumentException $e) {
            $this->context->flashToast($e->getMessage(), 'danger');

            return Response::redirect('/admin/appearance/menus');
        }
    }

    /**
     * Save menu metadata, location, and hierarchical items tree (`POST /admin/appearance/menus/{id}/save`).
     */
    public function save(Request $request, string $id): Response
    {
        $menuId = (int) $id;
        $menu = $this->menus->find($menuId);
        if ($menu === null) {
            if ($request->wantsJson()) {
                return Response::json(['ok' => false, 'message' => 'Menu introuvable.'], 404);
            }
            $this->context->flashToast('Menu introuvable.', 'danger');

            return Response::redirect('/admin/appearance/menus');
        }

        $body = $request->body();
        $payload = is_array($body) ? $body : [];
        if ($payload === [] && $request->rawBody() !== '') {
            $dec = json_decode($request->rawBody(), true);
            if (is_array($dec)) {
                $payload = $dec;
            }
        }

        $name = is_string($payload['name'] ?? null) ? trim($payload['name']) : $menu['name'];
        $location = is_string($payload['location'] ?? null) && trim($payload['location']) !== ''
            ? trim($payload['location'])
            : null;

        $rawItems = $payload['items'] ?? ($payload['items_json'] ?? '[]');
        if (is_string($rawItems)) {
            $decoded = json_decode($rawItems, true);
            $items = is_array($decoded) ? array_values($decoded) : [];
        } elseif (is_array($rawItems)) {
            $items = array_values($rawItems);
        } else {
            $items = [];
        }

        try {
            $this->menus->update($menuId, $name, $location);
            /** @var list<array<string, mixed>> $items */
            $this->menus->replaceItems($menuId, $items);
        } catch (InvalidArgumentException $e) {
            if ($request->wantsJson()) {
                return Response::json(['ok' => false, 'message' => $e->getMessage()], 422);
            }
            $this->context->flashToast($e->getMessage(), 'danger');

            return Response::redirect('/admin/appearance/menus?menu=' . $menuId);
        }

        if ($request->wantsJson()) {
            return Response::json([
                'ok' => true,
                'message' => $this->t('admin.ctrl_text_menu.menu_enregistre_avec_succes'),
                'items' => $this->menus->itemsTree($menuId, forPublic: false),
            ]);
        }

        $this->context->flashToast($this->t('admin.ctrl_text_menu.le_menu_a_ete_enregistre'), 'success');

        return Response::redirect('/admin/appearance/menus?menu=' . $menuId);
    }

    /**
     * Delete a menu (`POST /admin/appearance/menus/{id}/delete`).
     */
    public function delete(Request $request, string $id): Response
    {
        $menuId = (int) $id;
        $menu = $this->menus->find($menuId);
        if ($menu !== null) {
            $this->menus->delete($menuId);
            $this->context->flashToast(sprintf($this->t('admin.ctrl_text_menu.le_menu_s_a_ete_supprime'), $menu['name']), 'success');
        }

        return Response::redirect('/admin/appearance/menus');
    }
}
