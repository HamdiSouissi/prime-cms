<?php

declare(strict_types=1);

namespace Prime\Site\Zone;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Prime\Database\Connection;
use Prime\Editor\Renderer;
use Prime\Media\MediaUsageRepository;

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
    ) {
    }

    /**
     * Find a saved zone by slug.
     *
     * @return ?array{slug: string, content: string, content_html: string, updated_at: string}
     */
    public function find(string $slug): ?array
    {
        $clean = trim($slug);
        if ($clean === '') {
            return null;
        }

        $row = $this->db->table('zones')->where('slug', '=', $clean)->first();
        if ($row === null) {
            return null;
        }

        return [
            'slug' => (string) $row['slug'],
            'content' => (string) ($row['content'] ?? ''),
            'content_html' => (string) ($row['content_html'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * Return all saved zones indexed by slug.
     *
     * @return array<string, array{slug: string, content: string, content_html: string, updated_at: string}>
     */
    public function allIndexed(): array
    {
        $rows = $this->db->table('zones')->orderBy('slug', 'ASC')->get();
        $out = [];
        foreach ($rows as $row) {
            $slug = (string) $row['slug'];
            $out[$slug] = [
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
     * @return array{version: int, blocks: list<array<string, mixed>>}
     */
    public function document(string $slug): array
    {
        $zone = $this->find($slug);
        if ($zone === null || trim($zone['content']) === '') {
            return Renderer::emptyDocument();
        }

        $decoded = json_decode($zone['content'], true);
        if (!is_array($decoded) || !is_array($decoded['blocks'] ?? null)) {
            return Renderer::emptyDocument();
        }

        return [
            'version' => (int) ($decoded['version'] ?? 1),
            'blocks' => array_values(array_filter($decoded['blocks'], 'is_array')),
        ];
    }

    /**
     * Check whether a zone has at least one non-empty block.
     */
    public function hasContent(string $slug): bool
    {
        $doc = $this->document($slug);

        return $doc['blocks'] !== [];
    }

    /**
     * Validate, normalize, render, save a zone document, and sync media usages.
     *
     * @param string|array<string, mixed> $rawDocument
     * @return array{slug: string, content: string, content_html: string, block_count: int}
     */
    public function save(string $slug, string|array $rawDocument, bool $allowUnfilteredHtml = false): array
    {
        $cleanSlug = trim($slug);
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $cleanSlug) !== 1) {
            throw new InvalidArgumentException('Identifiant de zone invalide.');
        }

        $existing = $this->find($cleanSlug);
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
            $this->db->table('zones')->where('slug', '=', $cleanSlug)->update([
                'content' => $json,
                'content_html' => $html,
                'updated_at' => $now,
            ]);
        } else {
            $this->db->table('zones')->insert([
                'slug' => $cleanSlug,
                'content' => $json,
                'content_html' => $html,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($this->mediaUsage !== null) {
            $this->mediaUsage->syncForZoneDocument($cleanSlug, $doc);
        }

        return [
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

        $flushStatic = function () use (&$staticBuffer, &$chunks): void {
            if ($staticBuffer === []) {
                return;
            }
            $chunks[] = $this->renderer->renderHtml(['version' => 1, 'blocks' => $staticBuffer], null, allowUnfilteredHtml: true);
            $staticBuffer = [];
        };

        foreach ($doc['blocks'] as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = is_string($block['type'] ?? null) ? $block['type'] : '';

            if ($type === 'dynamic' && $dynamicBlocks !== null) {
                $flushStatic();
                $data = is_array($block['data'] ?? null) ? $block['data'] : [];
                $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
                $attrs = is_array($data['attrs'] ?? null) ? $data['attrs'] : $data;
                if ($name !== '') {
                    $rendered = $dynamicBlocks->render($name, $attrs);
                    if ($rendered !== '') {
                        $chunks[] = $rendered;
                    }
                }
                continue;
            }

            if ($dynamicBlocks !== null && $dynamicBlocks->has($type)) {
                $flushStatic();
                $attrs = is_array($block['data'] ?? null) ? $block['data'] : [];
                $rendered = $dynamicBlocks->render($type, $attrs);
                if ($rendered !== '') {
                    $chunks[] = $rendered;
                }
                continue;
            }

            $staticBuffer[] = $block;
        }

        $flushStatic();

        return implode("\n", array_filter($chunks, static fn (string $s): bool => $s !== ''));
    }
}
