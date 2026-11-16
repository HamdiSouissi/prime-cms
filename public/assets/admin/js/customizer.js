/**
 * Prime CMS Admin — Live Theme Customizer (`GET /admin/appearance/customizer`).
 *
 * Streams CSS custom properties and identity updates to the same-origin preview iframe
 * via `postMessage`, toggles desktop/tablet/mobile viewports, and publishes via CSRF-protected JSON POST.
 */

import { showToast } from './toast.js';
import { tr } from './i18n.js';

/**
 * Pure helper computing the CSS custom property value for a field change.
 *
 * @param {{ type: string, value: string, cssUnit?: string, cssMap?: Record<string, string> }} field
 * @returns {string}
 */
export function resolveCssVarValue(field) {
  const { type, value, cssUnit = '', cssMap } = field;
  if (cssMap && typeof cssMap === 'object' && Object.prototype.hasOwnProperty.call(cssMap, value)) {
    return String(cssMap[value]);
  }
  if (type === 'range' && cssUnit) {
    return `${value}${cssUnit}`;
  }
  return String(value);
}

/**
 * Collect identity and theme options from the customizer form.
 *
 * @param {HTMLFormElement} form
 * @returns {{ identity: Record<string, string | null>, options: Record<string, string | boolean | number | null>, csrf: string }}
 */
export function collectCustomizerPayload(form) {
  const identity = {};
  const options = {};
  const csrfInput = form.querySelector('input[name="_csrf_token"]');
  const csrf = csrfInput instanceof HTMLInputElement ? csrfInput.value : '';

  form.querySelectorAll('[data-prime-identity-field]').forEach((el) => {
    if (el instanceof HTMLInputElement) {
      const key = el.dataset.primeIdentityField;
      if (key) {
        identity[key] = el.value;
      }
    }
  });

  form.querySelectorAll('[data-prime-opt-key]').forEach((el) => {
    const key = el.dataset.primeOptKey;
    const type = el.dataset.primeOptType || 'text';
    if (!key) {
      return;
    }
    if (el instanceof HTMLInputElement && el.type === 'checkbox') {
      options[key] = el.checked;
    } else if (el instanceof HTMLInputElement || el instanceof HTMLSelectElement || el instanceof HTMLTextAreaElement) {
      options[key] = type === 'range' ? Number(el.value) : el.value;
    }
  });

  return { identity, options, csrf };
}

