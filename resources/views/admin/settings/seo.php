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
    'title' => 'Référencement (SEO)',
    'subtitle' => 'Modèle de titre, méta-description par défaut, image Open Graph, sitemap XML et robots.txt.',
    'breadcrumbs' => $breadcrumbs,
])) ?>

<div class="pc-stack pc-stack--lg pc-form-constrained">
    <form method="post" action="/admin/settings/seo" class="pc-card">
        <?= $this->raw($this->csrfField()) ?>
        <div class="pc-card__body pc-stack pc-stack--md">
            <div class="pc-grid-2">
                <div class="pc-field">
                    <label class="pc-label" for="pc-seo-template">Modèle de balise &lt;title&gt;</label>
                    <input id="pc-seo-template" type="text" name="title_template" value="<?= $this->e($titleTemplate) ?>" class="pc-input" required>
                    <p class="pc-text-xs pc-text-muted">Variables disponibles : <code>{title}</code>, <code>{sep}</code>, <code>{site_name}</code>.</p>
                </div>

                <div class="pc-field">
                    <label class="pc-label" for="pc-seo-sep">Séparateur</label>
                    <input id="pc-seo-sep" type="text" name="separator" value="<?= $this->e($separator) ?>" maxlength="10" class="pc-input pc-input--narrow" required>
                </div>
            </div>

            <div class="pc-field">
                <label class="pc-label" for="pc-seo-default-desc">Méta-description par défaut</label>
                <textarea id="pc-seo-default-desc" name="default_description" rows="3" maxlength="320" class="pc-textarea"><?= $this->e($defaultDescription) ?></textarea>
                <p class="pc-text-xs pc-text-muted">Utilisée lorsqu'une page ou une archive ne définit pas sa propre description (idéalement ≤ 160 caractères).</p>
            </div>

            <div class="pc-field">
                <label class="pc-label" for="pc-seo-default-img">Image de partage par défaut (ID média Open Graph / Twitter)</label>
                <input
                    id="pc-seo-default-img"
                    type="number"
                    min="0"
                    name="default_image_id"
                    value="<?= $defaultImageId !== null ? $this->e((string) $defaultImageId) : '' ?>"
                    class="pc-input pc-input--narrow"
                    placeholder="Aucun"
                >
                <?php if ($defaultImageUrl !== null): ?>
                    <img src="<?= $this->e($defaultImageUrl) ?>" alt="Image de partage par défaut" class="pc-customizer__thumb">
                <?php endif; ?>
            </div>

            <label class="pc-checkbox">
                <input type="checkbox" name="global_noindex" value="1"<?= $globalNoindex ? ' checked' : '' ?>>
                <span><strong>Demander aux moteurs de recherche de ne pas indexer le site</strong> (émet <code>noindex, nofollow</code> partout et <code>Disallow: /</code> dans <code>robots.txt</code>).</span>
            </label>

            <div class="pc-field">
                <label class="pc-label" for="pc-seo-robots">Contenu personnalisé de <code>robots.txt</code> (optionnel)</label>
                <textarea id="pc-seo-robots" name="robots_custom" rows="5" class="pc-textarea pc-text-mono" placeholder="Laissez vide pour utiliser les règles recommandées par Prime CMS."><?= $this->e($robotsCustom) ?></textarea>
                <p class="pc-text-xs pc-text-muted">
                    Liens générés dynamiquement :
                    <a href="/sitemap.xml" target="_blank" rel="noopener">/sitemap.xml</a> ·
                    <a href="/robots.txt" target="_blank" rel="noopener">/robots.txt</a>
                    (un fichier physique <code>public/robots.txt</code> reste prioritaire s'il existe).
                </p>
            </div>
        </div>

        <div class="pc-card__footer">
            <button type="submit" class="pc-btn pc-btn--primary">Enregistrer les réglages SEO</button>
        </div>
    </form>
</div>

<?php $this->stop(); ?>
