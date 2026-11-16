<?php

declare(strict_types=1);

/** @var Prime\Site\View\SiteView $site */
/** @var Prime\Site\View\EntryView $entry */
?>
<div class="site-container">
  <?= $site->partial('breadcrumbs') ?>

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
</div>
