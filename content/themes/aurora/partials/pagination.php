<?php

declare(strict_types=1);

/** @var Prime\Site\View\SiteView $site */
$pag = $site->pagination();
if ($pag['total'] <= 1) {
    return;
}
?>
<nav class="aurora-pagination" aria-label="Pagination">
  <ul class="aurora-pagination__list">
    <?php if ($pag['prev_url'] !== null): ?>
      <li>
        <a class="aurora-pagination__link" href="<?= $pag['prev_url'] ?>" rel="prev">&larr; Précédent</a>
      </li>
    <?php endif; ?>

    <?php foreach ($pag['pages'] as $p): ?>
      <li>
        <a
          class="aurora-pagination__link"
          href="<?= $p['url'] ?>"
          <?= $p['active'] ? 'aria-current="page"' : '' ?>
        ><?= $p['num'] ?></a>
      </li>
    <?php endforeach; ?>

    <?php if ($pag['next_url'] !== null): ?>
      <li>
        <a class="aurora-pagination__link" href="<?= $pag['next_url'] ?>" rel="next">Suivant &rarr;</a>
      </li>
    <?php endif; ?>
  </ul>
</nav>
