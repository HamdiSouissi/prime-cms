/**
 * Accessible slide-over side drawer with backdrop click, Escape key, and focus trap.
 */

import { createFocusTrap } from './focus-trap.js';

/** @type {HTMLElement|null} */
let activeDrawer = null;

/** @type {(() => void)|null} */
let releaseTrap = null;

/**
 * Open a drawer by element ID or element reference.
 *
 * @param {string|HTMLElement} target
 */
export function openDrawer(target) {
  const drawer = typeof target === 'string' ? document.getElementById(target) : target;
  if (!(drawer instanceof HTMLElement)) {
    return;
  }

  if (activeDrawer && activeDrawer !== drawer) {
    closeDrawer(activeDrawer);
  }

  drawer.hidden = false;
  activeDrawer = drawer;
  document.body.style.overflow = 'hidden';

  const panel = drawer.querySelector('[data-prime-drawer-panel]');
  releaseTrap = createFocusTrap(panel instanceof HTMLElement ? panel : drawer);
}

/**
 * Close the active drawer and restore previous focus.
 *
 * @param {string|HTMLElement} [target]
 */
export function closeDrawer(target) {
  const drawer =
    target === undefined
      ? activeDrawer
      : typeof target === 'string'
        ? document.getElementById(target)
        : target;

  if (!(drawer instanceof HTMLElement)) {
    return;
  }

  drawer.hidden = true;
  if (activeDrawer === drawer) {
    activeDrawer = null;
  }
  document.body.style.overflow = '';

  if (releaseTrap) {
    releaseTrap();
    releaseTrap = null;
  }
}

export function initDrawers() {
  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    const openTrigger = target.closest('[data-prime-drawer-open]');
    if (openTrigger instanceof HTMLElement) {
      const drawerId = openTrigger.getAttribute('data-prime-drawer-open');
      if (drawerId) {
        event.preventDefault();
        openDrawer(drawerId);
      }
      return;
    }

    const closeTrigger = target.closest('[data-prime-drawer-close]');
    if (closeTrigger instanceof HTMLElement) {
      const drawer = closeTrigger.closest('[data-prime-drawer]');
      if (drawer instanceof HTMLElement) {
        event.preventDefault();
        closeDrawer(drawer);
      }
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && activeDrawer) {
      event.preventDefault();
      closeDrawer(activeDrawer);
    }
  });
}
