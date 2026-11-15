<?php

declare(strict_types=1);

namespace Prime\Admin\Menu;

/**
 * Resolved hierarchical node produced by `AdminMenu::tree()`.
 */
final readonly class MenuNode
{
    /**
     * @param list<MenuNode> $children
     */
    public function __construct(
        public MenuItem $item,
        public bool $active = false,
        public bool $hasActiveChild = false,
        public array $children = [],
    ) {
    }
}
