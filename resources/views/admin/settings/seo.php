<?php

declare(strict_types=1);

/**
 * Global SEO settings view (`GET /admin/settings/seo`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $separator
 * @var string $titleTemplate
 * @var string $defaultDescription
 * @var ?int $defaultImageId
 * @var ?string $defaultImageUrl
 * @var bool $globalNoindex
 * @var string $robotsCustom
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $this->t('admin.view_settings_seo.referencement_seo'),
    'subtitle' => $this->t('admin.view_settings_seo.modele_de_titre_meta_description_par_defaut_image_open_graph'),
    'breadcrumbs' => $breadcrumbs,
])) ?>

<div class="pc-stack pc-stack--lg pc-form-constrained">
    <form method="post" action="/admin/settings/seo" class="pc-card">
        <?= $this->raw($this->csrfField()) ?>
        <div class="pc-card__body pc-stack pc-stack--md">
            <div class="pc-grid-2">
                <div class="pc-field">
                    <label class="pc-label" for="pc-seo-template"><?= $this->e($this->t('admin.settings.seo.modele_balise_lt_title_gt')) ?></label>
                    <input id="pc-seo-template" type="text" name="title_template" value="<?= $this->e($titleTemplate) ?>" class="pc-input" required>
                    <p class="pc-text-xs pc-text-muted"><?= t('admin.settings.seo.variables') ?></p>
                </div>

                <div class="pc-field">
                    <label class="pc-label" for="pc-seo-sep"><?= $this->e($this->t('admin.settings.seo.separateur')) ?></label>
                    <input id="pc-seo-sep" type="text" name="separator" value="<?= $this->e($separator) ?>" maxlength="10" class="pc-input pc-input--narrow" required>
                </div>
            </div>

            <div class="pc-field">
                <label class="pc-label" for="pc-seo-default-desc"><?= $this->e($this->t('admin.settings.seo.meta_description_defaut')) ?></label>
                <textarea id="pc-seo-default-desc" name="default_description" rows="3" maxlength="320" class="pc-textarea"><?= $this->e($defaultDescription) ?></textarea>
                <p class="pc-text-xs pc-text-muted"><?= $this->e($this->t('admin.settings.seo.utilisee_lorsqu_page_archive_ne')) ?></p>
            </div>

            <div class="pc-field">
                <label class="pc-label" for="pc-seo-default-img"><?= $this->e($this->t('admin.settings.seo.image_partage_defaut_id_media')) ?></label>
                <input
                    id="pc-seo-default-img"
                    type="number"
                    min="0"
                    name="default_image_id"
                    value="<?= $defaultImageId !== null ? $this->e((string) $defaultImageId) : '' ?>"
                    class="pc-input pc-input--narrow"
                    placeholder="<?= $this->e($this->t('admin.settings.seo.aucun')) ?>"
                >
                <?php if ($defaultImageUrl !== null): ?>
                    <img src="<?= $this->e($defaultImageUrl) ?>" alt="<?= t('admin.attr.image_de_partage_par_defaut') ?>" class="pc-customizer__thumb">
                <?php endif; ?>
            </div>

            <label class="pc-checkbox">
                <input type="checkbox" name="global_noindex" value="1"<?= $globalNoindex ? ' checked' : '' ?>>
                <span><?= $this->raw($this->t('admin.settings.seo.demander_moteurs_pas_indexer')) ?></span>
            </label>

            <div class="pc-field">
                <label class="pc-label" for="pc-seo-robots"><?= $this->raw($this->t('admin.settings.seo.contenu_personnalise_robots')) ?></label>
                <textarea id="pc-seo-robots" name="robots_custom" rows="5" class="pc-textarea pc-text-mono" placeholder="<?= t('admin.attr.laissez_vide_pour_utiliser_les_regles_recommandees_par_prime') ?>"><?= $this->e($robotsCustom) ?></textarea>
                <p class="pc-text-xs pc-text-muted">
                    <?= $this->e($this->t('admin.settings.seo.liens_generes_dynamiquement')) ?>
                    <a href="/sitemap.xml" target="_blank" rel="noopener">/sitemap.xml</a> ·
                    <a href="/robots.txt" target="_blank" rel="noopener">/robots.txt</a>
                    <?= $this->raw($this->t('admin.settings.seo.fichier_physique_prioritaire')) ?>
                </p>
            </div>
        </div>

        <div class="pc-card__footer">
            <button type="submit" class="pc-btn pc-btn--primary"><?= $this->e($this->t('admin.settings.seo.enregistrer_reglages_seo')) ?></button>
        </div>
    </form>
</div>

<?php $this->stop(); ?>
