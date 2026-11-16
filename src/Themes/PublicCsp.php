<?php

declare(strict_types=1);

namespace Prime\Themes;

use Prime\Hooks\Hooks;
use Prime\Http\Request;

/**
 * Builds the strict Content-Security-Policy header for public site responses
 * (zero inline scripts, embedded videos restricted to youtube-nocookie & player.vimeo)
 * and applies the `site.csp` filter hook for plugins.
 */
final class PublicCsp
{
    /**
     * Default CSP directives for public site pages.
     *
     * @var array<string, string>
     */
    public const DEFAULT_DIRECTIVES = [
        'default-src' => "'self'",
        'script-src' => "'self'",
        'style-src' => "'self'",
        'style-src-attr' => "'unsafe-inline'",
        'img-src' => "'self' data:",
        'font-src' => "'self'",
        'media-src' => "'self'",
        'connect-src' => "'self'",
        'frame-src' => 'https://www.youtube-nocookie.com https://player.vimeo.com',
        'object-src' => "'none'",
        'base-uri' => "'self'",
        'form-action' => "'self'",
        'frame-ancestors' => "'self'",
    ];

    public function __construct(
        private readonly ?Hooks $hooks = null,
    ) {
    }

    /**
     * Build the final CSP header value after running the `site.csp` filter.
     */
    public function headerValue(?Request $request = null): string
    {
        $directives = self::DEFAULT_DIRECTIVES;

        if ($this->hooks !== null) {
            $filtered = $this->hooks->apply('site.csp', $directives, $request);
            if (is_string($filtered) && trim($filtered) !== '') {
                return trim($filtered);
            }
            if (is_array($filtered)) {
                $clean = [];
                foreach ($filtered as $name => $value) {
                    if (is_string($name) && preg_match('/^[a-z0-9-]+$/', $name) === 1 && is_string($value)) {
                        $clean[$name] = trim(str_replace(["\r", "\n", ';'], '', $value));
                    }
                }
                if ($clean !== []) {
                    $directives = $clean;
                }
            }
        }

        $parts = [];
        foreach ($directives as $directive => $value) {
            $parts[] = $value !== '' ? $directive . ' ' . $value : $directive;
        }

        return implode('; ', $parts);
    }
}
