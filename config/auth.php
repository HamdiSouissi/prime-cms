<?php

declare(strict_types=1);

use Prime\Kernel\Env;

return [
    // Maximum inactivity duration before automatic logout (seconds — default 2h).
    'session_idle_timeout' => (int) Env::get('AUTH_SESSION_IDLE_TIMEOUT', '7200'),

    // Maximum total session lifetime before re-authentication (seconds — default 12h).
    'session_absolute_timeout' => (int) Env::get('AUTH_SESSION_ABSOLUTE_TIMEOUT', '43200'),

    // Remember-me token lifetime (seconds — default 30 days).
    'remember_ttl' => (int) Env::get('AUTH_REMEMBER_TTL', '2592000'),

    // Remember-me cookie name.
    'remember_cookie' => 'prime_remember',

    // Login rate limiting: 5 failures / (email + IP) / 15 min, and 20 / IP / 15 min.
    'login_max_attempts' => 5,
    'login_ip_max_attempts' => 20,
    'login_decay_seconds' => 900,

    // Password reset rate limits: 3 / hour / email, and 10 / hour / IP.
    'reset_email_max_attempts' => 3,
    'reset_ip_max_attempts' => 10,
    'reset_decay_seconds' => 3600,

    // Password reset token TTL (seconds — default 60 min) and user invite TTL (48h).
    'reset_token_ttl' => 3600,
    'invite_token_ttl' => 172800,

    // Pending 2FA login challenge limits (5 attempts, 5 minutes).
    'two_factor_max_attempts' => 5,
    'two_factor_ttl' => 300,
];
