<?php

declare(strict_types=1);

/**
 * Create / edit role view with grouped capability checkbox matrix.
 *
 * @var \Prime\View\TemplateContext $this
 * @var 'create'|'edit' $mode
 * @var \Prime\Auth\Role|null $role
 * @var bool $isReadOnly
 * @var array<string, array<string, string>> $groupedCapabilities
 * @var array{name: string, slug: string, description: string, capabilities: list<string>} $old
 * @var array<string, string> $errors
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 */
$this->layout('admin::layouts/app');

$isCreate = $mode === 'create';
$formAction = $isCreate ? '/admin/roles' : '/admin/roles/' . ($role !== null ? $role->id : 0);
$hasWildcard = in_array('*', $old['capabilities'], true);
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $isCreate ? 'Créer un rôle' : ($isReadOnly ? 'Rôle Administrateur (lecture seule)' : 'Modifier le rôle : ' . $old['name']),
    'subtitle' => $isReadOnly
        ? 'Le rôle Administrateur possède automatiquement l\'ensemble des permissions présentes et futures.'
        : 'Configurez l\'identité du rôle et cochez précisément les permissions accordées.',
    'breadcrumbs' => $breadcrumbs,
    'actions' => $this->insert('admin::components/button', [
        'label' => 'Retour aux rôles',
        'variant' => 'secondary',
        'href' => '/admin/roles',
    ]),
])) ?>

<div class="pc-stack pc-stack--lg">
    <?php if ($isReadOnly): ?>
        <?= $this->raw($this->insert('admin::components/alert', [
            'variant' => 'info',
            'title' => 'Rôle protégé en lecture seule',
            'message' => 'Le rôle Administrateur détient le privilège universel (*) afin d\'empêcher tout verrouillage accidentel du site. Il ne peut ni être modifié ni supprimé.',
        ])) ?>
    <?php endif; ?>

    <form method="post" action="<?= $this->e($formAction) ?>" class="pc-stack pc-stack--lg" novalidate>
        <?= $this->raw($this->csrfField()) ?>

        <?php ob_start(); ?>
        <div class="pc-stack pc-stack--md">
            <div class="pc-grid pc-grid--2">
                <div class="pc-field<?= isset($errors['name']) ? ' pc-field--error' : '' ?>">
                    <label class="pc-field__label" for="role-name">
                        <span>Nom du rôle</span>
                        <span class="pc-field__required" aria-hidden="true">*</span>
                    </label>
                    <input
                        type="text"
                        id="role-name"
                        name="name"
                        class="pc-input"
                        value="<?= $this->e($old['name']) ?>"
                        placeholder="Ex. Modérateur"
                        <?= $isCreate ? 'data-prime-slug-source="role-slug"' : '' ?>
                        <?= $isReadOnly ? 'disabled' : 'required' ?>
                    >
                    <?php if (isset($errors['name'])): ?>
                        <p class="pc-field__error" role="alert"><?= $this->e($errors['name']) ?></p>
                    <?php endif; ?>
                </div>

                <?= $this->raw($this->insert('admin::components/field', [
                    'id' => 'role-slug',
                    'name' => 'slug',
                    'label' => 'Identifiant (slug)',
                    'type' => 'text',
                    'value' => $old['slug'],
                    'placeholder' => 'ex. moderateur',
                    'required' => $isCreate,
                    'disabled' => !$isCreate,
                    'help' => $isCreate
                        ? 'Généré automatiquement depuis le nom (lettres minuscules, chiffres et tirets).'
                        : 'L\'identifiant d\'un rôle existant ne peut plus être modifié.',
                    'error' => $errors['slug'] ?? null,
                ])) ?>
            </div>

            <?= $this->raw($this->insert('admin::components/field', [
                'id' => 'role-description',
                'name' => 'description',
                'label' => 'Description',
                'type' => 'textarea',
                'value' => $old['description'],
                'placeholder' => 'Décrivez le périmètre et les responsabilités de ce rôle…',
                'disabled' => $isReadOnly,
                'error' => $errors['description'] ?? null,
            ])) ?>
        </div>
        <?php $metaBody = (string) ob_get_clean(); ?>

        <div class="pc-form-wide">
            <?= $this->raw($this->insert('admin::components/card', [
                'title' => 'Identité du rôle',
                'body' => $metaBody,
            ])) ?>
        </div>

        <?php ob_start(); ?>
        <div class="pc-cap-matrix">
            <?php foreach ($groupedCapabilities as $groupName => $groupCaps): ?>
                <?php $groupId = 'cap-group-' . md5($groupName); ?>
                <section class="pc-cap-group" data-prime-cap-group="<?= $this->e($groupId) ?>">
                    <header class="pc-cap-group__header">
                        <h3 class="pc-cap-group__title"><?= $this->e($groupName) ?></h3>
                        <?php if (!$isReadOnly): ?>
                            <button
                                type="button"
                                class="pc-auth-link-btn"
                                data-prime-cap-group-toggle="<?= $this->e($groupId) ?>"
                            >
                                Tout cocher
                            </button>
                        <?php endif; ?>
                    </header>
                    <div class="pc-cap-group__list">
                        <?php foreach ($groupCaps as $capKey => $capLabel): ?>
                            <?php $checked = $hasWildcard || in_array($capKey, $old['capabilities'], true); ?>
                            <label class="pc-cap-item">
                                <input
                                    type="checkbox"
                                    name="capabilities[]"
                                    value="<?= $this->e($capKey) ?>"
                                    <?= $checked ? ' checked' : '' ?>
                                    <?= $isReadOnly ? ' disabled' : '' ?>
                                >
                                <span>
                                    <span><?= $this->e($capLabel) ?></span>
                                    <span class="pc-cap-item__key"><?= $this->e($capKey) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
        <?php $matrixBody = (string) ob_get_clean(); ?>

        <?= $this->raw($this->insert('admin::components/card', [
            'title' => 'Matrice des permissions',
            'subtitle' => 'Permissions groupées par domaine fonctionnel.',
            'body' => $matrixBody,
            'footer' => $isReadOnly
                ? '<a href="/admin/roles" class="pc-btn pc-btn--secondary">Retour à la liste</a>'
                : '<a href="/admin/roles" class="pc-btn pc-btn--ghost">Annuler</a><button type="submit" class="pc-btn pc-btn--primary">' . ($isCreate ? 'Créer le rôle' : 'Enregistrer les permissions') . '</button>',
        ])) ?>
    </form>
</div>
<?php $this->stop(); ?>
