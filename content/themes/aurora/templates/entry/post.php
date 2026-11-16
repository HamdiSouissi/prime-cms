<?php

declare(strict_types=1);

/** @var Prime\Site\View\SiteView $site */
/** @var Prime\Site\View\EntryView $entry */
$showSidebar = (bool) $site->rawOption('show_sidebar', true) && $site->hasZone('sidebar');
$showAuthor = (bool) $site->rawOption('show_author_box', true);
$showRelated = (bool) $site->rawOption('show_related', true);
$categories = $entry->terms('category');
$tags = $entry->terms('tag');
$author = $entry->author();
$prev = $entry->previous();
$next = $entry->next();
$related = $showRelated ? $entry->related(3) : [];
?>
<div class="site-container">
  <?= $site->partial('breadcrumbs') ?>

  <div class="aurora-layout<?= $showSidebar ? ' aurora-layout--with-sidebar' : '' ?>">
    <article class="aurora-article<?= $showSidebar ? '' : ' aurora-article--narrow' ?>">
      <header class="aurora-article__header">
        <?php if ($categories !== []): ?>
          <div class="aurora-terms">
            <?php foreach ($categories as $cat): ?>
              <a class="aurora-chip" href="<?= $cat['url'] ?>"><?= $cat['name'] ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <h1 class="aurora-article__title"><?= $entry->title() ?></h1>

        <div class="aurora-meta">
          <?php if ($entry->date() !== ''): ?>
            <time datetime="<?= $entry->isoDate() ?>"><?= $entry->date() ?></time>
          <?php endif; ?>
          <span>&middot;</span>
          <a href="<?= $author['url'] ?>"><?= $author['name'] ?></a>
          <span>&middot;</span>
          <span><?= $entry->readingTime() ?> <?= $site->e($site->t('site.aurora.entry.post.min_lecture')) ?></span>
        </div>
      </header>

      <?php if ($entry->hasFeaturedImage()): ?>
        <figure class="aurora-article__hero">
          <?= $entry->featuredImage('hero', ['loading' => 'eager', 'fetchpriority' => 'high']) ?>
        </figure>
      <?php endif; ?>

      <div class="aurora-prose">
        <?= $entry->content() ?>
      </div>

      <?php if ($tags !== []): ?>
        <footer class="aurora-terms aurora-article__tags" aria-label="Étiquettes">
          <?php foreach ($tags as $tag): ?>
            <a class="aurora-chip" href="<?= $tag['url'] ?>">#<?= $tag['name'] ?></a>
          <?php endforeach; ?>
        </footer>
      <?php endif; ?>

      <?php if ($showAuthor): ?>
        <section class="aurora-author-box" aria-label="À propos de l'auteur">
          <div class="site-avatar">
            <?php if ($author['avatar'] !== ''): ?>
              <?= $author['avatar'] ?>
            <?php else: ?>
              <span aria-hidden="true"><?= $author['initials'] ?></span>
            <?php endif; ?>
          </div>
          <div>
            <p class="aurora-author-box__name">
              <a href="<?= $author['url'] ?>"><?= $author['name'] ?></a>
            </p>
            <?php if ($author['bio'] !== ''): ?>
              <p class="aurora-author-box__bio"><?= $author['bio'] ?></p>
            <?php else: ?>
              <p class="aurora-author-box__bio"><?= $site->e($site->t('site.aurora.entry.post.tous_articles_publies_par', ['name' => $author['name']])) ?></p>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($prev !== null || $next !== null): ?>
        <nav class="aurora-post-nav" aria-label="Navigation entre articles">
          <div>
            <?php if ($prev !== null): ?>
              <a class="aurora-post-nav__link" href="<?= $prev->url() ?>" rel="prev">
                <span class="aurora-post-nav__label"><?= $site->e($site->t('site.aurora.entry.post.larr_article_precedent')) ?></span>
                <span class="aurora-post-nav__title"><?= $prev->title() ?></span>
              </a>
            <?php endif; ?>
          </div>
          <div>
            <?php if ($next !== null): ?>
              <a class="aurora-post-nav__link aurora-post-nav__link--next" href="<?= $next->url() ?>" rel="next">
                <span class="aurora-post-nav__label">Article suivant &rarr;</span>
                <span class="aurora-post-nav__title"><?= $next->title() ?></span>
              </a>
            <?php endif; ?>
          </div>
        </nav>
      <?php endif; ?>
    </article>

    <?php if ($showSidebar): ?>
      <aside class="aurora-sidebar" aria-label="Barre latérale">
        <?= $site->zone('sidebar') ?>
      </aside>
    <?php endif; ?>
  </div>

  <?php if ($related !== []): ?>
    <section class="aurora-related" aria-labelledby="aurora-related-title">
      <h2 id="aurora-related-title" class="aurora-related__heading">Articles similaires</h2>
      <div class="aurora-grid">
        <?php foreach ($related as $rel): ?>
          <?= $site->partial('card', ['item' => $rel]) ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>
