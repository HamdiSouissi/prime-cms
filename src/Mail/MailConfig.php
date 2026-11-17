<?php

declare(strict_types=1);

namespace Prime\Mail;

use Prime\Kernel\Config;
use Prime\Kernel\Env;
use Prime\Settings\Settings;

/**
 * Effective mail configuration: database settings, then `config/mail.php`, then environment.
 *
 * The precedence rule is deliberate. `config/settings.php` seeds defaults from `Env`, so a `.env`
 * value is honoured on a fresh install, the moment an administrator saves the mail screen the
 * database wins — and `MailConfig` is the only place that knows how the three sources stack up.
 * Reading it through this object (never through `config('mail.*')`) is what makes a saved password
 * reach the transport without a deploy.
 */
final class MailConfig
{
    public const SETTING_DRIVER = 'mail.driver';
    public const SETTING_FROM_ADDRESS = 'mail.from_address';
    public const SETTING_FROM_NAME = 'mail.from_name';
    public const SETTING_REPLY_TO = 'mail.reply_to';
    public const SETTING_HOST = 'mail.host';
    public const SETTING_PORT = 'mail.port';
    public const SETTING_ENCRYPTION = 'mail.encryption';
    public const SETTING_USERNAME = 'mail.username';
    public const SETTING_PASSWORD = 'mail.password';
    public const SETTING_TIMEOUT = 'mail.timeout';
    public const SETTING_VERIFY_TLS = 'mail.verify_tls';
    public const SETTING_LOG_ENABLED = 'mail.log_enabled';
    public const SETTING_LOG_DAYS = 'mail.log_days';

    public const DEFAULT_LOG_DAYS = 30;

    public const DRIVER_LOG = 'log';
    public const DRIVER_NATIVE = 'native';
    public const DRIVER_SMTP = 'smtp';
    public const DRIVER_QUEUE = 'queue';

    public const ENCRYPTION_NONE = '';
    public const ENCRYPTION_SSL = 'ssl';
    public const ENCRYPTION_STARTTLS = 'tls';

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(public readonly array $values = [])
    {
    }

    /**
     * Resolve the three sources into one immutable set of values.
     */
    public static function resolve(?Settings $settings = null, ?Config $config = null): self
    {
        $pick = static function (string $setting, string $configKey, mixed $default) use ($settings, $config): mixed {
            if ($settings !== null) {
                $value = $settings->get($setting, null);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }

            if ($config !== null) {
                $value = $config->get($configKey, null);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }

            return $default;
        };

        $password = null;
        if ($settings !== null) {
            // Sealed with `setSecret()` by the settings form, plain in `config/mail.php`.
            $password = $settings->getSecret(self::SETTING_PASSWORD);
        }

        if ($password === null || $password === '') {
            $password = Env::get('MAIL_PASSWORD', '');
        }

        return new self([
            'driver' => strtolower(trim((string) $pick(self::SETTING_DRIVER, 'mail.driver', self::DRIVER_LOG))),
            'from_address' => trim((string) $pick(self::SETTING_FROM_ADDRESS, 'mail.from.address', 'no-reply@prime-cms.local')),
            'from_name' => trim((string) $pick(self::SETTING_FROM_NAME, 'mail.from.name', 'Prime CMS')),
            'reply_to' => trim((string) $pick(self::SETTING_REPLY_TO, 'mail.reply_to', '')),
            'host' => trim((string) $pick(self::SETTING_HOST, 'mail.host', '')),
            'port' => (int) $pick(self::SETTING_PORT, 'mail.port', 587),
            'encryption' => strtolower(trim((string) $pick(self::SETTING_ENCRYPTION, 'mail.encryption', self::ENCRYPTION_STARTTLS))),
            'username' => trim((string) $pick(self::SETTING_USERNAME, 'mail.username', '')),
            // A stray space here is an authentication failure nobody can read off the error message.
            'password' => trim((string) $password),
            'timeout' => (int) $pick(self::SETTING_TIMEOUT, 'mail.timeout', 15),
            'verify_peer' => (bool) $pick(self::SETTING_VERIFY_TLS, 'mail.verify_peer', true),
            'log_enabled' => (bool) $pick(self::SETTING_LOG_ENABLED, 'mail.log_enabled', true),
            'log_days' => (int) $pick(self::SETTING_LOG_DAYS, 'mail.log_days', 30),
        ]);
    }

