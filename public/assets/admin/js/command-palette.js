/**
 * Command palette (`Ctrl+K` / `⌘+K`, `/` outside inputs, or search trigger click).
 * Consumes JSON payload embedded in `<script type="application/json" id="prime-command-palette-data">`.
 */

import { showToast } from './toast.js';

/**
 * @typedef {Object} CommandPaletteItem
 * @property {string} id
 * @property {string} label
 * @property {string} [subtitle]
 * @property {string} [group]
 * @property {string} [section]
 * @property {string} [icon]
 * @property {string} [accent]
 * @property {string} [url]
 * @property {string[]} [keywords]
 * @property {string} [action]
 */

/**
 * Normalize text for accent-insensitive and case-insensitive matching.
 *
 * @param {string} value
 * @returns {string}
 */
export function normalizeText(value) {
  return String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();
}

/**
 * Pure, deterministic search and ranking for command palette entries.
 *
 * Rules:
 * - Every whitespace-separated word in `query` must appear verbatim (ignoring
 *   case and diacritics) in `label`, `group` (or `section`), or `keywords` — never `url`.
 * - Ranking priority:
 *   1. `label` starts with the query (400)
 *   2. a word inside `label` starts with the query (300)
 *   3. `label` contains the query / all query words (200)
 *   4. match via `group` or `keywords` (100)
 * - Ties preserve original insertion order.
 *
 * @param {CommandPaletteItem[]} items
 * @param {string} query
 * @returns {CommandPaletteItem[]}
 */
