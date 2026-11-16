<?php

declare(strict_types=1);

/** @var Prime\Site\View\SiteView $site */
$recent = $site->entries(['type' => 'post', 'limit' => 3]);
?>
<div class="site-container">
  <section class="aurora-empty">
    <div class="aurora-empty__code" aria-hidden="true">404</div>
    <h1>Page introuvable</h1>
    <p><?= $site->e($site->t('site.aurora.404.page_recherchez_peut_etre_ete')) ?></p>

    <form class="site-search-form aurora-empty__search" role="search" method="get" action="<?= $site->searchUrl() ?>">
      <label class="sr-only" for="aurora-404-search"><?= $site->e($site->t('site.aurora.404.rechercher_site')) ?></label>
      <input id="aurora-404-search" class="site-search-form__input" type="search" name="q" placeholder="Rechercher un contenu…" required>
      <button class="site-search-form__btn" type="submit"><?= $site->e($site->t('site.search.submit')) ?></button>
    </form>

    <div class="aurora-empty__actions">
      <a class="aurora-btn" href="<?= $site->homeUrl() ?>"><?= $site->e($site->t('site.not_found.home')) ?></a>
      <a class="aurora-btn aurora-btn--secondary" href="<?= $site->blogUrl() ?>"><?= $site->e($site->t('site.aurora.404.parcourir_blog')) ?></a>
    </div>
  </section>

  <?php if ($recent !== []): ?>
    <section class="aurora-related" aria-labelledby="aurora-404-recent">
      <h2 id="aurora-404-recent" class="aurora-related__heading"><?= $site->e($site->t('site.aurora.404.articles_recents')) ?></h2>
      <div class="aurora-grid">
        <?php foreach ($recent as $item): ?>
          <?= $site->partial('card', ['item' => $item]) ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>
