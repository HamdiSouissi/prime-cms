<?php

declare(strict_types=1);

/**
 * Live theme customizer view (`GET /admin/appearance/customizer`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var \Prime\Themes\ThemeInfo $activeTheme
 * @var array{sections: array<string, array{label: string, description: string}>, fields: array<string, array<string, mixed>>} $schema
 * @var array<string, mixed> $values
 * @var array{name: string, tagline: string, logo_id: ?int, logo_url: ?string, icon_id: ?int, icon_url: ?string} $siteIdentity
 * @var array<int, \Prime\Media\Media> $optionMedia
 * @var string $previewUrl
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');

$fieldsBySection = [];
foreach ($schema['fields'] as $key => $field) {
    $sec = is_string($field['section'] ?? null) ? $field['section'] : 'general';
    $fieldsBySection[$sec][$key] = $field;
}
?>
<?php $this->start('content'); ?>

<div class="pc-customizer" data-prime-customizer data-prime-save-url="/admin/appearance/customizer/save" data-prime-reset-url="/admin/appearance/customizer/reset">
    <aside class="pc-customizer__sidebar" aria-label="<?= t('admin.attr.options_de_personnalisation') ?>">
        <header class="pc-customizer__head">
            <div>
                <a href="/admin/appearance/themes" class="pc-text-xs pc-text-muted"><?= $this->e($this->t('admin.appearance.customizer.larr_retour_themes')) ?></a>
                <h1 class="pc-customizer__title">Personnaliser <?= $this->e($activeTheme->name()) ?></h1>
            </div>
            <div class="pc-customizer__actions">
                <button type="button" class="pc-btn pc-btn--primary pc-btn--sm" data-prime-customizer-publish>Publier</button>
            </div>
        </header>

        <form method="post" action="/admin/appearance/customizer/save" class="pc-customizer__form" data-prime-customizer-form>
            <?= $this->raw($this->csrfField()) ?>

            <!-- Section: Site Identity -->
            <details class="pc-customizer__section" open>
                <summary class="pc-customizer__summary"><?= $this->e($this->t('admin.appearance.customizer.identite_site')) ?></summary>
                <div class="pc-customizer__fields pc-stack pc-stack--sm">
                    <div class="pc-field">
                        <label class="pc-label" for="pc-cust-site-name"><?= $this->e($this->t('admin.appearance.customizer.nom_site')) ?></label>
                        <input
                            id="pc-cust-site-name"
                            type="text"
                            name="identity[name]"
                            value="<?= $this->e($siteIdentity['name']) ?>"
                            class="pc-input"
                            data-prime-identity-field="name"
                        >
                    </div>
                    <div class="pc-field">
                        <label class="pc-label" for="pc-cust-site-tagline">Slogan</label>
                        <input
                            id="pc-cust-site-tagline"
                            type="text"
                            name="identity[tagline]"
                            value="<?= $this->e($siteIdentity['tagline']) ?>"
                            class="pc-input"
                            data-prime-identity-field="tagline"
                        >
                    </div>
                    <div class="pc-field">
                        <label class="pc-label" for="pc-cust-logo-id"><?= $this->e($this->t('admin.appearance.customizer.logo_id_media')) ?></label>
                        <div class="pc-customizer__media-row">
                            <input
                                id="pc-cust-logo-id"
                                type="number"
                                min="0"
                                name="identity[logo_id]"
                                value="<?= $siteIdentity['logo_id'] !== null ? $this->e((string) $siteIdentity['logo_id']) : '' ?>"
                                class="pc-input"
                                placeholder="<?= $this->e($this->t('admin.appearance.customizer.aucun')) ?>"
                                data-prime-identity-field="logo_id"
                            >
                            <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-pick-media="pc-cust-logo-id">Choisir</button>
                        </div>
                        <?php if ($siteIdentity['logo_url'] !== null): ?>
                            <img src="<?= $this->e($siteIdentity['logo_url']) ?>" alt="Logo actuel" class="pc-customizer__thumb">
                        <?php endif; ?>
                    </div>
                    <div class="pc-field">
                        <label class="pc-label" for="pc-cust-icon-id"><?= $this->e($this->t('admin.appearance.customizer.icone_site_favicon_id_media')) ?></label>
                        <div class="pc-customizer__media-row">
                            <input
                                id="pc-cust-icon-id"
                                type="number"
                                min="0"
                                name="identity[icon_id]"
                                value="<?= $siteIdentity['icon_id'] !== null ? $this->e((string) $siteIdentity['icon_id']) : '' ?>"
                                class="pc-input"
                                placeholder="<?= $this->e($this->t('admin.appearance.customizer.aucun')) ?>"
                                data-prime-identity-field="icon_id"
                            >
                            <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-pick-media="pc-cust-icon-id">Choisir</button>
                        </div>
                        <p class="pc-text-xs pc-text-muted"><?= $this->e($this->t('admin.appearance.customizer.genere_automatiquement_favicons_32_180')) ?></p>
                    </div>
                </div>
            </details>

            <!-- Sections declared in theme.json -->
            <?php foreach ($schema['sections'] as $secKey => $secMeta): ?>
                <?php $secFields = $fieldsBySection[$secKey] ?? []; ?>
                <?php if ($secFields === []): continue; endif; ?>
                <details class="pc-customizer__section">
                    <summary class="pc-customizer__summary"><?= $this->e($secMeta['label']) ?></summary>
                    <div class="pc-customizer__fields pc-stack pc-stack--sm">
                        <?php if ($secMeta['description'] !== ''): ?>
                            <p class="pc-text-xs pc-text-muted"><?= $this->e($secMeta['description']) ?></p>
                        <?php endif; ?>

                        <?php foreach ($secFields as $fieldKey => $field): ?>
                            <?php
                                $type = (string) ($field['type'] ?? 'text');
                            $val = $values[$fieldKey] ?? ($field['default'] ?? '');
                            $inputId = 'pc-cust-opt-' . $fieldKey;
                            $cssVar = is_string($field['css_var'] ?? null) ? $field['css_var'] : '';
                            $cssUnit = is_string($field['css_unit'] ?? null) ? $field['css_unit'] : '';
                            $cssMapJson = is_array($field['css_map'] ?? null) ? (json_encode($field['css_map'], JSON_UNESCAPED_UNICODE) ?: '{}') : '';
                            ?>
                            <div class="pc-field">
                                <?php if ($type === 'toggle'): ?>
                                    <label class="pc-checkbox" for="<?= $this->e($inputId) ?>">
                                        <input
                                            id="<?= $this->e($inputId) ?>"
                                            type="checkbox"
                                            name="options[<?= $this->e($fieldKey) ?>]"
                                            value="1"
                                            <?= !empty($val) ? 'checked' : '' ?>
                                            data-prime-opt-key="<?= $this->e($fieldKey) ?>"
                                            data-prime-opt-type="toggle"
                                        >
                                        <span><?= $this->e((string) $field['label']) ?></span>
                                    </label>
                                <?php else: ?>
                                    <label class="pc-label" for="<?= $this->e($inputId) ?>"><?= $this->e((string) $field['label']) ?></label>
                                    <?php if ($type === 'color'): ?>
                                        <div class="pc-customizer__color-row">
                                            <input
                                                id="<?= $this->e($inputId) ?>"
                                                type="color"
                                                name="options[<?= $this->e($fieldKey) ?>]"
                                                value="<?= $this->e((string) $val) ?>"
                                                class="pc-customizer__color"
                                                data-prime-opt-key="<?= $this->e($fieldKey) ?>"
                                                data-prime-opt-type="color"
                                                data-prime-css-var="<?= $this->e($cssVar) ?>"
                                            >
                                            <span class="pc-text-xs pc-text-mono" data-prime-color-value="<?= $this->e($fieldKey) ?>"><?= $this->e((string) $val) ?></span>
                                        </div>
                                    <?php elseif ($type === 'textarea'): ?>
                                        <textarea
                                            id="<?= $this->e($inputId) ?>"
                                            name="options[<?= $this->e($fieldKey) ?>]"
                                            rows="3"
                                            class="pc-textarea"
                                            data-prime-opt-key="<?= $this->e($fieldKey) ?>"
                                            data-prime-opt-type="textarea"
                                        ><?= $this->e((string) $val) ?></textarea>
                                    <?php elseif ($type === 'select' || $type === 'font'): ?>
                                        <select
                                            id="<?= $this->e($inputId) ?>"
                                            name="options[<?= $this->e($fieldKey) ?>]"
                                            class="pc-select"
                                            data-prime-opt-key="<?= $this->e($fieldKey) ?>"
                                            data-prime-opt-type="<?= $this->e($type) ?>"
                                            data-prime-css-var="<?= $this->e($cssVar) ?>"
                                            data-prime-css-map="<?= $this->e($cssMapJson) ?>"
                                        >
                                            <?php foreach ((array) ($field['choices'] ?? []) as $cKey => $cLabel): ?>
                                                <option value="<?= $this->e((string) $cKey) ?>"<?= (string) $val === (string) $cKey ? ' selected' : '' ?>>
                                                    <?= $this->e((string) $cLabel) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($type === 'range'): ?>
                                        <div class="pc-customizer__range-row">
                                            <input
                                                id="<?= $this->e($inputId) ?>"
                                                type="range"
                                                name="options[<?= $this->e($fieldKey) ?>]"
                                                min="<?= $this->e((string) ($field['min'] ?? 0)) ?>"
                                                max="<?= $this->e((string) ($field['max'] ?? 100)) ?>"
                                                step="<?= $this->e((string) ($field['step'] ?? 1)) ?>"
                                                value="<?= $this->e((string) $val) ?>"
                                                data-prime-opt-key="<?= $this->e($fieldKey) ?>"
                                                data-prime-opt-type="range"
                                                data-prime-css-var="<?= $this->e($cssVar) ?>"
                                                data-prime-css-unit="<?= $this->e($cssUnit) ?>"
                                            >
                                            <span class="pc-text-xs pc-text-mono" data-prime-range-value="<?= $this->e($fieldKey) ?>"><?= $this->e((string) $val . $cssUnit) ?></span>
                                        </div>
                                    <?php elseif ($type === 'media'): ?>
                                        <div class="pc-customizer__media-row">
                                            <input
                                                id="<?= $this->e($inputId) ?>"
                                                type="number"
                                                min="0"
                                                name="options[<?= $this->e($fieldKey) ?>]"
                                                value="<?= is_numeric($val) ? $this->e((string) $val) : '' ?>"
                                                class="pc-input"
                                                data-prime-opt-key="<?= $this->e($fieldKey) ?>"
                                                data-prime-opt-type="media"
                                            >
                                            <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-pick-media="<?= $this->e($inputId) ?>">Choisir</button>
                                        </div>
                                    <?php else: ?>
                                        <input
                                            id="<?= $this->e($inputId) ?>"
                                            type="text"
                                            name="options[<?= $this->e($fieldKey) ?>]"
                                            value="<?= $this->e((string) $val) ?>"
                                            class="pc-input"
                                            data-prime-opt-key="<?= $this->e($fieldKey) ?>"
                                            data-prime-opt-type="text"
                                        >
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>

            <div class="pc-customizer__footer">
                <button type="button" class="pc-btn pc-btn--ghost pc-btn--sm" data-prime-customizer-reset>
                    <?= $this->e($this->t('admin.appearance.customizer.reinitialiser_defaut')) ?>
                </button>
            </div>
        </form>
    </aside>

    <section class="pc-customizer__stage" aria-label="<?= t('admin.attr.apercu_en_direct') ?>">
        <div class="pc-customizer__toolbar">
            <span class="pc-text-xs pc-text-muted"><?= $this->e($this->t('admin.appearance.customizer.apercu_direct_brouillon_prive')) ?></span>
            <div class="pc-customizer__devices" role="group" aria-label="Format d'affichage">
                <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm is-active" data-prime-device="desktop" aria-pressed="true">Bureau</button>
                <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-device="tablet" aria-pressed="false">Tablette</button>
                <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-device="mobile" aria-pressed="false">Mobile</button>
            </div>
        </div>
        <div class="pc-customizer__frame-wrap" data-prime-frame-wrap="desktop">
            <iframe
                src="<?= $this->e($previewUrl) ?>"
                title="<?= $this->e($this->t('admin.appearance.customizer.apercu_direct_site')) ?>"
                class="pc-customizer__iframe"
                data-prime-customizer-iframe
            ></iframe>
        </div>
    </section>
</div>

<?php $this->stop(); ?>
