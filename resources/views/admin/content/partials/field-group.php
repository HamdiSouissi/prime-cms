<?php

declare(strict_types=1);

/**
 * Renders a registered custom FieldGroup inside the editor (main or sidebar).
 *
 * @var \Prime\View\TemplateContext $this
 * @var \Prime\Content\Fields\FieldGroup $group
 * @var array<string, mixed> $metaValues
 * @var array<string, string> $relatedEntryLabels
 * @var array<string, string> $errors
 */
?>
<section class="pc-inspector-card" style="margin-bottom:14px;">
    <h2 class="pc-inspector-card__title"><?= $this->e($group->title) ?></h2>
    <?php if ($group->description !== ''): ?>
        <p class="pc-text-xs pc-text-muted" style="margin:-6px 0 12px;"><?= $this->e($group->description) ?></p>
    <?php endif; ?>

    <div class="pc-stack pc-stack--sm">
        <?php foreach ($group->fields as $field): ?>
            <?php
            $inputId = 'pc-meta-' . $group->id . '-' . $field->name;
            $inputName = 'meta[' . $field->name . ']';
            $val = array_key_exists($field->name, $metaValues) ? $metaValues[$field->name] : $field->default;
            $strVal = is_scalar($val) ? (string) $val : '';
            $errorMsg = $errors['fields.' . $field->name] ?? ($errors[$field->name] ?? null);
            ?>
            <div class="pc-field">
                <?php if ($field->type !== 'checkbox' && $field->type !== 'toggle'): ?>
                    <label class="pc-label" for="<?= $this->e($inputId) ?>">
                        <?= $this->e($field->label) ?>
                        <?php if ($field->required): ?><span aria-hidden="true">*</span><?php endif; ?>
                    </label>
                <?php endif; ?>

                <?php if ($field->type === 'textarea'): ?>
                    <textarea
                        id="<?= $this->e($inputId) ?>"
                        name="<?= $this->e($inputName) ?>"
                        rows="3"
                        class="pc-textarea"
                        placeholder="<?= $this->e($field->placeholder) ?>"
                        <?= $field->required ? 'required' : '' ?>
                    ><?= $this->e($strVal) ?></textarea>

                <?php elseif ($field->type === 'select'): ?>
                    <select id="<?= $this->e($inputId) ?>" name="<?= $this->e($inputName) ?>" class="pc-select"<?= $field->required ? ' required' : '' ?>>
                        <option value=""><?= $this->e($this->t('admin.content.partials.field-group.selectionner')) ?></option>
                        <?php foreach ($field->options as $optKey => $optLabel): ?>
                            <option value="<?= $this->e((string) $optKey) ?>"<?= $strVal === (string) $optKey ? ' selected' : '' ?>>
                                <?= $this->e($optLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                <?php elseif ($field->type === 'checkbox' || $field->type === 'toggle'): ?>
                    <label class="pc-term-check">
                        <input
                            id="<?= $this->e($inputId) ?>"
                            type="checkbox"
                            name="<?= $this->e($inputName) ?>"
                            value="1"
                            <?= !empty($val) ? ' checked' : '' ?>
                        >
                        <span><?= $this->e($field->label) ?></span>
                    </label>

                <?php elseif ($field->type === 'media'): ?>
                    <?php
                    $resolvedFieldMedia = is_array($fieldMediaItems[$field->name] ?? null) ? $fieldMediaItems[$field->name] : [];
                    $initialMediaJson = json_encode($resolvedFieldMedia, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
                    $mediaValStr = is_array($val)
                        ? implode(',', array_map(static fn (mixed $v): string => (string) $v, $val))
                        : $strVal;
                    $kindsAttr = implode(',', $field->mediaKinds);
                    ?>
                    <div
                        class="pc-media-field"
                        data-prime-media-field
                        data-prime-media-multiple="<?= $field->multiple ? '1' : '0' ?>"
                        data-prime-media-kinds="<?= $this->e($kindsAttr) ?>"
                        data-prime-media-title="<?= $this->e($field->label) ?>"
                    >
                        <script type="application/json" data-prime-media-initial><?= $this->raw(is_string($initialMediaJson) ? $initialMediaJson : '[]') ?></script>
                        <input
                            id="<?= $this->e($inputId) ?>"
                            type="hidden"
                            name="<?= $this->e($inputName) ?>"
                            value="<?= $this->e($mediaValStr) ?>"
                            data-prime-media-input
                        >
                        <div class="pc-media-field__preview" data-prime-media-preview hidden></div>
                        <div class="pc-media-field__buttons">
                            <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-media-choose>
                                <?= $this->raw($this->icon('image', 14)) ?>
                                <span data-prime-media-choose-label><?= $field->multiple ? $this->t('admin.view_content_partials_field-group.choisir_des_medias') : $this->t('admin.view_content_partials_field-group.choisir_un_media') ?></span>
                            </button>
                            <button type="button" class="pc-btn pc-btn--ghost pc-btn--sm" data-prime-media-clear hidden>Retirer</button>
                        </div>
                    </div>

                <?php elseif ($field->type === 'entry'): ?>
                    <?php
                    $allowedTypes = implode(',', $field->entryTypes);
                    $relLabel = $relatedEntryLabels[$field->name] ?? ($strVal !== '' ? '#' . $strVal : '');
                    ?>
                    <div
                        class="pc-entry-rel"
                        data-prime-entry-relation
                        data-prime-endpoint="/admin/content/search-entries"
                        data-prime-types="<?= $this->e($allowedTypes) ?>"
                    >
                        <input type="hidden" name="<?= $this->e($inputName) ?>" value="<?= $this->e($strVal) ?>" data-prime-entry-value>
                        <input
                            id="<?= $this->e($inputId) ?>"
                            type="text"
                            class="pc-input"
                            placeholder="<?= $this->e($field->placeholder !== '' ? $field->placeholder : $this->t('admin.view_content_partials_field-group.rechercher_un_contenu')) ?>"
                            autocomplete="off"
                            data-prime-entry-search
                        >
                        <div class="pc-entry-rel-results" data-prime-entry-results hidden></div>
                        <div class="pc-entry-rel-selected-row">
                            <span class="pc-badge pc-badge--neutral" data-prime-entry-selected<?= $relLabel === '' ? ' hidden' : '' ?>><?= $this->e($relLabel) ?></span>
                            <button type="button" class="pc-btn pc-btn--ghost pc-btn--sm" data-prime-entry-clear<?= $relLabel === '' ? ' hidden' : '' ?>>Retirer</button>
                        </div>
                    </div>

                <?php else: ?>
                    <?php
                    $htmlType = match ($field->type) {
                        'number' => 'number',
                        'email' => 'email',
                        'url' => 'url',
                        'date' => 'date',
                        'datetime' => 'datetime-local',
                        'color' => 'color',
                        default => 'text',
                    };
                    $inputVal = $field->type === 'datetime' && $strVal !== '' ? str_replace(' ', 'T', substr($strVal, 0, 16)) : $strVal;
                    ?>
                    <input
                        id="<?= $this->e($inputId) ?>"
                        type="<?= $this->e($htmlType) ?>"
                        name="<?= $this->e($inputName) ?>"
                        value="<?= $this->e($inputVal) ?>"
                        class="pc-input"
                        placeholder="<?= $this->e($field->placeholder) ?>"
                        <?php if ($field->min !== null): ?>min="<?= $this->e((string) $field->min) ?>"<?php endif; ?>
                        <?php if ($field->max !== null): ?>max="<?= $this->e((string) $field->max) ?>"<?php endif; ?>
                        <?php if ($field->step !== null): ?>step="<?= $this->e((string) $field->step) ?>"<?php endif; ?>
                        <?= $field->required ? 'required' : '' ?>
                    >
                <?php endif; ?>

                <?php if ($field->description !== ''): ?>
                    <span class="pc-text-xs pc-text-muted"><?= $this->e($field->description) ?></span>
                <?php endif; ?>
                <?php if ($errorMsg !== null): ?>
                    <span class="pc-field__error"><?= $this->e($errorMsg) ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
