<?php

declare(strict_types=1);

/** @var Prime\Site\View\SiteView $site */
/** @var ?Prime\Site\View\EntryView $entry */
/** @var list<Prime\Site\View\EntryView> $entries */
$showSidebar = (bool) $site->rawOption('show_sidebar', true) && $site->hasZone('sidebar');
?>
<div class="site-container">
  <?php if ($entry !== null): ?>
    <article class="aurora-article aurora-article--full">
      <header class="aurora-article__header">
        <h1 class="aurora-article__title"><?= $entry->title() ?></h1>
      </header>
      <?php if ($entry->hasFeaturedImage()): ?>
        <figure class="aurora-article__hero">
          <?= $entry->featuredImage('hero', ['loading' => 'eager', 'fetchpriority' => 'high']) ?>
        </figure>
      <?php endif; ?>
      <div class="aurora-prose">
        <?= $entry->content() ?>
      </div>
    </article>
  <?php else: ?>
    <?php
      $lead = ($site->context()->page === 1 && $entries !== []) ? $entries[0] : null;
      $gridItems = $lead !== null ? array_slice($entries, 1) : $entries;
      ?>
    <?php if ($lead !== null): ?>
      <?php $leadAuthor = $lead->author(); ?>
      <article class="aurora-lead">
        <?php if ($lead->hasFeaturedImage()): ?>
          <a class="aurora-lead__media" href="<?= $lead->url() ?>" tabindex="-1" aria-hidden="true">
            <?= $lead->featuredImage('hero', ['loading' => 'eager', 'fetchpriority' => 'high']) ?>
          </a>
        <?php endif; ?>
        <div class="aurora-lead__body">
          <?php $cats = $lead->terms('category'); ?>
          <?php if ($cats !== []): ?>
            <div class="aurora-terms">
              <?php foreach (array_slice($cats, 0, 2) as $cat): ?>
                <a class="aurora-chip" href="<?= $cat['url'] ?>"><?= $cat['name'] ?></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <h1 class="aurora-lead__title"><a href="<?= $lead->url() ?>"><?= $lead->title() ?></a></h1>
          <?php if ($lead->excerpt() !== ''): ?>
            <p class="aurora-lead__excerpt"><?= $lead->excerpt(220) ?></p>
          <?php endif; ?>
          <div class="aurora-meta">
            <?php if ($lead->date() !== ''): ?>
              <time datetime="<?= $lead->isoDate() ?>"><?= $lead->date() ?></time>
            <?php endif; ?>
            <span>&middot;</span>
            <a href="<?= $leadAuthor['url'] ?>"><?= $leadAuthor['name'] ?></a>
            <span>&middot;</span>
            <span><?= $lead->readingTime() ?> <?= $site->e($site->t('site.aurora.front.min_lecture')) ?></span>
          </div>
        </div>
      </article>
    <?php else: ?>
      <header class="aurora-page-header">
        <h1 class="aurora-page-header__title"><?= $site->name() ?></h1>
        <?php if ($site->rawTagline() !== ''): ?>
          <p class="aurora-page-header__lead"><?= $site->tagline() ?></p>
        <?php endif; ?>
      </header>
    <?php endif; ?>

    <div class="aurora-layout<?= $showSidebar ? ' aurora-layout--with-sidebar' : '' ?>">
      <div>
        <?php if ($gridItems !== []): ?>
          <div class="aurora-grid">
            <?php foreach ($gridItems as $item): ?>
              <?= $site->partial('card', ['item' => $item]) ?>
            <?php endforeach; ?>
          </div>
        <?php elseif ($lead === null): ?>
          <section class="aurora-empty">
            <h2>Bienvenue sur <?= $site->name() ?></h2>
            <p><?= $site->e($site->t('site.aurora.front.aucun_article_encore_ete_publie')) ?></p>
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
  <?php endif; ?>
</div>
