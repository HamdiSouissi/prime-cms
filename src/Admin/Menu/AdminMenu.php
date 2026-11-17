<?php

declare(strict_types=1);

namespace Prime\Admin\Menu;

use Prime\Admin\Data\FilterValueHelper;
use Prime\Admin\Exception\InvalidMenuItemException;
use Prime\Admin\TranslatesAdminCopy;
use Prime\Http\Request;

/**
 * Extensible registry for the admin sidebar navigation tree.
 */
final class AdminMenu
{
    use TranslatesAdminCopy;

    /**
     * Top-level items indexed by ID.
     *
     * @var array<string, array{item: MenuItem, order: int}>
     */
    private array $items = [];

    /**
     * Child items grouped by parent ID, then indexed by child ID.
     *
     * @var array<string, array<string, array{item: MenuItem, order: int}>>
     */
    private array $children = [];

    private int $sequence = 0;

    private ?string $activeId = null;

    /**
     * Register or replace a top-level menu item.
     */
    public function add(MenuItem $item): self
    {
        $order = isset($this->items[$item->id]) ? $this->items[$item->id]['order'] : $this->sequence++;
        $this->items[$item->id] = [
            'item' => $item,
            'order' => $order,
        ];

        return $this;
    }

    /**
     * Attach a submenu item under an existing top-level item.
     */
    public function addChild(string $parentId, MenuItem $item): self
    {
        if (!isset($this->items[$parentId])) {
            throw new InvalidMenuItemException(
                sprintf('Cannot add child [%s] to unknown parent menu item [%s].', $item->id, $parentId),
            );
        }

        $order = isset($this->children[$parentId][$item->id])
            ? $this->children[$parentId][$item->id]['order']
            : $this->sequence++;

        $this->children[$parentId][$item->id] = [
            'item' => $item,
            'order' => $order,
        ];

        return $this;
    }

    /**
     * Remove any top-level or submenu entries carrying unsafe URLs or invalid icon names,
     * returning diagnostic messages for each discarded item.
     *
     * @return list<string>
     */
    public function sanitize(): array
    {
        $issues = [];

        foreach ($this->items as $id => $entry) {
            $item = $entry['item'];
            if (!FilterValueHelper::isSafeUrl($item->url) || !FilterValueHelper::isValidIcon($item->icon)) {
                unset($this->items[$id], $this->children[$id]);
                $issues[] = sprintf($this->t('admin.ctrl_menu_adminmenu.entree_de_menu_s_ignoree_url_ou_icone_invalide'), $id);
                continue;
            }

            if (!isset($this->children[$id])) {
                continue;
            }

            foreach ($this->children[$id] as $childId => $childEntry) {
                $child = $childEntry['item'];
                if (!FilterValueHelper::isSafeUrl($child->url) || !FilterValueHelper::isValidIcon($child->icon)) {
                    unset($this->children[$id][$childId]);
                    $issues[] = sprintf($this->t('admin.ctrl_menu_adminmenu.sous_menu_s_ignore_url_ou_icone_invalide'), $childId);
                }
            }
        }

        return $issues;
    }

    /**
     * Remove a top-level item (and its children) or a submenu item by ID.
     */
    public function remove(string $id): self
    {
        if (isset($this->items[$id])) {
            unset($this->items[$id], $this->children[$id]);
        } else {
            foreach ($this->children as $parentId => $entries) {
                if (isset($entries[$id])) {
                    unset($this->children[$parentId][$id]);
                    break;
                }
            }
        }

        if ($this->activeId === $id) {
            $this->activeId = null;
        }

        return $this;
    }

    /**
     * Retrieve a top-level or submenu item by ID.
     */
    public function get(string $id): ?MenuItem
    {
        if (isset($this->items[$id])) {
            return $this->items[$id]['item'];
        }

        foreach ($this->children as $entries) {
            if (isset($entries[$id])) {
                return $entries[$id]['item'];
            }
        }

        return null;
    }

