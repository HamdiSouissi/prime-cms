<?php

declare(strict_types=1);

namespace Prime\Security;

use Prime\Settings\Settings;

/**
 * Unified password policy enforced across the installer, profile, password reset,
 * user management, and CLI commands.
 *
 * The character floor and the optional hardening rules are administrator-tunable through the
 * settings registry (`security.password_*`); a bare `new PasswordPolicy()` keeps the historic
 * defaults so the installer, the CLI and the unit tests stay independent from the database.
 *
 * Rules:
 * - Minimum 12 characters (UTF-8), maximum 4096 characters (prevents Argon2/bcrypt DoS).
 * - Rejects single-character repetition (e.g. `aaaaaaaaaaaa`).
 * - Rejects passwords containing the user's e-mail, e-mail local part, display name, or site name.
 * - Rejects common/breached passwords listed in `resources/security/common-passwords.txt`.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    public const MAX_LENGTH = 4096;

    /**
     * Bounds the administrator may pick in `security.password_min_length`.
     */
    public const MIN_LENGTH_FLOOR = 8;

    public const MIN_LENGTH_CEILING = 64;

    /**
     * Cached set of common weak passwords indexed by lowercase string.
     *
     * @var array<string, array<string, true>>
     */
    private static array $dictionaryCache = [];

    private readonly string $dictionaryPath;

    private readonly int $minLength;

    /**
     * @var array<string, bool>
     */
    private readonly array $rules;

    /**
     * @param array<string, bool>|null $rules Hardening toggles; unknown keys are ignored
     */
    public function __construct(?string $dictionaryPath = null, ?int $minLength = null, ?array $rules = null)
    {
        $this->dictionaryPath = $dictionaryPath
            ?? dirname(__DIR__, 2) . '/resources/security/common-passwords.txt';

        $this->minLength = $minLength === null
            ? self::MIN_LENGTH
            : max(self::MIN_LENGTH_FLOOR, min(self::MIN_LENGTH_CEILING, $minLength));

        $this->rules = array_merge([
            'require_mixed_case' => false,
            'require_number' => false,
            'require_special' => false,
            'block_common' => true,
            'block_personal' => true,
            'block_repetition' => true,
        ], array_intersect_key((array) ($rules ?? []), array_flip([
            'require_mixed_case',
            'require_number',
            'require_special',
            'block_common',
            'block_personal',
            'block_repetition',
        ])));
    }

    /**
     * Build the policy from the persisted `security.password_*` settings.
     */
    public static function fromSettings(Settings $settings): self
    {
        return new self(
            minLength: $settings->getInt('security.password_min_length', self::MIN_LENGTH, self::MIN_LENGTH_FLOOR, self::MIN_LENGTH_CEILING),
            rules: [
                'require_mixed_case' => $settings->getBool('security.password_require_mixed_case', false),
                'require_number' => $settings->getBool('security.password_require_number', false),
                'require_special' => $settings->getBool('security.password_require_special', false),
                'block_common' => $settings->getBool('security.password_block_common', true),
            ],
        );
    }

    public function minLength(): int
    {
        return $this->minLength;
    }

    /**
     * Human-readable summary of the active rules, for admin screens and CLI output.
     *
     * @return list<string>
     */
    public function describe(): array
    {
        $lines = [sprintf('Au moins %d caractères.', $this->minLength)];

        if ($this->rules['require_mixed_case']) {
            $lines[] = 'Une majuscule et une minuscule.';
        }

        if ($this->rules['require_number']) {
            $lines[] = 'Au moins un chiffre.';
        }

        if ($this->rules['require_special']) {
            $lines[] = 'Au moins un caractère spécial.';
        }

        if ($this->rules['block_common']) {
            $lines[] = 'Ni mot de passe courant, ni nom, ni e-mail.';
        }

        return $lines;
    }

    /**
     * Validate a password against the policy and optional personal context.
     *
     * @param array{email?: string, display_name?: string, site_name?: string} $context
     * @return string|null First error message in French, or null if valid
     */
    public function validate(string $password, array $context = []): ?string
    {
        $length = mb_strlen($password, 'UTF-8');

        if ($length < $this->minLength) {
            return sprintf(
                'Le mot de passe doit contenir au moins %d caractères (ajoutez des mots, chiffres ou symboles).',
                $this->minLength,
            );
        }

        if ($this->rules['require_mixed_case']
            && (preg_match('/\p{Lu}/u', $password) !== 1 || preg_match('/\p{Ll}/u', $password) !== 1)) {
            return 'Le mot de passe doit contenir au moins une majuscule et une minuscule.';
        }

        if ($this->rules['require_number'] && preg_match('/\p{N}/u', $password) !== 1) {
            return 'Le mot de passe doit contenir au moins un chiffre.';
        }

        if ($this->rules['require_special'] && preg_match('/[^\p{L}\p{N}]/u', $password) !== 1) {
            return 'Le mot de passe doit contenir au moins un caractère spécial.';
        }

        if ($length > self::MAX_LENGTH || strlen($password) > self::MAX_LENGTH) {
            return sprintf(
                'Le mot de passe ne peut pas dépasser %d caractères.',
                self::MAX_LENGTH,
            );
        }

        $lower = mb_strtolower(trim($password), 'UTF-8');

        if ($this->rules['block_repetition'] && preg_match('/^(.)\1+$/u', $lower) === 1) {
            return 'Choisissez un mot de passe plus varié plutôt qu\'un caractère répété.';
        }

        $compact = preg_replace('/[\s._-]+/', '', $lower) ?? $lower;
        $common = $this->rules['block_common'] ? $this->commonPasswords() : [];

        if (isset($common[$lower]) || isset($common[$compact])) {
            return 'Ce mot de passe est trop courant. Combinez plusieurs mots inhabituels ou utilisez un gestionnaire de mots de passe.';
        }

        if (!$this->rules['block_personal']) {
            return null;
        }

        $email = trim((string) ($context['email'] ?? ''));
        if ($email !== '') {
            $lowerEmail = mb_strtolower($email, 'UTF-8');
            if (str_contains($lower, $lowerEmail)) {
                return 'Le mot de passe ne doit pas contenir votre adresse e-mail.';
            }

            $localPart = strstr($lowerEmail, '@', true);
            if (is_string($localPart) && mb_strlen($localPart, 'UTF-8') >= 4 && str_contains($lower, $localPart)) {
                return 'Le mot de passe est trop proche de votre adresse e-mail. Choisissez une phrase secrète distincte.';
            }
        }

        $displayName = trim((string) ($context['display_name'] ?? ''));
        if ($displayName !== '') {
            $lowerDisplay = mb_strtolower($displayName, 'UTF-8');
            if (mb_strlen($lowerDisplay, 'UTF-8') >= 4 && str_contains($lower, $lowerDisplay)) {
                return 'Le mot de passe ne doit pas reprendre votre nom affiché. Choisissez une combinaison distincte.';
            }

            // Also reject individual display name words of 4+ chars (e.g. first or last name).
            $tokens = preg_split('/[\s._-]+/u', $lowerDisplay) ?: [];
            foreach ($tokens as $token) {
                if (mb_strlen($token, 'UTF-8') >= 4 && str_contains($lower, $token)) {
                    return 'Le mot de passe ne doit pas contenir votre nom. Choisissez une phrase secrète distincte.';
                }
            }
        }

        $siteName = trim((string) ($context['site_name'] ?? ''));
        if ($siteName !== '') {
            $lowerSite = mb_strtolower($siteName, 'UTF-8');
            if (mb_strlen($lowerSite, 'UTF-8') >= 4 && str_contains($lower, $lowerSite)) {
                return 'Le mot de passe ne doit pas reprendre le nom du site. Choisissez un mot de passe unique.';
            }
        }

        return null;
    }

    /**
     * Convenience boolean check.
     *
     * @param array{email?: string, display_name?: string, site_name?: string} $context
     */
    public function passes(string $password, array $context = []): bool
    {
        return $this->validate($password, $context) === null;
    }

    /**
     * Load the common passwords set (cached per path for the request lifetime).
     *
     * @return array<string, true>
     */
    private function commonPasswords(): array
    {
        if (isset(self::$dictionaryCache[$this->dictionaryPath])) {
            return self::$dictionaryCache[$this->dictionaryPath];
        }

        $set = [];
        if (is_file($this->dictionaryPath) && is_readable($this->dictionaryPath)) {
            $lines = file($this->dictionaryPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                        continue;
                    }
                    $set[mb_strtolower($trimmed, 'UTF-8')] = true;
                }
            }
        }

        self::$dictionaryCache[$this->dictionaryPath] = $set;

        return $set;
    }
}
