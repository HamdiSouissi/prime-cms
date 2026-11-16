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
    <span class="aurora-page-header__eyebrow">Recherche</span>
    <h1 class="aurora-page-header__title">
      <?= $q !== '' ? 'Résultats pour « ' . $site->e($q) . ' »' : 'Rechercher sur le site' ?>
    </h1>
    <?php if ($q !== ''): ?>
      <p class="aurora-page-header__lead">
        <?= $total ?> résultat<?= $total > 1 ? 's' : '' ?> trouvé<?= $total > 1 ? 's' : '' ?>.
      </p>
    <?php endif; ?>

    <form class="site-search-form aurora-search-header-form" role="search" method="get" action="<?= $site->searchUrl() ?>">
      <label class="sr-only" for="aurora-search-q">Terme de recherche</label>
      <input
        id="aurora-search-q"
        class="site-search-form__input"
        type="search"
        name="q"
        value="<?= $site->e($q) ?>"
        placeholder="Tapez un mot-clé…"
        required
      >
      <button class="site-search-form__btn" type="submit">Rechercher</button>
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
      <h2>Aucun résultat</h2>
      <p>Essayez avec d'autres mots-clés ou explorez nos derniers articles.</p>
      <div class="aurora-empty__actions">
        <a class="aurora-btn" href="<?= $site->homeUrl() ?>">Retourner à l'accueil</a>
      </div>
    </section>
  <?php endif; ?>
</div>
