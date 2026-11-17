<?php

declare(strict_types=1);

namespace Prime\Auth;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Prime\Auth\Exception\PolicyException;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Log\Logger;
use Prime\Mail\MailerInterface;
use Prime\Mail\Message;
use Prime\Security\PasswordPolicy;
use Prime\Security\RateLimiter;
use Prime\View\View;

/**
 * Handles password reset requests, user invite links (48h), token validation,
 * and secure password updates with session/token revocation.
 *
 * Security invariants:
 * - Reset tokens are 32 random bytes (64 hex chars); only their SHA-256 hash is persisted.
 * - Tokens are single-use (`used_at`) and strictly time-limited (60 min reset, 48h invite).
 * - New requests automatically invalidate all previous unused tokens for that user.
 * - Reset links and tokens are NEVER written to the application Logger.
 */
final class PasswordResetService
{
    /**
     * @var Closure(): int
     */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock Optional Unix timestamp supplier for tests
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly UserRepository $users,
        private readonly RateLimiter $rateLimiter,
        private readonly MailerInterface $mailer,
        private readonly View $view,
        private readonly PasswordPolicy $passwordPolicy = new PasswordPolicy(),
        private readonly ?Hooks $hooks = null,
        private readonly ?Logger $logger = null,
        private readonly int $emailMaxAttempts = 3,
        private readonly int $ipMaxAttempts = 10,
        private readonly int $decaySeconds = 3600,
        private readonly int $resetTtlSeconds = 3600,
        private readonly int $inviteTtlSeconds = 172800,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Process a public forgot-password request (enforces 3/h/email and 10/h/IP rate limits).
     *
     * Always returns silently for unknown or disabled accounts so callers present
     * a single non-enumerating confirmation message.
     *
     * @return array{rate_limited: bool, retry_after: int}
     */
    public function requestPublicReset(string $email, string $ip, string $appUrl, string $siteName): array
    {
        $normalized = UserRepository::normalizeEmail($email);
        $emailKey = 'reset:email:' . $normalized;
        $ipKey = 'reset:ip:' . $ip;

        if ($this->rateLimiter->tooManyAttempts($emailKey, $this->emailMaxAttempts)) {
            return ['rate_limited' => true, 'retry_after' => $this->rateLimiter->availableIn($emailKey)];
        }

        if ($this->rateLimiter->tooManyAttempts($ipKey, $this->ipMaxAttempts)) {
            return ['rate_limited' => true, 'retry_after' => $this->rateLimiter->availableIn($ipKey)];
        }

        $this->rateLimiter->hit($emailKey, $this->decaySeconds);
        $this->rateLimiter->hit($ipKey, $this->decaySeconds);

        $user = $this->users->findByEmail($normalized);
        if ($user === null || !$user->isActive()) {
            return ['rate_limited' => false, 'retry_after' => 0];
        }

        $this->issueTokenAndSendEmail($user, $appUrl, $siteName, isInvite: false);

        return ['rate_limited' => false, 'retry_after' => 0];
    }

    /**
     * Admin-initiated password reset link or 48h new-user invitation link (bypasses public IP limits).
     */
    public function sendAdminResetOrInvite(User $user, string $appUrl, string $siteName, bool $isInvite = false): void
    {
        $this->issueTokenAndSendEmail($user, $appUrl, $siteName, isInvite: $isInvite);
    }

    /**
     * Validate a raw hex token and return its matching active user if unused and unexpired.
     *
     * @return array{reset_id: int, user: User}|null
     */
    public function validateToken(string $rawToken): ?array
    {
        $clean = trim($rawToken);
        if (strlen($clean) !== 64 || !ctype_xdigit($clean)) {
            return null;
        }

        $hash = hash('sha256', $clean);
        $row = $this->connection->table('password_resets')
            ->where('token_hash', '=', $hash)
            ->first();

        if ($row === null || $row['used_at'] !== null) {
            return null;
        }

        $expiresTs = $this->parseUtc((string) $row['expires_at']);
        if ($expiresTs <= ($this->clock)()) {
            return null;
        }

        $user = $this->users->find((int) $row['user_id']);
        if ($user === null || !$user->isActive()) {
            return null;
        }

        return [
            'reset_id' => (int) $row['id'],
            'user' => $user,
        ];
    }