    /**
     * Filter the menu in place according to a capability callback `fn(string $cap): bool`.
     *
     * Top-level items with children remain visible when at least one child is permitted;
     * if the parent's own capability is denied, its URL is transparently pointed to the first visible child.
     *
     * @param callable(string): bool $can
     */
    public function filterByCapability(callable $can): self
    {
        foreach (array_keys($this->items) as $parentId) {
            $parent = $this->items[$parentId]['item'];
            $hasDefinedChildren = isset($this->children[$parentId]) && $this->children[$parentId] !== [];

            if ($hasDefinedChildren) {
                foreach (array_keys($this->children[$parentId]) as $childId) {
                    $child = $this->children[$parentId][$childId]['item'];
                    if ($child->capability !== null && !$can($child->capability)) {
                        unset($this->children[$parentId][$childId]);
                    }
                }
            }

            $parentAllowed = $parent->capability === null || $can($parent->capability);
            $remainingChildren = $this->children[$parentId] ?? [];

            if (!$parentAllowed) {
                if ($remainingChildren !== []) {
                    $sorted = array_values($remainingChildren);
                    usort($sorted, self::compareEntries(...));
                    $this->items[$parentId]['item'] = $parent->withUrl($sorted[0]['item']->url);
                } else {
                    unset($this->items[$parentId], $this->children[$parentId]);
                }
            } elseif ($hasDefinedChildren && $remainingChildren === [] && $parent->capability === null) {
                unset($this->items[$parentId], $this->children[$parentId]);
            }
        }

        return $this;
    }

    /**
     * Detect and mark the active menu item for the given HTTP request.
     * Prefers an exact path match, falling back to the longest matching URL prefix.
     */
    public function setActive(Request $request): self
    {
        $requestPath = $this->normalizePath($request->path());
        $bestId = null;
        $bestScore = -1;

        foreach ($this->allItems() as $item) {
            $itemPath = $this->normalizePath($item->url);
            if ($itemPath === '') {
                continue;
            }

            if ($requestPath === $itemPath) {
                $score = 10_000 + strlen($itemPath);
            } elseif ($itemPath !== '/' && $itemPath !== '/admin' && str_starts_with($requestPath, $itemPath . '/')) {
                $score = strlen($itemPath);
            } else {
                continue;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = $item->id;
            }
        }

        $this->activeId = $bestId;

        return $this;
    }

    /**
     * Return the currently active menu item ID, if any.
     */
    public function activeId(): ?string
    {
        return $this->activeId;
    }

    /**
     * Return the sorted hierarchical navigation tree.
     *
     * @return list<MenuNode>
     */
    public function tree(): array
    {
        $topEntries = array_values($this->items);
        usort($topEntries, self::compareEntries(...));

        $nodes = [];
        foreach ($topEntries as $entry) {
            $item = $entry['item'];
            $childEntries = array_values($this->children[$item->id] ?? []);
            usort($childEntries, self::compareEntries(...));

            $childNodes = [];
            $hasActiveChild = false;

            foreach ($childEntries as $childEntry) {
                $childItem = $childEntry['item'];
                $childActive = $this->activeId === $childItem->id;
                if ($childActive) {
                    $hasActiveChild = true;
                }

                $childNodes[] = new MenuNode(
                    item: $childItem,
                    active: $childActive,
                );
            }

            $nodes[] = new MenuNode(
                item: $item,
                active: $this->activeId === $item->id,
                hasActiveChild: $hasActiveChild,
                children: $childNodes,
            );
        }

        return $nodes;
    }

    /**
     * Group the sorted top-level tree by section label (`null` for ungrouped top items).
     *
     * @return list<array{label: ?string, items: list<MenuNode>}>
     */
    public function groupedTree(): array
    {
        /** @var array<string, array{label: ?string, items: list<MenuNode>}> $byKey */
        $byKey = [];

        foreach ($this->tree() as $node) {
            $label = $node->item->group;
            $key = $label === null ? '__ungrouped__' : $label;

            if (!isset($byKey[$key])) {
                $byKey[$key] = [
                    'label' => $label,
                    'items' => [],
                ];
            }

            $byKey[$key]['items'][] = $node;
        }

        return array_values($byKey);
    }

    /**
     * @return list<MenuItem>
     */
    private function allItems(): array
    {
        $all = [];
        foreach ($this->items as $entry) {
            $all[] = $entry['item'];
        }
        foreach ($this->children as $entries) {
            foreach ($entries as $entry) {
                $all[] = $entry['item'];
            }
        }

        return $all;
    }

    /**
     * @param array{item: MenuItem, order: int} $a
     * @param array{item: MenuItem, order: int} $b
     */
    private static function compareEntries(array $a, array $b): int
    {
        $posComparison = $a['item']->position <=> $b['item']->position;
        if ($posComparison !== 0) {
            return $posComparison;
        }

        return $a['order'] <=> $b['order'];
    }

    private function normalizePath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }

        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