export function searchCommands(items, query) {
  const normalizedQuery = normalizeText(query);
  if (!normalizedQuery) {
    return items.slice();
  }

  const tokens = normalizedQuery.split(/\s+/).filter(Boolean);
  if (tokens.length === 0) {
    return items.slice();
  }

  /** @type {Array<{ item: CommandPaletteItem, score: number, index: number }>} */
  const ranked = [];

  items.forEach((item, index) => {
    const label = normalizeText(item.label || '');
    const group = normalizeText(item.group || item.section || '');
    const keywords = Array.isArray(item.keywords)
      ? item.keywords.map((kw) => normalizeText(kw)).filter(Boolean)
      : [];

    // Every typed word must appear verbatim in label, group, or keywords (never in URL).
    const allTokensMatch = tokens.every(
      (token) =>
        label.includes(token) ||
        group.includes(token) ||
        keywords.some((kw) => kw.includes(token)),
    );

    if (!allTokensMatch) {
      return;
    }

    const labelWords = label.split(/[\s\-→/'’]+/).filter(Boolean);
    let score = 100;

    if (label.startsWith(normalizedQuery)) {
      score = 400;
    } else if (
      labelWords.some((word) => word.startsWith(normalizedQuery)) ||
      (tokens.length > 1 && tokens.every((token) => labelWords.some((word) => word.startsWith(token))))
    ) {
      score = 300;
    } else if (
      label.includes(normalizedQuery) ||
      (tokens.length > 1 && tokens.every((token) => label.includes(token)))
    ) {
      score = 200;
    }

    ranked.push({ item, score, index });
  });

  ranked.sort((a, b) => (b.score !== a.score ? b.score - a.score : a.index - b.index));

  return ranked.map((entry) => entry.item);
}

/** @returns {boolean} */
function isEditableTarget(target) {
  if (!(target instanceof HTMLElement)) {
    return false;
  }
  const tag = target.tagName;
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable;
}

/** @returns {string} */
function getSpriteUrl() {
  const meta = document.querySelector('meta[name="prime-icon-sprite"]');
  const url = meta ? meta.getAttribute('content') : null;
  return url || '/assets/admin/icons/sprite.svg';
}

/**
 * @param {string} name
 * @returns {SVGSVGElement}
 */
function buildIcon(name) {
  const svgNS = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(svgNS, 'svg');
  svg.setAttribute('class', 'pc-icon');
  svg.setAttribute('width', '18');
  svg.setAttribute('height', '18');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('fill', 'none');
  svg.setAttribute('stroke', 'currentColor');
  svg.setAttribute('stroke-width', '1.5');
  svg.setAttribute('stroke-linecap', 'round');
  svg.setAttribute('stroke-linejoin', 'round');
  svg.setAttribute('aria-hidden', 'true');

  const use = document.createElementNS(svgNS, 'use');
  use.setAttribute('href', `${getSpriteUrl()}#${encodeURIComponent(name || 'arrow-right')}`);
  svg.appendChild(use);

  return svg;
}

/** @type {(() => void)|null} */
let openPaletteFn = null;

export function openCommandPalette() {
  if (openPaletteFn) {
    openPaletteFn();
  }
}

export function initCommandPalette() {
  const dialog = document.getElementById('prime-command-palette');
  const dataNode = document.getElementById('prime-command-palette-data');
  if (!(dialog instanceof HTMLDialogElement) || !dataNode) {
    return;
  }

  const input = dialog.querySelector('[data-prime-cmd-input]');
  const resultsEl = dialog.querySelector('[data-prime-cmd-results]');
  if (!(input instanceof HTMLInputElement) || !(resultsEl instanceof HTMLElement)) {
    return;
  }

  /** @type {CommandPaletteItem[]} */
  let items = [];
  try {
    const parsed = JSON.parse(dataNode.textContent || '[]');
    if (Array.isArray(parsed)) {
      items = parsed;
    }
  } catch {
    items = [];
  }

  /** @type {HTMLElement|null} */
  let previousFocus = null;
  /** @type {CommandPaletteItem[]} */
  let flatVisible = [];
  let activeIndex = 0;

  // Update visible shortcut badge for macOS users (⌘ vs Ctrl)
  const isMac = /Mac|iPhone|iPad|iPod/i.test(navigator.platform || navigator.userAgent || '');
  document.querySelectorAll('[data-prime-shortcut-mod]').forEach((el) => {
    el.textContent = isMac ? '⌘' : 'Ctrl';
  });

  /** @param {CommandPaletteItem} item */
  function executeItem(item) {
    close();
    if (item.url) {
      window.location.assign(item.url);
      return;
    }
    if (item.action === 'toast') {
      showToast(item.subtitle || item.label, 'info', { title: item.label });
    }
  }

  function updateActiveClass() {
    const buttons = Array.from(resultsEl.querySelectorAll('[data-prime-cmd-index]'));
    buttons.forEach((btn, idx) => {
      const isCurrent = idx === activeIndex;
      btn.classList.toggle('is-active', isCurrent);
      btn.setAttribute('aria-selected', isCurrent ? 'true' : 'false');
      if (isCurrent) {
        btn.scrollIntoView({ block: 'nearest' });
      }
    });
  }

  /** @param {string} query */
  function renderResults(query) {
    const matches = searchCommands(items, query);

    resultsEl.textContent = '';
    flatVisible = [];
    activeIndex = 0;

    if (matches.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'pc-cmd__empty';
      empty.textContent = `Aucun résultat pour « ${query.trim()} ».`;
      resultsEl.appendChild(empty);
      return;
    }

    /** @type {Map<string, CommandPaletteItem[]>} */
    const grouped = new Map();
    matches.forEach((item) => {
      const section = item.section || 'Navigation';
      const list = grouped.get(section) || [];
      list.push(item);
      grouped.set(section, list);
    });

    grouped.forEach((groupItems, sectionName) => {
      const group = document.createElement('div');
      group.className = 'pc-cmd__group';
      group.setAttribute('role', 'group');

      const heading = document.createElement('div');
      heading.className = 'pc-cmd__group-title';
      heading.textContent = sectionName;
      group.appendChild(heading);

      groupItems.forEach((item) => {
        const index = flatVisible.length;
        flatVisible.push(item);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pc-cmd__item';
        btn.setAttribute('role', 'option');
        btn.setAttribute('data-prime-cmd-index', String(index));

        const accent = item.accent || 'neutral';
        const iconBox = document.createElement('span');
        iconBox.className = `pc-cmd__item-icon pc-accent-tile--${accent}`;
        iconBox.appendChild(buildIcon(item.icon || 'arrow-right'));

        const body = document.createElement('span');
        body.className = 'pc-cmd__item-body';

        const title = document.createElement('span');
        title.className = 'pc-cmd__item-title';
        title.textContent = item.label;
        body.appendChild(title);

        if (item.subtitle) {
          const sub = document.createElement('span');
          sub.className = 'pc-cmd__item-subtitle';
          sub.textContent = item.subtitle;
          body.appendChild(sub);
        }

        btn.appendChild(iconBox);
        btn.appendChild(body);

        btn.addEventListener('mouseenter', () => {
          activeIndex = index;
          updateActiveClass();
        });
        btn.addEventListener('click', () => executeItem(item));

        group.appendChild(btn);
      });

      resultsEl.appendChild(group);
    });

    updateActiveClass();
  }

  function open() {
    previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    if (!dialog.open) {
      dialog.showModal();
    }
    input.setAttribute('aria-expanded', 'true');
    input.value = '';
    renderResults('');
    input.focus();
  }

  function close() {
    if (dialog.open) {
      dialog.close();
    }
  }

  openPaletteFn = open;

  dialog.addEventListener('close', () => {
    input.setAttribute('aria-expanded', 'false');
    if (previousFocus && document.contains(previousFocus)) {
      previousFocus.focus();
    }
    previousFocus = null;
  });

  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) {
      const rect = dialog.getBoundingClientRect();
      const outside =
        event.clientX < rect.left ||
        event.clientX > rect.right ||
        event.clientY < rect.top ||
        event.clientY > rect.bottom;
      if (outside) {
        close();
      }
    }
  });

  input.addEventListener('input', () => {
    renderResults(input.value);
  });

  input.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      if (flatVisible.length > 0) {
        activeIndex = (activeIndex + 1) % flatVisible.length;
        updateActiveClass();
      }
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      if (flatVisible.length > 0) {
        activeIndex = (activeIndex - 1 + flatVisible.length) % flatVisible.length;
        updateActiveClass();
      }
    } else if (event.key === 'Enter') {
      event.preventDefault();
      const selected = flatVisible[activeIndex];
      if (selected) {
        executeItem(selected);
      }
    }
  });

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }
    if (target.closest('[data-prime-command-palette-open]')) {
      event.preventDefault();
      open();
    }
    if (target.closest('[data-prime-command-palette-close]')) {
      event.preventDefault();
      close();
    }
  });

  document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      if (dialog.open) {
        close();
      } else {
        open();
      }
      return;
    }

    if (
      event.key === '/' &&
      !event.ctrlKey &&
      !event.metaKey &&
      !event.altKey &&
      !dialog.open &&
      !isEditableTarget(event.target)
    ) {
      event.preventDefault();
      open();
    }
  });
}
