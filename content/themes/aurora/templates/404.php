<?php

declare(strict_types=1);

/** @var Prime\Site\View\SiteView $site */
$recent = $site->entries(['type' => 'post', 'limit' => 3]);
?>
<div class="site-container">
  <section class="aurora-empty">
    <div class="aurora-empty__code" aria-hidden="true">404</div>
    <h1>Page introuvable</h1>
    <p>La page que vous recherchez a peut-être été déplacée, renommée ou supprimée.</p>

    <form class="site-search-form aurora-empty__search" role="search" method="get" action="<?= $site->searchUrl() ?>">
      <label class="sr-only" for="aurora-404-search">Rechercher sur le site</label>
      <input id="aurora-404-search" class="site-search-form__input" type="search" name="q" placeholder="Rechercher un contenu…" required>
      <button class="site-search-form__btn" type="submit">Rechercher</button>
    </form>

    <div class="aurora-empty__actions">
      <a class="aurora-btn" href="<?= $site->homeUrl() ?>">Retourner à l'accueil</a>
      <a class="aurora-btn aurora-btn--secondary" href="<?= $site->blogUrl() ?>">Parcourir le blog</a>
    </div>
  </section>

  <?php if ($recent !== []): ?>
    <section class="aurora-related" aria-labelledby="aurora-404-recent">
      <h2 id="aurora-404-recent" class="aurora-related__heading">Articles récents</h2>
      <div class="aurora-grid">
        <?php foreach ($recent as $item): ?>
          <?= $site->partial('card', ['item' => $item]) ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>
