/**
 * Prime Editor — Pure keyboard navigation, focus resolution, and slash-shortcut guards.
 * Kept DOM-independent so every edge case is verified by fast zero-dependency Node unit tests.
 */

const PRIMARY_FOCUS_SELECTORS = Object.freeze({
  paragraph: '.pc-ed-p-input',
  heading: '.pc-ed-h-input',
  list: '.pc-ed-list-input',
  quote: '.pc-ed-quote-text',
  callout: '.pc-ed-callout-title, .pc-ed-callout-body',
  code: '.pc-ed-code-textarea',
  html: '.pc-ed-code-textarea',
  image: 'input[type="url"], input:not([type="hidden"])',
  embed: 'input[type="url"], input:not([type="hidden"])',
  table: '.pc-ed-table-cell',
  separator: null,
});

const FALLBACK_FOCUS_SELECTOR = '.pc-ed-richtext, textarea, input:not([type="hidden"])';

/**
 * Resolve keyboard navigation triggered from the entry title input (`#pc-entry-title`).
 *
 * @param {string} key
 * @param {number} blockCount
 * @returns {{ action: 'focus_block' | 'create_and_focus_first' | 'none', index: number, atEnd: boolean }}
 */
export function resolveTitleKeyNavigation(key, blockCount) {
  if (key !== 'Enter' && key !== 'ArrowDown') {
    return { action: 'none', index: -1, atEnd: false };
  }

  if (blockCount <= 0) {
    return { action: 'create_and_focus_first', index: 0, atEnd: false };
  }

  return { action: 'focus_block', index: 0, atEnd: false };
}

/**
 * Resolve ArrowUp / ArrowDown navigation across block boundaries and back into the title.
 *
 * @param {{ key: string, blockIndex: number, blockCount: number, atStart: boolean, atEnd: boolean }} params
 * @returns {{ action: 'focus_title' | 'focus_block' | 'none', index: number, atEnd: boolean }}
 */
export function resolveBlockArrowNavigation({ key, blockIndex, blockCount, atStart, atEnd }) {
  if (key === 'ArrowUp' && atStart) {
    if (blockIndex <= 0) {
      return { action: 'focus_title', index: -1, atEnd: true };
    }
    return { action: 'focus_block', index: blockIndex - 1, atEnd: true };
  }

  if (key === 'ArrowDown' && atEnd) {
    if (blockIndex < blockCount - 1) {
      return { action: 'focus_block', index: blockIndex + 1, atEnd: false };
    }
  }

  return { action: 'none', index: -1, atEnd: false };
}

/**
 * Resolve list-item keyboard navigation (Enter inserts next item & focuses it;
 * Backspace on an empty item removes it & focuses previous; ArrowUp/ArrowDown navigate items/blocks).
 *
 * @param {{
 *   key: string,
 *   shiftKey?: boolean,
 *   itemIndex: number,
 *   itemCount: number,
 *   isEmpty: boolean,
 *   atStart: boolean,
 *   atEnd: boolean,
 *   blockIndex: number,
 *   blockCount: number,
 * }} params
 * @returns {{ action: 'insert_item' | 'remove_item' | 'focus_item' | 'focus_title' | 'focus_block' | 'none', itemIndex: number, blockIndex: number, atEnd: boolean }}
 */
export function resolveListItemKeyAction({
  key,
  shiftKey = false,
  itemIndex,
  itemCount,
  isEmpty,
  atStart,
  atEnd,
  blockIndex,
  blockCount,
}) {
  if (key === 'Enter' && !shiftKey) {
    return { action: 'insert_item', itemIndex: itemIndex + 1, blockIndex, atEnd: false };
  }

  if (key === 'Backspace' && isEmpty && itemCount > 1) {
    return { action: 'remove_item', itemIndex: Math.max(0, itemIndex - 1), blockIndex, atEnd: true };
  }

  if (key === 'ArrowUp' && atStart) {
    if (itemIndex > 0) {
      return { action: 'focus_item', itemIndex: itemIndex - 1, blockIndex, atEnd: true };
    }
    const blockNav = resolveBlockArrowNavigation({ key: 'ArrowUp', blockIndex, blockCount, atStart: true, atEnd: false });
    if (blockNav.action === 'focus_title') {
      return { action: 'focus_title', itemIndex: -1, blockIndex: -1, atEnd: true };
    }
    if (blockNav.action === 'focus_block') {
      return { action: 'focus_block', itemIndex: -1, blockIndex: blockNav.index, atEnd: true };
    }
  }

  if (key === 'ArrowDown' && atEnd) {
    if (itemIndex < itemCount - 1) {
      return { action: 'focus_item', itemIndex: itemIndex + 1, blockIndex, atEnd: false };
    }
    const blockNav = resolveBlockArrowNavigation({ key: 'ArrowDown', blockIndex, blockCount, atStart: false, atEnd: true });
    if (blockNav.action === 'focus_block') {
      return { action: 'focus_block', itemIndex: -1, blockIndex: blockNav.index, atEnd: false };
    }
  }

  return { action: 'none', itemIndex, blockIndex, atEnd: false };
}

