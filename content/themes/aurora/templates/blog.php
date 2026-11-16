<?php

declare(strict_types=1);

/** @var Prime\Site\View\SiteView $site */
/** @var ?Prime\Site\View\EntryView $entry */
/** @var list<Prime\Site\View\EntryView> $entries */
$showSidebar = (bool) $site->rawOption('show_sidebar', true) && $site->hasZone('sidebar');
?>
<div class="site-container">
  <?= $site->partial('breadcrumbs') ?>

  <header class="aurora-page-header">
    <span class="aurora-page-header__eyebrow"><?= $site->e($site->t('site.aurora.templates.blog.eyebrow')) ?></span>
    <h1 class="aurora-page-header__title"><?= $entry !== null ? $entry->title() : $site->e($site->t('site.blog.title')) ?></h1>
    <?php if ($entry !== null && $entry->excerpt() !== ''): ?>
      <p class="aurora-page-header__lead"><?= $entry->excerpt(200) ?></p>
    <?php endif; ?>
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
          <h2><?= $site->e($site->t('site.aurora.blog.aucun_article_publie')) ?></h2>
          <p><?= $site->e($site->t('site.aurora.blog.prochains_articles_apparaitront_ici_des')) ?></p>
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