export function initCustomizer() {
  const root = document.querySelector('[data-prime-customizer]');
  if (!(root instanceof HTMLElement)) {
    return;
  }

  const form = root.querySelector('[data-prime-customizer-form]');
  const iframe = root.querySelector('[data-prime-customizer-iframe]');
  const frameWrap = root.querySelector('[data-prime-frame-wrap]');
  const publishBtn = root.querySelector('[data-prime-customizer-publish]');
  const resetBtn = root.querySelector('[data-prime-customizer-reset]');
  const saveUrl = root.dataset.primeSaveUrl || '/admin/appearance/customizer/save';
  const resetUrl = root.dataset.primeResetUrl || '/admin/appearance/customizer/reset';

  if (!(form instanceof HTMLFormElement)) {
    return;
  }

  let dirty = false;

  const postToPreview = (payload) => {
    if (!(iframe instanceof HTMLIFrameElement) || !iframe.contentWindow) {
      return;
    }
    iframe.contentWindow.postMessage(
      { source: 'prime-customizer', ...payload },
      window.location.origin,
    );
  };

  // Stream live updates on input/change
  form.addEventListener('input', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) {
      return;
    }
    dirty = true;

    const identityField = target.dataset.primeIdentityField;
    if (identityField && target instanceof HTMLInputElement) {
      postToPreview({ type: 'identity', field: identityField, value: target.value });
      return;
    }

    const optKey = target.dataset.primeOptKey;
    const optType = target.dataset.primeOptType || 'text';
    if (!optKey) {
      return;
    }

    if (target instanceof HTMLInputElement && optType === 'color') {
      const label = form.querySelector(`[data-prime-color-value="${optKey}"]`);
      if (label) {
        label.textContent = target.value;
      }
    }
    if (target instanceof HTMLInputElement && optType === 'range') {
      const unit = target.dataset.primeCssUnit || '';
      const label = form.querySelector(`[data-prime-range-value="${optKey}"]`);
      if (label) {
        label.textContent = `${target.value}${unit}`;
      }
    }

    const cssVar = target.dataset.primeCssVar || '';
    if (cssVar && (target instanceof HTMLInputElement || target instanceof HTMLSelectElement)) {
      let cssMap;
      if (target.dataset.primeCssMap) {
        try {
          cssMap = JSON.parse(target.dataset.primeCssMap);
        } catch {
          cssMap = undefined;
        }
      }
      const value = resolveCssVarValue({
        type: optType,
        value: target.value,
        cssUnit: target.dataset.primeCssUnit || '',
        cssMap,
      });
      postToPreview({ type: 'css-var', name: cssVar, value });
    }
  });

  // Device viewport toggles (desktop / tablet / mobile)
  root.querySelectorAll('[data-prime-device]').forEach((btn) => {
    if (!(btn instanceof HTMLButtonElement)) {
      return;
    }
    btn.addEventListener('click', () => {
      const device = btn.dataset.primeDevice || 'desktop';
      if (frameWrap instanceof HTMLElement) {
        frameWrap.dataset.primeFrameWrap = device;
      }
      root.querySelectorAll('[data-prime-device]').forEach((other) => {
        const active = other === btn;
        other.classList.toggle('is-active', active);
        other.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    });
  });

  // Optional media picker integration via window.Prime.media
  root.querySelectorAll('[data-prime-pick-media]').forEach((btn) => {
    if (!(btn instanceof HTMLButtonElement)) {
      return;
    }
    btn.addEventListener('click', () => {
      const targetId = btn.dataset.primePickMedia;
      const input = targetId ? document.getElementById(targetId) : null;
      if (!(input instanceof HTMLInputElement)) {
        return;
      }
      if (window.Prime?.media?.openPicker) {
        window.Prime.media.openPicker({
          mime: 'image',
          onSelect: (media) => {
            if (media && media.id) {
              input.value = String(media.id);
              dirty = true;
              input.dispatchEvent(new Event('input', { bubbles: true }));
            }
          },
        });
      }
    });
  });

  // Publish button
  const publish = async () => {
    const { identity, options, csrf } = collectCustomizerPayload(form);
    if (publishBtn instanceof HTMLButtonElement) {
      publishBtn.disabled = true;
    }
    try {
      const res = await fetch(saveUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-Token': csrf,
        },
        body: JSON.stringify({ _csrf_token: csrf, identity, options }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.ok === false) {
        showToast(data.message || tr('admin.js.customizer.impossible_d_enregistrer_la_personnalisation', 'Impossible d\'enregistrer la personnalisation.'), 'danger');
        return;
      }
      dirty = false;
      showToast(data.message || tr('admin.js.customizer.personnalisation_publiee', 'Personnalisation publiée.'), 'success');
      postToPreview({ type: 'reload' });
    } catch {
      showToast(tr('admin.js.customizer.erreur_reseau_lors_de_la', 'Erreur réseau lors de la publication.'), 'danger');
    } finally {
      if (publishBtn instanceof HTMLButtonElement) {
        publishBtn.disabled = false;
      }
    }
  };

  if (publishBtn instanceof HTMLButtonElement) {
    publishBtn.addEventListener('click', publish);
  }
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    publish();
  });

  // Reset to defaults
  if (resetBtn instanceof HTMLButtonElement) {
    resetBtn.addEventListener('click', async () => {
      if (!window.confirm(tr('admin.js.customizer.reinitialiser_toutes_les_options_du', 'Réinitialiser toutes les options du thème aux valeurs par défaut ?'))) {
        return;
      }
      const { csrf } = collectCustomizerPayload(form);
      try {
        const res = await fetch(resetUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-Token': csrf,
          },
          body: JSON.stringify({ _csrf_token: csrf }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.ok === false) {
          showToast(data.message || tr('admin.js.customizer.reinitialisation_impossible', 'Réinitialisation impossible.'), 'danger');
          return;
        }
        dirty = false;
        window.location.reload();
      } catch {
        showToast(tr('admin.js.customizer.erreur_reseau_lors_de_la', 'Erreur réseau lors de la réinitialisation.'), 'danger');
      }
    });
  }

  window.addEventListener('beforeunload', (e) => {
    if (dirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });
}
