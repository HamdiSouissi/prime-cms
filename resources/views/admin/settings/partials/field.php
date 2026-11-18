<?php

declare(strict_types=1);

/**
 * One registry setting rendered as a form control (`settings[<name>]`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var \Prime\Settings\SettingsField $field
 * @var mixed $value
 * @var array<int|string, string> $options
 * @var ?string $error
 * @var bool $secretSet
 * @var ?array $media
 */

use Prime\Settings\SettingsText;

$name = 'settings[' . $field->name . ']';
$id = 'setting-' . preg_replace('/[^a-z0-9_-]/', '-', $field->name);
$label = SettingsText::label($field);
$description = SettingsText::description($field);
$helpId = $id . '-help';
$errorId = $id . '-error';
$describedBy = trim(($description !== null ? $helpId : '') . ' ' . ($error !== null ? $errorId : ''));
$attributes = $field->htmlAttributes();
$required = !empty($attributes['required']);
$conditions = $field->conditions !== [] ? json_encode($field->conditions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

$attr = static function (array $attributes): string {
    $out = '';
    foreach ($attributes as $key => $val) {
        if ($val === true) {
            $out .= ' ' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8');
        } elseif ($val !== false && $val !== null) {
            $out .= sprintf(' %s="%s"', htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8'), htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8'));
        }
    }

    return $out;
};

