<?php

declare(strict_types=1);

/**
 * Reading settings view (`GET /admin/settings/reading`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var list<\Prime\Content\Entry> $pages
 * @var string $frontMode
 * @var ?int $frontPageId
 * @var ?int $postsPageId
 * @var int $postsPerPage
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $this->t('admin.nav.reading'),
    'subtitle' => $this->t('admin.view_settings_reading.choisissez_ce_qu_affiche_la_page_d_accueil_et_le_nombre_d_ar'),
    'breadcrumbs' => $breadcrumbs,
])) ?>

<div class="pc-form-constrained">
    <form method="post" action="/admin/settings/reading" class="pc-card">
        <?= $this->raw($this->csrfField()) ?>
        <div class="pc-card__body pc-stack pc-stack--lg">
            <fieldset class="pc-fieldset pc-stack pc-stack--sm">
                <legend class="pc-label"><?= $this->e($this->t('admin.settings.reading.affichage_page_accueil')) ?></legend>

                <label class="pc-radio-card">
                    <input type="radio" name="front_mode" value="posts"<?= $frontMode === 'posts' ? ' checked' : '' ?>>
                    <span>
                        <strong><?= $this->e($this->t('admin.settings.reading.derniers_articles')) ?></strong>
                        <small class="pc-text-xs pc-text-muted"><?= $this->raw($this->t('admin.settings.reading.derniers_articles_aide')) ?></small>
                    </span>
                </label>

                <label class="pc-radio-card">
                    <input type="radio" name="front_mode" value="page"<?= $frontMode === 'page' ? ' checked' : '' ?>>
                    <span>
                        <strong><?= $this->e($this->t('admin.settings.reading.page_statique')) ?></strong>
                        <small class="pc-text-xs pc-text-muted"><?= $this->raw($this->t('admin.settings.reading.page_statique_aide')) ?></small>
                    </span>
                </label>
            </fieldset>

            <div class="pc-grid-2">
                <div class="pc-field">
                    <label class="pc-label" for="pc-reading-front-page"><?= t('admin.settings.reading.static_home') ?></label>
                    <select id="pc-reading-front-page" name="front_page_id" class="pc-select">
                        <option value=""><?= $this->e($this->t('admin.settings.reading.selectionner_page')) ?></option>
                        <?php foreach ($pages as $page): ?>
                            <option value="<?= $this->e((string) $page->id) ?>"<?= $frontPageId === $page->id ? ' selected' : '' ?>>
                                <?= $this->e($page->title) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pc-field">
                    <label class="pc-label" for="pc-reading-posts-page"><?= $this->e($this->t('admin.settings.reading.page_articles_blog')) ?></label>
                    <select id="pc-reading-posts-page" name="posts_page_id" class="pc-select">
                        <option value=""><?= $this->e($this->t('admin.settings.reading.defaut_blog')) ?></option>
                        <?php foreach ($pages as $page): ?>
                            <option value="<?= $this->e((string) $page->id) ?>"<?= $postsPageId === $page->id ? ' selected' : '' ?>>
                                <?= $this->e($page->title) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="pc-field">
                <label class="pc-label" for="pc-reading-per-page"><?= t('admin.settings.reading.posts_per_page') ?></label>
                <input
                    id="pc-reading-per-page"
                    type="number"
                    name="posts_per_page"
                    min="1"
                    max="50"
                    value="<?= $this->e((string) $postsPerPage) ?>"
                    class="pc-input pc-input--narrow"
                    required
                >
                <p class="pc-text-xs pc-text-muted"><?= $this->e($this->t('admin.settings.reading.applique_blog_archives_categories_etiquettes')) ?></p>
            </div>
        </div>

        <div class="pc-card__footer">
            <button type="submit" class="pc-btn pc-btn--primary"><?= $this->e($this->t('admin.settings.reading.enregistrer_reglages')) ?></button>
        </div>
    </form>
</div>

<?php $this->stop(); ?>