    public function driver(): string
    {
        return (string) ($this->values['driver'] ?? self::DRIVER_LOG);
    }

    public function fromAddress(): string
    {
        return (string) ($this->values['from_address'] ?? '');
    }

    public function fromName(): string
    {
        return (string) ($this->values['from_name'] ?? '');
    }

    public function replyTo(): string
    {
        return (string) ($this->values['reply_to'] ?? '');
    }

    public function host(): string
    {
        return (string) ($this->values['host'] ?? '');
    }

    public function port(): int
    {
        return (int) ($this->values['port'] ?? 587);
    }

    public function encryption(): string
    {
        $encryption = strtolower(trim((string) ($this->values['encryption'] ?? self::ENCRYPTION_STARTTLS)));

        // An administrator may type "starttls", "none" or "none " in a plugin-driven form: accept the
        // obvious synonyms instead of silently opening an unencrypted connection.
        return match ($encryption) {
            'starttls' => self::ENCRYPTION_STARTTLS,
            'implicit', 'smtps' => self::ENCRYPTION_SSL,
            'none', 'no', 'plain' => self::ENCRYPTION_NONE,
            default => $encryption,
        };
    }

    public function username(): string
    {
        return (string) ($this->values['username'] ?? '');
    }

    public function password(): string
    {
        return (string) ($this->values['password'] ?? '');
    }

    public function timeout(): int
    {
        return max(5, min(120, (int) ($this->values['timeout'] ?? 15)));
    }

    public function verifyPeer(): bool
    {
        return (bool) ($this->values['verify_peer'] ?? true);
    }

    public function logEnabled(): bool
    {
        return (bool) ($this->values['log_enabled'] ?? true);
    }

    public function logDays(): int
    {
        return max(1, min(365, (int) ($this->values['log_days'] ?? 30)));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function with(array $overrides): self
    {
        return new self(array_merge($this->values, $overrides));
    }

    /**
     * Whether SMTP settings are usable at all — the answer `mail:test` and the health notice need.
     */
    public function isSmtpConfigured(): bool
    {
        if (!$this->usesSmtp()) {
            return true;
        }

        return $this->host() !== ''
            && filter_var($this->fromAddress(), FILTER_VALIDATE_EMAIL) !== false
            && ($this->username() === '' || $this->password() !== '');
    }

    public function usesSmtp(): bool
    {
        return in_array($this->driver(), [self::DRIVER_SMTP, self::DRIVER_QUEUE], true);
    }

    /**
     * Deliveries are parked in the queue instead of being pushed at the visitor.
     */
    public function usesQueue(): bool
    {
        return $this->driver() === self::DRIVER_QUEUE;
    }

    /**
     * The domain part of generated `Message-ID` values.
     */
    public function domain(): string
    {
        $at = strrpos($this->fromAddress(), '@');

        return $at === false ? 'localhost' : substr($this->fromAddress(), $at + 1);
    }

    /**
     * Configuration summary for the admin screen and `mail:test`: the password never leaves this object.
     *
     * @return array<string, mixed>
     */
    public function redacted(): array
    {
        return [
            'driver' => $this->driver(),
            'from_address' => $this->fromAddress(),
            'from_name' => $this->fromName(),
            'reply_to' => $this->replyTo(),
            'host' => $this->host(),
            'port' => $this->port(),
            'encryption' => $this->encryption(),
            'username' => $this->username(),
            'password' => $this->password() !== '' ? '••••••••' : '',
            'timeout' => $this->timeout(),
            'verify_peer' => $this->verifyPeer(),
            'log_enabled' => $this->logEnabled(),
            'log_days' => $this->logDays(),
        ];
    }
}