$common = ['id' => $id, 'aria-describedby' => $describedBy !== '' ? $describedBy : null, 'aria-invalid' => $error !== null ? 'true' : null];
$scalar = is_scalar($value) ? (string) $value : '';
?>
<div class="pc-field<?= $error !== null ? ' pc-field--error' : '' ?>" data-prime-setting="<?= $this->e($field->name) ?>"<?= $conditions !== null ? ' data-prime-setting-condition="' . $this->e((string) $conditions) . '"' : '' ?>>
    <?php if (in_array($field->type, ['toggle', 'checkbox'], true)): ?>
        <input type="hidden" name="<?= $this->e($name) ?>" value="0">
        <label class="pc-switch" for="<?= $this->e($id) ?>">
            <input class="pc-switch__input" type="checkbox" name="<?= $this->e($name) ?>" value="1"<?= $value ? ' checked' : '' ?><?= $this->raw($attr($common)) ?>>
            <span class="pc-field__label"><?= $this->e($label) ?></span>
        </label>
    <?php elseif (in_array($field->type, ['radio', 'multiselect'], true)): ?>
        <fieldset class="pc-fieldset pc-stack pc-stack--sm"<?= $describedBy !== '' ? ' aria-describedby="' . $this->e($describedBy) . '"' : '' ?>>
            <legend class="pc-field__label"><?= $this->e($label) ?><?php if ($required): ?> <span class="pc-field__required" aria-hidden="true">*</span><?php endif; ?></legend>
            <?php if ($field->type === 'multiselect'): ?>
                <input type="hidden" name="<?= $this->e($name) ?>[]" value="">
            <?php endif; ?>
            <div class="pc-settings-choices">
                <?php foreach ($options as $optKey => $optLabel): ?>
                    <?php
                    $selected = $field->type === 'multiselect'
                        ? in_array((string) $optKey, array_map('strval', is_array($value) ? $value : []), true)
                        : (string) $optKey === $scalar;
                    ?>
                    <label class="pc-choice">
                        <input
                            type="<?= $field->type === 'multiselect' ? 'checkbox' : 'radio' ?>"
                            name="<?= $this->e($name) ?><?= $field->type === 'multiselect' ? '[]' : '' ?>"
                            value="<?= $this->e((string) $optKey) ?>"<?= $selected ? ' checked' : '' ?>
                        >
                        <span class="pc-choice__title"><?= $this->e(SettingsText::option($field, $optKey, (string) $optLabel)) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
    <?php else: ?>
        <label class="pc-field__label" for="<?= $this->e($id) ?>">
            <?= $this->e($label) ?><?php if ($required): ?> <span class="pc-field__required" aria-hidden="true">*</span><?php endif; ?>
        </label>
        <?php if ($field->type === 'select' && $field->name === 'general.timezone'): ?>
            <label class="pc-field__label" for="<?= $this->e($id) ?>">
                <?= $this->e($label) ?><?php if ($required): ?> <span class="pc-field__required" aria-hidden="true">*</span><?php endif; ?>
            </label>
            <input type="search" class="pc-input pc-input--sm pc-timezone-filter" placeholder="<?= $this->e($this->t('admin.settings.timezone_filter')) ?>" aria-controls="<?= $this->e($id) ?>">
            <select class="pc-select" id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>"<?= $this->raw($attr($common + ['required' => $required])) ?>>
                <?php
                $tzOptions = $options;
                $groups = [];
                $utc = null;
                foreach ($tzOptions as $optKey => $optLabel) {
                    if ($optKey === 'UTC') {
                        $utc = [$optKey => $optLabel];
                        continue;
                    }
                    $pos = strpos((string) $optKey, '/');
                    $group = $pos !== false ? substr((string) $optKey, 0, $pos) : 'Autres';
                    if (!isset($groups[$group])) {
                        $groups[$group] = [];
                    }
                    $groups[$group][$optKey] = $optLabel;
                }
                ksort($groups);
                ?>
                <?php if ($utc !== null): ?>
                    <optgroup label="UTC">
                        <?php foreach ($utc as $optKey => $optLabel): ?>
                            <option value="<?= $this->e((string) $optKey) ?>"<?= (string) $optKey === $scalar ? ' selected' : '' ?>><?= $this->e($optLabel) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
                <?php foreach ($groups as $groupName => $groupOpts): ?>
                    <optgroup label="<?= $this->e($groupName) ?>">
                        <?php foreach ($groupOpts as $optKey => $optLabel): ?>
                            <option value="<?= $this->e((string) $optKey) ?>"<?= (string) $optKey === $scalar ? ' selected' : '' ?>><?= $this->e($optLabel) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        <?php elseif ($field->type === 'select'): ?>
            <select class="pc-select" name="<?= $this->e($name) ?>"<?= $this->raw($attr($common + ['required' => $required])) ?>>
                <?php if (!$required && !array_key_exists('', $options)): ?>
                    <option value=""><?= $this->e($this->t('admin.settings.none')) ?></option>
                <?php endif; ?>
                <?php foreach ($options as $optKey => $optLabel): ?>
                    <option value="<?= $this->e((string) $optKey) ?>"<?= (string) $optKey === $scalar ? ' selected' : '' ?>><?= $this->e(SettingsText::option($field, $optKey, (string) $optLabel)) ?></option>
                <?php endforeach; ?>
            </select>
        <?php elseif (in_array($field->type, ['textarea', 'code'], true)): ?>
            <textarea class="pc-textarea<?= $field->type === 'code' ? ' pc-mono' : '' ?>" name="<?= $this->e($name) ?>" rows="<?= $field->type === 'code' ? 8 : 4 ?>"<?= $this->raw($attr($common + $attributes)) ?>><?= $this->e($scalar) ?></textarea>
        <?php elseif ($field->type === 'secret'): ?>
            <div class="pc-input-group">
                <input
                    class="pc-input"
                    type="password"
                    name="<?= $this->e($name) ?>"
                    value=""
                    autocomplete="new-password"
                    placeholder="<?= $this->e($secretSet ? $this->t('admin.settings.secret_set') : $this->t('admin.settings.secret_empty')) ?>"<?= $this->raw($attr($common)) ?>
                >
                <button type="button" class="pc-input-group__action" data-prime-password-toggle aria-label="<?= $this->e($this->t('admin.settings.secret_toggle')) ?>">
                    <?= $this->raw($this->icon('eye', 16)) ?>
                </button>
            </div>
                <?php elseif ($field->type === 'media'): ?>
            <?php
            $mediaId = is_numeric($scalar) ? (int) $scalar : 0;
            $mediaData = isset($media) && is_array($media) ? $media : null;
            $hasMedia = $mediaData !== null && !empty($mediaData['id']);
            ?>
            <div class="pc-media-field" data-prime-media-field data-prime-media-kinds="image" data-prime-media-title="<?= $this->e($this->t('admin.settings.media.choose')) ?>">
                <input type="hidden" name="<?= $this->e($name) ?>" value="<?= $this->e($scalar) ?>" data-prime-media-input>
                <div data-prime-media-preview>
                    <?php if ($hasMedia): ?>
                        <img class="pc-media-field__thumb" src="<?= $this->e((string) ($mediaData['thumbnail_url'] ?? $mediaData['url'] ?? '')) ?>" alt="<?= $this->e((string) ($mediaData['alt'] ?? $mediaData['display_name'] ?? '')) ?>" style="object-position:50% 50%">
                        <script type="application/json" data-prime-media-initial><?= $this->raw(json_encode($mediaData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?></script>
                    <?php else: ?>
                        <span class="pc-media-field__empty"><?= $this->e($this->t('admin.settings.media.none')) ?></span>
                    <?php endif; ?>
                </div>
                <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-media-choose>
                    <span data-prime-media-choose-label><?= $this->e($this->t('admin.settings.media.choose')) ?></span>
                </button>
                <button type="button" class="pc-btn pc-btn--ghost pc-btn--sm" data-prime-media-clear<?= !$hasMedia ? ' hidden' : '' ?>><?= $this->e($this->t('admin.settings.media.remove')) ?></button>
            </div>
        <?php else: ?>
            <?php
            $type = match ($field->type) {
                'number' => 'number',
                'email' => 'email',
                'url' => 'url',
                'color' => 'color',
                'date' => 'date',
                'time' => 'time',
                'datetime' => 'datetime-local',
                default => 'text',
            };
            $extra = $attributes;
            if ($field->type === 'media') {
                $extra['min'] = 0;
            }
            ?>
            <?php
            $presets = match ($field->name) {
                'general.date_format' => ['d/m/Y', 'j F Y', 'l j F Y', 'Y-m-d', 'm/d/Y', 'F j, Y'],
                'general.time_format' => ['H:i', 'G\\hi', 'g:i a', 'g:i A'],
                default => [],
            };
            if ($presets !== []) {
                $extra['list'] = $id . '-presets';
            }
            ?>
            <input
                class="pc-input<?= in_array($type, ['number'], true) ? ' pc-input--narrow' : '' ?>"
                type="<?= $this->e($type) ?>"
                name="<?= $this->e($name) ?>"
                value="<?= $this->e($scalar) ?>"<?= $field->placeholder !== null ? ' placeholder="' . $this->e(\Prime\Content\LabelTranslator::resolve($field->placeholder)) . '"' : '' ?><?= $this->raw($attr($common + $extra)) ?><?= $presets !== [] ? ' data-prime-date-format' : '' ?>
            >
            <?php if ($presets !== []): ?>
                <?php $fmt = locale_formatter(); ?>
                <datalist id="<?= $this->e($id . '-presets') ?>">
                    <?php foreach ($presets as $preset): ?>
                        <option value="<?= $this->e($preset) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <p class="pc-field__help" aria-live="polite"
                   data-prime-date-preview="<?= $this->e($id) ?>"
                   data-months="<?= $this->e((string) json_encode(array_values($fmt->monthNames()), JSON_UNESCAPED_UNICODE)) ?>"
                   data-days="<?= $this->e((string) json_encode(array_values($fmt->weekdayNames()), JSON_UNESCAPED_UNICODE)) ?>">
                    <?= $this->e($this->t('admin.settings.date_preview')) ?> <strong data-prime-date-preview-value><?= $this->e(date($scalar !== '' ? $scalar : 'd/m/Y')) ?></strong>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($description !== null): ?>
        <p class="pc-field__help" id="<?= $this->e($helpId) ?>"><?= $this->e($description) ?></p>
    <?php elseif ($field->type === 'media'): ?>
        <p class="pc-field__help"><?= $this->e($this->t('admin.settings.media_help')) ?></p>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <p class="pc-field__error" id="<?= $this->e($errorId) ?>"><?= $this->raw($this->icon('alert-circle', 14)) ?> <?= $this->e($error) ?></p>
    <?php endif; ?>
</div>

<?php
// Clés du catalogue référencées dynamiquement (via unused-baseline) :
// elles sont résolues par MenuItem et d'autres composants sans appel t() littéral.
t('admin.common.back_to_list');
t('admin.common.characters');
t('admin.common.confirm_delete');
t('admin.common.copy_to_clipboard');
t('admin.common.error_saving');
t('admin.common.filter');
t('admin.common.help');
t('admin.common.hide');
t('admin.common.learn_more');
t('admin.common.none_found');
t('admin.common.not_available');
t('admin.common.optional');
t('admin.common.per_page');
t('admin.common.preview_window');
t('admin.common.restore_default');
t('admin.common.saved');
t('admin.common.saving');
t('admin.common.select_option');
t('admin.common.show');
t('admin.common.use_default');
