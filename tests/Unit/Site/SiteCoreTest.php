<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Site;

use PHPUnit\Framework\TestCase;
use Prime\Auth\User;
use Prime\Content\ContentType;
use Prime\Content\Entry;
use Prime\Content\Term;
use Prime\Hooks\Hooks;
use Prime\Http\Request;
use Prime\Site\PageContext;
use Prime\Site\PermalinkSettings;
use Prime\Site\ReservedSlugValidator;
use Prime\Site\Seo\SeoManager;
use Prime\Site\Seo\SeoSettings;
use Prime\Site\UrlGenerator;
use Prime\Site\View\EntryView;
use Prime\Themes\PublicCsp;

/**
 * Unit tests for Phase 8 Public Site Core:
 * - ReservedSlugValidator (system routes, taxonomy bases, blog/author/search)
 * - UrlGenerator (all 3 post structures, pagination, terms, authors, search, absolute)
 * - PublicCsp (baseline headers + `site.csp` filter extensibility)
 * - EntryView XSS auto-escaping
 * - SeoManager (titles, descriptions, noindex rules, OG/Twitter, JSON-LD `</script>` safety)
 */
final class SiteCoreTest extends TestCase
{
    public function testReservedSlugValidatorBlocksSystemAndCoreSlugs(): void
    {
        $validator = new ReservedSlugValidator();

        foreach (['admin', 'install', 'uploads', 'assets', 'sitemap.xml', 'robots.txt', 'blog', 'page', 'categorie', 'etiquette', 'auteur', 'recherche'] as $slug) {
            $this->assertTrue($validator->isReserved($slug), "Expected [$slug] to be reserved.");
        }

        $this->assertFalse($validator->isReserved('a-propos'));
        $this->assertFalse($validator->isReserved('contact'));
        $this->assertTrue($validator->appliesToEntry('page', 0));
        $this->assertFalse($validator->appliesToEntry('page', 42));
    }

    public function testUrlGeneratorGeneratesCanonicalPathsForEntriesTermsAuthorsAndPagination(): void
    {
        $permalinks = new PermalinkSettings();
        $urls = new UrlGenerator($permalinks, baseUrl: 'https://prime.example');

        $post = $this->makeEntry(id: 10, type: 'post', title: 'Hello', slug: 'hello-world', publishedAt: '2026-11-15 10:00:00');
        $this->assertSame('/blog/hello-world', $urls->entryUrl($post));
        $this->assertSame('https://prime.example/blog/hello-world', $urls->absolute($urls->entryUrl($post)));

        $page = $this->makeEntry(id: 2, type: 'page', title: 'À propos', slug: 'a-propos');
        $this->assertSame('/a-propos', $urls->entryUrl($page));

        $cat = new Term(id: 3, taxonomy: 'category', name: 'Actualités', slug: 'actualites', description: '', parentId: 0, menuOrder: 0, count: 2, createdAt: '2026-11-15 00:00:00', updatedAt: '2026-11-15 00:00:00');
        $this->assertSame('/categorie/actualites', $urls->termUrl($cat));

        $tag = new Term(id: 4, taxonomy: 'tag', name: 'PHP', slug: 'php', description: '', parentId: 0, menuOrder: 0, count: 5, createdAt: '2026-11-15 00:00:00', updatedAt: '2026-11-15 00:00:00');
        $this->assertSame('/etiquette/php', $urls->termUrl($tag));

        $author = $this->makeUser(7, 'claire@prime.test', 'Claire Moreau', 'claire-moreau', 'Éditrice');
        $this->assertSame('/auteur/claire-moreau', $urls->authorUrl($author));

        $this->assertSame('/', $urls->blogUrl(1));
        $this->assertSame('/page/2', $urls->blogUrl(2));
        $this->assertSame('/recherche?q=prime%20cms', $urls->searchUrl('prime cms', 1));
        $this->assertSame('/recherche/page/3?q=prime', $urls->searchUrl('prime', 3));
    }

    public function testPublicCspBaselineAndSiteCspFilter(): void
    {
        $hooks = new Hooks();
        $csp = new PublicCsp($hooks);
        $baseline = $csp->headerValue(Request::create('GET', '/'));

        $this->assertStringContainsString("default-src 'self'", $baseline);
        $this->assertStringContainsString("script-src 'self'", $baseline);
        $this->assertStringContainsString("style-src-attr 'unsafe-inline'", $baseline);
        $this->assertStringContainsString('frame-src https://www.youtube-nocookie.com https://player.vimeo.com', $baseline);
        $this->assertStringContainsString("object-src 'none'", $baseline);

        $hooks->filter('site.csp', static function (array $directives): array {
            $directives['img-src'] = "'self' data: https://cdn.prime.test";

            return $directives;
        });

        $filtered = $csp->headerValue(Request::create('GET', '/'));
        $this->assertStringContainsString('https://cdn.prime.test', $filtered);
    }

