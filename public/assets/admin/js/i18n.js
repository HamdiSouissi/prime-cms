/**
 * Prime CMS — front-end translation helper (`Prime.t`).
 *
 * The server embeds the catalogue needed by the admin UI as a JSON `<script>` block
 * (`<script type="application/json" data-prime-translations>`), so the strings are versioned
 * by HTTP cache, contain no executable code (strict CSP), and never require an extra request.
 *
 * Usage:
 *   import { initTranslations, t, choice } from './i18n.js';
 *   initTranslations(document);
 *   t('core.actions.save');                                   // → "Enregistrer"
 *   t('admin.media.selected', { count: 3 });                   // → placeholders replaced
 *   choice('core.media.items', 3);                             // → "3 médias"
 *
 * All helpers degrade gracefully: an unknown key returns the key itself (mirroring the PHP
 * `Translator`) and a missing catalogue simply leaves the shipped French markup in place.
 */

/** @type {Record<string, string>} */
let catalogue = {};

/** @type {Record<string, string>} */
let fallbackCatalogue = {};

/**
 * Replace `:name` and `%s` placeholders, in the same order as the PHP translator.
 *
 * @param {string} line
 * @param {Record<string, string|number>} replace
 * @returns {string}
 */
function replacePlaceholders(line, replace) {
  let result = line;
  let positional = 0;

  result = result.replace(/:([a-z_][a-z0-9_]*)/gi, (match, name) => {
    const value = replace[name];
    return value === undefined ? match : String(value);
  });

  result = result.replace(/%s/g, () => {
    const value = replace[positional];
    positional += 1;
    return value === undefined ? '%s' : String(value);
  });

  return result;
}

/**
 * Render a plural string using the `one|other` segments of the catalogue.
 *
 * @param {string} line
 * @param {number} count
 * @returns {string}
 */
function pluralize(line, count) {
  const segments = line.split('|').map((segment) => segment.trim());
  if (segments.length < 2) {
    return line;
  }

  // French treats 0 and 1 as singular, English only 1: the catalogue ships both rules,
  // so the segment count and the first (`one`) segment decide.
  const isSingular = segments.length === 2 ? Math.abs(count) < 2 : Math.abs(count) === 1;

  return isSingular ? segments[0] : segments[segments.length - 1];
}

/**
 * Translate a `group.nested.key` string.
 *
 * @param {string} key
 * @param {Record<string, string|number>} [replace]
 * @returns {string}
 */
export function t(key, replace = {}) {
  const line = catalogue[key] ?? fallbackCatalogue[key];
  if (typeof line !== 'string') {
    return key;
  }

  return replacePlaceholders(line, replace);
}

/**
 * Translate a plural key: `t('key', { count })` semantics with an explicit count.
 *
 * @param {string} key
 * @param {number} count
 * @param {Record<string, string|number>} [replace]
 * @returns {string}
 */
export function choice(key, count, replace = {}) {
  const line = catalogue[key] ?? fallbackCatalogue[key];
  if (typeof line !== 'string') {
    return key;
  }

  return replacePlaceholders(pluralize(line, count), { count, ...replace });
}

/**
 * Load the catalogue embedded by the server (idempotent, safe to call on every page).
 *
 * @param {Document|HTMLElement} root
 * @returns {{locale: string, direction: string, count: number}}
 */
export function initTranslations(root = document) {
  const script = root.querySelector('script[type="application/json"][data-prime-translations]');
  let locale = 'fr';
  let direction = 'ltr';
  let count = 0;

  // Deliberately not `instanceof HTMLScriptElement`: the same helper is unit-tested in Node,
  // where only `textContent` is guaranteed to exist on the injected root.
  if (script && typeof script.textContent === 'string' && script.textContent !== '') {
    try {
      const payload = JSON.parse(script.textContent);
      if (payload && typeof payload === 'object') {
        if (payload.messages && typeof payload.messages === 'object') {
          catalogue = payload.messages;
        }
        if (payload.fallback && typeof payload.fallback === 'object') {
          fallbackCatalogue = payload.fallback;
        }
        if (typeof payload.locale === 'string' && payload.locale !== '') {
          locale = payload.locale;
        }
        if (typeof payload.direction === 'string' && payload.direction !== '') {
          direction = payload.direction;
        }
        count = Object.keys(catalogue).length;
      }
    } catch {
      // A truncated catalogue must never break the admin: keep the shipped markup.
      catalogue = {};
      fallbackCatalogue = {};
    }
  }

  return { locale, direction, count };
}

/**
 * Current messages, exposed for tests and plugins.
 *
 * @returns {Record<string, string>}
 */
export function messages() {
  return { ...catalogue };
}

/**
 * Reset the loaded catalogue (tests only).
 */
export function resetTranslations() {
  catalogue = {};
  fallbackCatalogue = {};
}
