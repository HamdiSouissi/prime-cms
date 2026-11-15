/**
 * Focus management helper for drawers, mobile navigation, and custom dialogs.
 */

const FOCUSABLE_SELECTOR = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(', ');

/**
 * Return visible focusable elements inside a container.
 *
 * @param {HTMLElement} container
 * @returns {HTMLElement[]}
 */
export function getFocusable(container) {
  return Array.from(container.querySelectorAll(FOCUSABLE_SELECTOR)).filter((el) => {
    if (!(el instanceof HTMLElement)) {
      return false;
    }
    return el.offsetParent !== null && getComputedStyle(el).visibility !== 'hidden';
  });
}

/**
 * Trap Tab focus inside `container` and restore previous focus when released.
 *
 * @param {HTMLElement} container
 * @returns {() => void} Cleanup callback that deactivates the trap.
 */
export function createFocusTrap(container) {
  const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;

  const focusables = getFocusable(container);
  if (focusables.length > 0) {
    focusables[0].focus();
  } else {
    container.setAttribute('tabindex', '-1');
    container.focus();
  }

  /** @param {KeyboardEvent} event */
  function handleKeyDown(event) {
    if (event.key !== 'Tab') {
      return;
    }

    const items = getFocusable(container);
    if (items.length === 0) {
      event.preventDefault();
      return;
    }

    const first = items[0];
    const last = items[items.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  document.addEventListener('keydown', handleKeyDown);

  return () => {
    document.removeEventListener('keydown', handleKeyDown);
    if (previouslyFocused && document.contains(previouslyFocused)) {
      previouslyFocused.focus();
    }
  };
}
