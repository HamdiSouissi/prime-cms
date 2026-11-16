<?php

declare(strict_types=1);

namespace Prime\Site\Zone;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Prime\Database\Connection;
use Prime\Editor\Renderer;
use Prime\Media\MediaUsageRepository;
use Prime\Themes\ThemeManager;

/**
 * Persistence and validation for theme block zones (`pc_zones`) edited via Prime Editor.
 *
 * Tracks embedded media inside zone documents via `MediaUsageRepository::syncForZoneDocument()`
 * so the Media Library reports when a media file is used in a zone (L6).
 */
final class ZoneRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly Renderer $renderer,
        private readonly ?MediaUsageRepository $mediaUsage = null,
        private readonly ?ThemeManager $themes = null,
    ) {
    }

    /**
     * Resolve the active theme slug used to scope persisted zones (`pc_zones.theme`).
     */
    public function activeThemeSlug(?string $theme = null): string
    {
        if ($theme !== null && trim($theme) !== '') {
            return trim($theme);
        }
        if ($this->themes !== null) {
            return $this->themes->configuredActiveSlug();
        }

        return ThemeManager::DEFAULT_THEME;
    }

    /**
     * Find a saved zone by slug (for the active or specified theme).
     *
     * @return ?array{id: int, theme: string, slug: string, content: string, content_html: string, updated_at: string}
     */
    public function find(string $slug, ?string $theme = null): ?array
    {
        $clean = trim($slug);
        if ($clean === '') {
            return null;
        }
        $themeSlug = $this->activeThemeSlug($theme);

        $row = $this->db->table('zones')
            ->where('theme', '=', $themeSlug)
            ->where('slug', '=', $clean)
            ->first();
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'theme' => (string) $row['theme'],
            'slug' => (string) $row['slug'],
            'content' => (string) ($row['content'] ?? ''),
            'content_html' => (string) ($row['content_html'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * Return all saved zones indexed by slug for the active (or specified) theme.
     *
     * @return array<string, array{id: int, theme: string, slug: string, content: string, content_html: string, updated_at: string}>
     */
    public function allIndexed(?string $theme = null): array
    {
        $themeSlug = $this->activeThemeSlug($theme);
        $rows = $this->db->table('zones')
            ->where('theme', '=', $themeSlug)
            ->orderBy('slug', 'ASC')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $slug = (string) $row['slug'];
            $out[$slug] = [
                'id' => (int) $row['id'],
                'theme' => (string) $row['theme'],
                'slug' => $slug,
                'content' => (string) ($row['content'] ?? ''),
                'content_html' => (string) ($row['content_html'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Decode a zone's Prime Editor JSON document (or empty document when absent).
     *
     * @return array{version: int, blocks: list<array{id: string, type: string, data: array<string, mixed>}>}
     */
    public function document(string $slug, ?string $theme = null): array
    {
        $zone = $this->find($slug, $theme);
        if ($zone === null || trim($zone['content']) === '') {
            return Renderer::emptyDocument();
        }

        $decoded = json_decode($zone['content'], true);
        if (!is_array($decoded) || !is_array($decoded['blocks'] ?? null)) {
            return Renderer::emptyDocument();
        }

        $blocks = [];
        foreach ($decoded['blocks'] as $idx => $block) {
            if (!is_array($block) || !is_string($block['type'] ?? null) || trim($block['type']) === '') {
                continue;
            }
            $id = is_string($block['id'] ?? null) && $block['id'] !== '' ? $block['id'] : 'blk_' . $idx;
            /** @var array<string, mixed> $data */
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $blocks[] = [
                'id' => $id,
                'type' => $block['type'],
                'data' => $data,
            ];
        }

        return [
            'version' => (int) ($decoded['version'] ?? 1),
            'blocks' => $blocks,
        ];
    }

    /**
     * Check whether a zone has at least one non-empty block.
     */
    public function hasContent(string $slug, ?string $theme = null): bool
    {
        $doc = $this->document($slug, $theme);

        return $doc['blocks'] !== [];
    }

    /**
     * Validate, normalize, render, save a zone document, and sync media usages.
     *
     * @param string|array<string, mixed> $rawDocument
     * @return array{id: int, theme: string, slug: string, content: string, content_html: string, block_count: int}
     */
    public function save(string $slug, string|array $rawDocument, bool $allowUnfilteredHtml = false, ?string $theme = null): array
    {
        $cleanSlug = trim($slug);
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $cleanSlug) !== 1) {
            throw new InvalidArgumentException('Identifiant de zone invalide.');
        }

        $themeSlug = $this->activeThemeSlug($theme);
        $existing = $this->find($cleanSlug, $themeSlug);
        $preserved = $existing !== null ? $this->renderer->extractUnfilteredHtmlBlocks($existing['content']) : [];

        $validated = $this->renderer->validateAndNormalize(
            $rawDocument,
            allowUnfilteredHtml: $allowUnfilteredHtml,
            preservedUnfilteredBlocks: $preserved,
        );

        if (!$validated['valid']) {
            throw new InvalidArgumentException(implode(' ', $validated['errors']));
        }

        $doc = $validated['document'];
        $json = $this->renderer->encode($doc);
        $html = $this->renderer->renderHtml($doc, null, allowUnfilteredHtml: $allowUnfilteredHtml);
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        if ($existing !== null) {
            $zoneId = $existing['id'];
            $this->db->table('zones')->where('id', '=', $zoneId)->update([
                'content' => $json,
                'content_html' => $html,
                'updated_at' => $now,
            ]);
        } else {
            $zoneId = $this->db->table('zones')->insertGetId([
                'theme' => $themeSlug,
                'slug' => $cleanSlug,
                'content' => $json,
                'content_html' => $html,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($this->mediaUsage !== null) {
            $this->mediaUsage->syncForZoneDocument($zoneId, $doc);
        }

        return [
            'id' => $zoneId,
            'theme' => $themeSlug,
            'slug' => $cleanSlug,
            'content' => $json,
            'content_html' => $html,
            'block_count' => count($doc['blocks']),
        ];
    }

    /**
     * Render a zone live (re-evaluating dynamic blocks on every public request!).
     */
    public function renderLive(string $slug, ?DynamicBlockRegistry $dynamicBlocks = null): string
    {
        $doc = $this->document($slug);
        if ($doc['blocks'] === []) {
            return '';
        }

        return $this->renderDocumentWithDynamicBlocks($doc, $dynamicBlocks);
    }

    /**
     * Render any Prime Editor document, evaluating dynamic blocks (`type === 'dynamic'` or registered dynamic block types) at render time.
     *
     * @param array{version: int, blocks: list<array<string, mixed>>} $doc
     */
    public function renderDocumentWithDynamicBlocks(array $doc, ?DynamicBlockRegistry $dynamicBlocks = null): string
    {
        if ($doc['blocks'] === []) {
            return '';
        }

        $chunks = [];
        $staticBuffer = [];

        foreach ($doc['blocks'] as $block) {
            $type = is_string($block['type'] ?? null) ? $block['type'] : '';

            if ($type === 'dynamic' && $dynamicBlocks !== null) {
                if ($staticBuffer !== []) {
                    $chunks[] = $this->renderer->renderHtml(['version' => 1, 'blocks' => $staticBuffer], null, allowUnfilteredHtml: true);
                    $staticBuffer = [];
                }
                $data = is_array($block['data'] ?? null) ? $block['data'] : [];
                $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
                $attrs = is_array($data['attrs'] ?? null) ? $data['attrs'] : [];
                if ($name !== '') {
                    $rendered = $dynamicBlocks->render($name, $attrs);
                    if ($rendered !== '') {
                        $chunks[] = $rendered;
                    }
                }
                continue;
            }

            if ($type !== '' && $dynamicBlocks !== null && $dynamicBlocks->has($type)) {
                if ($staticBuffer !== []) {
                    $chunks[] = $this->renderer->renderHtml(['version' => 1, 'blocks' => $staticBuffer], null, allowUnfilteredHtml: true);
                    $staticBuffer = [];
                }
                $attrs = is_array($block['data'] ?? null) ? $block['data'] : [];
                $rendered = $dynamicBlocks->render($type, $attrs);
                if ($rendered !== '') {
                    $chunks[] = $rendered;
                }
                continue;
            }

            $staticBuffer[] = $block;
        }

        if ($staticBuffer !== []) {
            $chunks[] = $this->renderer->renderHtml(['version' => 1, 'blocks' => $staticBuffer], null, allowUnfilteredHtml: true);
        }

        return implode("\n", array_filter($chunks, static fn (string $s): bool => $s !== ''));
    }
}
