/**
 * Admin sidebar state: desktop/tablet collapse (persisted safely in localStorage),
 * mobile off-canvas drawer with focus trap, and collapsible submenu sections.
 */

import { createFocusTrap } from './focus-trap.js';

const STORAGE_KEY = 'prime.admin.sidebar.collapsed';
const MOBILE_MAX = 767;
const TABLET_MAX = 1279;

/** @returns {boolean|null} */
function readSavedCollapse() {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (raw === '1') {
      return true;
    }
    if (raw === '0') {
      return false;
    }
    return null;
  } catch {
    return null;
  }
}

/** @param {boolean} collapsed */
function writeSavedCollapse(collapsed) {
  try {
    window.localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
  } catch {
    // Ignore storage quota or private-mode restrictions.
  }
}

export function initSidebar() {
  const shell = document.querySelector('[data-admin-shell]');
  const sidebar = document.querySelector('[data-sidebar]');
  if (!(shell instanceof HTMLElement) || !(sidebar instanceof HTMLElement)) {
    return;
  }

  const collapseBtn = sidebar.querySelector('[data-sidebar-collapse]');
  const mobileOpenBtn = document.querySelector('[data-sidebar-mobile-open]');
  const mobileCloseBtn = sidebar.querySelector('[data-sidebar-mobile-close]');
  const backdrop = document.querySelector('[data-sidebar-backdrop]');

  /** @type {(() => void)|null} */
  let releaseTrap = null;

  /** @param {boolean} collapsed */
  function applyCollapsed(collapsed) {
    shell.classList.toggle('is-sidebar-collapsed', collapsed);
    if (collapseBtn instanceof HTMLElement) {
      collapseBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      collapseBtn.setAttribute(
        'aria-label',
        collapsed ? 'Déplier la barre latérale' : 'Réduire la barre latérale',
      );
      const label = collapseBtn.querySelector('[data-sidebar-collapse-label]');
      if (label) {
        label.textContent = collapsed ? 'Déplier' : 'Réduire';
      }
      const iconUse = collapseBtn.querySelector('use');
      if (iconUse) {
        const href = iconUse.getAttribute('href') || '';
        const base = href.split('#')[0];
        iconUse.setAttribute('href', `${base}#${collapsed ? 'panel-left-open' : 'panel-left-close'}`);
      }
    }
  }

  function applyInitialResponsiveState() {
    const width = window.innerWidth;
    if (width <= MOBILE_MAX) {
      applyCollapsed(false);
      return;
    }
    const saved = readSavedCollapse();
    if (saved !== null) {
      applyCollapsed(saved);
      return;
    }
    applyCollapsed(width <= TABLET_MAX);
  }

  function openMobileMenu() {
    shell.classList.add('is-mobile-menu-open');
    if (backdrop instanceof HTMLElement) {
      backdrop.classList.add('is-open');
    }
    if (mobileOpenBtn instanceof HTMLElement) {
      mobileOpenBtn.setAttribute('aria-expanded', 'true');
    }
    document.body.style.overflow = 'hidden';
    releaseTrap = createFocusTrap(sidebar);
  }

  function closeMobileMenu() {
    if (!shell.classList.contains('is-mobile-menu-open')) {
      return;
    }
    shell.classList.remove('is-mobile-menu-open');
    if (backdrop instanceof HTMLElement) {
      backdrop.classList.remove('is-open');
    }
    if (mobileOpenBtn instanceof HTMLElement) {
      mobileOpenBtn.setAttribute('aria-expanded', 'false');
    }
    document.body.style.overflow = '';
    if (releaseTrap) {
      releaseTrap();
      releaseTrap = null;
    }
  }

  applyInitialResponsiveState();

  if (collapseBtn instanceof HTMLElement) {
    collapseBtn.addEventListener('click', () => {
      const next = !shell.classList.contains('is-sidebar-collapsed');
      applyCollapsed(next);
      writeSavedCollapse(next);
    });
  }

  if (mobileOpenBtn instanceof HTMLElement) {
    mobileOpenBtn.addEventListener('click', openMobileMenu);
  }

  if (mobileCloseBtn instanceof HTMLElement) {
    mobileCloseBtn.addEventListener('click', closeMobileMenu);
  }

  if (backdrop instanceof HTMLElement) {
    backdrop.addEventListener('click', closeMobileMenu);
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && shell.classList.contains('is-mobile-menu-open')) {
      closeMobileMenu();
    }
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth > MOBILE_MAX) {
      closeMobileMenu();
      const saved = readSavedCollapse();
      if (saved === null) {
        applyCollapsed(window.innerWidth <= TABLET_MAX);
      }
    }
  });

  // Submenu toggles
  sidebar.querySelectorAll('[data-submenu-toggle]').forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }
    const targetId = button.getAttribute('aria-controls');
    if (!targetId) {
      return;
    }
    const submenu = document.getElementById(targetId);
    if (!submenu) {
      return;
    }

    button.addEventListener('click', () => {
      const isExpanded = button.getAttribute('aria-expanded') === 'true';
      button.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
      submenu.hidden = isExpanded;
    });
  });
}
