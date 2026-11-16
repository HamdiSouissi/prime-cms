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
    'title' => 'Lecture',
    'subtitle' => 'Choisissez ce qu\'affiche la page d\'accueil et le nombre d\'articles par page.',
    'breadcrumbs' => $breadcrumbs,
])) ?>

<div class="pc-form-constrained">
    <form method="post" action="/admin/settings/reading" class="pc-card">
        <?= $this->raw($this->csrfField()) ?>
        <div class="pc-card__body pc-stack pc-stack--lg">
            <fieldset class="pc-fieldset pc-stack pc-stack--sm">
                <legend class="pc-label">Affichage de la page d'accueil</legend>

                <label class="pc-radio-card">
                    <input type="radio" name="front_mode" value="posts"<?= $frontMode === 'posts' ? ' checked' : '' ?>>
                    <span>
                        <strong>Les derniers articles</strong>
                        <small class="pc-text-xs pc-text-muted">Affiche le flux éditorial des derniers articles publiés directement sur <code>/</code>.</small>
                    </span>
                </label>

                <label class="pc-radio-card">
                    <input type="radio" name="front_mode" value="page"<?= $frontMode === 'page' ? ' checked' : '' ?>>
                    <span>
                        <strong>Une page statique</strong>
                        <small class="pc-text-xs pc-text-muted">Affiche une page précise sur <code>/</code> et optionnellement une autre page pour la liste des articles.</small>
                    </span>
                </label>
            </fieldset>

            <div class="pc-grid-2">
                <div class="pc-field">
                    <label class="pc-label" for="pc-reading-front-page">Page d'accueil statique</label>
                    <select id="pc-reading-front-page" name="front_page_id" class="pc-select">
                        <option value="">— Sélectionner une page —</option>
                        <?php foreach ($pages as $page): ?>
                            <option value="<?= $this->e((string) $page->id) ?>"<?= $frontPageId === $page->id ? ' selected' : '' ?>>
                                <?= $this->e($page->title) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pc-field">
                    <label class="pc-label" for="pc-reading-posts-page">Page des articles (blog)</label>
                    <select id="pc-reading-posts-page" name="posts_page_id" class="pc-select">
                        <option value="">— Par défaut (/blog) —</option>
                        <?php foreach ($pages as $page): ?>
                            <option value="<?= $this->e((string) $page->id) ?>"<?= $postsPageId === $page->id ? ' selected' : '' ?>>
                                <?= $this->e($page->title) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="pc-field">
                <label class="pc-label" for="pc-reading-per-page">Nombre d'articles par page</label>
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
                <p class="pc-text-xs pc-text-muted">Appliqué au blog, aux archives de catégories, d'étiquettes, d'auteurs et aux résultats de recherche.</p>
            </div>
        </div>

        <div class="pc-card__footer">
            <button type="submit" class="pc-btn pc-btn--primary">Enregistrer les réglages</button>
        </div>
    </form>
</div>

<?php $this->stop(); ?>
