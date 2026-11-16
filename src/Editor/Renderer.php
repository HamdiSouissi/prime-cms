<?php

declare(strict_types=1);

namespace Prime\Editor;

use Prime\Hooks\Hooks;
use Prime\Security\HtmlSanitizer;
use Prime\Support\Str;

/**
 * Validates, normalizes, renders semantic HTML (`content_html`), and extracts plain text
 * (`content_text`) from Prime Editor JSON documents (`{"version": 1, "blocks": [...]}`).
 */
final class Renderer
{
    public const SCHEMA_VERSION = 1;
    public const MAX_BLOCKS = 500;

    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly HtmlSanitizer $sanitizer,
        private readonly ?Hooks $hooks = null,
    ) {
    }

    /**
     * Return an empty canonical Prime Editor document.
     *
     * @return array{version: int, blocks: list<array{id: string, type: string, data: array<string, mixed>}>}
     */
    public static function emptyDocument(): array
    {
        return [
            'version' => self::SCHEMA_VERSION,
            'blocks' => [],
        ];
    }

    /**
     * Decode a JSON string or array into a raw document structure (or null if malformed).
     *
     * @param string|array<string, mixed> $input
     * @return ?array<string, mixed>
     */
    public function decode(string|array $input): ?array
    {
        if (is_array($input)) {
            return $input;
        }

        $trimmed = trim($input);
        if ($trimmed === '') {
            return self::emptyDocument();
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Validate and normalize a Prime Editor document.
     *
     * `$preservedUnfilteredBlocks` maps block ID => original HTML string from the persisted
     * entry so editors without `content.unfiltered_html` can save edits to *other* blocks
     * while existing `html` blocks remain strictly untouched (read-only).
     *
     * @param string|array<string, mixed> $input
     * @param array<string, string> $preservedUnfilteredBlocks
     * @return array{
     *   valid: bool,
     *   errors: list<string>,
     *   document: array{version: int, blocks: list<array{id: string, type: string, data: array<string, mixed>}>}
     * }
     */
    public function validateAndNormalize(
        string|array $input,
        bool $allowUnfilteredHtml = true,
        array $preservedUnfilteredBlocks = [],
    ): array {
        $raw = $this->decode($input);
        if ($raw === null) {
            return [
                'valid' => false,
                'errors' => ['Le document de contenu JSON est invalide.'],
                'document' => self::emptyDocument(),
            ];
        }

        $errors = [];
        $version = $raw['version'] ?? self::SCHEMA_VERSION;
        if (!is_int($version) || $version !== self::SCHEMA_VERSION) {
            $errors[] = sprintf('Version de document non prise en charge (attendue : %d).', self::SCHEMA_VERSION);
        }

        $rawBlocks = $raw['blocks'] ?? [];
        if (!is_array($rawBlocks)) {
            return [
                'valid' => false,
                'errors' => ['La propriété "blocks" doit être un tableau de blocs.'],
                'document' => self::emptyDocument(),
            ];
        }

        if (count($rawBlocks) > self::MAX_BLOCKS) {
            $errors[] = sprintf('Le document ne peut pas dépasser %d blocs.', self::MAX_BLOCKS);
        }

        $normalizedBlocks = [];
        $seenIds = [];

        foreach (array_values(array_slice($rawBlocks, 0, self::MAX_BLOCKS)) as $index => $rawBlock) {
            $humanIndex = $index + 1;

            if (!is_array($rawBlock)) {
                $errors[] = sprintf('Bloc #%d : structure invalide.', $humanIndex);
                continue;
            }

            $type = is_string($rawBlock['type'] ?? null) ? trim($rawBlock['type']) : '';
            if ($type === '') {
                $errors[] = sprintf('Bloc #%d : type de bloc manquant.', $humanIndex);
                continue;
            }

            $definition = $this->registry->get($type);
            if ($definition === null) {
                $errors[] = sprintf('Bloc #%d (%s) : type de bloc inconnu.', $humanIndex, $type);
                continue;
            }

            $id = is_string($rawBlock['id'] ?? null) ? trim($rawBlock['id']) : '';
            if ($id === '' || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1 || isset($seenIds[$id])) {
                $id = 'b_' . bin2hex(random_bytes(6));
            }
            $seenIds[$id] = true;

            $rawData = $rawBlock['data'] ?? [];
            if (!is_array($rawData)) {
                $errors[] = sprintf('Bloc #%d (%s) : les données du bloc doivent être un objet.', $humanIndex, $type);
                continue;
            }

            /** @var array<string, mixed> $typedData */
            $typedData = $rawData;

            // Enforce privileged capability on blocks like `html` (`content.unfiltered_html`).
            if ($definition->requiresCapability !== null && !$allowUnfilteredHtml) {
                if (
                    $type === 'html'
                    && array_key_exists($id, $preservedUnfilteredBlocks)
                    && ($typedData['html'] ?? '') === $preservedUnfilteredBlocks[$id]
                ) {
                    // Existing untouched HTML block preserved read-only.
                    $normalizedBlocks[] = [
                        'id' => $id,
                        'type' => $type,
                        'data' => ['html' => $preservedUnfilteredBlocks[$id]],
                    ];
                    continue;
                }

                $errors[] = sprintf(
                    'Bloc #%d (%s) : vous ne disposez pas de la permission requise (%s).',
                    $humanIndex,
                    $type,
                    $definition->requiresCapability,
                );
                continue;
            }

            $result = ($definition->validator)($typedData, $this->sanitizer);
            foreach ($result['errors'] as $blockError) {
                $errors[] = sprintf('Bloc #%d (%s) : %s', $humanIndex, $type, $blockError);
            }

            $normalizedBlocks[] = [
                'id' => $id,
                'type' => $type,
                'data' => $result['data'],
            ];
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'document' => [
                'version' => self::SCHEMA_VERSION,
                'blocks' => $normalizedBlocks,
            ],
        ];
    }

    /**
     * Render a normalized Prime Editor document into semantic HTML and apply `content.render`.
     *
     * @param array<string, mixed> $document
     */
    public function renderHtml(
        array $document,
        ?object $entry = null,
        bool $allowUnfilteredHtml = true,
    ): string {
        $blocks = is_array($document['blocks'] ?? null) ? $document['blocks'] : [];
        $usedAnchors = [];
        $chunks = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = is_string($block['type'] ?? null) ? $block['type'] : '';
            $definition = $this->registry->get($type);
            if ($definition === null) {
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $context = [
                'entry' => $entry,
                'allowUnfilteredHtml' => $allowUnfilteredHtml,
            ];

            // Ensure heading anchors are unique within the rendered document
            if ($type === 'heading') {
                $baseAnchor = is_string($data['anchor'] ?? null) && $data['anchor'] !== ''
                    ? Str::slug($data['anchor'], '-', 80)
                    : Str::slug(Str::plainText((string) ($data['text'] ?? '')), '-', 80);

                if ($baseAnchor !== '') {
                    $candidate = $baseAnchor;
                    $suffix = 2;
                    while (isset($usedAnchors[$candidate])) {
                        $candidate = $baseAnchor . '-' . $suffix;
                        $suffix++;
                    }
                    $usedAnchors[$candidate] = true;
                    $context['anchor'] = $candidate;
                }
            }

            $rendered = ($definition->renderer)($data, $this->sanitizer, $context);
            if ($rendered !== '') {
                $chunks[] = $rendered;
            }
        }

        $html = implode("\n", $chunks);

        if ($this->hooks !== null) {
            $filtered = $this->hooks->apply('content.render', $html, $entry, $document);
            if (is_string($filtered)) {
                $html = $filtered;
            }
        }

        return $html;
    }

    /**
     * Extract clean plain text from all blocks for FULLTEXT indexing, search, and word count.
     *
     * @param array<string, mixed> $document
     */
    public function extractText(array $document): string
    {
        $blocks = is_array($document['blocks'] ?? null) ? $document['blocks'] : [];
        $segments = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = is_string($block['type'] ?? null) ? $block['type'] : '';
            $definition = $this->registry->get($type);
            if ($definition === null) {
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $text = trim(($definition->textExtractor)($data));
            if ($text !== '') {
                $segments[] = $text;
            }
        }

        return implode("\n\n", $segments);
    }

    /**
     * Extract map of existing `html` block ID => raw HTML string from a persisted document.
     *
     * @param string|array<string, mixed> $persisted
     * @return array<string, string>
     */
    public function extractUnfilteredHtmlBlocks(string|array $persisted): array
    {
        $doc = $this->decode($persisted);
        if ($doc === null || !is_array($doc['blocks'] ?? null)) {
            return [];
        }

        $map = [];
        foreach ($doc['blocks'] as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) === 'html' && is_string($block['id'] ?? null)) {
                $data = $block['data'] ?? null;
                if (is_array($data) && is_string($data['html'] ?? null)) {
                    $map[$block['id']] = $data['html'];
                }
            }
        }

        return $map;
    }

    /**
     * Encode a document cleanly as UTF-8 JSON.
     *
     * @param array{version: int, blocks: list<array{id: string, type: string, data: array<string, mixed>}>} $document
     */
    public function encode(array $document): string
    {
        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{"version":1,"blocks":[]}';
    }
}
