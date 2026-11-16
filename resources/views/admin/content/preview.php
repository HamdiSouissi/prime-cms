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
    <title>Aperçu : <?= $this->e($previewTitle) ?> — <?= $this->e($appName) ?></title>
    <link rel="stylesheet" href="<?= $this->e(asset('admin/css/admin.css')) ?>">
</head>
<body class="pc-preview-shell">
    <header class="pc-preview-banner">
        <div>
            <strong>Mode prévisualisation</strong>
            · <?= $this->e($contentType->label('singular')) ?>
            · Statut : <?= $this->e($entry->statusLabel()) ?>
            <?php if ($previewRevision !== null): ?>
                · Révision #<?= $this->e((string) $previewRevision->id) ?> (<?= $this->e($previewRevision->createdAt) ?>)
            <?php endif; ?>
        </div>
        <div class="pc-row pc-row--sm">
            <?php if ($canEdit): ?>
                <a class="pc-btn pc-btn--secondary pc-btn--sm" href="<?= $this->e($entry->editUrl()) ?>">
                    Retour à l’éditeur
                </a>
            <?php endif; ?>
        </div>
    </header>

    <article class="pc-preview-article">
        <h1><?= $this->e($previewTitle) ?></h1>
        <div class="pc-preview-meta">
            <span>Par <?= $this->e($author !== null ? $author->displayName : ($entry->authorName ?? 'Auteur inconnu')) ?></span>
            <span>·</span>
            <span>/<?= $this->e($entry->slug) ?></span>
            <?php if ($entry->publishedAt !== null): ?>
                <span>·</span>
                <span><?= $this->e($entry->publishedAt) ?> UTC</span>
            <?php endif; ?>
        </div>

        <div class="pc-prose">
            <?= $this->raw($renderedHtml) ?>
        </div>
    </article>
</body>
</html>
