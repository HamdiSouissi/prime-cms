/**
 * Command palette (`Ctrl+K` / `⌘+K`, `/` outside inputs, or search trigger click).
 * Consumes JSON payload embedded in `<script type="application/json" id="prime-command-palette-data">`.
 */

import { showToast } from './toast.js';

/**
 * Normalize text for accent-insensitive and case-insensitive matching.
 *
 * @param {string} value
 * @returns {string}
 */
function normalizeText(value) {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();
}

/**
 * Simple accent-insensitive fuzzy score (higher is better, 0 = no match).
 *
 * @param {string} haystack
 * @param {string} needle
 * @returns {number}
 */
function fuzzyScore(haystack, needle) {
  if (!needle) {
    return 1;
  }

  const h = normalizeText(haystack);
  const n = normalizeText(needle);
  if (!n) {
    return 1;
  }

  const exactPos = h.indexOf(n);
  if (exactPos === 0) {
    return 100;
  }
  if (exactPos > 0) {
    return 70 - Math.min(exactPos, 30);
  }

  // Subsequence fuzzy match
  let ni = 0;
  let gaps = 0;
  for (let hi = 0; hi < h.length && ni < n.length; hi += 1) {
    if (h[hi] === n[ni]) {
      ni += 1;
    } else if (ni > 0) {
      gaps += 1;
    }
  }

  if (ni === n.length) {
    return Math.max(10, 40 - gaps);
  }

  return 0;
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

/**
 * @typedef {Object} CommandPaletteItem
 * @property {string} id
 * @property {string} label
 * @property {string} [subtitle]
 * @property {string} [section]
 * @property {string} [icon]
 * @property {string} [accent]
 * @property {string} [url]
 * @property {string[]} [keywords]
 * @property {string} [action]
 */

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

  const input = dialog.querySelector('[data-cmd-input]');
  const resultsEl = dialog.querySelector('[data-cmd-results]');
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
  document.querySelectorAll('[data-shortcut-mod]').forEach((el) => {
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
    const buttons = Array.from(resultsEl.querySelectorAll('[data-cmd-index]'));
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
    const scored = items
      .map((item) => {
        const keywords = Array.isArray(item.keywords) ? item.keywords.join(' ') : '';
        const haystack = `${item.label} ${item.subtitle || ''} ${item.section || ''} ${keywords}`;
        return { item, score: fuzzyScore(haystack, query) };
      })
      .filter((entry) => entry.score > 0)
      .sort((a, b) => b.score - a.score);

    resultsEl.textContent = '';
    flatVisible = [];
    activeIndex = 0;

    if (scored.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'pc-cmd__empty';
      empty.textContent = `Aucun résultat pour « ${query.trim()} ».`;
      resultsEl.appendChild(empty);
      return;
    }

    /** @type {Map<string, CommandPaletteItem[]>} */
    const grouped = new Map();
    scored.forEach(({ item }) => {
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
        btn.setAttribute('data-cmd-index', String(index));

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
    if (target.closest('[data-command-palette-open]')) {
      event.preventDefault();
      open();
    }
    if (target.closest('[data-command-palette-close]')) {
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
