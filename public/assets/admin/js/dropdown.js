/**
 * Accessible dropdown menus with outside-click dismissal and keyboard navigation.
 */

export function initDropdowns() {
  /** @type {HTMLElement|null} */
  let openContainer = null;

  /**
   * @param {HTMLElement} container
   * @param {boolean} restoreFocus
   */
  function closeDropdown(container, restoreFocus = false) {
    const trigger = container.querySelector('[data-dropdown-trigger]');
    const menu = container.querySelector('[data-dropdown-menu]');
    if (menu instanceof HTMLElement) {
      menu.hidden = true;
    }
    if (trigger instanceof HTMLElement) {
      trigger.setAttribute('aria-expanded', 'false');
      if (restoreFocus) {
        trigger.focus();
      }
    }
    if (openContainer === container) {
      openContainer = null;
    }
  }

  /**
   * @param {HTMLElement} container
   * @param {boolean} focusFirst
   */
  function openDropdown(container, focusFirst = false) {
    if (openContainer && openContainer !== container) {
      closeDropdown(openContainer, false);
    }

    const trigger = container.querySelector('[data-dropdown-trigger]');
    const menu = container.querySelector('[data-dropdown-menu]');
    if (!(menu instanceof HTMLElement) || !(trigger instanceof HTMLElement)) {
      return;
    }

    menu.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    openContainer = container;

    if (focusFirst) {
      const firstItem = getMenuItems(menu)[0];
      if (firstItem) {
        firstItem.focus();
      }
    }
  }

  /**
   * @param {HTMLElement} menu
   * @returns {HTMLElement[]}
   */
  function getMenuItems(menu) {
    return Array.from(menu.querySelectorAll('[role="menuitem"]:not([disabled])')).filter(
      (item) => item instanceof HTMLElement,
    );
  }

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    const trigger = target.closest('[data-dropdown-trigger]');
    if (trigger instanceof HTMLElement) {
      const container = trigger.closest('[data-dropdown]');
      if (container instanceof HTMLElement) {
        event.preventDefault();
        const isOpen = trigger.getAttribute('aria-expanded') === 'true';
        if (isOpen) {
          closeDropdown(container, false);
        } else {
          openDropdown(container, false);
        }
      }
      return;
    }

    // Close menu after clicking a menu item (unless marked persistent)
    const menuItem = target.closest('[role="menuitem"]');
    if (menuItem instanceof HTMLElement && openContainer && !menuItem.hasAttribute('data-dropdown-keep-open')) {
      closeDropdown(openContainer, false);
      return;
    }

    if (openContainer && !openContainer.contains(target)) {
      closeDropdown(openContainer, false);
    }
  });

  document.addEventListener('keydown', (event) => {
    if (!openContainer) {
      const active = document.activeElement;
      if (
        active instanceof HTMLElement &&
        active.matches('[data-dropdown-trigger]') &&
        (event.key === 'ArrowDown' || event.key === 'ArrowUp')
      ) {
        const container = active.closest('[data-dropdown]');
        if (container instanceof HTMLElement) {
          event.preventDefault();
          openDropdown(container, true);
        }
      }
      return;
    }

    const menu = openContainer.querySelector('[data-dropdown-menu]');
    if (!(menu instanceof HTMLElement)) {
      return;
    }

    if (event.key === 'Escape') {
      event.preventDefault();
      closeDropdown(openContainer, true);
      return;
    }

    if (event.key === 'Tab') {
      closeDropdown(openContainer, false);
      return;
    }

    const items = getMenuItems(menu);
    if (items.length === 0) {
      return;
    }

    const currentIndex = items.indexOf(/** @type {HTMLElement} */ (document.activeElement));

    if (event.key === 'ArrowDown') {
      event.preventDefault();
      const next = currentIndex < items.length - 1 ? currentIndex + 1 : 0;
      items[next].focus();
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      const prev = currentIndex > 0 ? currentIndex - 1 : items.length - 1;
      items[prev].focus();
    } else if (event.key === 'Home') {
      event.preventDefault();
      items[0].focus();
    } else if (event.key === 'End') {
      event.preventDefault();
      items[items.length - 1].focus();
    }
  });
}
