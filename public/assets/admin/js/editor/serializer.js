/**
 * Prime Editor — Pure document serializer, deserializer, block converter, and plain-text extractor.
 */

import { generateBlockId, sanitizeInlineHtml, slugify, stripHtml } from './utils.js';

export const SCHEMA_VERSION = 1;

export const CONVERTIBLE_TYPES = ['paragraph', 'heading', 'quote', 'list'];

/**
 * Create a clean default block object for any core block type.
 */
export function createDefaultBlock(type, overrides = {}) {
  const id = overrides.id || generateBlockId();
  const data = overrides.data || {};

  switch (type) {
    case 'paragraph':
      return { id, type: 'paragraph', data: { text: '', ...data } };
    case 'heading':
      return { id, type: 'heading', data: { level: 2, text: '', anchor: '', ...data } };
    case 'list':
      return {
        id,
        type: 'list',
        data: {
          style: 'unordered',
          items: [{ text: '', children: [] }],
          ...data,
        },
      };
    case 'quote':
      return { id, type: 'quote', data: { text: '', citation: '', ...data } };
    case 'image':
      return {
        id,
        type: 'image',
        data: { url: '', alt: '', decorative: false, caption: '', alignment: 'normal', ...data },
      };
    case 'code':
      return { id, type: 'code', data: { language: '', code: '', ...data } };
    case 'separator':
      return { id, type: 'separator', data: {} };
    case 'embed':
      return { id, type: 'embed', data: { url: '', title: '', ...data } };
    case 'table':
      return {
        id,
        type: 'table',
        data: {
          hasHeader: true,
          rows: [
            ['Colonne 1', 'Colonne 2'],
            ['', ''],
          ],
          ...data,
        },
      };
    case 'callout':
      return { id, type: 'callout', data: { variant: 'info', title: '', text: '', ...data } };
    case 'html':
      return { id, type: 'html', data: { html: '', ...data } };
    default:
      return { id, type, data: { ...data } };
  }
}

/**
 * Deserialize JSON string or raw object into a canonical `{ version: 1, blocks: [...] }` document.
 */
export function deserializeDocument(input) {
  let raw = input;
  if (typeof input === 'string') {
    const trimmed = input.trim();
    if (!trimmed) {
      return { version: SCHEMA_VERSION, blocks: [createDefaultBlock('paragraph')] };
    }
    try {
      raw = JSON.parse(trimmed);
    } catch {
      return { version: SCHEMA_VERSION, blocks: [createDefaultBlock('paragraph')] };
    }
  }

  if (!raw || typeof raw !== 'object' || !Array.isArray(raw.blocks)) {
    return { version: SCHEMA_VERSION, blocks: [createDefaultBlock('paragraph')] };
  }

  const seen = new Set();
  const blocks = raw.blocks
    .filter((b) => b && typeof b === 'object' && typeof b.type === 'string' && b.type.trim() !== '')
    .map((b) => {
      let id = typeof b.id === 'string' && /^[a-zA-Z0-9_-]{1,64}$/.test(b.id) ? b.id : generateBlockId();
      if (seen.has(id)) {
        id = generateBlockId();
      }
      seen.add(id);
      const data = b.data && typeof b.data === 'object' ? { ...b.data } : {};
      return createDefaultBlock(b.type.trim(), { id, data });
    });

  if (blocks.length === 0) {
    blocks.push(createDefaultBlock('paragraph'));
  }

  return {
    version: SCHEMA_VERSION,
    blocks,
  };
}

/**
 * Serialize blocks into clean JSON matching the Prime Editor schema.
 */
export function serializeDocument(blocks) {
  const cleanBlocks = (Array.isArray(blocks) ? blocks : []).map((block) => {
    const normalized = {
      id: block.id || generateBlockId(),
      type: block.type,
      data: { ...(block.data || {}) },
    };

    if (normalized.type === 'heading') {
      const level = Number(normalized.data.level);
      normalized.data.level = [2, 3, 4].includes(level) ? level : 2;
      if (!normalized.data.anchor && normalized.data.text) {
        normalized.data.anchor = slugify(stripHtml(normalized.data.text), 80);
      }
    }

    return normalized;
  });

  return JSON.stringify({
    version: SCHEMA_VERSION,
    blocks: cleanBlocks,
  });
}

