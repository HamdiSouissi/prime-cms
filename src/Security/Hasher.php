<?php

declare(strict_types=1);

namespace Prime\Security;

use RuntimeException;

/**
 * Single authoritative entry point for password hashing, verification, rehashing,
 * and constant-timing dummy verification across Prime CMS.
 *
 * Uses Argon2id when available (with automatic bcrypt fallback), driven by `config/auth.php`.
 * `dummyVerify()` always verifies against a hash produced with the exact same algorithm and
 * options as `make()`, eliminating timing-based account enumeration.
 */
final class Hasher
{
    private readonly string $algorithm;

    /**
     * @var array<string, int>
     */
    private readonly array $options;

    private ?string $dummyHash = null;

    /**
     * @param array<string, mixed> $config Password hashing configuration (`config/auth.php`)
     * @param string|null $cacheDir Optional directory (e.g. `storage/cache`) to persist the dummy hash across requests
     */
    public function __construct(
        array $config = [],
        private readonly ?string $cacheDir = null,
    ) {
        $requestedDriver = strtolower(trim((string) ($config['hash_driver'] ?? 'argon2id')));
        $argonAlgo = defined('PASSWORD_ARGON2ID') ? constant('PASSWORD_ARGON2ID') : null;
        $useArgon2id = $requestedDriver !== 'bcrypt' && is_string($argonAlgo);

        if ($useArgon2id) {
            $this->algorithm = $argonAlgo;
            $argonCfg = is_array($config['argon2'] ?? null) ? $config['argon2'] : [];

            $memConst = defined('PASSWORD_ARGON2_DEFAULT_MEMORY_COST')
                ? constant('PASSWORD_ARGON2_DEFAULT_MEMORY_COST')
                : null;
            $timeConst = defined('PASSWORD_ARGON2_DEFAULT_TIME_COST')
                ? constant('PASSWORD_ARGON2_DEFAULT_TIME_COST')
                : null;
            $threadsConst = defined('PASSWORD_ARGON2_DEFAULT_THREADS')
                ? constant('PASSWORD_ARGON2_DEFAULT_THREADS')
                : null;

            $defaultMemory = is_int($memConst) ? $memConst : 65536;
            $defaultTime = is_int($timeConst) ? $timeConst : 4;
            $defaultThreads = is_int($threadsConst) ? $threadsConst : 1;

            $this->options = [
                'memory_cost' => max(8192, (int) ($argonCfg['memory_cost'] ?? $defaultMemory)),
                'time_cost' => max(1, (int) ($argonCfg['time_cost'] ?? $defaultTime)),
                'threads' => max(1, (int) ($argonCfg['threads'] ?? $defaultThreads)),
            ];
        } else {
            $this->algorithm = PASSWORD_BCRYPT;
            $bcryptCfg = is_array($config['bcrypt'] ?? null) ? $config['bcrypt'] : [];
            $cost = (int) ($bcryptCfg['cost'] ?? 12);

            $this->options = [
                'cost' => max(4, min(31, $cost)),
            ];
        }
    }

    /**
     * Hash a plaintext secret (password or 2FA recovery code) with the configured algorithm and options.
     */
    public function make(string $plain): string
    {
        return password_hash($plain, $this->algorithm, $this->options);
    }

    /**
     * Verify a plaintext secret against a stored password hash in constant time.
     */
    public function verify(string $plain, string $hash): bool
    {
        if ($hash === '') {
            $this->dummyVerify($plain);

            return false;
        }

        return password_verify($plain, $hash);
    }

    /**
     * Determine whether an existing hash should be transparently upgraded to the current algorithm/options.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }

    /**
     * Perform a real verification against a dummy hash produced with the exact same algorithm
     * and options as `make()`, keeping login timing uniform for unknown e-mails.
     */
    public function dummyVerify(string $plain): void
    {
        password_verify($plain, $this->dummyHash());
    }

    /**
     * Return (and lazily generate/cache) the dummy hash matching the active algorithm and options.
     */
    public function dummyHash(): string
    {
        if ($this->dummyHash !== null) {
            return $this->dummyHash;
        }

        $cacheFile = $this->dummyCacheFilePath();
        if ($cacheFile !== null && is_file($cacheFile) && is_readable($cacheFile)) {
            $cached = trim((string) @file_get_contents($cacheFile));
            if ($cached !== '' && !$this->needsRehash($cached)) {
                $this->dummyHash = $cached;

                return $this->dummyHash;
            }
        }

        $generated = $this->make('prime-dummy-' . bin2hex(random_bytes(24)));
        $this->dummyHash = $generated;

        if ($cacheFile !== null) {
            $this->persistDummyHash($cacheFile, $generated);
        }

        return $generated;
    }

    /**
     * Return the active PHP password algorithm identifier (`PASSWORD_ARGON2ID` or `PASSWORD_BCRYPT`).
     */
    public function algorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * Return the active PHP password algorithm options.
     *
     * @return array<string, int>
     */
    public function options(): array
    {
        return $this->options;
    }

    private function dummyCacheFilePath(): ?string
    {
        if ($this->cacheDir === null || trim($this->cacheDir) === '') {
            return null;
        }

        $encodedOptions = json_encode($this->options);
        if (!is_string($encodedOptions)) {
            throw new RuntimeException('Impossible de sérialiser les options de hachage.');
        }

        $fingerprint = substr(hash('sha256', $this->algorithm . ':' . $encodedOptions), 0, 16);

        return rtrim($this->cacheDir, '/\\') . '/auth-dummy-hash-' . $fingerprint . '.txt';
    }

    private function persistDummyHash(string $cacheFile, string $hash): void
    {
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }

        $tmpFile = $cacheFile . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmpFile, $hash . "\n", LOCK_EX) !== false) {
            @chmod($tmpFile, 0640);
            if (!@rename($tmpFile, $cacheFile)) {
                @unlink($tmpFile);
            }
        }
    }
}
