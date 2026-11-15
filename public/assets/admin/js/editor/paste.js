/**
 * Prime Editor — Pure paste cleaner converting Word, Google Docs, HTML, or plain text
 * into clean Prime Editor blocks (zero DOM dependencies, Node-testable).
 */

import { createDefaultBlock } from './serializer.js';
import { sanitizeInlineHtml, slugify, stripHtml } from './utils.js';

/**
 * Convert plain text (double newlines -> paragraphs, bullet lines -> list) into clean blocks.
 */
export function parsePlainTextToBlocks(plainText) {
  const normalized = String(plainText ?? '')
    .replace(/\r\n/g, '\n')
    .replace(/\r/g, '\n')
    .trim();

  if (!normalized) {
    return [];
  }

  const chunks = normalized.split(/\n{2,}/).map((c) => c.trim()).filter(Boolean);
  const blocks = [];

  for (const chunk of chunks) {
    const lines = chunk.split('\n').map((l) => l.trim()).filter(Boolean);
    if (lines.length === 0) {
      continue;
    }

    // Markdown-style heading (`## Title`)
    if (lines.length === 1) {
      const headingMatch = lines[0].match(/^(#{2,4})\s+(.+)$/);
      if (headingMatch) {
        const level = Math.min(4, Math.max(2, headingMatch[1].length));
        const text = sanitizeInlineHtml(headingMatch[2]);
        blocks.push(
          createDefaultBlock('heading', {
            data: { level, text, anchor: slugify(stripHtml(text), 80) },
          }),
        );
        continue;
      }

      if (/^[-*_]{3,}$/.test(lines[0])) {
        blocks.push(createDefaultBlock('separator'));
        continue;
      }
    }

    // Unordered bullet list
    if (lines.every((l) => /^[-*•]\s+/.test(l))) {
      blocks.push(
        createDefaultBlock('list', {
          data: {
            style: 'unordered',
            items: lines.map((l) => ({
              text: sanitizeInlineHtml(l.replace(/^[-*•]\s+/, '')),
              children: [],
            })),
          },
        }),
      );
      continue;
    }

    // Ordered numbered list
    if (lines.every((l) => /^\d+[.)]\s+/.test(l))) {
      blocks.push(
        createDefaultBlock('list', {
          data: {
            style: 'ordered',
            items: lines.map((l) => ({
              text: sanitizeInlineHtml(l.replace(/^\d+[.)]\s+/, '')),
              children: [],
            })),
          },
        }),
      );
      continue;
    }

    // Quote (`> ...`)
    if (lines.every((l) => /^>\s?/.test(l))) {
      const text = sanitizeInlineHtml(lines.map((l) => l.replace(/^>\s?/, '')).join('<br>'));
      blocks.push(createDefaultBlock('quote', { data: { text, citation: '' } }));
      continue;
    }

    // Standard paragraph
    const text = sanitizeInlineHtml(lines.join('<br>'));
    blocks.push(createDefaultBlock('paragraph', { data: { text } }));
  }

  return blocks;
}

/**
 * Clean pasted HTML from Word, Google Docs, or websites into structured Prime Editor blocks.
 * Strips Word XML/MSO tags, `<style>`, `<script>`, `<meta>`, inline styles, and foreign classes.
 */
export function parsePastedHtmlToBlocks(rawHtml) {
  if (!rawHtml || typeof rawHtml !== 'string') {
    return [];
  }

  // 1. Strip Word/Docs/HTML boilerplate, comments, scripts, styles, meta, head
  let cleaned = rawHtml
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/<!\[(?:endif|if\b[^\]]*)\]>/gi, '')
    .replace(/<head\b[^>]*>[\s\S]*?<\/head>/gi, '')
    .replace(/<(script|style|xml|o:p|meta|link|title|iframe|svg|math)\b[^>]*>[\s\S]*?<\/\1>/gi, '')
    .replace(/<\/?(?:o:p|w:[a-z0-9-]+|m:[a-z0-9-]+|html|body|meta|link|font|span|div|section|article|header|footer|main)\b[^>]*>/gi, (m) => {
      // Turn closing </div> into a newline boundary if followed by text
      return /^<\/(?:div|section|article)/i.test(m) ? '\n' : '';
    });

  // 2. Extract block-level elements in document order
  const blockPattern = /<(h[1-6]|p|ul|ol|blockquote|pre|hr)\b[^>]*>([\s\S]*?)<\/\1>|<hr\b[^>]*\/?>/gi;
  const blocks = [];
  let lastIndex = 0;
  let match;

  while ((match = blockPattern.exec(cleaned)) !== null) {
    // Capture any loose text before this matched block
    const before = cleaned.slice(lastIndex, match.index).trim();
    if (before && stripHtml(before) !== '') {
      blocks.push(
        createDefaultBlock('paragraph', {
          data: { text: sanitizeInlineHtml(before) },
        }),
      );
    }
    lastIndex = blockPattern.lastIndex;

    const fullMatch = match[0];
    if (/^<hr\b/i.test(fullMatch)) {
      blocks.push(createDefaultBlock('separator'));
      continue;
    }

    const tag = (match[1] || '').toLowerCase();
    const inner = (match[2] || '').trim();

    if (/^h[1-6]$/.test(tag)) {
      const rawLevel = Number(tag.slice(1));
      const level = Math.min(4, Math.max(2, rawLevel));
      const text = sanitizeInlineHtml(inner).trim();
      if (stripHtml(text) !== '') {
        blocks.push(
          createDefaultBlock('heading', {
            data: { level, text, anchor: slugify(stripHtml(text), 80) },
          }),
        );
      }
      continue;
    }

    if (tag === 'p') {
      const text = sanitizeInlineHtml(inner).trim();
      if (stripHtml(text) !== '') {
        blocks.push(createDefaultBlock('paragraph', { data: { text } }));
      }
      continue;
    }

    if (tag === 'ul' || tag === 'ol') {
      const items = [];
      const liPattern = /<li\b[^>]*>([\s\S]*?)<\/li>/gi;
      let liMatch;
      while ((liMatch = liPattern.exec(inner)) !== null) {
        const itemText = sanitizeInlineHtml(liMatch[1]).trim();
        if (stripHtml(itemText) !== '') {
          items.push({ text: itemText, children: [] });
        }
      }
      if (items.length > 0) {
        blocks.push(
          createDefaultBlock('list', {
            data: { style: tag === 'ol' ? 'ordered' : 'unordered', items },
          }),
        );
      }
      continue;
    }

    if (tag === 'blockquote') {
      const text = sanitizeInlineHtml(inner).trim();
      if (stripHtml(text) !== '') {
        blocks.push(createDefaultBlock('quote', { data: { text, citation: '' } }));
      }
      continue;
    }

    if (tag === 'pre') {
      const codeText = stripHtml(inner);
      if (codeText !== '') {
        blocks.push(createDefaultBlock('code', { data: { language: '', code: codeText } }));
      }
      continue;
    }
  }

  // Remaining trailing content
  const trailing = cleaned.slice(lastIndex).trim();
  if (trailing && stripHtml(trailing) !== '') {
    const sanitized = sanitizeInlineHtml(trailing);
    if (stripHtml(sanitized) !== '') {
      blocks.push(createDefaultBlock('paragraph', { data: { text: sanitized } }));
    }
  }

  if (blocks.length === 0) {
    return parsePlainTextToBlocks(stripHtml(rawHtml));
  }

  return blocks;
}
