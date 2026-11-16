<?php

declare(strict_types=1);

/** @var Prime\Site\View\SiteView $site */
/** @var list<Prime\Site\View\EntryView> $entries */
/** @var ?Prime\Content\ContentType $contentType */
$showSidebar = (bool) $site->rawOption('show_sidebar', true) && $site->hasZone('sidebar');
$heading = $contentType !== null ? $contentType->label('plural') : 'Archives';
?>
<div class="site-container">
  <?= $site->partial('breadcrumbs') ?>

  <header class="aurora-page-header">
    <span class="aurora-page-header__eyebrow">Archive</span>
    <h1 class="aurora-page-header__title"><?= $site->e($heading) ?></h1>
    <p class="aurora-page-header__lead">
      <?= $site->context()->totalEntries ?> publication<?= $site->context()->totalEntries > 1 ? 's' : '' ?>
    </p>
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
          <h2><?= $site->e($site->t('site.aurora.archive.default.aucun_contenu_publie')) ?></h2>
          <p><?= $site->e($site->t('site.aurora.archive.default.rubrique_ne_contient_aucun_element')) ?></p>
        </section>
      <?php endif; ?>

      <?= $site->partial('pagination') ?>
    </div>

    <?php if ($showSidebar): ?>
      <aside class="aurora-sidebar" aria-label="Barre latérale">
        <?= $site->zone('sidebar') ?>
      </aside>
    <?php endif; ?>
  </div>
</div>