/**
 * Convert between convertible text blocks (`paragraph` <-> `heading` <-> `quote` <-> `list`).
 */
export function convertBlock(block, targetType) {
  if (!block || !CONVERTIBLE_TYPES.includes(block.type) || !CONVERTIBLE_TYPES.includes(targetType)) {
    return block;
  }
  if (block.type === targetType) {
    return block;
  }

  // Extract inline text representation from source block
  let inlineText = '';
  let items = [];

  if (block.type === 'list') {
    const rawItems = Array.isArray(block.data?.items) ? block.data.items : [];
    items = rawItems.map((it) => (typeof it === 'string' ? it : String(it?.text ?? ''))).filter(Boolean);
    inlineText = items.join('<br>');
  } else {
    inlineText = sanitizeInlineHtml(String(block.data?.text ?? ''));
    items = inlineText
      .split(/<br\s*\/?>/i)
      .map((part) => part.trim())
      .filter(Boolean);
  }

  if (targetType === 'paragraph') {
    return { id: block.id, type: 'paragraph', data: { text: inlineText } };
  }
  if (targetType === 'heading') {
    const clean = inlineText.replace(/<br\s*\/?>/gi, ' ');
    return {
      id: block.id,
      type: 'heading',
      data: { level: 2, text: clean, anchor: slugify(stripHtml(clean), 80) },
    };
  }
  if (targetType === 'quote') {
    return { id: block.id, type: 'quote', data: { text: inlineText, citation: '' } };
  }
  if (targetType === 'list') {
    const listItems = items.length > 0 ? items.map((t) => ({ text: t, children: [] })) : [{ text: '', children: [] }];
    return { id: block.id, type: 'list', data: { style: 'unordered', items: listItems } };
  }

  return block;
}

/**
 * Extract plain text from an array of blocks (for word count and reading time).
 */
export function blocksToPlainText(blocks) {
  if (!Array.isArray(blocks)) {
    return '';
  }

  const parts = [];
  for (const block of blocks) {
    const d = block?.data || {};
    switch (block?.type) {
      case 'paragraph':
      case 'heading':
      case 'quote':
        if (d.text) parts.push(stripHtml(d.text));
        if (d.citation) parts.push(stripHtml(d.citation));
        break;
      case 'list':
        if (Array.isArray(d.items)) {
          for (const item of d.items) {
            if (typeof item === 'string') {
              parts.push(stripHtml(item));
            } else if (item) {
              if (item.text) parts.push(stripHtml(item.text));
              if (Array.isArray(item.children)) {
                item.children.forEach((c) => parts.push(stripHtml(c)));
              }
            }
          }
        }
        break;
      case 'image':
        if (d.alt) parts.push(String(d.alt));
        if (d.caption) parts.push(stripHtml(d.caption));
        break;
      case 'code':
        if (d.code) parts.push(String(d.code));
        break;
      case 'callout':
        if (d.title) parts.push(stripHtml(d.title));
        if (d.text) parts.push(stripHtml(d.text));
        break;
      case 'table':
        if (Array.isArray(d.rows)) {
          for (const row of d.rows) {
            if (Array.isArray(row)) {
              row.forEach((c) => parts.push(stripHtml(c)));
            }
          }
        }
        break;
      case 'embed':
        if (d.title) parts.push(String(d.title));
        break;
      case 'html':
        if (d.html) parts.push(stripHtml(d.html));
        break;
      default:
        // Custom plugin blocks: collect string values
        Object.values(d).forEach((val) => {
          if (typeof val === 'string') {
            parts.push(stripHtml(val));
          }
        });
        break;
    }
  }

  return parts.filter(Boolean).join('\n');
}
