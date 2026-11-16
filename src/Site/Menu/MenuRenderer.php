<?php

declare(strict_types=1);

namespace Prime\Site\Menu;

use Prime\Hooks\Hooks;

/**
 * Renders accessible semantic HTML (`<nav>` / `<ul>` / `<li>` / `<a>`) for a navigation menu
 * with active link detection (`aria-current="page"`), `rel="noopener noreferrer"` on external
 * targets, and the `site.menu_items` filter hook.
 */
final class MenuRenderer
{
    public function __construct(
        private readonly MenuRepository $menus,
        private readonly ?Hooks $hooks = null,
    ) {
    }

    /**
     * Render a menu assigned to `$location` (or by menu ID when `$options['menu_id']` is passed).
     *
     * @param array<string, mixed> $options Supported keys: `class`, `list_class`, `aria_label`, `wrap_nav`, `current_path`, `menu_id`
     */
    public function renderLocation(string $location, array $options = []): string
    {
        $menu = isset($options['menu_id']) && is_numeric($options['menu_id'])
            ? $this->menus->find((int) $options['menu_id'])
            : $this->menus->findByLocation($location);

        if ($menu === null) {
            return '';
        }

        $items = $this->menus->itemsTree($menu['id'], forPublic: true);

        if ($this->hooks !== null) {
            $filtered = $this->hooks->apply('site.menu_items', $items, $location, $menu, $options);
            if (is_array($filtered)) {
                /** @var list<array<string, mixed>> $filtered */
                $items = array_values($filtered);
            }
        }

        if ($items === []) {
            return '';
        }

        $currentPath = is_string($options['current_path'] ?? null)
            ? $this->normalizePath($options['current_path'])
            : '/';
        $navClass = $this->cleanClassList(is_string($options['class'] ?? null) ? $options['class'] : 'site-nav');
        $listClass = $this->cleanClassList(is_string($options['list_class'] ?? null) ? $options['list_class'] : 'site-nav__list');
        $ariaLabel = is_string($options['aria_label'] ?? null) && trim($options['aria_label']) !== ''
            ? trim($options['aria_label'])
            : $menu['name'];
        $wrapNav = ($options['wrap_nav'] ?? true) !== false;

        $listHtml = $this->renderList($items, $currentPath, $listClass, depth: 1);

        if (!$wrapNav) {
            return $listHtml;
        }

        $navAttrs = $navClass !== '' ? ' class="' . $this->e($navClass) . '"' : '';

        return sprintf(
            '<nav%s aria-label="%s">%s</nav>',
            $navAttrs,
            $this->e($ariaLabel),
            $listHtml,
        );
    }

    /**
     * Check whether a menu with at least one publicly available item is assigned to `$location`.
     */
    public function hasLocation(string $location): bool
    {
        $menu = $this->menus->findByLocation($location);
        if ($menu === null) {
            return false;
        }

        return $this->menus->itemsTree($menu['id'], forPublic: true) !== [];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function renderList(array $items, string $currentPath, string $listClass, int $depth): string
    {
        $cls = $depth === 1 ? $listClass : 'site-nav__submenu';
        $classAttr = $cls !== '' ? ' class="' . $this->e($cls) . '"' : '';
        $html = '<ul' . $classAttr . '>';

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = is_string($item['label'] ?? null) ? trim($item['label']) : '';
            $url = is_string($item['url'] ?? null) ? $item['url'] : '';
            $safeUrl = MenuRepository::sanitizeUrl($url);
            if ($label === '' || $safeUrl === null) {
                continue;
            }

            $children = is_array($item['children'] ?? null) ? array_values($item['children']) : [];
            $hasChildren = $children !== [] && $depth < MenuRepository::MAX_DEPTH;
            $isCurrent = $this->isCurrentUrl($safeUrl, $currentPath);

            $liClasses = ['site-nav__item'];
            if ($hasChildren) {
                $liClasses[] = 'site-nav__item--has-children';
            }
            if ($isCurrent) {
                $liClasses[] = 'is-active';
            }
            $extraClass = is_string($item['css_class'] ?? null) ? $this->cleanClassList($item['css_class']) : '';
            if ($extraClass !== '') {
                $liClasses[] = $extraClass;
            }

            $aAttrs = ' href="' . $this->e($safeUrl) . '" class="site-nav__link"';
            if ($isCurrent) {
                $aAttrs .= ' aria-current="page"';
            }
            if (($item['target'] ?? '_self') === '_blank') {
                $aAttrs .= ' target="_blank" rel="noopener noreferrer"';
            }

            $html .= '<li class="' . $this->e(implode(' ', $liClasses)) . '">';
            $html .= '<a' . $aAttrs . '>' . $this->e($label) . '</a>';
            if ($hasChildren) {
                /** @var list<array<string, mixed>> $children */
                $html .= $this->renderList($children, $currentPath, $listClass, $depth + 1);
            }
            $html .= '</li>';
        }

        $html .= '</ul>';

        return $html;
    }

    private function isCurrentUrl(string $url, string $currentPath): bool
    {
        if (!str_starts_with($url, '/')) {
            return false;
        }

        return $this->normalizePath($url) === $currentPath;
    }

    private function normalizePath(string $path): string
    {
        $noQuery = explode('#', explode('?', $path, 2)[0], 2)[0];
        $trimmed = '/' . trim($noQuery, '/');

        return $trimmed === '/' ? '/' : rtrim($trimmed, '/');
    }

    private function cleanClassList(string $raw): string
    {
        $tokens = [];
        foreach (preg_split('/\s+/', trim($raw)) ?: [] as $tok) {
            if (preg_match('/^[a-zA-Z_-][a-zA-Z0-9_-]{0,60}$/', $tok) === 1) {
                $tokens[] = $tok;
            }
        }

        return implode(' ', $tokens);
    }

    private function e(string $val): string
    {
        return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
