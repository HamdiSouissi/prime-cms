<?php

declare(strict_types=1);

namespace Prime\Security;

use Prime\Http\Request;
use Prime\Http\Session;

/**
 * Per-session synchronizer CSRF token generator and constant-time validator.
 */
final class Csrf
{
    public const FIELD_NAME = '_csrf_token';
    public const HEADER_NAME = 'X-CSRF-Token';

    private const SESSION_KEY = '_prime_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    /**
     * Return the active session's CSRF token, generating a 256-bit token if missing.
     */
    public function token(): string
    {
        $existing = $this->session->get(self::SESSION_KEY);
        if (is_string($existing) && strlen($existing) === 64 && ctype_xdigit($existing)) {
            return $existing;
        }

        return $this->regenerate();
    }

    /**
     * Generate and store a fresh 256-bit cryptographically random CSRF token.
     */
    public function regenerate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    /**
     * Verify a submitted token against the session token using constant-time comparison.
     */
    public function validate(?string $submittedToken): bool
    {
        if ($submittedToken === null || $submittedToken === '') {
            return false;
        }

        $expected = $this->session->get(self::SESSION_KEY);
        if (!is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $submittedToken);
    }

    /**
     * Extract a CSRF token from either the POST body (`_csrf_token`) or the `X-CSRF-Token` header.
     */
    public function extractFromRequest(Request $request): ?string
    {
        $bodyToken = $request->body(self::FIELD_NAME);
        if (is_string($bodyToken) && $bodyToken !== '') {
            return $bodyToken;
        }

        $headerToken = $request->header(self::HEADER_NAME);
        if ($headerToken !== null && $headerToken !== '') {
            return $headerToken;
        }

        return null;
    }

    /**
     * Render a hidden HTML `<input>` element carrying the active CSRF token.
     */
    public function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD_NAME,
            htmlspecialchars($this->token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }
}
