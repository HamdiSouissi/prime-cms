<?php

declare(strict_types=1);

/**
 * Shared Create / Edit content view powered by Prime Editor.
 *
 * @var \Prime\View\TemplateContext $this
 * @var \Prime\Content\ContentType $contentType
 * @var \Prime\Content\Entry|null $entry
 * @var bool $isEdit
 * @var array{title: string, slug: string, excerpt: string, content_json: string, status: string, published_at: string, author_id: int, parent_id: int, menu_order: int} $form
 * @var array<string, mixed> $metaValues
 * @var array<string, string> $relatedEntryLabels
 * @var list<\Prime\Content\Fields\FieldGroup> $mainFieldGroups
 * @var list<\Prime\Content\Fields\FieldGroup> $sidebarFieldGroups
 * @var list<array{taxonomy: \Prime\Content\Taxonomy, terms: list<\Prime\Content\Term>, flatValue: string}> $taxonomyPanels
 * @var list<int> $selectedTermIds
 * @var list<array{id: int, title: string, depth: int}> $parentOptions
 * @var array<int, \Prime\Auth\User> $authorsMap
 * @var list<\Prime\Content\Revision> $recentRevisions
 * @var array<string, string> $errors
 * @var bool $canPublish
 * @var bool $canEditOthers
 * @var bool $canDelete
 * @var bool $isPendingLocked
 * @var string $editorConfigJson
 */
$this->layout('admin::layouts/app');

$isPendingLocked = !empty($isPendingLocked);
$baseUrl = $contentType->adminListUrl();
$formAction = $isEdit && $entry !== null ? sprintf('%s/%d', $baseUrl, $entry->id) : $baseUrl;
$primaryLabel = !$canPublish
    ? 'Soumettre pour relecture'
    : ($isEdit ? 'Mettre à jour' : 'Publier');
$primaryTargetStatus = !$canPublish
    ? 'pending'
    : (!$isEdit ? 'published' : '');
$formattedDate = $form['published_at'] !== '' ? str_replace(' ', 'T', substr($form['published_at'], 0, 16)) : '';
?>
<?php $this->start('content'); ?>

<?php if ($isPendingLocked && $entry !== null): ?>
    <div class="pc-alert pc-alert--info" role="status" data-prime-pending-locked-banner style="margin-bottom:16px;">
        <span class="pc-alert__icon" aria-hidden="true"><?= $this->raw($this->icon('clock', 18)) ?></span>
        <div class="pc-alert__body">
            <p class="pc-alert__title">Contenu soumis pour relecture — modification verrouillée</p>
            <p class="pc-alert__text">Ce contenu est actuellement en attente de validation éditoriale. Vous ne pouvez plus le modifier tant qu’un éditeur ou un administrateur ne l’a pas examiné.</p>
        </div>
    </div>

    <div class="pc-editor-topbar">
        <div class="pc-editor-topbar__left">
            <a href="<?= $this->e($baseUrl) ?>" class="pc-btn pc-btn--ghost pc-btn--sm">
                <?= $this->raw($this->icon('chevron-left', 16)) ?>
                <span><?= $this->e($contentType->label('plural')) ?></span>
            </a>
            <?= $this->raw($this->insert('admin::components/badge', [
                'label' => 'En attente de relecture',
                'variant' => 'warning',
                'dot' => true,
            ])) ?>
        </div>
        <div class="pc-editor-topbar__right">
            <a href="<?= $this->e($entry->previewUrl()) ?>" target="_blank" rel="noopener" class="pc-btn pc-btn--primary pc-btn--sm">
                <?= $this->raw($this->icon('eye', 16)) ?>
                <span>Voir l’aperçu</span>
            </a>
        </div>
    </div>

    <div class="pc-editor-sheet">
        <h1 style="margin-bottom:12px;"><?= $this->e($entry->title) ?></h1>
        <p class="pc-text-sm pc-text-muted" style="margin-bottom:18px;">Permalien : <code>/<?= $this->e($entry->slug) ?></code> · Mis à jour le <?= $this->e($entry->updatedAt) ?> UTC</p>
        <div class="pc-preview-body">
            <?= $this->raw($entry->contentHtml !== '' ? $entry->contentHtml : '<p class="pc-text-muted">Aucun contenu saisi.</p>') ?>
        </div>
    </div>
