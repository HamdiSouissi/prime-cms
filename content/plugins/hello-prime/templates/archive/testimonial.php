<?php

declare(strict_types=1);

/**
 * Fallback archive template for the `testimonial` Custom Content Type provided by
 * the Hello Prime plugin via `site.template_paths`. Any active theme may override it
 * by adding its own `templates/archive/testimonial.php`.
 *
 * @var Prime\Site\View\SiteView $site
 * @var list<Prime\Site\View\EntryView> $entries
 */
?>
<div class="site-container">
  <?= $site->partial('breadcrumbs') ?>

  <header class="aurora-page-header">
    <span class="aurora-page-header__eyebrow">Hello Prime</span>
    <h1 class="aurora-page-header__title">Témoignages clients</h1>
    <p class="aurora-page-header__lead">
      Retours vérifiés de nos utilisateurs et partenaires.
    </p>
  </header>

  <?php if ($entries !== []): ?>
    <div class="aurora-grid">
      <?php foreach ($entries as $item): ?>
        <?php
          $role = (string) $item->field('author_role', '');
          $rating = max(1, min(5, (int) $item->rawField('rating', 5)));
          $stars = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
          ?>
        <article class="aurora-testimonial">
          <div class="aurora-testimonial__stars" aria-label="Note : <?= $rating ?> sur 5"><?= $stars ?></div>
          <blockquote class="aurora-testimonial__quote">
            <?= $item->excerpt(260) ?>
          </blockquote>
          <footer class="aurora-testimonial__author">
            <a href="<?= $item->url() ?>"><?= $item->title() ?></a>
            <?php if ($role !== ''): ?>
              <span class="aurora-testimonial__role"><?= $role ?></span>
            <?php endif; ?>
          </footer>
        </article>
      <?php endforeach; ?>
    </div>
    <?= $site->partial('pagination') ?>
  <?php else: ?>
    <section class="aurora-empty">
      <h2>Aucun témoignage publié</h2>
      <p>Ajoutez votre premier témoignage depuis l'administration Prime CMS.</p>
    </section>
  <?php endif; ?>
</div>
