<?php

declare(strict_types=1);

namespace Prime\Site\Seo;

use Prime\Site\UrlGenerator;

/**
 * Generates dynamic `/robots.txt` when no physical `public/robots.txt` exists.
 */
final class RobotsGenerator
{
    public function __construct(
        private readonly SeoSettings $seoSettings,
        private readonly UrlGenerator $urls,
        private readonly string $publicRoot,
    ) {
    }

    /**
     * Return physical `public/robots.txt` content if present, otherwise generated dynamic rules.
     */
    public function generate(): string
    {
        $physical = rtrim($this->publicRoot, DIRECTORY_SEPARATOR . '/') . '/robots.txt';
        if (is_file($physical) && is_readable($physical)) {
            $raw = @file_get_contents($physical);
            if (is_string($raw)) {
                return $raw;
            }
        }

        if ($this->seoSettings->globalNoindex()) {
            return "User-agent: *\nDisallow: /\n";
        }

        $custom = $this->seoSettings->robotsCustom();
        $sitemapUrl = $this->urls->absolute('/sitemap.xml');

        if ($custom !== '') {
            $body = rtrim($custom) . "\n";
            if (stripos($body, 'Sitemap:') === false) {
                $body .= "\nSitemap: " . $sitemapUrl . "\n";
            }

            return $body;
        }

        return implode("\n", [
            'User-agent: *',
            'Disallow: /admin/',
            'Disallow: /install/',
            'Allow: /',
            '',
            'Sitemap: ' . $sitemapUrl,
            '',
        ]);
    }
}
