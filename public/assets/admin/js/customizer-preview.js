/**
 * Prime CMS — Customizer iframe live preview listener.
 *
 * Loaded ONLY on `/?customizer_preview=1` for authenticated users with `themes.manage`.
 * Strictly enforces same-origin `event.origin === window.location.origin` before applying updates.
 */
(() => {
  'use strict';

  const ALLOWED_CSS_VAR = /^--[a-z][a-z0-9-]{0,60}$/;
  const UNSAFE_CSS_VALUE = /[;{}<>]|url\s*\(|expression\s*\(|@import/i;

  window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin) {
      return;
    }

    const msg = event.data;
    if (!msg || typeof msg !== 'object' || msg.source !== 'prime-customizer') {
      return;
    }

    if (msg.type === 'css-var' && typeof msg.name === 'string' && typeof msg.value === 'string') {
      if (!ALLOWED_CSS_VAR.test(msg.name) || UNSAFE_CSS_VALUE.test(msg.value)) {
        return;
      }
      document.documentElement.style.setProperty(msg.name, msg.value);
      return;
    }

    if (msg.type === 'identity' && typeof msg.field === 'string' && typeof msg.value === 'string') {
      if (msg.field === 'name') {
        document.querySelectorAll('.aurora-brand__name, .site-title').forEach((el) => {
          el.textContent = msg.value;
        });
      } else if (msg.field === 'tagline') {
        document.querySelectorAll('.aurora-brand__tagline, .site-tagline').forEach((el) => {
          el.textContent = msg.value;
        });
      }
      return;
    }

    if (msg.type === 'reload') {
      window.location.reload();
    }
  });
})();
