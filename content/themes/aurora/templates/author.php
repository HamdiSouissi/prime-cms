<?php

declare(strict_types=1);

/** @var Prime\Site\View\SiteView $site */
/** @var list<Prime\Site\View\EntryView> $entries */
/** @var ?Prime\Auth\User $author */
$showSidebar = (bool) $site->rawOption('show_sidebar', true) && $site->hasZone('sidebar');
$authorName = $author !== null ? $author->displayName : 'Auteur';
$authorBio = $author?->bio ?? '';
$authorAvatar = ($entries !== []) ? $entries[0]->author()['avatar'] : '';
$authorInitials = ($entries !== []) ? $entries[0]->author()['initials'] : strtoupper(substr($authorName, 0, 1));
?>
<div class="site-container">
  <?= $site->partial('breadcrumbs') ?>

  <header class="aurora-page-header">
    <div class="aurora-author-box aurora-author-box--header">
      <div class="site-avatar site-avatar--lg">
        <?php if ($authorAvatar !== ''): ?>
          <?= $authorAvatar ?>
        <?php else: ?>
          <span aria-hidden="true"><?= $site->e($authorInitials) ?></span>
        <?php endif; ?>
      </div>
      <div>
        <span class="aurora-page-header__eyebrow">Auteur</span>
        <h1 class="aurora-page-header__title"><?= $site->e($authorName) ?></h1>
        <?php if ($authorBio !== ''): ?>
          <p class="aurora-page-header__lead"><?= $site->e($authorBio) ?></p>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <div class="aurora-layout<?= $showSidebar ? ' aurora-layout--with-sidebar' : '' ?>">
    <div>
      <?php if ($entries !== []): ?>
        <div class="aurora-grid">
          <?php foreach ($entries as $idx => $item): ?>
            <?= $site->partial('card', ['item' => $item, 'eager' => $idx === 0]) ?>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <section class="aurora-empty">
          <h2><?= $site->e($site->t('site.aurora.author.aucun_article_publie')) ?></h2>
          <p><?= $site->e($authorName) ?> <?= $site->e($site->t('site.aurora.author.pas_encore_publie_article')) ?></p>
        </section>
      <?php endif; ?>

      <?= $site->partial('pagination') ?>
    </div>

    <?php if ($showSidebar): ?>
      <aside class="aurora-sidebar" aria-label="<?= t('site.attr.barre_laterale') ?>">
        <?= $site->zone('sidebar') ?>
      </aside>
    <?php endif; ?>
  </div>
</div>
