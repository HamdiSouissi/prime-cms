/**
 * Accessible toast notification stack with auto-dismiss and hover/focus pause.
 * Exposed via `Prime.toast(message, type, options)`.
 */

import { tr } from './i18n.js';
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
  let stack = document.querySelector('[data-prime-toast-stack]');
  if (stack instanceof HTMLElement) {
    return stack;
  }

  stack = document.createElement('div');
  stack.className = 'pc-toast-stack';
  stack.setAttribute('data-prime-toast-stack', '');
  stack.setAttribute('aria-live', 'polite');
  stack.setAttribute('aria-atomic', 'false');
  document.body.appendChild(stack);
  return stack;
}

/**
 * Normalize arguments passed to `showToast()`, defensively handling legacy object payloads
 * `{ message?: string, title?: string, type?: string, duration?: number }`.
 *
 * @param {string|{ message?: string, title?: string, type?: string, duration?: number }} message
 * @param {'info'|'success'|'warning'|'danger'} [type='info']
 * @param {{ title?: string, duration?: number }} [options={}]
 * @returns {{ message: string, type: 'info'|'success'|'warning'|'danger', title: string|undefined, duration: number }}
 */
export function normalizeToastArgs(message, type = 'info', options = {}) {
  let resolvedMessage = '';
  let resolvedType = type;
  let resolvedTitle = typeof options?.title === 'string' && options.title !== '' ? options.title : undefined;
  let resolvedDuration = typeof options?.duration === 'number' ? options.duration : 4500;

  if (message !== null && typeof message === 'object') {
    const payload = /** @type {{ message?: unknown, title?: unknown, type?: unknown, duration?: unknown }} */ (message);
    const hasMessage = typeof payload.message === 'string' && payload.message.trim() !== '';
    const hasTitle = typeof payload.title === 'string' && payload.title.trim() !== '';
    if (hasMessage && hasTitle) {
      resolvedTitle = payload.title.trim();
      resolvedMessage = payload.message.trim();
    } else if (hasMessage) {
      resolvedMessage = payload.message.trim();
    } else if (hasTitle) {
      resolvedMessage = payload.title.trim();
    }
    if (typeof payload.type === 'string' && VALID_TYPES.has(payload.type)) {
      resolvedType = /** @type {'info'|'success'|'warning'|'danger'} */ (payload.type);
    }
    if (typeof payload.duration === 'number') {
      resolvedDuration = payload.duration;
    }
  } else if (typeof message === 'string') {
    resolvedMessage = message;
  } else if (message !== undefined && message !== null) {
    resolvedMessage = String(message);
  }

  const normalizedType = VALID_TYPES.has(resolvedType)
    ? /** @type {'info'|'success'|'warning'|'danger'} */ (resolvedType)
    : 'info';

  return {
    message: resolvedMessage,
    type: normalizedType,
    title: resolvedTitle,
    duration: resolvedDuration,
  };
}

/**
 * Show a toast notification.
 *
 * @param {string|{ message?: string, title?: string, type?: string, duration?: number }} message
 * @param {'info'|'success'|'warning'|'danger'} [type='info']
 * @param {{ title?: string, duration?: number }} [options={}]
 * @returns {HTMLElement}
 */
export function showToast(message, type = 'info', options = {}) {
  const normalized = normalizeToastArgs(message, type, options);
  const normalizedType = normalized.type;
  const duration = normalized.duration;
  const stack = ensureStack();

  const toast = document.createElement('div');
  toast.className = `pc-toast pc-toast--${normalizedType}`;
  toast.setAttribute('role', normalizedType === 'danger' ? 'alert' : 'status');

  const iconWrap = document.createElement('span');
  iconWrap.className = 'pc-toast__icon';
  iconWrap.appendChild(createSpriteIcon(ICON_BY_TYPE[normalizedType], 18));

  const content = document.createElement('div');
  content.className = 'pc-toast__content';

  if (normalized.title) {
    const titleEl = document.createElement('div');
    titleEl.className = 'pc-toast__title';
    titleEl.textContent = normalized.title;
    content.appendChild(titleEl);
  }

  const messageEl = document.createElement('div');
  messageEl.className = 'pc-toast__message';
  messageEl.textContent = normalized.message;
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
  const flashEl = document.getElementById('prime-flash-toasts');
  if (flashEl && flashEl.textContent) {
    try {
      const items = JSON.parse(flashEl.textContent);
      if (Array.isArray(items)) {
        items.forEach((item) => {
          if (item && typeof item.message === 'string' && item.message !== '') {
            showToast(item.message, item.type || 'info', {
              title: typeof item.title === 'string' ? item.title : undefined,
            });
          }
        });
      }
    } catch {
      // Ignore malformed flash payload.
    }
  }

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    const trigger = target.closest('[data-prime-toast-trigger]');
    if (!(trigger instanceof HTMLElement)) {
      return;
    }

    const message = trigger.getAttribute('data-prime-toast-message') || tr('admin.js.toast.action_effectuee', 'Action effectuée.');
    const type = /** @type {'info'|'success'|'warning'|'danger'} */ (
      trigger.getAttribute('data-prime-toast-type') || 'info'
    );
    const title = trigger.getAttribute('data-prime-toast-title') || undefined;

    showToast(message, type, { title });
  });
}
