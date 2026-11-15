<?php

declare(strict_types=1);

/**
 * Standard admin page header with optional breadcrumb, title, subtitle, and action buttons.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $title
 * @var string|null $subtitle
 * @var list<array{label: string, url: ?string}>|null $breadcrumbs
 * @var string|null $actions Trusted HTML slot
 */
$pageSubtitle = isset($subtitle) && is_string($subtitle) && $subtitle !== '' ? $subtitle : null;
$crumbs = is_array($breadcrumbs ?? null) ? $breadcrumbs : [];
$actionsHtml = isset($actions) && is_string($actions) && $actions !== '' ? $actions : null;
?>
<header class="pc-page-header">
    <?php if ($crumbs !== []): ?>
        <?= $this->raw($this->insert('admin::components/breadcrumb', ['items' => $crumbs])) ?>
    <?php endif; ?>

    <div class="pc-page-header__main">
        <div class="pc-page-header__heading">
            <h1 class="pc-page-header__title"><?= $this->e($title) ?></h1>
            <?php if ($pageSubtitle !== null): ?>
                <p class="pc-page-header__subtitle"><?= $this->e($pageSubtitle) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($actionsHtml !== null): ?>
            <div class="pc-page-header__actions">
                <?= $this->raw($actionsHtml) ?>
            </div>
        <?php endif; ?>
    </div>
</header>