    /**
     * Complete a password reset using a valid single-use token.
     *
     * @throws PolicyException When token is invalid/expired or password fails PasswordPolicy
     */
    public function resetPassword(string $rawToken, string $newPassword, string $siteName): User
    {
        $valid = $this->validateToken($rawToken);
        if ($valid === null) {
            throw new PolicyException('Ce lien de réinitialisation est invalide ou a expiré.');
        }

        $user = $valid['user'];
        $policyError = $this->passwordPolicy->validate($newPassword, [
            'email' => $user->email,
            'display_name' => $user->displayName,
            'site_name' => $siteName,
        ]);

        if ($policyError !== null) {
            throw new PolicyException($policyError);
        }

        $nowUtc = $this->formatUtc(($this->clock)());

        $this->connection->transaction(function () use ($valid, $user, $newPassword, $nowUtc): void {
            // Mark the token used and invalidate any other pending reset tokens for this user.
            $this->connection->table('password_resets')
                ->where('user_id', '=', $user->id)
                ->whereNull('used_at')
                ->update(['used_at' => $nowUtc]);

            $this->connection->table('password_resets')
                ->where('id', '=', $valid['reset_id'])
                ->update(['used_at' => $nowUtc]);

            // Update password hash, `password_changed_at`, increment `session_version`, and revoke remember tokens.
            $this->users->updatePassword($user->id, $newPassword);
        });

        $this->sendPasswordChangedEmail($user, $siteName, $nowUtc);

        $this->logger?->notice('Mot de passe réinitialisé avec succès.', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        $this->hooks?->emit('auth.password_reset', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return $this->users->find($user->id) ?? $user;
    }

    /**
     * Send confirmation email when a user changes or resets their password.
     */
    public function sendPasswordChangedEmail(User $user, string $siteName, ?string $changedAtUtc = null): void
    {
        $changedAt = $changedAtUtc ?? $this->formatUtc(($this->clock)());
        $locale = $user->locale !== '' ? $user->locale : null;
        $subject = t('core.mail.password_changed.subject', ['site' => $siteName], $locale);

        $html = $this->view->render('mail::password-changed', [
            'subject' => $subject,
            'siteName' => $siteName,
            'displayName' => $user->displayName,
            'changedAt' => $changedAt,
        ]);

        $text = t('core.mail.password_changed.text', ['name' => $user->displayName, 'site' => $siteName, 'date' => $changedAt], $locale) . "\n";

        $this->mailer->send(new Message(
            to: $user->email,
            subject: $subject,
            html: $html,
            text: $text,
        ));
    }

    private function issueTokenAndSendEmail(User $user, string $appUrl, string $siteName, bool $isInvite): void
    {
        $now = ($this->clock)();
        $nowUtc = $this->formatUtc($now);
        $ttl = $isInvite ? $this->inviteTtlSeconds : $this->resetTtlSeconds;
        $expiresUtc = $this->formatUtc($now + $ttl);

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $this->connection->transaction(function () use ($user, $tokenHash, $nowUtc, $expiresUtc): void {
            // Invalidate any previous unused reset tokens for this user.
            $this->connection->table('password_resets')
                ->where('user_id', '=', $user->id)
                ->whereNull('used_at')
                ->update(['used_at' => $nowUtc]);

            $this->connection->table('password_resets')->insert([
                'user_id' => $user->id,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresUtc,
                'used_at' => null,
                'created_at' => $nowUtc,
            ]);
        });

        $url = rtrim($appUrl, '/') . '/admin/reset-password/' . $rawToken;
        $locale = $user->locale !== '' ? $user->locale : null;

        if ($isInvite) {
            $ttlHours = max(1, (int) round($ttl / 3600));
            $subject = t('core.mail.invite.subject', ['site' => $siteName], $locale);
            $html = $this->view->render('mail::user-invite', [
                'subject' => $subject,
                'siteName' => $siteName,
                'displayName' => $user->displayName,
                'inviteUrl' => $url,
                'ttlHours' => $ttlHours,
            ]);
            $text = t('core.mail.invite.text', ['name' => $user->displayName, 'site' => $siteName, 'hours' => $ttlHours], $locale) . "\n{$url}\n";
        } else {
            $ttlMinutes = max(1, (int) round($ttl / 60));
            $subject = t('core.mail.reset.subject', ['site' => $siteName], $locale);
            $html = $this->view->render('mail::password-reset', [
                'subject' => $subject,
                'siteName' => $siteName,
                'displayName' => $user->displayName,
                'resetUrl' => $url,
                'ttlMinutes' => $ttlMinutes,
            ]);
            $text = t('core.mail.reset.text', ['name' => $user->displayName, 'site' => $siteName, 'minutes' => $ttlMinutes], $locale)
                . "\n{$url}\n\n" . t('core.mail.reset.ignore', [], $locale) . "\n";
        }

        $this->mailer->send(new Message(
            to: $user->email,
            subject: $subject,
            html: $html,
            text: $text,
        ));

        // Security invariant: NEVER log `$rawToken` or `$url` in app logs.
        $this->logger?->notice($isInvite ? 'Lien d\'invitation envoyé.' : 'Demande de réinitialisation de mot de passe envoyée.', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
    }

    private function formatUtc(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function parseUtc(string $value): int
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($dt === false) {
            $fallback = strtotime($value . ' UTC');

            return $fallback === false ? 0 : $fallback;
        }

        return $dt->getTimestamp();
    }
}
