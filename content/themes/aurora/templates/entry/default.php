<?php

declare(strict_types=1);

/** @var Prime\Site\View\SiteView $site */
/** @var Prime\Site\View\EntryView $entry */
/** @var ?Prime\Content\ContentType $contentType */
?>
<div class="site-container">
  <?= $site->partial('breadcrumbs') ?>

  <article class="aurora-article aurora-article--narrow">
    <header class="aurora-article__header">
      <?php if ($contentType !== null): ?>
        <span class="aurora-page-header__eyebrow"><?= $site->e($contentType->label('singular')) ?></span>
      <?php endif; ?>
      <h1 class="aurora-article__title"><?= $entry->title() ?></h1>
      <?php if ($entry->date() !== ''): ?>
        <div class="aurora-meta">
          <time datetime="<?= $entry->isoDate() ?>"><?= $entry->date() ?></time>
        </div>
      <?php endif; ?>
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
</div>
