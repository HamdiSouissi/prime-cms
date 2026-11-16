<?php

declare(strict_types=1);

/** @var Prime\Site\View\SiteView $site */
/** @var Prime\Site\View\EntryView $entry */
?>
<div class="site-container aurora-landing">
  <section class="aurora-article aurora-article--full">
    <header class="aurora-article__header">
      <h1 class="aurora-article__title"><?= $entry->title() ?></h1>
      <?php if ($entry->excerpt() !== ''): ?>
        <p class="aurora-page-header__lead"><?= $entry->excerpt(260) ?></p>
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
  </section>
</div>
