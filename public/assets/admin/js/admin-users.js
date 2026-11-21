/**
 * Prime CMS Admin — Progressive ES2022 helpers for Users, Roles & Profile screens.
 * Zero inline scripts, zero external libraries.
 */

import { showToast } from './toast.js';
import { tr } from './i18n.js';

function slugify(value) {
  return value
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

function initAutoSlug() {
  document.querySelectorAll('[data-prime-slug-source]').forEach((source) => {
    if (!(source instanceof HTMLInputElement)) {
      return;
    }

    const targetId = source.getAttribute('data-prime-slug-source');
    const target = targetId ? document.getElementById(targetId) : null;
    if (!(target instanceof HTMLInputElement)) {
      return;
    }

    let manual = target.value.trim() !== '';

    target.addEventListener('input', () => {
      manual = target.value.trim() !== '';
    });

    source.addEventListener('input', () => {
      if (!manual) {
        target.value = slugify(source.value);
      }
    });
  });
}

function initCapabilityGroupToggles() {
  document.querySelectorAll('[data-prime-cap-group]').forEach((group) => {
    const groupId = group.getAttribute('data-prime-cap-group');
    const toggleBtn = group.querySelector(`[data-prime-cap-group-toggle="${groupId}"]`);
    const checkboxes = Array.from(group.querySelectorAll('input[type="checkbox"]:not(:disabled)'));

    if (!(toggleBtn instanceof HTMLButtonElement) || checkboxes.length === 0) {
      return;
    }

    const syncLabel = () => {
      const allChecked = checkboxes.every((cb) => cb instanceof HTMLInputElement && cb.checked);
      toggleBtn.textContent = allChecked ? tr('admin.js.admin-users.tout_decocher', 'Tout décocher') : tr('admin.js.admin-users.check_all', 'Tout cocher');
    };

    toggleBtn.addEventListener('click', () => {
      const allChecked = checkboxes.every((cb) => cb instanceof HTMLInputElement && cb.checked);
      checkboxes.forEach((cb) => {
        if (cb instanceof HTMLInputElement) {
          cb.checked = !allChecked;
        }
      });
      syncLabel();
    });

    checkboxes.forEach((cb) => cb.addEventListener('change', syncLabel));
    syncLabel();
  });
}

function initUserCreationMode() {
  const radios = document.querySelectorAll('[data-prime-creation-mode]');
  const pwdSection = document.querySelector('[data-prime-password-section]');
  if (radios.length === 0 || !(pwdSection instanceof HTMLElement)) {
    return;
  }

  const sync = () => {
    const selected = document.querySelector('[data-prime-creation-mode]:checked');
    const isInvite = selected instanceof HTMLInputElement && selected.value === 'invite';
    pwdSection.hidden = isInvite;
  };

  radios.forEach((r) => r.addEventListener('change', sync));
  sync();
}

function initCopyAndDownload() {
  document.addEventListener('click', async (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    const copyBtn = target.closest('[data-prime-copy-text]');
    if (copyBtn instanceof HTMLElement) {
      const text = copyBtn.getAttribute('data-prime-copy-text') || '';
      const feedback = copyBtn.getAttribute('data-prime-copy-feedback') || tr('admin.js.admin-users.copie_dans_le_presse_papiers', 'Copié dans le presse-papiers.');
      try {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
          await navigator.clipboard.writeText(text);
          showToast(feedback, 'success');
        }
      } catch {
        showToast(tr('admin.js.admin-users.impossible_de_copier_automatiquement_copiez', 'Impossible de copier automatiquement. Copiez la sélection manuellement.'), 'warning');
      }
      return;
    }

    const downloadBtn = target.closest('[data-prime-download-text]');
    if (downloadBtn instanceof HTMLElement) {
      const content = downloadBtn.getAttribute('data-prime-download-text') || '';
      const filename = downloadBtn.getAttribute('data-prime-download-filename') || 'prime-cms-codes.txt';
      const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
  });
}

function initConfirmForms() {
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
      return;
    }

    const msg = form.getAttribute('data-prime-confirm-form');
    if (msg && !window.confirm(msg)) {
      event.preventDefault();
      return;
    }

    if (form.id === 'pc-users-bulk-form') {
      const select = form.querySelector('[data-prime-bulk-action-select]');
      const checkedCount = document.querySelectorAll('input[form="pc-users-bulk-form"][data-prime-table-select-row]:checked').length;
      if (select instanceof HTMLSelectElement && select.value === 'delete') {
        const confirmMsg = checkedCount > 1
          ? `Supprimer définitivement ${checkedCount} utilisateurs sélectionnés ?`
          : tr('admin.js.admin-users.supprimer_definitivement_1_utilisateur_selectionne', 'Supprimer définitivement 1 utilisateur sélectionné ?');
        if (!window.confirm(confirmMsg)) {
          event.preventDefault();
        }
      }
    }
  });
}

