<?php

declare(strict_types=1);

/**
 * Registry-driven settings screen (`/admin/settings/{general|security|emails}`).
 *
 * @var \Prime\View\TemplateContext $this
 * @var string $page
 * @var array<string, array{section: array<string, mixed>, fields: list<array{field: \Prime\Settings\SettingsField, value: mixed, options: array<int|string, string>}>}> $cards
 * @var array<string, string> $errors
 * @var array<string, bool> $secretsSet
 * @var list<array{label: string, url: ?string}> $breadcrumbs
 * @var list<array<string, mixed>> $mailEntries
 * @var array<string, mixed> $mailStats
 * @var ?string $mailStatus
 * @var bool $mailLogAvailable
 * @var ?array<string, mixed> $mailDiagnosis
 * @var string $testRecipient
 */

use Prime\Settings\SettingsText;

$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>

<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $this->t('admin.settings.pages.' . $page . '.title'),
    'subtitle' => $this->t('admin.settings.pages.' . $page . '.subtitle'),
    'breadcrumbs' => $breadcrumbs,
])) ?>

<div class="pc-settings-layout">
    <?php if ($page === 'emails' && is_array($mailDiagnosis ?? null) && ($mailDiagnosis['problem'] ?? null) !== null): ?>
        <div class="pc-alert pc-alert--danger" role="alert">
            <?= $this->raw($this->icon('alert-triangle', 18)) ?>
            <p><?= $this->e((string) $mailDiagnosis['problem']) ?></p>
        </div>
    <?php elseif ($page === 'emails' && is_array($mailDiagnosis ?? null) && ($mailDiagnosis['driver'] ?? '') === 'log'): ?>
        <div class="pc-alert pc-alert--warning" role="status">
            <?= $this->raw($this->icon('info', 18)) ?>
            <p><?= $this->e($this->t('admin.mail.log_driver_notice')) ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/settings/<?= $this->e($page) ?>" class="pc-stack pc-stack--lg" data-prime-settings-form novalidate>
        <?= $this->raw($this->csrfField()) ?>

        <?php if ($errors !== []): ?>
            <div class="pc-alert pc-alert--danger" role="alert" tabindex="-1" data-prime-settings-errors>
                <?= $this->raw($this->icon('alert-circle', 18)) ?>
                <p><?= $this->e($this->choice('admin.settings.errors_summary', count($errors))) ?></p>
            </div>
        <?php endif; ?>

        <?php foreach ($cards as $sectionId => $card): ?>
            <?php
            $section = $card['section'];
            $description = SettingsText::sectionDescription($section);
            ?>
            <section class="pc-card" aria-labelledby="settings-section-<?= $this->e($sectionId) ?>">
                <header class="pc-card__header<?= count($cards) === 1 ? ' sr-only' : '' ?>">
                    <div>
                        <h2 class="pc-card__title" id="settings-section-<?= $this->e($sectionId) ?>"><?= $this->e(SettingsText::sectionLabel($section)) ?></h2>
                        <?php if ($description !== ''): ?>
                            <p class="pc-card__subtitle"><?= $this->e($description) ?></p>
                        <?php endif; ?>
                    </div>
                </header>
                <div class="pc-card__body pc-settings-grid">
                    <?php foreach ($card['fields'] as $row): ?>
                        <?php $name = $row['field']->name; ?>
                        <?= $this->raw($this->insert('admin::settings/partials/field', [
                            'field' => $row['field'],
                            'value' => $row['value'],
                            'options' => $row['options'],
                            'error' => isset($errors[$name]) ? (string) $errors[$name] : null,
                            'secretSet' => $secretsSet[$name] ?? false,
                        ])) ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <div class="pc-settings-actions">
            <button type="submit" class="pc-btn pc-btn--primary">
                <?= $this->raw($this->icon('save', 16)) ?>
                <span><?= $this->e($this->t('admin.settings.save')) ?></span>
            </button>
        </div>
    </form>

    <?php if ($page === 'emails'): ?>
        <section class="pc-card" aria-labelledby="mail-test-title">
            <header class="pc-card__header">
                <div>
                    <h2 class="pc-card__title" id="mail-test-title"><?= $this->e($this->t('admin.mail.test_title')) ?></h2>
                    <p class="pc-card__subtitle"><?= $this->e($this->t('admin.mail.test_subtitle')) ?></p>
                </div>
            </header>
            <form method="post" action="/admin/settings/emails/test" class="pc-card__body pc-row pc-settings-test">
                <?= $this->raw($this->csrfField()) ?>
                <div class="pc-field pc-settings-test__field">
                    <label class="pc-field__label" for="mail-test-to"><?= $this->e($this->t('admin.mail.test_recipient')) ?></label>
                    <input class="pc-input" type="email" id="mail-test-to" name="to" value="<?= $this->e($testRecipient) ?>" required autocomplete="email">
                </div>
                <button type="submit" class="pc-btn pc-btn--secondary">
                    <?= $this->raw($this->icon('inbox', 16)) ?>
                    <span><?= $this->e($this->t('admin.mail.test_send')) ?></span>
                </button>
            </form>
        </section>

        <section class="pc-card" id="mail-log" aria-labelledby="mail-log-title">
            <header class="pc-card__header">
                <div>
                    <h2 class="pc-card__title" id="mail-log-title"><?= $this->e($this->t('admin.mail.log_title')) ?></h2>
                    <p class="pc-card__subtitle">
                        <?= $this->e($this->t('admin.mail.log_stats', [
                            'sent' => (int) ($mailStats['sent'] ?? 0),
                            'failed' => (int) ($mailStats['failed'] ?? 0),
                            'queued' => (int) ($mailStats['queued'] ?? 0),
                        ])) ?>
                    </p>
                </div>
                <nav class="pc-segmented" aria-label="<?= $this->e($this->t('admin.mail.filter_label')) ?>">
                    <?php foreach (['' => 'all', 'sent' => 'sent', 'failed' => 'failed', 'queued' => 'queued'] as $value => $labelKey): ?>
                        <?php $current = ($mailStatus ?? '') === $value; ?>
                        <a
                            class="pc-segmented__item<?= $current ? ' is-active' : '' ?>"
                            href="/admin/settings/emails<?= $value !== '' ? '?status=' . $this->e($value) : '' ?>#mail-log"
                            <?= $current ? 'aria-current="page"' : '' ?>
                        ><?= $this->e($this->t('admin.mail.status.' . $labelKey)) ?></a>
                    <?php endforeach; ?>
                </nav>
            </header>
            <div class="pc-card__body pc-card__body--flush">
                <?php if (!$mailLogAvailable): ?>
                    <p class="pc-empty-inline"><?= $this->e($this->t('admin.mail.log_unavailable')) ?></p>
                <?php elseif ($mailEntries === []): ?>
                    <p class="pc-empty-inline"><?= $this->e($this->t('admin.mail.log_empty')) ?></p>
                <?php else: ?>
                    <div class="pc-table-wrap">
                        <table class="pc-table">
                            <thead>
                                <tr>
                                    <th scope="col"><?= $this->e($this->t('admin.mail.columns.date')) ?></th>
                                    <th scope="col"><?= $this->e($this->t('admin.mail.columns.recipient')) ?></th>
                                    <th scope="col"><?= $this->e($this->t('admin.mail.columns.subject')) ?></th>
                                    <th scope="col"><?= $this->e($this->t('admin.mail.columns.status')) ?></th>
                                    <th scope="col"><span class="sr-only"><?= $this->e($this->t('admin.mail.columns.actions')) ?></span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mailEntries as $entry): ?>
                                    <?php
                                    $status = (string) ($entry['status'] ?? '');
                                    $variant = match ($status) {
                                        'sent' => 'success',
                                        'failed' => 'danger',
                                        default => 'amber',
                                    };
                                    $created = (string) ($entry['created_at'] ?? '');
                                    ?>
                                    <tr>
                                        <td class="pc-text-sm pc-nowrap">
                                            <?php if ($created !== ''): ?>
                                                <time datetime="<?= $this->e(str_replace(' ', 'T', $created)) ?>Z"><?= $this->e(format_datetime($created . ' UTC')) ?></time>
                                            <?php endif; ?>
                                        </td>
                                        <td class="pc-text-sm pc-truncate"><?= $this->e((string) ($entry['recipient'] ?? '')) ?></td>
                                        <td class="pc-text-sm">
                                            <?= $this->e((string) ($entry['subject'] ?? '')) ?>
                                            <?php if ($status === 'failed' && !empty($entry['error_message'])): ?>
                                                <span class="pc-field__help pc-mail-error"><?= $this->e((string) $entry['error_message']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="pc-badge pc-badge--<?= $this->e($variant) ?> pc-badge--pill">
                                                <span class="pc-badge__dot" aria-hidden="true"></span>
                                                <?= $this->e($this->t('admin.mail.status.' . ($status !== '' ? $status : 'queued'))) ?>
                                            </span>
                                        </td>
                                        <td class="pc-table__actions">
                                            <?php if (\Prime\Mail\Mailer::canResend($entry)): ?>
                                                <form method="post" action="/admin/settings/emails/log/<?= $this->e((string) (int) ($entry['id'] ?? 0)) ?>/resend">
                                                    <?= $this->raw($this->csrfField()) ?>
                                                    <button type="submit" class="pc-btn pc-btn--ghost pc-btn--sm">
                                                        <?= $this->raw($this->icon('rotate-ccw', 14)) ?>
                                                        <span><?= $this->e($this->t('admin.mail.resend')) ?></span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php $this->stop(); ?>
