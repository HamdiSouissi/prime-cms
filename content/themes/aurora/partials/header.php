<?php

declare(strict_types=1);

/** @var Prime\Site\View\SiteView $site */
$sticky = (bool) $site->rawOption('header_sticky', true);
$showSearch = (bool) $site->rawOption('show_header_search', true);
$headerClass = $sticky ? 'site-header site-header--sticky' : 'site-header';
$hasPrimaryMenu = $site->hasMenu('primary');
?>
<header class="<?= $headerClass ?>">
  <div class="site-container site-header__inner">
    <a class="site-brand" href="<?= $site->homeUrl() ?>" rel="home">
      <?php if ($site->hasLogo()): ?>
        <?= $site->logo(['class' => 'site-brand__logo']) ?>
      <?php endif; ?>
      <span class="site-brand__text">
        <span class="site-brand__name"><?= $site->name() ?></span>
        <?php if ($site->rawTagline() !== ''): ?>
          <span class="site-brand__tagline"><?= $site->tagline() ?></span>
        <?php endif; ?>
      </span>
    </a>

    <div class="site-header__nav" id="aurora-primary-nav" data-aurora-nav>
      <?php if ($hasPrimaryMenu): ?>
        <?= $site->menu('primary', ['class' => 'site-nav', 'aria_label' => $site->t('site.aurora.partials.header.primary_nav')]) ?>
      <?php else: ?>
        <nav class="site-nav" aria-label="<?= $site->e($site->t('site.aurora.partials.header.primary_nav')) ?>">
          <ul class="site-nav__list">
            <li class="site-nav__item">
              <a class="site-nav__link" href="/"<?= $site->isFront() ? ' aria-current="page"' : '' ?>><?= $site->e($site->t('site.aurora.partials.header.home')) ?></a>
            </li>
            <li class="site-nav__item">
              <a class="site-nav__link" href="<?= $site->blogUrl() ?>"<?= $site->isBlog() ? ' aria-current="page"' : '' ?>><?= $site->e($site->t('site.aurora.partials.header.blog')) ?></a>
            </li>
          </ul>
        </nav>
      <?php endif; ?>
    </div>

    <div class="site-header__actions">
      <?php if ($showSearch): ?>
        <button
          type="button"
          class="site-icon-btn"
          aria-label="<?= $site->e($site->t('site.aurora.partials.header.ouvrir_recherche')) ?>"
          aria-expanded="false"
          aria-controls="aurora-header-search"
          data-aurora-search-toggle
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
          </svg>
        </button>
      <?php endif; ?>

      <button
        type="button"
        class="site-icon-btn site-mobile-toggle"
        aria-label="<?= $site->e($site->t('site.aurora.partials.header.ouvrir_menu_navigation')) ?>"
        aria-expanded="false"
        aria-controls="aurora-primary-nav"
        data-aurora-nav-toggle
      >
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <line x1="3" y1="6" x2="21" y2="6"></line>
          <line x1="3" y1="12" x2="21" y2="12"></line>
          <line x1="3" y1="18" x2="21" y2="18"></line>
        </svg>
      </button>
    </div>
  </div>

  <?php if ($showSearch): ?>
    <div class="site-header-search" id="aurora-header-search" data-aurora-search-bar hidden>
      <div class="site-container">
        <form class="site-search-form" role="search" method="get" action="<?= $site->searchUrl() ?>">
          <label class="sr-only" for="aurora-header-q"><?= $site->e($site->t('site.aurora.partials.header.rechercher_site')) ?></label>
          <input id="aurora-header-q" class="site-search-form__input" type="search" name="q" placeholder="<?= $site->e($site->t('site.attr.rechercher_un_article_une_page')) ?>" required>
          <button class="site-search-form__btn" type="submit"><?= $site->e($site->t('site.search.submit')) ?></button>
        </form>
      </div>
    </div>
  <?php endif; ?>
</header>
