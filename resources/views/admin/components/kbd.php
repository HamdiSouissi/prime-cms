<?php

declare(strict_types=1);

/**
 * Keyboard shortcut badge.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $keys
 * @var bool|null $platformMod If true, adapts "Ctrl" to "⌘" on macOS via JS
 */
$isPlatformMod = (bool) ($platformMod ?? false);
?>
<kbd class="pc-kbd"<?= $isPlatformMod ? ' data-shortcut-mod' : '' ?>><?= $this->e($keys) ?></kbd>
