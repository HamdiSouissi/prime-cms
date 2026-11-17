<?php

declare(strict_types=1);

/**
 * Standalone clean content preview (`GET /admin/content/{type}/{id}/preview`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $appName
 * @var \Prime\Content\ContentType $contentType
 * @var \Prime\Content\Entry $entry
 * @var \Prime\Auth\User|null $author
 * @var string $previewTitle
 * @var \Prime\Content\Revision|null $previewRevision
 * @var string $renderedHtml
 * @var bool $canEdit
 */
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e($this->t('admin.content.preview.titre', ['title' => $previewTitle, 'site' => $appName])) ?></title>
    <link rel="stylesheet" href="<?= $this->e(asset('admin/css/admin.css')) ?>">
</head>
<body class="pc-preview-shell">
    <header class="pc-preview-banner">
        <div>
            <strong><?= $this->e($this->t('admin.content.preview.mode_previsualisation')) ?></strong>
            · <?= $this->e($contentType->label('singular')) ?>
            · <?= t('admin.content.preview.status') ?> : <?= $this->e($entry->statusLabel()) ?>
            <?php if ($previewRevision !== null): ?>
                · <?= $this->e($this->t('admin.content.preview.revision', ['id' => $previewRevision->id, 'date' => $previewRevision->createdAt])) ?>
            <?php endif; ?>
        </div>
        <div class="pc-row pc-row--sm">
            <?php if ($canEdit): ?>
                <a class="pc-btn pc-btn--secondary pc-btn--sm" href="<?= $this->e($entry->editUrl()) ?>">
                    <?= $this->e($this->t('admin.content.preview.retour_a_editeur')) ?>
                </a>
            <?php endif; ?>
        </div>
    </header>

    <article class="pc-preview-article">
        <h1><?= $this->e($previewTitle) ?></h1>
        <div class="pc-preview-meta">
            <span>Par <?= $this->e($author !== null ? $author->displayName : ($entry->authorName ?? $this->t('admin.content.unknown_author'))) ?></span>
            <span>·</span>
            <span>/<?= $this->e($entry->slug) ?></span>
            <?php if ($entry->publishedAt !== null): ?>
                <span>·</span>
                <span><?= $this->e($entry->publishedAt) ?> UTC</span>
            <?php endif; ?>
        </div>

        <?php if ($entry->featuredMediaId() !== null): ?>
            <?php $heroImg = $this->mediaImage($entry->featuredMediaId(), ['variant' => 'large', 'sizes' => '(max-width: 900px) 100vw, 820px', 'class' => 'pc-preview-hero']); ?>
            <?php if ($heroImg !== ''): ?>
                <figure class="pc-block pc-block--image" style="margin:0 0 24px;"><?= $this->raw($heroImg) ?></figure>
            <?php endif; ?>
        <?php endif; ?>

        <div class="pc-prose">
            <?= $this->raw($renderedHtml) ?>
        </div>
    </article>
</body>
</html>
