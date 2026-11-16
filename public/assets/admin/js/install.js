/**
 * Prime CMS — Installation Wizard client-side enhancements (vanilla ES2022).
 *
 * Strictly CSP-compliant (zero inline scripts), using `data-prime-*` hooks only:
 * - Password show/hide toggles (`data-prime-password-toggle`)
 * - Real-time password strength meter (`data-prime-password-strength`)
 * - On-blur and on-submit accessible field validation (`data-prime-validate`, `[required]`)
 * - Preserves existing `aria-describedby` IDs (help text) when toggling client errors
 * - Moves focus to the first invalid field on submit failure or server-side errors
 * - Copy-to-clipboard for manual `.env` fallback (`data-prime-copy-target`)
 */

import { tr } from './i18n.js';
const FORBIDDEN_PASSWORDS = new Set([
  'password1234',
  'password12345',
  'password123456',
  '123456789012',
  '1234567890123',
  '12345678901234',
  'azerty123456',
  'qwerty123456',
  'admin1234567',
  'admin12345678',
  'administrator',
  'primecms1234',
  'primecms2026',
  'motdepasse12',
  'motdepasse123',
  'iloveyou1234',
]);

function initPasswordToggles() {
  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    const toggle = target.closest('[data-prime-password-toggle]');
    if (!toggle) {
      return;
    }

    const group = toggle.closest('.pc-input-group');
    const input = group ? group.querySelector('input') : null;
    if (!input) {
      return;
    }

    const revealing = input.type === 'password';
    input.type = revealing ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', revealing ? 'true' : 'false');
    toggle.setAttribute('aria-label', revealing ? 'Masquer le mot de passe' : 'Afficher le mot de passe');

    const useEl = toggle.querySelector('use');
    if (useEl) {
      const href = useEl.getAttribute('href') || '';
      const base = href.split('#')[0];
      useEl.setAttribute('href', `${base}#${revealing ? 'eye-off' : 'eye'}`);
    }
  });
}

function evaluatePasswordStrength(password, context = {}) {
  if (!password) {
    return { level: 0, label: tr('admin.js.install.minimum_12_caracteres_recommandes_phrase', 'Minimum 12 caractères recommandés (phrase secrète, majuscules, chiffres et symboles).') };
  }

  const lower = password.trim().toLowerCase();
  const compact = lower.replace(/[\s._-]+/g, '');
  if (/^(.)\1+$/.test(lower) || FORBIDDEN_PASSWORDS.has(compact)) {
    return { level: 1, label: tr('admin.js.install.faible_ce_mot_de_passe', 'Faible — ce mot de passe est trop courant ou répétitif.') };
  }

  if (context.siteName && context.siteName.length >= 4 && lower.includes(context.siteName.toLowerCase())) {
    return { level: 1, label: tr('admin.js.install.faible_evitez_d_utiliser_le', 'Faible — évitez d\'utiliser le nom du site dans le mot de passe.') };
  }

  if (context.email) {
    const local = context.email.toLowerCase().split('@')[0] || '';
    if (local.length >= 4 && lower.includes(local)) {
      return { level: 1, label: tr('admin.js.install.faible_evitez_de_reprendre_votre', 'Faible — évitez de reprendre votre adresse e-mail.') };
    }
  }

  if (password.length < 12) {
    return { level: 1, label: `Trop court (${password.length}/12 caractères minimum).` };
  }

  let variety = 0;
  if (/[a-z]/.test(password)) variety += 1;
  if (/[A-Z]/.test(password)) variety += 1;
  if (/\d/.test(password)) variety += 1;
  if (/[^A-Za-z0-9]/.test(password)) variety += 1;

  if (password.length >= 16 && variety >= 3) {
    return { level: 4, label: tr('admin.js.install.robuste_excellente_resistance_aux_attaques', 'Robuste — excellente résistance aux attaques par force brute.') };
  }
  if (password.length >= 12 && variety >= 3) {
    return { level: 3, label: tr('admin.js.install.bon_ajoutez_encore_quelques_caracteres', 'Bon — ajoutez encore quelques caractères ou symboles pour le renforcer.') };
  }
  return { level: 2, label: tr('admin.js.install.moyen_combinez_majuscules_chiffres_et', 'Moyen — combinez majuscules, chiffres et symboles.') };
}