    public function testEntryViewEscapesHtmlByDefault(): void
    {
        $entry = $this->makeEntry(
            id: 5,
            type: 'post',
            title: '<script>alert("xss")</script> Titre',
            slug: 'xss-post',
            excerpt: '<img src=x onerror=alert(1)> Résumé',
            contentHtml: '<p>Contenu sûr</p>',
            contentText: str_repeat('mot ', 420),
        );
        $author = $this->makeUser(2, 'a@b.test', '<b>Alice</b>', 'alice', '<script>x</script>Bio');

        $urls = new UrlGenerator(new PermalinkSettings(), baseUrl: 'https://prime.example');
        $view = new EntryView(
            entry: $entry,
            urls: $urls,
            authorUser: $author,
        );

        $this->assertStringNotContainsString('<script>', $view->title());
        $this->assertStringContainsString('&lt;script&gt;', $view->title());
        $this->assertStringNotContainsString('<img', $view->excerpt());
        $authorData = $view->author();
        $this->assertStringNotContainsString('<b>', $authorData['name']);
        $this->assertStringNotContainsString('<script>', $authorData['bio']);
        $this->assertSame('<p>Contenu sûr</p>', $view->content());
        $this->assertGreaterThanOrEqual(2, $view->readingTime());
    }

    public function testSeoManagerGeneratesSafeMetaTagsNoindexAndEscapedJsonLd(): void
    {
        $permalinks = new PermalinkSettings();
        $urls = new UrlGenerator($permalinks, baseUrl: 'https://prime.example');
        $seo = new SeoManager(new SeoSettings(), $urls);

        $entry = $this->makeEntry(
            id: 9,
            type: 'post',
            title: 'Guide </script><script>alert(1)</script>',
            slug: 'guide-seo',
            excerpt: 'Une description éditoriale propre.',
            publishedAt: '2026-11-15 12:00:00',
            meta: ['seo_title' => 'Titre SEO personnalisé'],
        );

        $ctx = new PageContext(
            type: PageContext::TYPE_ENTRY,
            path: '/blog/guide-seo',
            entry: $entry,
            contentType: new ContentType('post', ['labels' => ['plural' => 'Articles', 'singular' => 'Article']]),
        );

        $head = $seo->renderHeadTags($ctx);
        $this->assertStringContainsString('<title>Titre SEO personnalisé</title>', $head);
        $this->assertStringContainsString('rel="canonical" href="https://prime.example/blog/guide-seo"', $head);
        $this->assertStringContainsString('property="og:type" content="article"', $head);
        $this->assertStringContainsString('application/ld+json', $head);
        // Critical: `</script>` inside JSON-LD must NEVER break out of the script tag
        $this->assertStringNotContainsString('</script><script>', $head);

        // Search & 404 pages must emit noindex
        $searchCtx = new PageContext(type: PageContext::TYPE_SEARCH, path: '/recherche', searchQuery: 'test');
        $this->assertTrue($seo->shouldNoindex($searchCtx));
        $this->assertStringContainsString('noindex', $seo->renderHeadTags($searchCtx));

        $notFoundCtx = new PageContext(type: PageContext::TYPE_NOT_FOUND, path: '/nope', httpStatus: 404);
        $this->assertTrue($seo->shouldNoindex($notFoundCtx));
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function makeEntry(
        int $id,
        string $type,
        string $title,
        string $slug,
        string $excerpt = '',
        string $contentHtml = '<p>Hello</p>',
        string $contentText = 'Hello',
        ?string $publishedAt = null,
        array $meta = [],
    ): Entry {
        return new Entry(
            id: $id,
            type: $type,
            status: Entry::STATUS_PUBLISHED,
            title: $title,
            slug: $slug,
            excerpt: $excerpt,
            content: '{"version":1,"blocks":[]}',
            contentHtml: $contentHtml,
            contentText: $contentText,
            authorId: 1,
            parentId: 0,
            menuOrder: 0,
            template: null,
            publishedAt: $publishedAt,
            previousStatus: null,
            trashedAt: null,
            createdAt: '2026-11-15 09:00:00',
            updatedAt: '2026-11-15 09:00:00',
            meta: $meta,
        );
    }

    private function makeUser(int $id, string $email, string $displayName, ?string $username = null, ?string $bio = null): User
    {
        return new User(
            id: $id,
            email: $email,
            displayName: $displayName,
            status: 'active',
            locale: 'fr',
            sessionVersion: 1,
            lastLoginAt: null,
            passwordChangedAt: null,
            twoFactorConfirmedAt: null,
            twoFactorLastStep: null,
            createdAt: '2026-11-15 00:00:00',
            updatedAt: '2026-11-15 00:00:00',
            username: $username,
            bio: $bio,
        );
    }
}
