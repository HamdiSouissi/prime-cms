<?php

declare(strict_types=1);

/**
 * Navigation menus editor view (`GET /admin/appearance/menus`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var list<array{id: int, name: string, slug: string, location: ?string, item_count: int}> $allMenus
 * @var ?array{id: int, name: string, slug: string, location: ?string} $currentMenu
 * @var string $itemsJson
 * @var array<string, string> $locations
 * @var list<\Prime\Content\Entry> $pages
 * @var list<\Prime\Content\Entry> $posts
 * @var list<\Prime\Content\Term> $categories
 * @var list<\Prime\Content\Term> $tags
 * @var list<array{type: string, label: string}> $archives
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => 'Menus de navigation',
    'subtitle' => $this->t('admin.view_appearance_menus.organisez_les_liens_de_votre_site_jusqu_a_3_niveaux_de_profo'),
    'breadcrumbs' => $breadcrumbs,
])) ?>

<div class="pc-stack pc-stack--lg" data-prime-menus-page>
    <!-- Menu switcher & create bar -->
    <div class="pc-card">
        <div class="pc-card__body pc-menus-bar">
            <?php if ($allMenus !== []): ?>
                <form method="get" action="/admin/appearance/menus" class="pc-menus-bar__select">
                    <label class="pc-label" for="pc-menu-select"><?= $this->e($this->t('admin.appearance.menus.menu_selectionne')) ?></label>
                    <div class="pc-menus-bar__inline">
                        <select id="pc-menu-select" name="menu" class="pc-select">
                            <?php foreach ($allMenus as $m): ?>
                                <option value="<?= $this->e((string) $m['id']) ?>"<?= ($currentMenu !== null && $currentMenu['id'] === $m['id']) ? ' selected' : '' ?>>
                                    <?= $this->e($m['name']) ?><?= $m['location'] !== null ? ' (' . $this->e($locations[$m['location']] ?? $m['location']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="pc-btn pc-btn--secondary pc-btn--sm">Ouvrir</button>
                    </div>
                </form>
            <?php endif; ?>

            <form method="post" action="/admin/appearance/menus/create" class="pc-menus-bar__create">
                <?= $this->raw($this->csrfField()) ?>
                <label class="pc-label" for="pc-menu-new-name"><?= $this->e($this->t('admin.appearance.menus.creer_nouveau_menu')) ?></label>
                <div class="pc-menus-bar__inline">
                    <input id="pc-menu-new-name" type="text" name="name" class="pc-input" placeholder="Ex. Navigation principale" required>
                    <button type="submit" class="pc-btn pc-btn--secondary pc-btn--sm"><?= $this->e($this->t('core.actions.create')) ?></button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($currentMenu === null): ?>
        <div class="pc-empty-state">
            <p class="pc-empty-state__title"><?= $this->e($this->t('admin.appearance.menus.aucun_menu_cree')) ?></p>
            <p class="pc-empty-state__desc"><?= $this->e($this->t('admin.appearance.menus.creez_premier_menu_ci_dessus')) ?></p>
        </div>
    <?php else: ?>
        <div class="pc-menus-layout">
            <!-- Left column: Source pickers -->
            <aside class="pc-stack pc-stack--md" aria-label="<?= t('admin.attr.ajouter_des_elements_au_menu') ?>">
                <details class="pc-card" open>
                    <summary class="pc-card__header pc-menus-accordion">Pages</summary>
                    <div class="pc-card__body pc-stack pc-stack--sm">
                        <?php if ($pages === []): ?>
                            <p class="pc-text-xs pc-text-muted"><?= $this->e($this->t('admin.appearance.menus.aucune_page_publiee')) ?></p>
                        <?php else: ?>
                            <div class="pc-menus-source-list">
                                <?php foreach ($pages as $p): ?>
                                    <label class="pc-checkbox">
                                        <input type="checkbox" data-prime-menu-source="entry" data-prime-source-id="<?= $this->e((string) $p->id) ?>" data-prime-source-label="<?= $this->e($p->title) ?>">
                                        <span><?= $this->e($p->title) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-menu-add-checked="entry"><?= $this->e($this->t('admin.appearance.menus.ajouter_menu')) ?></button>
                        <?php endif; ?>
                    </div>
                </details>

                <details class="pc-card">
                    <summary class="pc-card__header pc-menus-accordion">Articles</summary>
                    <div class="pc-card__body pc-stack pc-stack--sm">
                        <?php if ($posts === []): ?>
                            <p class="pc-text-xs pc-text-muted"><?= $this->e($this->t('admin.appearance.menus.aucun_article_publie')) ?></p>
                        <?php else: ?>
                            <div class="pc-menus-source-list">
                                <?php foreach ($posts as $p): ?>
                                    <label class="pc-checkbox">
                                        <input type="checkbox" data-prime-menu-source="entry" data-prime-source-id="<?= $this->e((string) $p->id) ?>" data-prime-source-label="<?= $this->e($p->title) ?>">
                                        <span><?= $this->e($p->title) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-menu-add-checked="entry"><?= $this->e($this->t('admin.appearance.menus.ajouter_menu')) ?></button>
                        <?php endif; ?>
                    </div>
                </details>

                <details class="pc-card">
                    <summary class="pc-card__header pc-menus-accordion"><?= $this->e($this->t('admin.appearance.menus.categories_amp_etiquettes')) ?></summary>
                    <div class="pc-card__body pc-stack pc-stack--sm">
                        <?php if ($categories === [] && $tags === []): ?>
                            <p class="pc-text-xs pc-text-muted"><?= $this->e($this->t('admin.appearance.menus.aucun_terme_disponible')) ?></p>
                        <?php else: ?>
                            <div class="pc-menus-source-list">
                                <?php foreach ($categories as $t): ?>
                                    <label class="pc-checkbox">
                                        <input type="checkbox" data-prime-menu-source="term" data-prime-source-id="<?= $this->e((string) $t->id) ?>" data-prime-source-label="<?= $this->e($t->name) ?>">
                                        <span><?= $this->e($t->name) ?> <em class="pc-text-xs pc-text-muted">(cat.)</em></span>
                                    </label>
                                <?php endforeach; ?>
                                <?php foreach ($tags as $t): ?>
                                    <label class="pc-checkbox">
                                        <input type="checkbox" data-prime-menu-source="term" data-prime-source-id="<?= $this->e((string) $t->id) ?>" data-prime-source-label="<?= $this->e($t->name) ?>">
                                        <span>#<?= $this->e($t->name) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-menu-add-checked="term"><?= $this->e($this->t('admin.appearance.menus.ajouter_menu')) ?></button>
                        <?php endif; ?>
                    </div>
                </details>

                <details class="pc-card">
                    <summary class="pc-card__header pc-menus-accordion">Archives</summary>
                    <div class="pc-card__body pc-stack pc-stack--sm">
                        <div class="pc-menus-source-list">
                            <?php foreach ($archives as $arch): ?>
                                <label class="pc-checkbox">
                                    <input type="checkbox" data-prime-menu-source="archive" data-prime-source-url="<?= $this->e($arch['type']) ?>" data-prime-source-label="<?= $this->e($arch['label']) ?>">
                                    <span><?= $this->e($arch['label']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-menu-add-checked="archive"><?= $this->e($this->t('admin.appearance.menus.ajouter_menu')) ?></button>
                    </div>
                </details>

                <details class="pc-card" open>
                    <summary class="pc-card__header pc-menus-accordion"><?= $this->e($this->t('admin.appearance.menus.lien_personnalise')) ?></summary>
                    <div class="pc-card__body pc-stack pc-stack--sm">
                        <div class="pc-field">
                            <label class="pc-label" for="pc-menu-custom-label"><?= $this->e($this->t('admin.appearance.menus.libelle')) ?></label>
                            <input id="pc-menu-custom-label" type="text" class="pc-input" placeholder="Ex. Accueil" data-prime-custom-label>
                        </div>
                        <div class="pc-field">
                            <label class="pc-label" for="pc-menu-custom-url">URL</label>
                            <input id="pc-menu-custom-url" type="text" class="pc-input" placeholder="/ ou https://..." data-prime-custom-url>
                        </div>
                        <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-menu-add-custom><?= $this->e($this->t('admin.appearance.menus.ajouter_lien')) ?></button>
                    </div>
                </details>
            </aside>

            <!-- Right column: Menu structure editor -->
            <section class="pc-card" aria-label="<?= t('admin.attr.structure_du_menu') ?>">
                <form
                    method="post"
                    action="/admin/appearance/menus/<?= $this->e((string) $currentMenu['id']) ?>/save"
                    class="pc-card__body pc-stack pc-stack--md"
                    data-prime-menu-editor
                >
                    <?= $this->raw($this->csrfField()) ?>
                    <script type="application/json" data-prime-menu-initial><?= $this->raw($itemsJson) ?></script>
                    <input type="hidden" name="items_json" value="" data-prime-menu-items-input>

                    <div class="pc-menus-meta">
                        <div class="pc-field">
                            <label class="pc-label" for="pc-menu-edit-name"><?= $this->e($this->t('admin.appearance.menus.nom_menu')) ?></label>
                            <input id="pc-menu-edit-name" type="text" name="name" value="<?= $this->e($currentMenu['name']) ?>" class="pc-input" required>
                        </div>
                        <div class="pc-field">
                            <label class="pc-label" for="pc-menu-edit-location"><?= $this->e($this->t('admin.appearance.menus.emplacement_theme')) ?></label>
                            <select id="pc-menu-edit-location" name="location" class="pc-select">
                                <option value=""><?= $this->e($this->t('admin.appearance.menus.aucun_emplacement')) ?></option>
                                <?php foreach ($locations as $locKey => $locLabel): ?>
                                    <option value="<?= $this->e($locKey) ?>"<?= $currentMenu['location'] === $locKey ? ' selected' : '' ?>>
                                        <?= $this->e($locLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div>
                        <h2 class="pc-text-sm pc-font-semibold"><?= $this->e($this->t('admin.appearance.menus.structure_hierarchique_glisser_deposer_fleches')) ?></h2>
                        <div class="pc-menu-tree" data-prime-menu-tree></div>
                        <p class="pc-text-xs pc-text-muted" data-prime-menu-empty hidden>
                            <?= $this->e($this->t('admin.appearance.menus.menu_vide_selectionnez_pages_categories')) ?>
                        </p>
                    </div>

                    <div class="pc-menus-footer">
                        <button type="submit" class="pc-btn pc-btn--primary" data-prime-menu-save><?= $this->e($this->t('admin.appearance.menus.enregistrer_menu')) ?></button>
                    </div>
                </form>

                <div class="pc-card__footer pc-menus-danger-row">
                    <form method="post" action="/admin/appearance/menus/<?= $this->e((string) $currentMenu['id']) ?>/delete">
                        <?= $this->raw($this->csrfField()) ?>
                        <button type="submit" class="pc-btn pc-btn--danger-outline pc-btn--sm" data-prime-confirm="Supprimer définitivement le menu « <?= $this->e($currentMenu['name']) ?> » ?">
                            <?= $this->e($this->t('admin.appearance.menus.supprimer_menu')) ?>
                        </button>
                    </form>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>

<?php $this->stop(); ?>
