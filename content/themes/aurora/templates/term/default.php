<?php

declare(strict_types=1);

/** @var Prime\Site\View\SiteView $site */
/** @var list<Prime\Site\View\EntryView> $entries */
/** @var ?Prime\Content\Term $term */
/** @var ?Prime\Content\Taxonomy $taxonomy */
$showSidebar = (bool) $site->rawOption('show_sidebar', true) && $site->hasZone('sidebar');
?>
<div class="site-container">
  <?= $site->partial('breadcrumbs') ?>

  <header class="aurora-page-header">
    <?php if ($taxonomy !== null): ?>
      <span class="aurora-page-header__eyebrow"><?= $site->e($taxonomy->label('singular')) ?></span>
    <?php endif; ?>
    <h1 class="aurora-page-header__title"><?= $term !== null ? $site->e($term->name) : 'Archive' ?></h1>
    <?php if ($term !== null && $term->description !== null && trim($term->description) !== ''): ?>
      <p class="aurora-page-header__lead"><?= $site->e($term->description) ?></p>
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
          <h2><?= $site->e($site->t('site.aurora.term.default.aucun_article_rubrique')) ?></h2>
          <p><?= $site->e($site->t('site.aurora.term.default.aucun_contenu_publie_encore_associe')) ?></p>
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
