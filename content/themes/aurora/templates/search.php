<?php

declare(strict_types=1);

/** @var Prime\Site\View\SiteView $site */
/** @var list<Prime\Site\View\EntryView> $entries */
/** @var ?string $searchQuery */
$q = is_string($searchQuery) ? trim($searchQuery) : '';
$total = $site->context()->totalEntries;
?>
<div class="site-container">
  <?= $site->partial('breadcrumbs') ?>

  <header class="aurora-page-header">
    <span class="aurora-page-header__eyebrow"><?= $site->e($site->t('site.search.eyebrow')) ?></span>
    <h1 class="aurora-page-header__title">
      <?= $q !== '' ? $site->e($site->t('site.search.results_for', ['query' => $q])) : $site->e($site->t('site.search.heading')) ?>
    </h1>
    <?php if ($q !== ''): ?>
      <p class="aurora-page-header__lead">
        <?= $site->e($site->choice('site.search.count', $total)) ?>.
      </p>
    <?php endif; ?>

    <form class="site-search-form aurora-search-header-form" role="search" method="get" action="<?= $site->searchUrl() ?>">
      <label class="sr-only" for="aurora-search-q"><?= $site->e($site->t('site.aurora.search.terme_recherche')) ?></label>
      <input
        id="aurora-search-q"
        class="site-search-form__input"
        type="search"
        name="q"
        value="<?= $site->e($q) ?>"
        placeholder="<?= $site->e($site->t('site.aurora.search.tapez_mot_cle')) ?>"
        required
      >
      <button class="site-search-form__btn" type="submit"><?= $site->e($site->t('site.search.submit')) ?></button>
    </form>
  </header>

  <?php if ($q !== '' && $entries !== []): ?>
    <div class="aurora-grid">
      <?php foreach ($entries as $item): ?>
        <?= $site->partial('card', ['item' => $item]) ?>
      <?php endforeach; ?>
    </div>
    <?= $site->partial('pagination') ?>
  <?php elseif ($q !== ''): ?>
    <section class="aurora-empty">
      <h2><?= $site->e($site->t('site.aurora.search.aucun_resultat')) ?></h2>
      <p><?= $site->e($site->t('site.aurora.search.essayez_autres_mots_cles_explorez')) ?></p>
      <div class="aurora-empty__actions">
        <a class="aurora-btn" href="<?= $site->homeUrl() ?>"><?= $site->e($site->t('site.not_found.home')) ?></a>
      </div>
    </section>
  <?php endif; ?>
</div>
