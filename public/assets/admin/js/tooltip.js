/**
 * Accessible floating tooltips on hover and keyboard focus.
 * Supports `[data-prime-tooltip]` (always) and `[data-prime-tooltip-collapsed]` (only when sidebar is collapsed).
 */

export function initTooltips() {
  const popup = document.createElement('div');
  popup.id = 'prime-tooltip-popup';
  popup.className = 'pc-tooltip-popup';
  popup.setAttribute('role', 'tooltip');
  popup.hidden = true;
  document.body.appendChild(popup);

  /** @type {HTMLElement|null} */
  let activeTarget = null;

  /**
   * @param {HTMLElement} element
   * @returns {string}
   */
  function resolveText(element) {
    const direct = element.getAttribute('data-prime-tooltip');
    if (direct) {
      return direct;
    }

    const collapsedText = element.getAttribute('data-prime-tooltip-collapsed');
    if (collapsedText && window.innerWidth >= 768) {
      const shell = document.querySelector('[data-prime-admin-shell]');
      if (shell && shell.classList.contains('is-sidebar-collapsed')) {
        return collapsedText;
      }
    }

    return '';
  }

  /** @param {HTMLElement} target */
  function show(target) {
    const text = resolveText(target);
    if (!text) {
      return;
    }

    activeTarget = target;
    popup.textContent = text;
    popup.hidden = false;
    popup.classList.add('is-visible');
    target.setAttribute('aria-describedby', popup.id);

    const rect = target.getBoundingClientRect();
    const popRect = popup.getBoundingClientRect();
    const preferredPlacement = target.getAttribute('data-prime-tooltip-placement') || 'top';

    let top = rect.top - popRect.height - 8;
    let left = rect.left + (rect.width - popRect.width) / 2;

    if (preferredPlacement === 'right') {
      top = rect.top + (rect.height - popRect.height) / 2;
      left = rect.right + 10;
    } else if (preferredPlacement === 'bottom' || top < 8) {
      top = rect.bottom + 8;
    }

    left = Math.max(8, Math.min(left, window.innerWidth - popRect.width - 8));
    top = Math.max(8, Math.min(top, window.innerHeight - popRect.height - 8));

    popup.style.top = `${Math.round(top)}px`;
    popup.style.left = `${Math.round(left)}px`;
  }

  function hide() {
    if (activeTarget) {
      if (activeTarget.getAttribute('aria-describedby') === popup.id) {
        activeTarget.removeAttribute('aria-describedby');
      }
      activeTarget = null;
    }
    popup.classList.remove('is-visible');
    popup.hidden = true;
  }

  /** @param {EventTarget|null} target */
  function findTooltipElement(target) {
    if (!(target instanceof Element)) {
      return null;
    }
    const found = target.closest('[data-prime-tooltip], [data-prime-tooltip-collapsed]');
    return found instanceof HTMLElement ? found : null;
  }

  document.addEventListener('mouseenter', (event) => {
    const el = findTooltipElement(event.target);
    if (el) {
      show(el);
    }
  }, true);

  document.addEventListener('mouseleave', (event) => {
    const el = findTooltipElement(event.target);
    if (el && el === activeTarget) {
      hide();
    }
  }, true);

  document.addEventListener('focusin', (event) => {
    const el = findTooltipElement(event.target);
    if (el) {
      show(el);
    }
  });

  document.addEventListener('focusout', () => hide());
  window.addEventListener('scroll', hide, true);
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      hide();
    }
  });
}
