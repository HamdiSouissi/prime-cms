<?php

declare(strict_types=1);

namespace Prime\Install;

use DateTimeZone;
use Prime\Security\PasswordPolicy;

/**
 * Pure validation rules shared by the web installation wizard and CLI `install` command.
 */
final class InstallerValidator
{
    public const MIN_PASSWORD_LENGTH = PasswordPolicy::MIN_LENGTH;

    /**
     * @var list<string>
     */
    public const SUPPORTED_LOCALES = ['fr', 'en', 'it'];

    private readonly PasswordPolicy $passwordPolicy;

    public function __construct(?PasswordPolicy $passwordPolicy = null)
    {
        $this->passwordPolicy = $passwordPolicy ?? new PasswordPolicy();
    }

    /**
     * Validate a table prefix: lowercase ASCII letters, digits, `_`, ending with `_` (2–20 chars).
     */
    public function validatePrefix(string $prefix): ?string
    {
        if ($prefix === '') {
            return 'Indiquez un préfixe de tables (ex. « pc_ »).';
        }

        if (strlen($prefix) > 20) {
            return 'Le préfixe ne doit pas dépasser 20 caractères.';
        }

        if (preg_match('/^(?=.*[a-z0-9])[a-z0-9_]{1,19}_$/', $prefix) !== 1) {
            return 'Utilisez uniquement des lettres minuscules, des chiffres et « _ », en terminant par « _ » (ex. « pc_ »).';
        }

        return null;
    }

    /**
     * Validate Step 3 (database connection parameters).
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function validateDatabase(array $input): array
    {
        $errors = [];

        $host = trim((string) ($input['db_host'] ?? ''));
        if ($host === '' || preg_match('/\s/', $host) === 1) {
            $errors['db_host'] = 'Indiquez un nom d\'hôte ou une adresse IP valide (ex. « 127.0.0.1 » ou « db »).';
        }

        $portRaw = trim((string) ($input['db_port'] ?? '3306'));
        if ($portRaw === '' || !ctype_digit($portRaw) || (int) $portRaw < 1 || (int) $portRaw > 65535) {
            $errors['db_port'] = 'Indiquez un numéro de port compris entre 1 et 65535 (3306 par défaut).';
        }

        $database = trim((string) ($input['db_database'] ?? ''));
        if ($database === '' || strlen($database) > 64 || preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]*$/', $database) !== 1) {
            $errors['db_database'] = 'Indiquez un nom de base valide (lettres, chiffres, « _ » ou « - », 64 caractères max).';
        }

        $username = trim((string) ($input['db_username'] ?? ''));
        if ($username === '') {
            $errors['db_username'] = 'Indiquez le nom d\'utilisateur MySQL/MariaDB.';
        }

        $prefix = (string) ($input['db_prefix'] ?? '');
        $prefixError = $this->validatePrefix($prefix);
        if ($prefixError !== null) {
            $errors['db_prefix'] = $prefixError;
        }

        return $errors;
    }

    /**
     * Validate Step 4 (site identity and regional parameters).
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function validateSite(array $input): array
    {
        $errors = [];

        $siteName = trim((string) ($input['site_name'] ?? ''));
        if (mb_strlen($siteName, 'UTF-8') < 2 || mb_strlen($siteName, 'UTF-8') > 120) {
            $errors['site_name'] = 'Saisissez un nom de site compris entre 2 et 120 caractères.';
        }

        $siteUrl = rtrim(trim((string) ($input['site_url'] ?? '')), '/');
        $parsedScheme = parse_url($siteUrl, PHP_URL_SCHEME);
        $parsedHost = parse_url($siteUrl, PHP_URL_HOST);
        if (
            $siteUrl === ''
            || filter_var($siteUrl, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) $parsedScheme), ['http', 'https'], true)
            || !is_string($parsedHost)
            || $parsedHost === ''
        ) {
            $errors['site_url'] = 'Saisissez une URL complète commençant par http:// ou https:// (ex. « https://monsite.fr »).';
        }

        $locale = trim((string) ($input['site_locale'] ?? ''));
        if (!in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $errors['site_locale'] = 'Choisissez une langue prise en charge (fr, en ou it).';
        }

        $timezone = trim((string) ($input['timezone'] ?? ''));
        if ($timezone === '' || !in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Sélectionnez un fuseau horaire IANA valide (ex. « Europe/Paris »).';
        }

        return $errors;
    }

    /**
     * Validate Step 5 (administrator account and password strength).
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function validateAdmin(array $input, string $siteName = ''): array
    {
        $errors = [];

        $displayName = trim((string) ($input['display_name'] ?? ''));
        if (mb_strlen($displayName, 'UTF-8') < 2 || mb_strlen($displayName, 'UTF-8') > 100) {
            $errors['display_name'] = 'Indiquez un nom affiché compris entre 2 et 100 caractères.';
        }

        $email = trim((string) ($input['email'] ?? ''));
        if ($email === '' || strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Saisissez une adresse e-mail valide (ex. « admin@monsite.fr »).';
        }

        $password = (string) ($input['password'] ?? '');
        $passwordError = $this->validatePassword($password, $email, $displayName, $siteName);
        if ($passwordError !== null) {
            $errors['password'] = $passwordError;
        }

        $confirmation = (string) ($input['password_confirmation'] ?? '');
        if ($password !== $confirmation) {
            $errors['password_confirmation'] = 'Les deux mots de passe ne correspondent pas. Ressaisissez la confirmation à l\'identique.';
        }

        return $errors;
    }

    /**
     * Enforce the shared Prime CMS PasswordPolicy (length, dictionary, personal context).
     */
    public function validatePassword(
        string $password,
        string $email = '',
        string $displayName = '',
        string $siteName = '',
    ): ?string {
        return $this->passwordPolicy->validate($password, [
            'email' => $email,
            'display_name' => $displayName,
            'site_name' => $siteName,
        ]);
    }

    /**
     * Return a curated list of common IANA timezones plus all PHP identifiers for the Site step.
     *
     * @return array<string, string>
     */
    public static function timezoneOptions(): array
    {
        $featured = [
            'Europe/Paris' => 'Europe/Paris (CET/CEST)',
            'UTC' => 'UTC (Temps universel)',
            'Europe/Brussels' => 'Europe/Brussels',
            'Europe/Zurich' => 'Europe/Zurich',
            'Europe/London' => 'Europe/London',
            'Europe/Rome' => 'Europe/Rome',
            'America/Montreal' => 'America/Montreal',
            'America/Toronto' => 'America/Toronto',
            'America/New_York' => 'America/New_York',
            'Africa/Tunis' => 'Africa/Tunis',
            'Africa/Casablanca' => 'Africa/Casablanca',
        ];

        foreach (DateTimeZone::listIdentifiers() as $tz) {
            if (!isset($featured[$tz])) {
                $featured[$tz] = $tz;
            }
        }

        return $featured;
    }
}