/**
 * Compute insertion index and post-insertion focus index when a block is picked from the Slash Menu.
 * Non-text blocks (e.g. `separator`) automatically receive a trailing paragraph so the user can keep typing.
 *
 * @param {{ mode?: 'replace' | 'after' | 'append', contextIndex?: number, blockCount: number, insertedType: string }} params
 * @returns {{ insertIndex: number, replaceExisting: boolean, focusIndex: number, appendTrailingParagraph: boolean }}
 */
export function resolveInsertionFocus({ mode = 'append', contextIndex = 0, blockCount, insertedType }) {
  const safeCount = Math.max(0, blockCount);
  const clampedCtx = Math.max(0, Math.min(contextIndex, Math.max(0, safeCount - 1)));
  const isNonEditableBlock = PRIMARY_FOCUS_SELECTORS[insertedType] === null;

  let insertIndex = safeCount;
  let replaceExisting = false;

  if (mode === 'replace' && safeCount > 0) {
    insertIndex = clampedCtx;
    replaceExisting = true;
  } else if (mode === 'after' && safeCount > 0) {
    insertIndex = clampedCtx + 1;
  }

  const focusIndex = isNonEditableBlock ? insertIndex + 1 : insertIndex;

  return {
    insertIndex,
    replaceExisting,
    focusIndex,
    appendTrailingParagraph: isNonEditableBlock,
  };
}

/**
 * Return the CSS selector for the primary text field of a given block type.
 *
 * @param {string} blockType
 * @returns {string}
 */
export function resolvePrimaryFocusSelector(blockType) {
  if (Object.prototype.hasOwnProperty.call(PRIMARY_FOCUS_SELECTORS, blockType)) {
    return PRIMARY_FOCUS_SELECTORS[blockType] || FALLBACK_FOCUS_SELECTOR;
  }
  return FALLBACK_FOCUS_SELECTOR;
}

/**
 * Clamp the focused block index after undo/redo so editor focus is preserved.
 *
 * @param {number | null} activeBlockIndex
 * @param {number} newBlockCount
 * @returns {number | null}
 */
export function resolveUndoRedoFocusIndex(activeBlockIndex, newBlockCount) {
  if (newBlockCount <= 0) {
    return null;
  }
  if (typeof activeBlockIndex !== 'number' || Number.isNaN(activeBlockIndex) || activeBlockIndex < 0) {
    return newBlockCount - 1;
  }
  return Math.max(0, Math.min(activeBlockIndex, newBlockCount - 1));
}

/**
 * Determine whether the global `/` shortcut for the admin command palette must be suppressed.
 * Works on both real DOM nodes and lightweight mock elements in unit tests.
 *
 * @param {unknown} target
 * @param {unknown} [activeElement]
 * @returns {boolean}
 */
export function shouldBlockGlobalSlashShortcut(target, activeElement = null) {
  const candidates = [target, activeElement];

  for (const node of candidates) {
    if (!node || typeof node !== 'object') {
      continue;
    }

    // Handle Text nodes cleanly by checking their parent element
    const el = node.nodeType === 3 ? node.parentElement : node;
    if (!el || typeof el !== 'object') {
      continue;
    }

    const tag = typeof el.tagName === 'string' ? el.tagName.toUpperCase() : '';
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
      return true;
    }

    if (el.isContentEditable === true) {
      return true;
    }

    if (typeof el.getAttribute === 'function') {
      const ce = el.getAttribute('contenteditable');
      if (ce === '' || ce === 'true' || ce === 'plaintext-only') {
        return true;
      }
    }

    if (
      typeof el.closest === 'function' &&
      el.closest(
        'input, textarea, select, [contenteditable="true"], [contenteditable=""], [contenteditable="plaintext-only"], [data-prime-editor], .pc-ed-root, .pc-ed-slash-menu, .pc-editor-sheet',
      ) !== null
    ) {
      return true;
    }
  }

  return false;
}