<?php else: ?>

<form method="post" action="<?= $this->e($formAction) ?>" id="pc-content-editor-form">
    <?= $this->raw($this->csrfField()) ?>

    <div class="pc-alert pc-alert--danger" role="alert" data-prime-lock-lost-banner hidden style="margin-bottom:14px;">
        <span class="pc-alert__icon" aria-hidden="true"><?= $this->raw($this->icon('lock', 18)) ?></span>
        <div class="pc-alert__body">
            <p class="pc-alert__title">Verrou d’édition perdu</p>
            <p class="pc-alert__text" data-prime-lock-lost-user>Un autre utilisateur a pris le contrôle de ce contenu.</p>
        </div>
    </div>

    <div class="pc-editor-topbar">
        <div class="pc-editor-topbar__left">
            <a href="<?= $this->e($baseUrl) ?>" class="pc-btn pc-btn--ghost pc-btn--sm">
                <?= $this->raw($this->icon('chevron-left', 16)) ?>
                <span><?= $this->e($contentType->label('plural')) ?></span>
            </a>

            <button type="button" class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm" data-prime-editor-undo aria-label="Annuler (Ctrl+Z)" disabled>
                <?= $this->raw($this->icon('undo', 16)) ?>
            </button>
            <button type="button" class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm" data-prime-editor-redo aria-label="Rétablir (Ctrl+Shift+Z)" disabled>
                <?= $this->raw($this->icon('redo', 16)) ?>
            </button>

            <span class="pc-editor-stats" data-prime-editor-stats>0 mot</span>
            <span class="pc-text-xs pc-text-muted" data-prime-autosave-status></span>
        </div>

        <div class="pc-editor-topbar__right">
            <?php if ($isEdit && $entry !== null): ?>
                <a href="<?= $this->e($entry->previewUrl()) ?>" target="_blank" rel="noopener" class="pc-btn pc-btn--secondary pc-btn--sm">
                    <?= $this->raw($this->icon('eye', 16)) ?>
                    <span>Aperçu</span>
                </a>
                <a href="<?= $this->e(sprintf('%s/%d/revisions', $baseUrl, $entry->id)) ?>" class="pc-btn pc-btn--ghost pc-btn--sm">
                    <?= $this->raw($this->icon('history', 16)) ?>
                    <span>Révisions</span>
                </a>
            <?php endif; ?>

            <button
                type="submit"
                class="pc-btn pc-btn--secondary pc-btn--sm"
                data-prime-secondary-save
                data-prime-submit-status="draft"
            >
                <?= $this->raw($this->icon('save', 16)) ?>
                <span>Enregistrer le brouillon</span>
            </button>

            <button
                type="submit"
                class="pc-btn pc-btn--primary pc-btn--sm"
                data-prime-primary-save
                <?php if ($primaryTargetStatus !== ''): ?>data-prime-submit-status="<?= $this->e($primaryTargetStatus) ?>"<?php endif; ?>
            >
                <?= $this->raw($this->icon('check', 16)) ?>
                <span><?= $this->e($primaryLabel) ?></span>
            </button>

            <button
                type="button"
                class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm"
                data-prime-editor-sidebar-toggle
                aria-expanded="true"
                aria-label="Afficher ou masquer les paramètres"
            >
                <?= $this->raw($this->icon('settings', 16)) ?>
            </button>
        </div>
    </div>

    <?php if ($errors !== []): ?>
        <div class="pc-alert pc-alert--danger" role="alert" style="margin-bottom:16px;">
            <span class="pc-alert__icon" aria-hidden="true"><?= $this->raw($this->icon('alert-circle', 18)) ?></span>
            <div class="pc-alert__body">
                <p class="pc-alert__title">Le formulaire contient des erreurs</p>
                <ul style="margin:6px 0 0 18px;padding:0;">
                    <?php foreach ($errors as $fieldErr): ?>
                        <li><?= $this->e($fieldErr) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="pc-editor-layout" data-prime-editor-layout>
        <div class="pc-editor-sidebar-backdrop" data-prime-editor-sidebar-backdrop hidden></div>

        <div class="pc-editor-main">
            <div class="pc-editor-sheet">
                <label class="pc-sr-only" for="pc-entry-title">Titre</label>
                <input
                    id="pc-entry-title"
                    type="text"
                    name="title"
                    value="<?= $this->e($form['title']) ?>"
                    class="pc-editor-title-input"
                    placeholder="Titre de votre <?= $this->e(mb_strtolower($contentType->label('singular'))) ?>…"
                    required
                    data-prime-content-title
                >
                <?php if (isset($errors['title'])): ?>
                    <p class="pc-field__error" style="margin:-10px 0 14px;"><?= $this->e($errors['title']) ?></p>
                <?php endif; ?>

                <div class="pc-ed-root" data-prime-editor>
                    <script type="application/json" data-prime-editor-config><?= $this->raw($editorConfigJson) ?></script>
                    <textarea name="content_json" hidden data-prime-editor-input><?= $this->e($form['content_json']) ?></textarea>
                    <div class="pc-ed-canvas" data-prime-editor-canvas></div>
                    <div class="pc-ed-add-bar">
                        <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-editor-add>
                            <?= $this->raw($this->icon('plus', 16)) ?>
                            <span>Ajouter un bloc</span>
                        </button>
                        <span class="pc-text-xs pc-text-muted">Astuce : tapez <code>/</code> dans un paragraphe pour insérer un bloc rapidement.</span>
                    </div>
                    <div class="pc-sr-only" aria-live="polite" data-prime-editor-live></div>
                </div>
            </div>

            <?php foreach ($mainFieldGroups as $group): ?>
                <?= $this->raw($this->insert('admin::content/partials/field-group', [
                    'group' => $group,
                    'metaValues' => $metaValues,
                    'relatedEntryLabels' => $relatedEntryLabels,
                    'fieldMediaItems' => $fieldMediaItems ?? [],
                    'errors' => $errors,
                ])) ?>
            <?php endforeach; ?>
        </div>

        <aside class="pc-editor-sidebar" aria-label="Paramètres du contenu">
            <section class="pc-inspector-card">
                <h2 class="pc-inspector-card__title">
                    <span>Publication</span>
                    <?= $this->raw($this->icon('globe', 16)) ?>
                </h2>

                <div class="pc-stack pc-stack--sm">
                    <div class="pc-field">
                        <label class="pc-label" for="pc-entry-status">Statut</label>
                        <select id="pc-entry-status" name="status" class="pc-select" data-prime-status-select>
                            <option value="draft"<?= $form['status'] === 'draft' ? ' selected' : '' ?>>Brouillon</option>
                            <option value="pending"<?= $form['status'] === 'pending' ? ' selected' : '' ?>>En attente de relecture</option>
                            <?php if ($canPublish || $form['status'] === 'published'): ?>
                                <option value="published"<?= $form['status'] === 'published' ? ' selected' : '' ?>>Publié</option>
                            <?php endif; ?>
                            <?php if ($canPublish || $form['status'] === 'scheduled'): ?>
                                <option value="scheduled"<?= $form['status'] === 'scheduled' ? ' selected' : '' ?>>Planifié</option>
                            <?php endif; ?>
                            <?php if ($canPublish || $form['status'] === 'private'): ?>
                                <option value="private"<?= $form['status'] === 'private' ? ' selected' : '' ?>>Privé</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="pc-field" data-prime-publish-date-wrap>
                        <label class="pc-label" for="pc-entry-published-at">Date de publication (UTC)</label>
                        <input
                            id="pc-entry-published-at"
                            type="datetime-local"
                            name="published_at"
                            value="<?= $this->e($formattedDate) ?>"
                            class="pc-input"
                        >
                        <?php if (isset($errors['published_at'])): ?>
                            <span class="pc-field__error"><?= $this->e($errors['published_at']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="pc-field">
                        <label class="pc-label" for="pc-entry-slug">Permalien (slug)</label>
                        <input
                            id="pc-entry-slug"
                            type="text"
                            name="slug"
                            value="<?= $this->e($form['slug']) ?>"
                            class="pc-input pc-mono"
                            placeholder="genere-automatiquement"
                            data-prime-content-slug
                        >
                        <span class="pc-text-xs pc-text-muted">Aperçu : /<span data-prime-slug-preview><?= $this->e($form['slug'] !== '' ? $form['slug'] : 'nouveau-contenu') ?></span></span>
                        <?php if (isset($errors['slug'])): ?>
                            <span class="pc-field__error"><?= $this->e($errors['slug']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($canEditOthers && $authorsMap !== []): ?>
                        <div class="pc-field">
                            <label class="pc-label" for="pc-entry-author">Auteur</label>
                            <select id="pc-entry-author" name="author_id" class="pc-select">
                                <?php foreach ($authorsMap as $uid => $author): ?>
                                    <option value="<?= $this->e((string) $uid) ?>"<?= $form['author_id'] === $uid ? ' selected' : '' ?>>
                                        <?= $this->e($author->displayName) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if ($contentType->hierarchical): ?>
                        <div class="pc-field">
                            <label class="pc-label" for="pc-entry-parent">Parent</label>
                            <select id="pc-entry-parent" name="parent_id" class="pc-select">
                                <option value="0">Aucun (racine)</option>
                                <?php foreach ($parentOptions as $parentOpt): ?>
                                    <option value="<?= $this->e((string) $parentOpt['entry']->id) ?>"<?= $form['parent_id'] === $parentOpt['entry']->id ? ' selected' : '' ?>>
                                        <?= $this->e(str_repeat('— ', $parentOpt['depth']) . $parentOpt['entry']->title) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="pc-field">
                            <label class="pc-label" for="pc-entry-menu-order">Ordre</label>
                            <input id="pc-entry-menu-order" type="number" min="0" name="menu_order" value="<?= $this->e((string) $form['menu_order']) ?>" class="pc-input">
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($contentType->supports('thumbnail')): ?>
                <section class="pc-inspector-card">
                    <h2 class="pc-inspector-card__title">
                        <span>Image à la une</span>
                        <?= $this->raw($this->icon('image', 16)) ?>
                    </h2>
                    <div
                        class="pc-media-field"
                        data-prime-media-field
                        data-prime-media-multiple="0"
                        data-prime-media-kinds="image"
                        data-prime-media-title="Choisir une image à la une"
                    >
                        <script type="application/json" data-prime-media-initial><?= $this->raw((string) ($featuredMediaJson ?? '[]')) ?></script>
                        <input
                            type="hidden"
                            name="featured_media_id"
                            value="<?= $this->e($featuredMediaId !== null ? (string) $featuredMediaId : '') ?>"
                            data-prime-media-input
                        >
                        <div class="pc-media-field__preview" data-prime-media-preview hidden></div>
                        <div class="pc-media-field__buttons">
                            <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-media-choose>
                                <?= $this->raw($this->icon('image', 14)) ?>
                                <span data-prime-media-choose-label>Choisir une image</span>
                            </button>
                            <button type="button" class="pc-btn pc-btn--ghost pc-btn--sm" data-prime-media-clear hidden>Retirer</button>
                        </div>
                        <?php if (isset($errors['featured_media_id'])): ?>
                            <span class="pc-field__error"><?= $this->e($errors['featured_media_id']) ?></span>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($contentType->supports('excerpt')): ?>
                <section class="pc-inspector-card">
                    <h2 class="pc-inspector-card__title">Extrait</h2>
                    <div class="pc-field">
                        <label class="pc-sr-only" for="pc-entry-excerpt">Extrait</label>
                        <textarea
                            id="pc-entry-excerpt"
                            name="excerpt"
                            rows="3"
                            class="pc-textarea"
                            placeholder="Résumé court affiché dans les listes (optionnel)…"
                        ><?= $this->e($form['excerpt']) ?></textarea>
                    </div>
                </section>
            <?php endif; ?>

            <?php foreach ($taxonomyPanels as $panel): ?>
                <?php
                /** @var \Prime\Content\Taxonomy $tax */
                $tax = $panel['taxonomy'];
                /** @var list<\Prime\Content\Term> $taxTerms */
                $taxTerms = $panel['terms'];
                ?>
                <section class="pc-inspector-card" data-prime-taxonomy-panel>
                    <h2 class="pc-inspector-card__title">
                        <span><?= $this->e($tax->label('plural')) ?></span>
                        <a href="<?= $this->e($tax->adminUrl()) ?>" class="pc-text-xs">Gérer</a>
                    </h2>

                    <?php if ($tax->hierarchical): ?>
                        <div class="pc-term-checklist" data-prime-term-list>
                            <?php if ($taxTerms === []): ?>
                                <p class="pc-text-xs pc-text-muted">Aucun terme créé pour le moment.</p>
                            <?php else: ?>
                                <?php foreach ($taxTerms as $term): ?>
                                    <label class="pc-term-check">
                                        <input
                                            type="checkbox"
                                            name="terms[<?= $this->e($tax->name) ?>][]"
                                            value="<?= $this->e((string) $term->id) ?>"
                                            <?= in_array($term->id, $selectedTermIds, true) ? ' checked' : '' ?>
                                        >
                                        <span><?= $this->e($term->name) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div
                            class="pc-quick-term-row"
                            data-prime-quick-term
                            data-prime-endpoint="<?= $this->e($tax->adminUrl()) ?>"
                            data-prime-taxonomy="<?= $this->e($tax->name) ?>"
                        >
                            <input
                                type="text"
                                class="pc-input"
                                placeholder="<?= $this->e($tax->label('add_new')) ?>"
                                data-prime-quick-term-input
                            >
                            <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-quick-term-submit>Ajouter</button>
                        </div>
                    <?php else: ?>
                        <div class="pc-field">
                            <label class="pc-sr-only" for="pc-flat-tax-<?= $this->e($tax->name) ?>"><?= $this->e($tax->label('plural')) ?></label>
                            <input
                                id="pc-flat-tax-<?= $this->e($tax->name) ?>"
                                type="text"
                                name="new_terms[<?= $this->e($tax->name) ?>]"
                                value="<?= $this->e($panel['flatValue']) ?>"
                                class="pc-input"
                                placeholder="Séparez par des virgules (ex. php, cms, guide)"
                            >
                            <span class="pc-text-xs pc-text-muted">Les nouveaux termes sont créés automatiquement.</span>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <?php foreach ($sidebarFieldGroups as $group): ?>
                <?= $this->raw($this->insert('admin::content/partials/field-group', [
                    'group' => $group,
                    'metaValues' => $metaValues,
                    'relatedEntryLabels' => $relatedEntryLabels,
                    'fieldMediaItems' => $fieldMediaItems ?? [],
                    'errors' => $errors,
                ])) ?>
            <?php endforeach; ?>

            <?php if ($isEdit && $entry !== null && $recentRevisions !== []): ?>
                <section class="pc-inspector-card">
                    <h2 class="pc-inspector-card__title">
                        <span>Révisions récentes</span>
                        <a href="<?= $this->e(sprintf('%s/%d/revisions', $baseUrl, $entry->id)) ?>" class="pc-text-xs">Comparer</a>
                    </h2>
                    <ul class="pc-revisions-list">
                        <?php foreach ($recentRevisions as $rev): ?>
                            <li>
                                <span><?= $this->e($rev->createdAt) ?><?= $rev->isAutosave ? ' (auto)' : '' ?></span>
                                <a href="<?= $this->e(sprintf('%s/%d/revisions?compare=%d', $baseUrl, $entry->id, $rev->id)) ?>">Voir</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php if ($isEdit && $entry !== null && $canDelete): ?>
                <section class="pc-inspector-card">
                    <h2 class="pc-inspector-card__title">Actions</h2>
                    <button type="submit" form="pc-editor-trash-form" class="pc-btn pc-btn--danger-outline pc-btn--sm" style="width:100%;">
                        <?= $this->raw($this->icon('trash', 16)) ?>
                        <span>Mettre à la corbeille</span>
                    </button>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</form>

<?php if ($isEdit && $entry !== null && $canDelete): ?>
    <form id="pc-editor-trash-form" method="post" action="<?= $this->e(sprintf('%s/%d/trash', $baseUrl, $entry->id)) ?>" hidden>
        <?= $this->raw($this->csrfField()) ?>
    </form>
<?php endif; ?>

<?php endif; ?>

<?php $this->stop(); ?>
