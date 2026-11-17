<?php

declare(strict_types=1);

/** @var Prime\Site\View\EntryView $item */
$eager = !empty($eager);
$categories = $item->terms('category');
$author = $item->author();
?>
<article class="aurora-card">
  <?php if ($item->hasFeaturedImage()): ?>
    <a class="aurora-card__media" href="<?= $item->url() ?>" tabindex="-1" aria-hidden="true">
      <?= $item->featuredImage('card', [
          'loading' => $eager ? 'eager' : 'lazy',
          'fetchpriority' => $eager ? 'high' : 'auto',
      ]) ?>
    </a>
  <?php endif; ?>

  <div class="aurora-card__body">
    <?php if ($categories !== []): ?>
      <div class="aurora-card__terms">
        <?php foreach (array_slice($categories, 0, 2) as $cat): ?>
          <a class="aurora-chip" href="<?= $cat['url'] ?>"><?= $cat['name'] ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h2 class="aurora-card__title">
      <a href="<?= $item->url() ?>"><?= $item->title() ?></a>
    </h2>

    <?php if ($item->excerpt() !== ''): ?>
      <p class="aurora-card__excerpt"><?= $item->excerpt(145) ?></p>
    <?php endif; ?>

    <div class="aurora-meta">
      <?php if ($item->date() !== ''): ?>
        <time datetime="<?= $item->isoDate() ?>"><?= $item->date() ?></time>
      <?php endif; ?>
      <span>&middot;</span>
      <a href="<?= $author['url'] ?>"><?= $author['name'] ?></a>
      <span>&middot;</span>
      <span><?= $site->e($site->t('site.aurora.partials.card.min_lecture', ['count' => $item->readingTime()])) ?></span>
    </div>
  </div>
</article>