function initAdminPasswordStrength() {
  document.querySelectorAll('[data-prime-password-strength]').forEach((meter) => {
    const inputId = meter.getAttribute('data-prime-password-strength');
    const input = inputId ? document.getElementById(inputId) : null;
    const labelEl = meter.querySelector('[data-prime-strength-label]');
    if (!(input instanceof HTMLInputElement) || !(labelEl instanceof HTMLElement)) {
      return;
    }

    const update = () => {
      const val = input.value;
      if (!val) {
        meter.setAttribute('data-prime-level', '0');
        labelEl.textContent = tr('admin.js.admin-users.password_min', 'Minimum 12 caractères (majuscules, chiffres et symboles recommandés).');
        return;
      }

      if (val.length < 12) {
        meter.setAttribute('data-prime-level', '1');
        labelEl.textContent = `Trop court (${val.length}/12 caractères minimum).`;
        return;
      }

      let variety = 0;
      if (/[a-z]/.test(val)) variety += 1;
      if (/[A-Z]/.test(val)) variety += 1;
      if (/\d/.test(val)) variety += 1;
      if (/[^A-Za-z0-9]/.test(val)) variety += 1;

      if (val.length >= 16 && variety >= 3) {
        meter.setAttribute('data-prime-level', '4');
        labelEl.textContent = tr('admin.js.admin-users.strength_strong', 'Robuste — excellente résistance aux attaques.');
      } else if (variety >= 3) {
        meter.setAttribute('data-prime-level', '3');
        labelEl.textContent = tr('admin.js.admin-users.strength_good', 'Bon — ajoutez encore quelques caractères ou symboles.');
      } else {
        meter.setAttribute('data-prime-level', '2');
        labelEl.textContent = tr('admin.js.admin-users.strength_medium', 'Moyen — combinez majuscules, chiffres et symboles.');
      }
    };

    input.addEventListener('input', update);
  });
}

/**
 * Capabilities a token-creation shortcut ticks: `readonly` keeps reading capabilities
 * (`*.view`), `all` every capability offered, `none` nothing.
 *
 * @param {string} preset
 * @param {string[]} offered
 * @returns {Set<string>}
 */
export function abilitiesForPreset(preset, offered) {
  if (preset === 'all') {
    return new Set(offered);
  }
  if (preset === 'readonly') {
    return new Set(offered.filter((cap) => cap.endsWith('.view')));
  }
  return new Set();
}

function initApiTokenShortcuts() {
  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }
    const button = target.closest('[data-prime-api-token-select]');
    const form = button ? button.closest('form') : null;
    if (!(button instanceof HTMLElement) || !form) {
      return;
    }
    event.preventDefault();
    const boxes = Array.from(form.querySelectorAll('input[type="checkbox"][name="abilities[]"]'));
    const selected = abilitiesForPreset(
      button.getAttribute('data-prime-api-token-select') || 'none',
      boxes.map((box) => box.value),
    );
    boxes.forEach((box) => {
      box.checked = selected.has(box.value);
    });
  });
}

export function initAdminUsers() {
  initApiTokenShortcuts();
  initAutoSlug();
  initCapabilityGroupToggles();
  initUserCreationMode();
  initCopyAndDownload();
  initConfirmForms();
  initAdminPasswordStrength();
}
