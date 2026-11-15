/**
 * Accessible toast notification stack with auto-dismiss and hover/focus pause.
 * Exposed via `Prime.toast(message, type, options)`.
 */

const VALID_TYPES = new Set(['info', 'success', 'warning', 'danger']);

const ICON_BY_TYPE = {
  info: 'info',
  success: 'check-circle',
  warning: 'alert-triangle',
  danger: 'alert-circle',
};

/** @returns {string} */
function getSpriteUrl() {
  const meta = document.querySelector('meta[name="prime-icon-sprite"]');
  const url = meta ? meta.getAttribute('content') : null;
  return url || '/assets/admin/icons/sprite.svg';
}

/**
 * Create an accessible `<svg><use/></svg>` referencing the local Lucide sprite.
 *
 * @param {string} name
 * @param {number} size
 * @returns {SVGSVGElement}
 */
function createSpriteIcon(name, size = 18) {
  const svgNS = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(svgNS, 'svg');
  svg.setAttribute('class', 'pc-icon');
  svg.setAttribute('width', String(size));
  svg.setAttribute('height', String(size));
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('fill', 'none');
  svg.setAttribute('stroke', 'currentColor');
  svg.setAttribute('stroke-width', '1.5');
  svg.setAttribute('stroke-linecap', 'round');
  svg.setAttribute('stroke-linejoin', 'round');
  svg.setAttribute('aria-hidden', 'true');

  const use = document.createElementNS(svgNS, 'use');
  use.setAttribute('href', `${getSpriteUrl()}#${encodeURIComponent(name)}`);
  svg.appendChild(use);

  return svg;
}

/** @returns {HTMLElement} */
function ensureStack() {
  let stack = document.querySelector('[data-toast-stack]');
  if (stack instanceof HTMLElement) {
    return stack;
  }

  stack = document.createElement('div');
  stack.className = 'pc-toast-stack';
  stack.setAttribute('data-toast-stack', '');
  stack.setAttribute('aria-live', 'polite');
  stack.setAttribute('aria-atomic', 'false');
  document.body.appendChild(stack);
  return stack;
}

/**
 * Show a toast notification.
 *
 * @param {string} message
 * @param {'info'|'success'|'warning'|'danger'} [type='info']
 * @param {{ title?: string, duration?: number }} [options={}]
 * @returns {HTMLElement}
 */
export function showToast(message, type = 'info', options = {}) {
  const normalizedType = VALID_TYPES.has(type) ? type : 'info';
  const duration = typeof options.duration === 'number' ? options.duration : 4500;
  const stack = ensureStack();

  const toast = document.createElement('div');
  toast.className = `pc-toast pc-toast--${normalizedType}`;
  toast.setAttribute('role', normalizedType === 'danger' ? 'alert' : 'status');

  const iconWrap = document.createElement('span');
  iconWrap.className = 'pc-toast__icon';
  iconWrap.appendChild(createSpriteIcon(ICON_BY_TYPE[normalizedType], 18));

  const content = document.createElement('div');
  content.className = 'pc-toast__content';

  if (options.title) {
    const titleEl = document.createElement('div');
    titleEl.className = 'pc-toast__title';
    titleEl.textContent = options.title;
    content.appendChild(titleEl);
  }

  const messageEl = document.createElement('div');
  messageEl.className = 'pc-toast__message';
  messageEl.textContent = message;
  content.appendChild(messageEl);

  const closeBtn = document.createElement('button');
  closeBtn.type = 'button';
  closeBtn.className = 'pc-toast__close';
  closeBtn.setAttribute('aria-label', 'Fermer la notification');
  closeBtn.appendChild(createSpriteIcon('x', 16));

  toast.appendChild(iconWrap);
  toast.appendChild(content);
  toast.appendChild(closeBtn);
  stack.appendChild(toast);

  /** @type {number|null} */
  let timer = null;

  function dismiss() {
    if (timer !== null) {
      window.clearTimeout(timer);
      timer = null;
    }
    toast.remove();
  }

  function schedule() {
    if (duration <= 0) {
      return;
    }
    timer = window.setTimeout(dismiss, duration);
  }

  function pause() {
    if (timer !== null) {
      window.clearTimeout(timer);
      timer = null;
    }
  }

  closeBtn.addEventListener('click', dismiss);
  toast.addEventListener('mouseenter', pause);
  toast.addEventListener('mouseleave', schedule);
  toast.addEventListener('focusin', pause);
  toast.addEventListener('focusout', schedule);

  schedule();

  return toast;
}

export function initToasts() {
  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    const trigger = target.closest('[data-toast-trigger]');
    if (!(trigger instanceof HTMLElement)) {
      return;
    }

    const message = trigger.getAttribute('data-toast-message') || 'Action effectuée.';
    const type = /** @type {'info'|'success'|'warning'|'danger'} */ (
      trigger.getAttribute('data-toast-type') || 'info'
    );
    const title = trigger.getAttribute('data-toast-title') || undefined;

    showToast(message, type, { title });
  });
}
