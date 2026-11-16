<?php

declare(strict_types=1);

/**
 * Permalinks & 301 redirects view (`GET /admin/settings/permalinks`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $postStructure
 * @var string $categoryBase
 * @var string $tagBase
 * @var list<array{id: int, source_path: string, target_path: string, status_code: int, hits: int, last_hit_at: ?string, created_at: string}> $redirects
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => 'Permaliens',
    'subtitle' => 'Configurez la structure des URLs publiques et gérez les redirections 301 automatiques ou manuelles.',
    'breadcrumbs' => $breadcrumbs,
])) ?>

<div class="pc-stack pc-stack--lg pc-form-constrained">
    <form method="post" action="/admin/settings/permalinks" class="pc-card">
        <?= $this->raw($this->csrfField()) ?>
        <div class="pc-card__header">
            <h2 class="pc-card__title">Structure des permaliens d'articles</h2>
        </div>
        <div class="pc-card__body pc-stack pc-stack--md">
            <fieldset class="pc-fieldset pc-stack pc-stack--sm">
                <legend class="pc-sr-only">Structure des articles</legend>

                <label class="pc-radio-card">
                    <input type="radio" name="post_structure" value="/blog/{slug}"<?= $postStructure === '/blog/{slug}' ? ' checked' : '' ?>>
                    <span>
                        <strong>Préfixe blog (recommandé)</strong>
                        <small class="pc-text-xs pc-text-muted"><code>/blog/mon-article</code></small>
                    </span>
                </label>

                <label class="pc-radio-card">
                    <input type="radio" name="post_structure" value="/{annee}/{mois}/{slug}"<?= $postStructure === '/{annee}/{mois}/{slug}' ? ' checked' : '' ?>>
                    <span>
                        <strong>Année et mois</strong>
                        <small class="pc-text-xs pc-text-muted"><code>/2026/11/mon-article</code></small>
                    </span>
                </label>

                <label class="pc-radio-card">
                    <input type="radio" name="post_structure" value="/{slug}"<?= $postStructure === '/{slug}' ? ' checked' : '' ?>>
                    <span>
                        <strong>Titre court à la racine</strong>
                        <small class="pc-text-xs pc-text-muted"><code>/mon-article</code></small>
                    </span>
                </label>
            </fieldset>

            <div class="pc-grid-2">
                <div class="pc-field">
                    <label class="pc-label" for="pc-perm-cat-base">Préfixe des catégories</label>
                    <input id="pc-perm-cat-base" type="text" name="category_base" value="<?= $this->e($categoryBase) ?>" class="pc-input" required>
                    <p class="pc-text-xs pc-text-muted">Exemple : <code>/<?= $this->e($categoryBase) ?>/actualites</code></p>
                </div>

                <div class="pc-field">
                    <label class="pc-label" for="pc-perm-tag-base">Préfixe des étiquettes</label>
                    <input id="pc-perm-tag-base" type="text" name="tag_base" value="<?= $this->e($tagBase) ?>" class="pc-input" required>
                    <p class="pc-text-xs pc-text-muted">Exemple : <code>/<?= $this->e($tagBase) ?>/prime-cms</code></p>
                </div>
            </div>
        </div>
        <div class="pc-card__footer">
            <button type="submit" class="pc-btn pc-btn--primary">Enregistrer les permaliens</button>
        </div>
    </form>

    <section class="pc-card" aria-labelledby="pc-redirects-heading">
        <header class="pc-card__header">
            <h2 id="pc-redirects-heading" class="pc-card__title">Redirections 301 internes (<?= count($redirects) ?>)</h2>
        </header>
        <div class="pc-card__body pc-stack pc-stack--md">
            <p class="pc-text-sm pc-text-muted">
                Prime CMS crée automatiquement une redirection 301 lorsque le slug ou le parent d'un contenu publié est modifié, en raccourcissant automatiquement les chaînes (A → B → C devient A → C).
            </p>

            <form method="post" action="/admin/settings/permalinks/redirects" class="pc-redirect-add-form">
                <?= $this->raw($this->csrfField()) ?>
                <div class="pc-field">
                    <label class="pc-label" for="pc-redir-source">Ancien chemin</label>
                    <input id="pc-redir-source" type="text" name="source_path" class="pc-input" placeholder="/ancien-chemin" required>
                </div>
                <div class="pc-field">
                    <label class="pc-label" for="pc-redir-target">Nouveau chemin interne</label>
                    <input id="pc-redir-target" type="text" name="target_path" class="pc-input" placeholder="/nouveau-chemin" required>
                </div>
                <button type="submit" class="pc-btn pc-btn--secondary">Ajouter</button>
            </form>

            <?php if ($redirects === []): ?>
                <p class="pc-text-sm pc-text-muted">Aucune redirection enregistrée pour le moment.</p>
            <?php else: ?>
                <div class="pc-table-wrap">
                    <table class="pc-table">
                        <thead>
                            <tr>
                                <th>Source</th>
                                <th>Cible</th>
                                <th>Code</th>
                                <th>Visites</th>
                                <th class="pc-table__actions-col">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($redirects as $rule): ?>
                                <tr>
                                    <td><code><?= $this->e($rule['source_path']) ?></code></td>
                                    <td><code><?= $this->e($rule['target_path']) ?></code></td>
                                    <td><span class="pc-badge pc-badge--neutral"><?= $this->e((string) $rule['status_code']) ?></span></td>
                                    <td><?= $this->e((string) $rule['hits']) ?></td>
                                    <td class="pc-table__actions-col">
                                        <form method="post" action="/admin/settings/permalinks/redirects/<?= $this->e((string) $rule['id']) ?>/delete">
                                            <?= $this->raw($this->csrfField()) ?>
                                            <button type="submit" class="pc-btn pc-btn--ghost pc-btn--danger pc-btn--sm">Supprimer</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php $this->stop(); ?>
