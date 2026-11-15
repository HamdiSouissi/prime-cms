<?php

declare(strict_types=1);

/**
 * Accessible ARIA tabs component.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $id Unique prefix for tab & panel IDs
 * @var string $label Accessible label for the tablist
 * @var list<array{id: string, label: string, content: string, badge?: string}> $tabs
 */
$tabItems = is_array($tabs ?? null) ? array_values($tabs) : [];
$activeId = isset($activeTab) && is_string($activeTab) && $activeTab !== '' ? $activeTab : null;
$activeIndex = 0;
if ($activeId !== null) {
    foreach ($tabItems as $idx => $tab) {
        if ($tab['id'] === $activeId) {
            $activeIndex = $idx;
            break;
        }
    }
}
?>
<div class="pc-tabs" data-prime-tabs>
    <div class="pc-tabs__list" role="tablist" aria-label="<?= $this->e($label) ?>">
        <?php foreach ($tabItems as $index => $tab): ?>
            <?php
            $tabId = $id . '-tab-' . $tab['id'];
            $panelId = $id . '-panel-' . $tab['id'];
            $selected = $index === $activeIndex;
            ?>
            <button
                type="button"
                role="tab"
                id="<?= $this->e($tabId) ?>"
                class="pc-tabs__tab"
                aria-selected="<?= $selected ? 'true' : 'false' ?>"
                aria-controls="<?= $this->e($panelId) ?>"
                tabindex="<?= $selected ? '0' : '-1' ?>"
            >
                <span><?= $this->e($tab['label']) ?></span>
                <?php if (!empty($tab['badge'])): ?>
                    <span class="pc-badge pc-badge--neutral pc-badge--pill"><?= $this->e((string) $tab['badge']) ?></span>
                <?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>

    <?php foreach ($tabItems as $index => $tab): ?>
        <?php
        $tabId = $id . '-tab-' . $tab['id'];
        $panelId = $id . '-panel-' . $tab['id'];
        $selected = $index === $activeIndex;
        ?>
        <div
            role="tabpanel"
            id="<?= $this->e($panelId) ?>"
            class="pc-tabs__panel"
            aria-labelledby="<?= $this->e($tabId) ?>"
            tabindex="0"
            <?= $selected ? '' : 'hidden' ?>
        >
            <?= $this->raw((string) $tab['content']) ?>
        </div>
    <?php endforeach; ?>
</div>
