<?php

declare(strict_types=1);

namespace Prime\Auth;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Session;
use Prime\Log\Logger;
use Prime\Security\Csrf;
use Prime\Security\Hasher;
use Prime\Security\RateLimiter;

/**
 * Core authentication engine (singleton) handling login, rate limiting, constant-time checks,
 * transparent password rehashing, session idle/absolute timeouts, `session_version` verification,
 * remember-me token rotation with theft detection, TOTP 2FA challenges, and security hooks/logging.
 */
final class Auth
{
    public const GENERIC_LOGIN_ERROR = 'E-mail ou mot de passe incorrect.';

    private const SESSION_USER_ID = '_auth.user_id';

    private const SESSION_VERSION = '_auth.session_version';

    private const SESSION_LOGGED_IN_AT = '_auth.logged_in_at';

    private const SESSION_LAST_ACTIVITY = '_auth.last_activity_at';

    private const SESSION_TWO_FACTOR = '_auth.two_factor';

    /**
     * @var Closure(): int
     */
    private readonly Closure $clock;

    private readonly Hasher $hasher;

    private ?User $cachedUser = null;

    private bool $userResolved = false;

    /**
     * Pending remember-me cookie action to flush onto the outgoing HTTP Response.
     *
     * @var array{action: 'set'|'clear', value?: string, maxAge?: int}|null
     */
    private ?array $pendingCookie = null;

    /**
     * @param array<string, mixed> $config Contents of `config/auth.php`
     * @param (Closure(): int)|null $clock Optional Unix timestamp supplier for tests
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly UserRepository $users,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly RateLimiter $rateLimiter,
        private readonly TwoFactorService $twoFactor,
        private readonly ?Hooks $hooks = null,
        private readonly ?Logger $logger = null,
        private readonly array $config = [],
        ?Closure $clock = null,
        ?Hasher $hasher = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->hasher = $hasher ?? new Hasher($config);
    }

    /**
     * Attempt email + password authentication (with rate limiting, dummy hash verification,
     * transparent password rehash, and 2FA challenge handoff).
     */
    public function attempt(
        string $email,
        string $password,
        string $ip = '127.0.0.1',
        bool $remember = false,
        ?string $userAgent = null,
    ): AuthResult {
        $normalizedEmail = UserRepository::normalizeEmail($email);
        $emailKey = 'login:email:' . $normalizedEmail . '|' . $ip;
        $ipKey = 'login:ip:' . $ip;

        $emailMax = $this->intConfig('login_max_attempts', 5);
        $ipMax = $this->intConfig('login_ip_max_attempts', 20);
        $decay = $this->intConfig('login_decay_seconds', 900);

        if ($this->rateLimiter->tooManyAttempts($emailKey, $emailMax)) {
            $retryAfter = $this->rateLimiter->availableIn($emailKey);
            $this->recordLockout($normalizedEmail, $ip, $retryAfter, 'email_ip');

            return AuthResult::locked($retryAfter);
        }

        if ($this->rateLimiter->tooManyAttempts($ipKey, $ipMax)) {
            $retryAfter = $this->rateLimiter->availableIn($ipKey);
            $this->recordLockout($normalizedEmail, $ip, $retryAfter, 'ip');

            return AuthResult::locked($retryAfter);
        }

        $raw = $this->users->findRawByEmail($normalizedEmail);
        $storedHash = is_array($raw) && is_string($raw['password_hash'] ?? null) && $raw['password_hash'] !== ''
            ? $raw['password_hash']
            : null;

        // Always execute a hash verification using the exact same algorithm & options to prevent timing enumeration.
        if ($storedHash !== null) {
            $passwordOk = $this->hasher->verify($password, $storedHash);
        } else {
            $this->hasher->dummyVerify($password);
            $passwordOk = false;
        }

        if ($raw === null || !$passwordOk || ($raw['status'] ?? '') !== User::STATUS_ACTIVE) {
            $emailHits = $this->rateLimiter->hit($emailKey, $decay);
            $ipHits = $this->rateLimiter->hit($ipKey, $decay);

            $reason = $raw === null
                ? 'unknown_email'
                : (!$passwordOk ? 'invalid_password' : 'account_disabled');

            $this->logger?->notice('Échec de connexion administrateur.', [
                'email' => $normalizedEmail,
                'ip' => $ip,
                'reason' => $reason,
            ]);

            $this->hooks?->emit('auth.failed', [
                'email' => $normalizedEmail,
                'ip' => $ip,
                'reason' => $reason,
            ]);

            if ($emailHits >= $emailMax) {
                $this->recordLockout($normalizedEmail, $ip, $this->rateLimiter->availableIn($emailKey), 'email_ip');
            } elseif ($ipHits >= $ipMax) {
                $this->recordLockout($normalizedEmail, $ip, $this->rateLimiter->availableIn($ipKey), 'ip');
            }

            return AuthResult::failed(self::GENERIC_LOGIN_ERROR);
        }

        $userId = (int) $raw['id'];

        // Transparently rehash legacy or weaker password hashes.
        if ($this->hasher->needsRehash($storedHash)) {
            $this->connection->table('users')
                ->where('id', '=', $userId)
                ->update([
                    'password_hash' => $this->hasher->make($password),
                    'updated_at' => $this->formatUtc(($this->clock)()),
                ]);
        }

        // Clear email+IP failure counter on successful primary authentication.
        $this->rateLimiter->clear($emailKey);

        $user = $this->users->find($userId);
        if ($user === null || !$user->isActive()) {
            return AuthResult::failed(self::GENERIC_LOGIN_ERROR);
        }

        // If 2FA is confirmed, park the user in a short-lived pending 2FA challenge.
        if ($user->hasTwoFactor()) {
            $this->session->regenerate(true);
            $this->csrf->regenerate();
            $this->session->set(self::SESSION_TWO_FACTOR, [
                'user_id' => $user->id,
                'remember' => $remember,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255, 'UTF-8') : null,
                'attempts' => 0,
                'expires_at' => ($this->clock)() + $this->intConfig('two_factor_ttl', 300),
            ]);

            return AuthResult::twoFactorRequired($user->id);
        }

