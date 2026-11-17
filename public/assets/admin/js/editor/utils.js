/**
 * Prime Editor — Pure utility functions (zero DOM dependencies, Node-testable).
 */

import { tr } from '../i18n.js';
let idCounter = 0;

/**
 * Generate a short unique block identifier safe for JSON and HTML attributes.
 */
export function generateBlockId() {
  idCounter += 1;
  const rand = Math.random().toString(36).slice(2, 8);
  return `b_${Date.now().toString(36)}_${idCounter}_${rand}`;
}

/**
 * Escape HTML special characters for safe text interpolation.
 */
export function escapeHtml(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

/**
 * Strip HTML tags and collapse whitespace to clean plain text.
 */
export function stripHtml(html) {
  return String(html ?? '')
    .replace(/<br\s*\/?>/gi, ' ')
    .replace(/<\/(?:p|div|li|h[1-6]|blockquote|tr|td|th)>/gi, ' ')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&quot;/gi, '"')
    .replace(/&#0?39;/gi, "'")
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * Count words in plain text (Unicode-aware).
 */
export function countWords(text) {
  const clean = String(text ?? '').trim();
  if (!clean) {
    return 0;
  }
  const matches = clean.match(/[\p{L}\p{N}]+(?:['’-][\p{L}\p{N}]+)*/gu);
  return matches ? matches.length : 0;
}

/**
 * Estimate reading time in minutes (200 wpm).
 */
export function estimateReadingTime(words, wordsPerMinute = 200) {
  if (words <= 0) {
    return 0;
  }
  return Math.max(1, Math.ceil(words / Math.max(1, wordsPerMinute)));
}

/**
 * Generate a clean ASCII slug from UTF-8 text (mirrors `Prime\Support\Str::slug`).
 */
export function slugify(text, maxLength = 191) {
  const raw = String(text ?? '')
    .trim()
    .replace(/[Œœ]/g, 'oe')
    .replace(/[Ææ]/g, 'ae')
    .replace(/ß/g, 'ss')
    .replace(/[’'‘`]/g, '-');

  if (!raw) {
    return '';
  }

  let slug = raw
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-+|-+$/g, '');

  if (maxLength > 0 && slug.length > maxLength) {
    slug = slug.slice(0, maxLength).replace(/-+$/, '');
  }

  return slug;
}

/**
 * Validate a link href on the client (mirrors server `HtmlSanitizer::sanitizeHref`).
 */
export function sanitizeHref(rawHref) {
  const raw = String(rawHref ?? '').replace(/[\x00-\x1f\x7f]/g, '').trim();
  if (!raw) {
    return null;
  }
  if (raw.startsWith('//') || raw.startsWith('\\') || raw.startsWith('/\\')) {
    return null;
  }
  if (raw.startsWith('/') || raw.startsWith('#') || raw.startsWith('?')) {
    return raw;
  }

  const compact = raw.replace(/\s+/g, '').toLowerCase();
  const colonIdx = compact.indexOf(':');
  if (colonIdx !== -1) {
    const firstDelim = Math.min(
      ...[compact.indexOf('/'), compact.indexOf('?'), compact.indexOf('#')].map((i) => (i === -1 ? compact.length : i)),
    );
    if (colonIdx < firstDelim) {
      const scheme = compact.slice(0, colonIdx);
      if (!['http', 'https', 'mailto', 'tel'].includes(scheme)) {
        return null;
      }
      if ((scheme === 'http' || scheme === 'https') && !compact.slice(colonIdx + 1).startsWith('//')) {
        return null;
      }
      return raw;
    }
  }

  if (/^(?:javascript|vbscript|data|file|blob)\b/i.test(compact)) {
    return null;
  }

  return raw;
}

/**
 * Parse a YouTube or Vimeo URL into its safe privacy-enhanced embed URL.
 */
export function parseEmbedUrl(rawUrl) {
  const trimmed = String(rawUrl ?? '').trim();
  if (!trimmed) {
    return null;
  }

  let parsed;
  try {
    parsed = new URL(trimmed);
  } catch {
    return null;
  }

  if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') {
    return null;
  }

  const host = parsed.hostname.toLowerCase();
  const path = parsed.pathname;

  if (
    ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'].includes(host)
  ) {
    let id = null;
    if (path === '/watch' || path === '/watch/') {
      id = parsed.searchParams.get('v');
    } else {
      const m = path.match(/^\/(?:embed|shorts|live)\/([A-Za-z0-9_-]{11})(?:\/|$)/);
      if (m) {
        id = m[1];
      }
    }
    if (id && /^[A-Za-z0-9_-]{11}$/.test(id)) {
      return {
        provider: 'youtube',
        id,
        embedUrl: `https://www.youtube-nocookie.com/embed/${id}`,
        defaultTitle: tr('admin.js.editor.utils.video_youtube', 'Vidéo YouTube'),
      };
    }
    return null;
  }

  if (host === 'youtu.be' || host === 'www.youtu.be') {
    const m = path.match(/^\/([A-Za-z0-9_-]{11})(?:\/|$)/);
    if (m) {
      return {
        provider: 'youtube',
        id: m[1],
        embedUrl: `https://www.youtube-nocookie.com/embed/${m[1]}`,
        defaultTitle: tr('admin.js.editor.utils.video_youtube', 'Vidéo YouTube'),
      };
    }
    return null;
  }

  if (['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'].includes(host)) {
    const m = path.match(/^\/(?:video\/|channels\/[^/]+\/|groups\/[^/]+\/videos\/)?([0-9]{4,15})(?:\/|$)/);
    if (m) {
      return {
        provider: 'vimeo',
        id: m[1],
        embedUrl: `https://player.vimeo.com/video/${m[1]}`,
        defaultTitle: tr('admin.js.editor.utils.video_vimeo', 'Vidéo Vimeo'),
      };
    }
    return null;
  }

  return null;
}

/**
 * Pure string-based inline HTML sanitizer for client-side paste and inline editing
 * (always re-validated and re-sanitized server-side by `Prime\Security\HtmlSanitizer`).
 */
export function sanitizeInlineHtml(input) {
  if (!input) {
    return '';
  }

  let html = String(input)
    .replace(/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/g, '')
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/<(script|style|svg|math|iframe|object|embed|form|template|noscript)\b[^>]*>[\s\S]*?<\/\1>/gi, '');

  // Strip tags outside whitelist, keep safe inline tags & safe <a href>
  const allowedSimple = new Set(['strong', 'b', 'em', 'i', 'code']);

  html = html.replace(/<\/?([a-zA-Z][a-zA-Z0-9:-]*)\b([^>]*)>/g, (match, rawTag, rawAttrs) => {
    const tag = rawTag.toLowerCase();
    const isClosing = match.startsWith('</');

    if (tag === 'br') {
      return isClosing ? '' : '<br>';
    }

    if (allowedSimple.has(tag)) {
      return isClosing ? `</${tag}>` : `<${tag}>`;
    }

    if (tag === 'a') {
      if (isClosing) {
        return '</a>';
      }
      const hrefMatch = rawAttrs.match(/\bhref\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/i);
      const rawHref = hrefMatch ? (hrefMatch[1] ?? hrefMatch[2] ?? hrefMatch[3] ?? '') : '';
      const safeHref = sanitizeHref(rawHref);
      if (!safeHref) {
        return '';
      }
      const isBlank = /\btarget\s*=\s*(?:"_blank"|'_blank'|_blank)/i.test(rawAttrs);
      return isBlank
        ? `<a href="${escapeHtml(safeHref)}" target="_blank" rel="noopener noreferrer">`
        : `<a href="${escapeHtml(safeHref)}">`;
    }

    return '';
  });

  return html;
}
