<?php

declare(strict_types=1);

/**
 * Table of API tokens, shared by « Mon profil », a user's page and « Outils › API ».
 *
 * Shows the prefix only (never the secret), the abilities by their translated label, dates in
 * the site format and a status badge; active tokens get a confirmed « Révoquer » action.
 *
 * @var \Prime\View\TemplateContext $this
 * @var list<array<string, mixed>> $tokens
 * @var string $revokeAction URL with an `{id}` placeholder
 * @var bool|null $showOwner
 */
$showOwner = (bool) ($showOwner ?? false);
$labels = [];
foreach (app(\Prime\Auth\Capabilities::class)->all() as $key => $meta) {
    $labels[$key] = $meta['label'];
}
$now = gmdate('Y-m-d H:i:s');
$formatDate = static function (mixed $value): string {
    return is_string($value) && $value !== '' ? format_datetime($value, 'short') : '—';
};
?>
<div class="pc-table-scroll">
    <table class="pc-table pc-table--tokens">
        <thead>
        <tr>
            <th scope="col"><?= $this->e($this->t('admin.view_profile_index.jeton_api_col_nom')) ?></th>
            <?php if ($showOwner): ?>
                <th scope="col"><?= $this->e($this->t('admin.api_tokens.owner')) ?></th>
            <?php endif; ?>
            <th scope="col"><?= $this->e($this->t('admin.view_profile_index.jeton_api_col_prefixe')) ?></th>
            <th scope="col"><?= $this->e($this->t('admin.view_profile_index.jeton_api_col_capacites')) ?></th>
            <th scope="col"><?= $this->e($this->t('admin.view_profile_index.jeton_api_col_derniere_utilisation')) ?></th>
            <th scope="col"><?= $this->e($this->t('admin.view_profile_index.jeton_api_col_expiration')) ?></th>
            <th scope="col"><?= $this->e($this->t('admin.view_profile_index.jeton_api_col_statut')) ?></th>
            <th scope="col"><span class="pc-sr-only"><?= $this->e($this->t('admin.view_profile_index.jeton_api_col_action')) ?></span></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($tokens as $token): ?>
            <?php
            $tokenId = (int) ($token['id'] ?? 0);
            $revoked = is_string($token['revoked_at'] ?? null) && $token['revoked_at'] !== '';
            $expiresAt = is_string($token['expires_at'] ?? null) ? $token['expires_at'] : '';
            $expired = !$revoked && $expiresAt !== '' && $expiresAt <= $now;
            $status = $revoked ? 'revoked' : ($expired ? 'expired' : 'active');
            [$statusLabel, $variant] = match ($status) {
                'active' => [$this->t('admin.view_profile_index.jeton_api_statut_actif'), 'success'],
                'expired' => [$this->t('admin.view_profile_index.jeton_api_statut_expire'), 'amber'],
                default => [$this->t('admin.view_profile_index.jeton_api_statut_revoque'), 'neutral'],
            };

            $abilities = json_decode((string) ($token['abilities'] ?? '[]'), true);
            $abilities = is_array($abilities) ? array_values(array_filter($abilities, 'is_string')) : [];
            if (in_array('*', $abilities, true)) {
                $abilityNames = [$this->t('admin.api_tokens.all_abilities')];
            } else {
                $abilityNames = array_map(static fn (string $cap): string => $labels[$cap] ?? $cap, $abilities);
            }
            $shown = array_slice($abilityNames, 0, 3);
            $more = count($abilityNames) - count($shown);
            ?>
            <tr>
                <td data-label="<?= $this->e($this->t('admin.view_profile_index.jeton_api_col_nom')) ?>"><strong><?= $this->e((string) ($token['name'] ?? '')) ?></strong></td>
                <?php if ($showOwner): ?>
                    <td data-label="<?= $this->e($this->t('admin.api_tokens.owner')) ?>">
                        <a href="/admin/users/<?= $this->e((string) (int) ($token['user_id'] ?? 0)) ?>/edit"><?= $this->e((string) ($token['user_name'] ?? '')) ?></a>
                    </td>
                <?php endif; ?>
                <td data-label="<?= $this->e($this->t('admin.view_profile_index.jeton_api_col_prefixe')) ?>"><code class="pc-mono">pcat_<?= $this->e(substr((string) ($token['token_prefix'] ?? ''), 0, 8)) ?>…</code></td>
                <td data-label="<?= $this->e($this->t('admin.view_profile_index.jeton_api_col_capacites')) ?>" title="<?= $this->e(implode(', ', $abilityNames)) ?>">
                    <?= $this->e(implode(', ', $shown)) ?><?php if ($more > 0): ?> <span class="pc-text-muted">+<?= $this->e((string) $more) ?></span><?php endif; ?>
                </td>
                <td data-label="<?= $this->e($this->t('admin.view_profile_index.jeton_api_col_derniere_utilisation')) ?>"><?= $this->e($formatDate($token['last_used_at'] ?? null)) ?></td>
                <td data-label="<?= $this->e($this->t('admin.view_profile_index.jeton_api_col_expiration')) ?>"><?= $this->e($expiresAt !== '' ? $formatDate($expiresAt) : $this->t('admin.view_profile_index.jeton_api_expiration_none')) ?></td>
                <td data-label="<?= $this->e($this->t('admin.view_profile_index.jeton_api_col_statut')) ?>"><?= $this->raw($this->insert('admin::components/badge', ['label' => $statusLabel, 'variant' => $variant])) ?></td>
                <td class="pc-table__actions">
                    <?php if ($status === 'active'): ?>
                        <form method="post" action="<?= $this->e(str_replace('{id}', (string) $tokenId, $revokeAction)) ?>"
                              data-prime-confirm-form="<?= $this->e($this->t('admin.view_profile_index.jeton_api_confirmer_revoke')) ?>">
                            <?= $this->raw($this->csrfField()) ?>
                            <button type="submit" class="pc-btn pc-btn--danger-outline pc-btn--sm"
                                    aria-label="<?= $this->e($this->t('admin.api_tokens.revoke_named', ['name' => (string) ($token['name'] ?? '')])) ?>">
                                <?= $this->e($this->t('admin.view_profile_index.jeton_api_revoquer')) ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