        $this->login($user, $remember, $userAgent, $ip);

        return AuthResult::authenticated($user);
    }

    /**
     * Check whether a valid, non-expired pending 2FA challenge exists in the current session.
     */
    public function hasPendingTwoFactor(): bool
    {
        return $this->pendingTwoFactorData() !== null;
    }

    /**
     * Complete a pending 2FA challenge using a TOTP code or a single-use recovery code.
     */
    public function completeTwoFactor(string $code, string $ip = '127.0.0.1'): AuthResult
    {
        $pending = $this->pendingTwoFactorData();
        if ($pending === null) {
            $this->session->remove(self::SESSION_TWO_FACTOR);

            return AuthResult::twoFactorExpired('Votre session de vérification 2FA a expiré. Veuillez vous reconnecter.');
        }

        $user = $this->users->find($pending['user_id']);
        if ($user === null || !$user->isActive() || !$user->hasTwoFactor()) {
            $this->session->remove(self::SESSION_TWO_FACTOR);

            return AuthResult::twoFactorExpired('Session de vérification invalide. Veuillez vous reconnecter.');
        }

        if ($this->twoFactor->verifyChallenge($user, $code)) {
            $this->session->remove(self::SESSION_TWO_FACTOR);
            $this->login($user, $pending['remember'], $pending['user_agent'], $ip);

            return AuthResult::authenticated($user);
        }

        $attempts = $pending['attempts'] + 1;
        $maxAttempts = $this->intConfig('two_factor_max_attempts', 5);

        $this->logger?->notice('Échec de vérification du code 2FA.', [
            'user_id' => $user->id,
            'ip' => $ip,
            'attempts' => $attempts,
        ]);

        $this->hooks?->emit('auth.failed', [
            'email' => $user->email,
            'ip' => $ip,
            'reason' => 'invalid_two_factor_code',
        ]);

        if ($attempts >= $maxAttempts) {
            $this->session->remove(self::SESSION_TWO_FACTOR);
            $this->logger?->warning('Trop de tentatives 2FA échouées : défi annulé.', [
                'user_id' => $user->id,
                'ip' => $ip,
            ]);

            return AuthResult::twoFactorExpired('Trop de codes incorrects. Veuillez vous reconnecter depuis le début.');
        }

        $pending['attempts'] = $attempts;
        $this->session->set(self::SESSION_TWO_FACTOR, $pending);

        return AuthResult::failed('Code de vérification invalide. Réessayez avec un code actif ou un code de récupération.');
    }

    /**
     * Clear any pending 2FA challenge in session.
     */
    public function clearPendingTwoFactor(): void
    {
        $this->session->remove(self::SESSION_TWO_FACTOR);
    }

    /**
     * Establish an authenticated session for `$user`, regenerating session ID & CSRF token,
     * updating `last_login_at`, and issuing a remember-me token if requested.
     */
    public function login(
        User $user,
        bool $remember = false,
        ?string $userAgent = null,
        string $ip = '127.0.0.1',
    ): void {
        $now = ($this->clock)();
        $nowUtc = $this->formatUtc($now);

        $this->session->regenerate(true);
        $this->csrf->regenerate();

        $this->session->set(self::SESSION_USER_ID, $user->id);
        $this->session->set(self::SESSION_VERSION, $user->sessionVersion);
        $this->session->set(self::SESSION_LOGGED_IN_AT, $now);
        $this->session->set(self::SESSION_LAST_ACTIVITY, $now);
        $this->session->remove(self::SESSION_TWO_FACTOR);

        $this->connection->table('users')
            ->where('id', '=', $user->id)
            ->update([
                'last_login_at' => $nowUtc,
            ]);

        if ($remember) {
            $this->issueRememberToken($user->id, $userAgent);
        }

        $freshUser = $this->users->find($user->id) ?? $user;
        $this->cachedUser = $freshUser;
        $this->userResolved = true;

        $this->logger?->notice('Connexion utilisateur réussie.', [
            'user_id' => $freshUser->id,
            'email' => $freshUser->email,
            'ip' => $ip,
            'remember' => $remember,
        ]);

        $this->hooks?->emit('auth.login', [
            'user_id' => $freshUser->id,
            'email' => $freshUser->email,
            'ip' => $ip,
            'remember' => $remember,
        ]);
    }

    /**
     * Log out the current user, delete the active remember-me token, expire the cookie,
     * and regenerate session ID + CSRF token.
     */
    public function logout(?Request $request = null): void
    {
        $currentUser = $this->cachedUser;
        if ($currentUser === null && $this->session->has(self::SESSION_USER_ID)) {
            $uid = $this->session->get(self::SESSION_USER_ID);
            if (is_int($uid) || (is_string($uid) && ctype_digit($uid))) {
                $currentUser = $this->users->find((int) $uid);
            }
        }

        // Delete active remember-me token row if present in request cookie.
        $cookieValue = $this->readRememberCookieValue($request);
        if ($cookieValue !== null) {
            $parsed = $this->parseRememberCookie($cookieValue);
            if ($parsed !== null) {
                $this->connection->table('remember_tokens')
                    ->where('selector', '=', $parsed['selector'])
                    ->delete();
            }
        }

        $this->pendingCookie = ['action' => 'clear'];

        $this->clearAuthSessionKeys();
        $this->session->regenerate(true);
        $this->csrf->regenerate();

        $this->cachedUser = null;
        $this->userResolved = true;

        if ($currentUser !== null) {
            $this->logger?->notice('Déconnexion utilisateur.', [
                'user_id' => $currentUser->id,
                'email' => $currentUser->email,
            ]);

            $this->hooks?->emit('auth.logout', [
                'user_id' => $currentUser->id,
                'email' => $currentUser->email,
            ]);
        }
    }

    /**
     * Invalidate all other sessions and remember-me tokens for `$user` while keeping the current session active.
     */
    public function logoutOtherDevices(User $user): void
    {
        $newVersion = $this->users->incrementSessionVersionAndRevokeTokens($user->id);

        if ($this->id() === $user->id) {
            $this->session->set(self::SESSION_VERSION, $newVersion);
            $this->pendingCookie = ['action' => 'clear'];
            $this->resetCache();
        }

        $this->logger?->notice('Déconnexion des autres appareils effectuée.', [
            'user_id' => $user->id,
            'session_version' => $newVersion,
        ]);
    }

    /**
     * Synchronize the current session's `session_version` after the logged-in user changes their own password.
     */
    public function syncCurrentSessionVersion(int $newVersion): void
    {
        $this->session->set(self::SESSION_VERSION, $newVersion);
        $this->pendingCookie = ['action' => 'clear'];
        $this->resetCache();
    }

    /**
     * Return the currently authenticated User (cached per request), or null for guests.
     *
     * Verifies idle timeout (2h), absolute timeout (12h), `session_version`, and active status
     * on every request; falls back to remember-me cookie authentication with rotation & theft detection.
     */
    public function user(?Request $request = null): ?User
    {
        if ($this->userResolved) {
            return $this->cachedUser;
        }

        $this->userResolved = true;
        $this->cachedUser = null;

        $sessionUser = $this->resolveFromSession();
        if ($sessionUser !== null) {
            $this->cachedUser = $sessionUser;

            return $sessionUser;
        }

        $rememberUser = $this->resolveFromRememberCookie($request);
        if ($rememberUser !== null) {
            $this->cachedUser = $rememberUser;

            return $rememberUser;
        }

        return null;
    }

    public function id(?Request $request = null): ?int
    {
        return $this->user($request)?->id;
    }

    public function check(?Request $request = null): bool
    {
        return $this->user($request) !== null;
    }

    public function guest(?Request $request = null): bool
    {
        return !$this->check($request);
    }

    public function can(string $capability, ?Request $request = null): bool
    {
        $user = $this->user($request);

        return $user !== null && $user->can($capability);
    }

    public function cannot(string $capability, ?Request $request = null): bool
    {
        return !$this->can($capability, $request);
    }

    /**
     * Reset the per-request cached User (useful in long-running tests or after profile updates).
     */
    public function resetCache(): void
    {
        $this->cachedUser = null;
        $this->userResolved = false;
    }

    /**
     * Flush any queued remember-me `Set-Cookie` directive onto the outgoing HTTP Response.
     */
    public function applyPendingCookies(Response $response, Request $request): Response
    {
        if ($this->pendingCookie === null) {
            return $response;
        }

        $action = $this->pendingCookie;
        $this->pendingCookie = null;
        $cookieName = $this->rememberCookieName();
        $secure = $request->isSecure();

        if ($action['action'] === 'clear') {
            return $response->withoutCookie($cookieName, '/', $secure, true, 'Lax');
        }

        return $response->withCookie(
            name: $cookieName,
            value: $action['value'] ?? '',
            maxAge: $action['maxAge'] ?? $this->intConfig('remember_ttl', 2592000),
            path: '/',
            secure: $secure,
            httpOnly: true,
            sameSite: 'Lax',
        );
    }

    public function rememberCookieName(): string
    {
        $name = $this->config['remember_cookie'] ?? 'prime_remember';

        return is_string($name) && $name !== '' ? $name : 'prime_remember';
    }

    private function resolveFromSession(): ?User
    {
        $rawId = $this->session->get(self::SESSION_USER_ID);
        if (!is_int($rawId) && !(is_string($rawId) && ctype_digit($rawId))) {
            return null;
        }

        $now = ($this->clock)();
        $idleLimit = $this->intConfig('session_idle_timeout', 7200);
        $absLimit = $this->intConfig('session_absolute_timeout', 43200);

        $loggedInAt = (int) $this->session->get(self::SESSION_LOGGED_IN_AT, 0);
        $lastActivity = (int) $this->session->get(self::SESSION_LAST_ACTIVITY, 0);

        if ($loggedInAt <= 0 || ($now - $loggedInAt) > $absLimit) {
            $this->clearAuthSessionKeys();

            return null;
        }

        if ($lastActivity <= 0 || ($now - $lastActivity) > $idleLimit) {
            $this->clearAuthSessionKeys();

            return null;
        }

        $user = $this->users->find((int) $rawId);
        $sessionVersion = (int) $this->session->get(self::SESSION_VERSION, 0);

        if ($user === null || !$user->isActive() || $user->sessionVersion !== $sessionVersion) {
            $this->clearAuthSessionKeys();

            return null;
        }

        // Slide the idle activity window forward.
        $this->session->set(self::SESSION_LAST_ACTIVITY, $now);

        return $user;
    }

    private function resolveFromRememberCookie(?Request $request): ?User
    {
        $cookieValue = $this->readRememberCookieValue($request);
        if ($cookieValue === null) {
            return null;
        }

        $parsed = $this->parseRememberCookie($cookieValue);
        if ($parsed === null) {
            $this->pendingCookie = ['action' => 'clear'];

            return null;
        }

        $row = $this->connection->table('remember_tokens')
            ->where('selector', '=', $parsed['selector'])
            ->first();

        if ($row === null) {
            $this->pendingCookie = ['action' => 'clear'];

            return null;
        }

        $now = ($this->clock)();
        $expiresTs = $this->parseUtc((string) $row['expires_at']);
        if ($expiresTs <= $now) {
            $this->connection->table('remember_tokens')
                ->where('id', '=', (int) $row['id'])
                ->delete();
            $this->pendingCookie = ['action' => 'clear'];

            return null;
        }

        $tokenUserId = (int) $row['user_id'];
        $computedHash = hash('sha256', $parsed['validator']);
        $storedHash = (string) $row['validator_hash'];

        // Theft detection: known selector with mismatched validator -> revoke ALL user remember tokens!
        if (!hash_equals($storedHash, $computedHash)) {
            $this->users->revokeRememberTokens($tokenUserId);
            $this->clearAuthSessionKeys();
            $this->pendingCookie = ['action' => 'clear'];

            $this->logger?->warning('Suspect de vol de jeton "Rester connecté" : tous les jetons de l\'utilisateur ont été révoqués.', [
                'user_id' => $tokenUserId,
                'selector_prefix' => substr($parsed['selector'], 0, 6),
            ]);

            return null;
        }

        $user = $this->users->find($tokenUserId);
        if ($user === null || !$user->isActive()) {
            $this->users->revokeRememberTokens($tokenUserId);
            $this->pendingCookie = ['action' => 'clear'];

            return null;
        }

        // Rotate validator on every successful remember-me use.
        $newValidator = bin2hex(random_bytes(32));
        $newHash = hash('sha256', $newValidator);
        $nowUtc = $this->formatUtc($now);
        $remainingTtl = max(60, $expiresTs - $now);

        $this->connection->table('remember_tokens')
            ->where('id', '=', (int) $row['id'])
            ->update([
                'validator_hash' => $newHash,
                'last_used_at' => $nowUtc,
            ]);

        $this->pendingCookie = [
            'action' => 'set',
            'value' => $parsed['selector'] . ':' . $newValidator,
            'maxAge' => $remainingTtl,
        ];

        // Establish fresh session for this request.
        $this->session->regenerate(true);
        $this->csrf->regenerate();
        $this->session->set(self::SESSION_USER_ID, $user->id);
        $this->session->set(self::SESSION_VERSION, $user->sessionVersion);
        $this->session->set(self::SESSION_LOGGED_IN_AT, $now);
        $this->session->set(self::SESSION_LAST_ACTIVITY, $now);

        $this->connection->table('users')
            ->where('id', '=', $user->id)
            ->update(['last_login_at' => $nowUtc]);

        return $this->users->find($user->id) ?? $user;
    }

    private function issueRememberToken(int $userId, ?string $userAgent): void
    {
        $now = ($this->clock)();
        $ttl = $this->intConfig('remember_ttl', 2592000);
        $selector = bin2hex(random_bytes(12)); // 24 hex chars
        $validator = bin2hex(random_bytes(32)); // 64 hex chars
        $validatorHash = hash('sha256', $validator);

        $this->connection->table('remember_tokens')->insert([
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => $validatorHash,
            'expires_at' => $this->formatUtc($now + $ttl),
            'last_used_at' => null,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255, 'UTF-8') : null,
            'created_at' => $this->formatUtc($now),
        ]);

        $this->pendingCookie = [
            'action' => 'set',
            'value' => $selector . ':' . $validator,
            'maxAge' => $ttl,
        ];
    }

    /**
     * @return array{user_id: int, remember: bool, user_agent: ?string, attempts: int, expires_at: int}|null
     */
    private function pendingTwoFactorData(): ?array
    {
        $raw = $this->session->get(self::SESSION_TWO_FACTOR);
        if (!is_array($raw) || !isset($raw['user_id'], $raw['expires_at'])) {
            return null;
        }

        if ((int) $raw['expires_at'] <= ($this->clock)()) {
            return null;
        }

        return [
            'user_id' => (int) $raw['user_id'],
            'remember' => (bool) ($raw['remember'] ?? false),
            'user_agent' => isset($raw['user_agent']) && is_string($raw['user_agent']) ? $raw['user_agent'] : null,
            'attempts' => (int) ($raw['attempts'] ?? 0),
            'expires_at' => (int) $raw['expires_at'],
        ];
    }

    private function readRememberCookieValue(?Request $request): ?string
    {
        $name = $this->rememberCookieName();
        if ($request !== null) {
            $val = $request->cookie($name);

            return is_string($val) && $val !== '' ? $val : null;
        }

        $val = $_COOKIE[$name] ?? null;

        return is_string($val) && $val !== '' ? $val : null;
    }

    /**
     * @return array{selector: string, validator: string}|null
     */
    private function parseRememberCookie(string $value): ?array
    {
        $parts = explode(':', $value, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$selector, $validator] = $parts;
        if (strlen($selector) !== 24 || !ctype_xdigit($selector)) {
            return null;
        }

        if (strlen($validator) !== 64 || !ctype_xdigit($validator)) {
            return null;
        }

        return ['selector' => $selector, 'validator' => $validator];
    }

    private function clearAuthSessionKeys(): void
    {
        $this->session->remove(self::SESSION_USER_ID);
        $this->session->remove(self::SESSION_VERSION);
        $this->session->remove(self::SESSION_LOGGED_IN_AT);
        $this->session->remove(self::SESSION_LAST_ACTIVITY);
        $this->session->remove(self::SESSION_TWO_FACTOR);
    }

    private function recordLockout(string $email, string $ip, int $retryAfter, string $scope): void
    {
        $this->logger?->warning('Verrouillage temporaire après trop de tentatives de connexion.', [
            'email' => $email,
            'ip' => $ip,
            'scope' => $scope,
            'retry_after' => $retryAfter,
        ]);

        $this->hooks?->emit('auth.lockout', [
            'email' => $email,
            'ip' => $ip,
            'scope' => $scope,
            'retry_after' => $retryAfter,
        ]);
    }

    private function intConfig(string $key, int $default): int
    {
        $value = $this->config[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
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