function initPasswordStrength() {
  const meter = document.querySelector('[data-prime-password-strength]');
  if (!meter) {
    return;
  }

  const inputId = meter.getAttribute('data-prime-password-strength');
  const input = inputId ? document.getElementById(inputId) : null;
  const labelEl = meter.querySelector('[data-prime-strength-label]');
  if (!input || !labelEl) {
    return;
  }

  const emailInput = document.getElementById('admin-email');
  const siteName = meter.getAttribute('data-prime-site-name') || '';

  const update = () => {
    const result = evaluatePasswordStrength(input.value, {
      email: emailInput ? emailInput.value : '',
      siteName,
    });
    meter.setAttribute('data-prime-level', String(result.level));
    labelEl.textContent = result.label;
  };

  input.addEventListener('input', update);
  if (emailInput) {
    emailInput.addEventListener('input', update);
  }
  update();
}

function getDescribedByIds(input) {
  const raw = input.getAttribute('aria-describedby');
  if (!raw) {
    return [];
  }

  return raw.trim().split(/\s+/).filter(Boolean);
}

function syncDescribedBy(input, ids) {
  const unique = Array.from(new Set(ids.filter(Boolean)));
  if (unique.length === 0) {
    input.removeAttribute('aria-describedby');
  } else {
    input.setAttribute('aria-describedby', unique.join(' '));
  }
}

function setClientFieldError(input, message) {
  const wrapper = input.closest('.pc-field');
  if (!wrapper) {
    return;
  }

  const clientErrorId = `${input.id || input.name}-client-error`;
  let describedBy = getDescribedByIds(input);

  const serverError = wrapper.querySelector('.pc-field__error:not([data-prime-client-error])');
  if (serverError) {
    if (serverError.id) {
      describedBy = describedBy.filter((id) => id !== serverError.id);
    }
    serverError.remove();
  }

  let errorEl = wrapper.querySelector('[data-prime-client-error]');

  if (!message) {
    wrapper.classList.remove('pc-field--error');
    input.removeAttribute('aria-invalid');
    if (errorEl) {
      if (errorEl.id) {
        describedBy = describedBy.filter((id) => id !== errorEl.id);
      }
      errorEl.remove();
    } else {
      describedBy = describedBy.filter((id) => id !== clientErrorId);
    }
    syncDescribedBy(input, describedBy);
    return;
  }

  wrapper.classList.add('pc-field--error');
  input.setAttribute('aria-invalid', 'true');

  if (!errorEl) {
    errorEl = document.createElement('p');
    errorEl.className = 'pc-field__error';
    errorEl.setAttribute('role', 'alert');
    errorEl.setAttribute('data-prime-client-error', 'true');
    errorEl.id = clientErrorId;
    wrapper.appendChild(errorEl);
  }

  errorEl.textContent = message;
  if (!describedBy.includes(errorEl.id)) {
    describedBy.push(errorEl.id);
  }
  syncDescribedBy(input, describedBy);
}

