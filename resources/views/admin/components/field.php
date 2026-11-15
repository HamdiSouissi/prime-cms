<?php

declare(strict_types=1);

/**
 * Form field component: text/email/password/search input, select, or textarea.
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $id
 * @var string $name
 * @var string $label
 * @var string|null $type text|email|password|search|url|number|select|textarea
 * @var string|null $value
 * @var string|null $placeholder
 * @var string|null $help
 * @var string|null $error
 * @var bool|null $required
 * @var bool|null $disabled
 * @var array<string, string>|null $options For select fields
 */
$fieldType = (string) ($type ?? 'text');
$fieldValue = (string) ($value ?? '');
$fieldPlaceholder = (string) ($placeholder ?? '');
$helpText = isset($help) && is_string($help) && $help !== '' ? $help : null;
$errorText = isset($error) && is_string($error) && $error !== '' ? $error : null;
$isRequired = (bool) ($required ?? false);
$isDisabled = (bool) ($disabled ?? false);
$selectOptions = is_array($options ?? null) ? $options : [];

$describedBy = [];
if ($helpText !== null) {
    $describedBy[] = $id . '-help';
}
if ($errorText !== null) {
    $describedBy[] = $id . '-error';
}
$describedAttr = $describedBy !== [] ? ' aria-describedby="' . $this->e(implode(' ', $describedBy)) . '"' : '';
$invalidAttr = $errorText !== null ? ' aria-invalid="true"' : '';
?>
<div class="pc-field<?= $errorText !== null ? ' pc-field--error' : '' ?>">
    <label class="pc-field__label" for="<?= $this->e($id) ?>">
        <span><?= $this->e($label) ?></span>
        <?php if ($isRequired): ?>
            <span class="pc-field__required" aria-hidden="true">*</span>
            <span class="sr-only">(obligatoire)</span>
        <?php endif; ?>
    </label>

    <?php if ($fieldType === 'textarea'): ?>
        <textarea
            id="<?= $this->e($id) ?>"
            name="<?= $this->e($name) ?>"
            class="pc-textarea"
            placeholder="<?= $this->e($fieldPlaceholder) ?>"
            <?= $isRequired ? 'required' : '' ?>
            <?= $isDisabled ? 'disabled' : '' ?>
            <?= $this->raw($invalidAttr . $describedAttr) ?>
        ><?= $this->e($fieldValue) ?></textarea>
    <?php elseif ($fieldType === 'select'): ?>
        <select
            id="<?= $this->e($id) ?>"
            name="<?= $this->e($name) ?>"
            class="pc-select"
            <?= $isRequired ? 'required' : '' ?>
            <?= $isDisabled ? 'disabled' : '' ?>
            <?= $this->raw($invalidAttr . $describedAttr) ?>
        >
            <?php foreach ($selectOptions as $optVal => $optLabel): ?>
                <option value="<?= $this->e((string) $optVal) ?>"<?= (string) $optVal === $fieldValue ? ' selected' : '' ?>>
                    <?= $this->e((string) $optLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php elseif ($fieldType === 'password'): ?>
        <div class="pc-input-group">
            <input
                type="password"
                id="<?= $this->e($id) ?>"
                name="<?= $this->e($name) ?>"
                class="pc-input"
                value="<?= $this->e($fieldValue) ?>"
                placeholder="<?= $this->e($fieldPlaceholder) ?>"
                <?= $isRequired ? 'required' : '' ?>
                <?= $isDisabled ? 'disabled' : '' ?>
                <?= $this->raw($invalidAttr . $describedAttr) ?>
            >
            <button
                type="button"
                class="pc-input-group__action"
                data-password-toggle
                aria-label="Afficher le mot de passe"
                aria-pressed="false"
            >
                <?= $this->raw($this->icon('eye', 18)) ?>
            </button>
        </div>
    <?php else: ?>
        <input
            type="<?= $this->e($fieldType) ?>"
            id="<?= $this->e($id) ?>"
            name="<?= $this->e($name) ?>"
            class="pc-input"
            value="<?= $this->e($fieldValue) ?>"
            placeholder="<?= $this->e($fieldPlaceholder) ?>"
            <?= $isRequired ? 'required' : '' ?>
            <?= $isDisabled ? 'disabled' : '' ?>
            <?= $this->raw($invalidAttr . $describedAttr) ?>
        >
    <?php endif; ?>

    <?php if ($helpText !== null): ?>
        <p class="pc-field__help" id="<?= $this->e($id) ?>-help"><?= $this->e($helpText) ?></p>
    <?php endif; ?>

    <?php if ($errorText !== null): ?>
        <p class="pc-field__error" id="<?= $this->e($id) ?>-error" role="alert">
            <?= $this->raw($this->icon('alert-circle', 14)) ?>
            <span><?= $this->e($errorText) ?></span>
        </p>
    <?php endif; ?>
</div>
