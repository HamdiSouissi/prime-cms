<?php

declare(strict_types=1);

use Prime\Kernel\Env;

/*
 * Defaults for the mailer, overridable per site in Réglages → E-mails (the `mail.*` options win).
 * See `Prime\Mail\MailConfig::resolve()` for the precedence.
 */

$bool = static fn (mixed $value, bool $default): bool => $value === null || $value === ''
    ? $default
    : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default);

return [
    // 'log' writes into storage/logs/mail-*.log, 'native' uses PHP mail(), 'smtp' talks to a server,
    // 'queue' hands the message to `mail.send` so a visitor never waits for a socket.
    'driver' => Env::get('MAIL_DRIVER', 'log'),

    'from' => [
        'address' => Env::get('MAIL_FROM_ADDRESS', 'no-reply@prime-cms.local'),
        'name' => Env::get('MAIL_FROM_NAME', 'Prime CMS'),
    ],

    'reply_to' => Env::get('MAIL_REPLY_TO_ADDRESS', ''),

    'host' => Env::get('MAIL_HOST', ''),
    'port' => (int) Env::get('MAIL_PORT', 587),

    // '' (none), 'tls' (STARTTLS, port 587) or 'ssl' (implicit TLS, port 465).
    'encryption' => Env::get('MAIL_ENCRYPTION', 'tls'),
    'username' => Env::get('MAIL_USERNAME', ''),
    'password' => Env::get('MAIL_PASSWORD', ''),
    'timeout' => (int) Env::get('MAIL_TIMEOUT', 15),

    // Turning verification off is how a self-signed internal relay gets used; keep it on otherwise.
    'verify_peer' => $bool(Env::get('MAIL_VERIFY_PEER', null), true),

    'log_enabled' => $bool(Env::get('MAIL_LOG_ENABLED', null), true),
    'log_days' => max(1, min(365, (int) Env::get('MAIL_LOG_DAYS', 30))),
];