function validateInput(input, checkRequired = false) {
  const rule = input.getAttribute('data-prime-validate');
  const val = input.value;
  const trimmed = val.trim();

  if (checkRequired && input.hasAttribute('required') && trimmed === '') {
    return tr('admin.js.install.ce_champ_est_obligatoire', 'Ce champ est obligatoire.');
  }

  if (rule === 'prefix') {
    if (!/^(?=.*[a-z0-9])[a-z0-9_]{1,19}_$/.test(trimmed)) {
      return tr('admin.js.install.utilisez_uniquement_des_lettres_minuscules', 'Utilisez uniquement des lettres minuscules, des chiffres et « _ », en terminant par « _ » (ex. « pc_ »).');
    }
    return null;
  }

  if (rule === 'url') {
    if (!/^https?:\/\/[^\s/$.?#].[^\s]*$/i.test(trimmed)) {
      return tr('admin.js.install.saisissez_une_url_complete_commencant', 'Saisissez une URL complète commençant par http:// ou https://.');
    }
    return null;
  }

  if (rule === 'email') {
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) {
      return tr('admin.js.install.saisissez_une_adresse_e_mail', 'Saisissez une adresse e-mail valide (ex. « admin@monsite.fr »).');
    }
    return null;
  }

  if (rule === 'display-name') {
    if (trimmed.length < 2 || trimmed.length > 100) {
      return tr('admin.js.install.indiquez_un_nom_affiche_compris', 'Indiquez un nom affiché compris entre 2 et 100 caractères.');
    }
    return null;
  }

  if (rule === 'admin-password') {
    if (val.length < 12) {
      return tr('admin.js.install.le_mot_de_passe_doit', 'Le mot de passe doit contenir au moins 12 caractères.');
    }
    const lower = val.trim().toLowerCase();
    const compact = lower.replace(/[\s._-]+/g, '');
    if (/^(.)\1+$/.test(lower) || FORBIDDEN_PASSWORDS.has(compact)) {
      return tr('admin.js.install.ce_mot_de_passe_est', 'Ce mot de passe est trop courant. Choisissez une phrase secrète plus variée.');
    }
    return null;
  }

  if (rule === 'password-confirm') {
    const primary = document.getElementById('admin-password');
    if (primary && val !== primary.value) {
      return tr('admin.js.install.les_deux_mots_de_passe', 'Les deux mots de passe ne correspondent pas.');
    }
    return null;
  }

  if (checkRequired && input.type === 'number' && trimmed !== '') {
    const num = Number(trimmed);
    if (!Number.isInteger(num) || num < 1 || num > 65535) {
      return tr('admin.js.install.indiquez_un_numero_de_port', 'Indiquez un numéro de port valide (entre 1 et 65535).');
    }
  }

  return null;
}

function initFormValidation() {
  const inputs = document.querySelectorAll('[data-prime-validate]');
  inputs.forEach((input) => {
    input.addEventListener('blur', () => {
      if (input.value === '' && !input.hasAttribute('aria-invalid')) {
        return;
      }
      setClientFieldError(input, validateInput(input, false));
    });
  });

  document.querySelectorAll('.pc-install__form').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const fields = form.querySelectorAll('input[data-prime-validate], input[required], select[required], textarea[required]');
      let firstInvalid = null;

      fields.forEach((field) => {
        if (field.disabled || field.type === 'hidden') {
          return;
        }
        const error = validateInput(field, true);
        if (error) {
          setClientFieldError(field, error);
          if (!firstInvalid) {
            firstInvalid = field;
          }
        } else if (field.hasAttribute('data-prime-validate') || field.hasAttribute('aria-invalid')) {
          setClientFieldError(field, null);
        }
      });

      if (firstInvalid) {
        event.preventDefault();
        if (typeof firstInvalid.focus === 'function') {
          firstInvalid.focus();
        }
      }
    });
  });
}

function focusFirstInvalidField() {
  const invalid = document.querySelector('[aria-invalid="true"]');
  if (invalid && typeof invalid.focus === 'function') {
    invalid.focus();
  }
}

function initCopyButtons() {
  document.addEventListener('click', async (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    const btn = target.closest('[data-prime-copy-target]');
    if (!btn) {
      return;
    }

    const targetId = btn.getAttribute('data-prime-copy-target');
    const source = targetId ? document.getElementById(targetId) : null;
    const statusId = btn.getAttribute('data-prime-copy-status');
    const statusEl = statusId ? document.getElementById(statusId) : null;
    if (!source) {
      return;
    }

    const text = 'value' in source ? source.value : source.textContent || '';

    try {
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        await navigator.clipboard.writeText(text);
      } else if ('select' in source) {
        source.focus();
        source.select();
        document.execCommand('copy');
      }
      if (statusEl) {
        statusEl.textContent = 'Contenu .env copié dans le presse-papiers.';
      }
    } catch {
      if (statusEl) {
        statusEl.textContent = 'Sélectionnez le texte ci-dessous et copiez-le avec Ctrl+C / ⌘+C.';
      }
    }
  });
}

function bootInstaller() {
  initPasswordToggles();
  initPasswordStrength();
  initFormValidation();
  initCopyButtons();
  focusFirstInvalidField();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootInstaller, { once: true });
} else {
  bootInstaller();
}
