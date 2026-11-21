<?php

declare(strict_types=1);

namespace Prime\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Every relative link of the documentation (README, CHANGELOG, docs/, plugin READMEs) points to
 * a file that exists, and every `#anchor` to a heading of that file.
 */
final class DocumentationLinksTest extends TestCase
{
    public function testInternalLinksAreNotBroken(): void
    {
        $root = dirname(__DIR__, 3);
        $files = array_merge(
            glob($root . '/*.md') ?: [],
            glob($root . '/docs/*.md') ?: [],
            glob($root . '/docs/phases/*.md') ?: [],
            glob($root . '/content/plugins/*/README.md') ?: [],
        );
        $this->assertNotEmpty($files);

        $broken = [];
        foreach ($files as $file) {
            $markdown = (string) file_get_contents($file);
            // Code blocks and inline code are examples, not links.
            $markdown = (string) preg_replace(['/```.*?```/s', '/`[^`\n]*`/'], '', $markdown);
            preg_match_all('/\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $markdown, $matches);

            foreach ($matches[1] as $target) {
                if (preg_match('#^(https?:|mailto:|computer:)#i', $target) === 1) {
                    continue;
                }
                [$path, $anchor] = array_pad(explode('#', $target, 2), 2, '');
                $resolved = $path === '' ? $file : dirname($file) . '/' . rawurldecode($path);
                $where = substr($file, strlen($root) + 1) . ' → ' . $target;

                if (!file_exists($resolved)) {
                    $broken[] = $where . ' (fichier absent)';
                    continue;
                }
                if ($anchor !== '' && is_file($resolved) && str_ends_with($resolved, '.md') && !in_array($anchor, self::anchors($resolved), true)) {
                    $broken[] = $where . ' (ancre absente)';
                }
            }
        }

        $this->assertSame([], $broken);
    }

    /**
     * GitHub-style heading anchors.
     *
     * @return list<string>
     */
    private static function anchors(string $file): array
    {
        $content = (string) preg_replace('/```.*?```/s', '', (string) file_get_contents($file));
        preg_match_all('/^#{1,6}\s+(.+?)\s*#*$/m', $content, $headings);
        $anchors = [];
        foreach ($headings[1] as $heading) {
            $slug = mb_strtolower(strip_tags((string) preg_replace('/[`*_]/', '', $heading)));
            $slug = (string) preg_replace('/[^\p{L}\p{N}\s-]/u', '', $slug);
            $anchors[] = (string) preg_replace('/\s/', '-', trim($slug));
        }
        preg_match_all('/<a\s+(?:name|id)="([^"]+)"/', $content, $explicit);

        return array_merge($anchors, $explicit[1]);
    }
}
