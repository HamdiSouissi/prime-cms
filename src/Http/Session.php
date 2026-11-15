<?php

declare(strict_types=1);

namespace Prime\Http;

/**
 * Minimal, secure lazy session manager wrapping native PHP file sessions.
 *
 * Uses `prime_session` cookie with `HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS,
 * and stores files in `storage/sessions/`. Falls back to an isolated in-memory store
 * in CLI/test runtimes where HTTP headers are not applicable.
 */
final class Session
{
    public const DEFAULT_NAME = 'prime_session';

    private const FLASH_KEY = '_prime_flash';
    private const OLD_INPUT_KEY = '_prime_old_input';

    /**
     * Sensitive form keys that must never be flashed back to templates.
     *
     * @var list<string>
     */
    private const NEVER_FLASH_KEYS = [
        'password',
        'password_confirmation',
        'db_password',
        'admin_password',
        '_csrf_token',
    ];

    private bool $started = false;

    private string $memoryId = '';

    /**
     * Isolated in-memory session store used when native PHP sessions are disabled (CLI/tests).
     *
     * @var array<string, mixed>
     */
    private array $memoryStore = [];

    private readonly bool $useNativeSession;

    public function __construct(
        private readonly string $savePath,
        private readonly string $name = self::DEFAULT_NAME,
        private readonly ?bool $secureCookie = null,
        ?bool $useNativeSession = null,
    ) {
        $this->useNativeSession = $useNativeSession ?? (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg');
    }

    /**
     * Return the cookie parameters configured for this session.
     *
     * @return array{lifetime: int, path: string, domain: string, secure: bool, httponly: bool, samesite: 'Lax'}
     */
    public function cookieParams(): array
    {
        return [
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $this->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    public function name(): string
    {
        return $this->name;
    }

    public function savePath(): string
    {
        return $this->savePath;
    }

    public function isStarted(): bool
    {
        if ($this->useNativeSession) {
            return session_status() === PHP_SESSION_ACTIVE;
        }

        return $this->started;
    }

    /**
     * Start the session lazily on first access.
     */
    public function start(): void
    {
        if ($this->isStarted()) {
            return;
        }

        if (!$this->useNativeSession) {
            $this->started = true;
            if ($this->memoryId === '') {
                $this->memoryId = bin2hex(random_bytes(16));
            }

            return;
        }

        if (!is_dir($this->savePath)) {
            @mkdir($this->savePath, 0750, true);
        }

        if (!headers_sent()) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_trans_sid', '0');

            session_save_path($this->savePath);
            session_name($this->name);
            session_set_cookie_params($this->cookieParams());
            session_start();
        }

        $this->started = true;
    }

    public function id(): string
    {
        $this->start();

        if ($this->useNativeSession) {
            return (string) session_id();
        }

        return $this->memoryId;
    }

    /**
     * Regenerate the session identifier (helps prevent session fixation).
     */
    public function regenerate(bool $deleteOldSession = true): void
    {
        $this->start();

        if ($this->useNativeSession) {
            if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                session_regenerate_id($deleteOldSession);
            }

            return;
        }

        $this->memoryId = bin2hex(random_bytes(16));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();
        $store = $this->readStore();

        return array_key_exists($key, $store) ? $store[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $this->writeKey($key, $value);
    }

    public function has(string $key): bool
    {
        $this->start();

        return array_key_exists($key, $this->readStore());
    }

    public function remove(string $key): void
    {
        $this->start();
        $this->unsetKey($key);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->start();

        return $this->readStore();
    }

    public function clear(): void
    {
        $this->start();

        if ($this->useNativeSession) {
            $_SESSION = [];

            return;
        }

        $this->memoryStore = [];
    }

    /**
     * Store a one-time flash value that will be consumed on its next `getFlash()` read.
     */
    public function flash(string $key, mixed $value): void
    {
        $this->start();
        $bag = $this->flashBag();
        $bag[$key] = $value;
        $this->writeKey(self::FLASH_KEY, $bag);
    }

    public function hasFlash(string $key): bool
    {
        $this->start();

        return array_key_exists($key, $this->flashBag());
    }

    public function peekFlash(string $key, mixed $default = null): mixed
    {
        $this->start();
        $bag = $this->flashBag();

        return array_key_exists($key, $bag) ? $bag[$key] : $default;
    }

    /**
     * Retrieve and remove a one-time flash value.
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        $this->start();
        $bag = $this->flashBag();

        if (!array_key_exists($key, $bag)) {
            return $default;
        }

        $value = $bag[$key];
        unset($bag[$key]);
        $this->writeKey(self::FLASH_KEY, $bag);

        return $value;
    }

    /**
     * Flash sanitized form input for redisplay after validation failure,
     * automatically stripping passwords and CSRF tokens.
     *
     * @param array<string, mixed> $input
     */
    public function flashInput(array $input): void
    {
        $safe = [];
        foreach ($input as $key => $value) {
            if (in_array(strtolower($key), self::NEVER_FLASH_KEYS, true)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        $this->flash(self::OLD_INPUT_KEY, $safe);
    }

    /**
     * Read and consume all flashed old form inputs.
     *
     * @return array<string, mixed>
     */
    public function pullOldInput(): array
    {
        $old = $this->getFlash(self::OLD_INPUT_KEY, []);

        return is_array($old) ? $old : [];
    }

    /**
     * Read a single flashed old input field (without destroying the rest of the old input bag).
     */
    public function old(string $key, mixed $default = null): mixed
    {
        $old = $this->peekFlash(self::OLD_INPUT_KEY, []);
        if (!is_array($old) || !array_key_exists($key, $old)) {
            return $default;
        }

        return $old[$key];
    }

    private function isSecure(): bool
    {
        if ($this->secureCookie !== null) {
            return $this->secureCookie;
        }

        $https = $_SERVER['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        $port = $_SERVER['SERVER_PORT'] ?? null;
        if (is_numeric($port) && (int) $port === 443) {
            return true;
        }

        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';

        return is_string($forwardedProto) && strtolower($forwardedProto) === 'https';
    }

    /**
     * @return array<string, mixed>
     */
    private function flashBag(): array
    {
        $store = $this->readStore();
        $raw = $store[self::FLASH_KEY] ?? [];

        return is_array($raw) ? $raw : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function readStore(): array
    {
        if ($this->useNativeSession) {
            /** @var array<string, mixed> $session */
            $session = $_SESSION ?? [];

            return $session;
        }

        return $this->memoryStore;
    }

    private function writeKey(string $key, mixed $value): void
    {
        if ($this->useNativeSession) {
            $_SESSION[$key] = $value;

            return;
        }

        $this->memoryStore[$key] = $value;
    }

    private function unsetKey(string $key): void
    {
        if ($this->useNativeSession) {
            unset($_SESSION[$key]);

            return;
        }

        unset($this->memoryStore[$key]);
    }
}
