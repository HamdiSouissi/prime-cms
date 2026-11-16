/**
 * Prime CMS — Authentication pages ES2022 module.
 * Zero external dependencies, strict CSP compliant (no inline executable scripts).
 */

import { tr } from './i18n.js';
const COMMON_WEAK = new Set([
  'password1234',
  'password12345',
  'password123456',
  '123456789012',
  '12345678901234',
  'azerty123456',
  'qwerty123456',
  'admin1234567',
  'admin12345678',
  'administrator',
  'administrateur',
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

    const button = target.closest('[data-prime-password-toggle]');
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }

    const group = button.closest('.pc-input-group');
    const input = group ? group.querySelector('input') : null;
    if (!(input instanceof HTMLInputElement)) {
      return;
    }

    const revealing = input.type === 'password';
    input.type = revealing ? 'text' : 'password';
    button.setAttribute('aria-pressed', revealing ? 'true' : 'false');
    button.setAttribute('aria-label', revealing ? 'Masquer le mot de passe' : 'Afficher le mot de passe');

    const iconUse = button.querySelector('use');
    if (iconUse) {
      const href = iconUse.getAttribute('href') || '';
      const base = href.split('#')[0];
      iconUse.setAttribute('href', `${base}#${revealing ? 'eye-off' : 'eye'}`);
    }
  });
}

function evaluatePasswordStrength(password) {
  if (!password) {
    return { level: 0, label: tr('admin.js.auth.minimum_12_caracteres_phrase_secrete', 'Minimum 12 caractères (phrase secrète, majuscules, chiffres et symboles).') };
  }

  const lower = password.trim().toLowerCase();
  const compact = lower.replace(/[\s._-]+/g, '');
  if (/^(.)\1+$/.test(lower) || COMMON_WEAK.has(compact)) {
    return { level: 1, label: tr('admin.js.auth.faible_ce_mot_de_passe', 'Faible — ce mot de passe est trop courant ou répétitif.') };
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
    return { level: 4, label: tr('admin.js.auth.robuste_excellente_resistance_aux_attaques', 'Robuste — excellente résistance aux attaques.') };
  }
  if (password.length >= 12 && variety >= 3) {
    return { level: 3, label: tr('admin.js.auth.bon_ajoutez_encore_quelques_caracteres', 'Bon — ajoutez encore quelques caractères ou symboles.') };
  }
  return { level: 2, label: tr('admin.js.auth.moyen_combinez_majuscules_chiffres_et', 'Moyen — combinez majuscules, chiffres et symboles.') };
}

export function initPasswordStrengthMeters() {
  document.querySelectorAll('[data-prime-password-strength]').forEach((meter) => {
    const inputId = meter.getAttribute('data-prime-password-strength');
    const input = inputId ? document.getElementById(inputId) : null;
    const labelEl = meter.querySelector('[data-prime-strength-label]');
    if (!(input instanceof HTMLInputElement) || !(labelEl instanceof HTMLElement)) {
      return;
    }

    const update = () => {
      const result = evaluatePasswordStrength(input.value);
      meter.setAttribute('data-prime-level', String(result.level));
      labelEl.textContent = result.label;
    };

    input.addEventListener('input', update);
    update();
  });
}

function initTwoFactorModeToggle() {
  const toggleBtn = document.querySelector('[data-prime-2fa-toggle]');
  const codeInput = document.getElementById('two-factor-code');
  const labelEl = document.querySelector('label[for="two-factor-code"] span');
  const helpEl = document.getElementById('two-factor-code-help');

  if (!(toggleBtn instanceof HTMLButtonElement) || !(codeInput instanceof HTMLInputElement)) {
    return;
  }

  let recoveryMode = false;

  toggleBtn.addEventListener('click', () => {
    recoveryMode = !recoveryMode;

    if (recoveryMode) {
      codeInput.value = '';
      codeInput.setAttribute('inputmode', 'text');
      codeInput.setAttribute('autocomplete', 'off');
      codeInput.setAttribute('maxlength', '12');
      codeInput.setAttribute('placeholder', 'xxxx-xxxx');
      if (labelEl) {
        labelEl.textContent = 'Code de récupération';
      }
      if (helpEl) {
        helpEl.textContent = 'Saisissez l\'un de vos codes de récupération à usage unique (format xxxx-xxxx).';
      }
      toggleBtn.textContent = 'Utiliser un code de l\'application d\'authentification';
    } else {
      codeInput.value = '';
      codeInput.setAttribute('inputmode', 'numeric');
      codeInput.setAttribute('autocomplete', 'one-time-code');
      codeInput.setAttribute('maxlength', '6');
      codeInput.setAttribute('placeholder', '000000');
      if (labelEl) {
        labelEl.textContent = tr('admin.js.auth.code_a_6_chiffres', 'Code à 6 chiffres');
      }
      if (helpEl) {
        helpEl.textContent = 'Ouvrez votre application TOTP (Aegis, Google Authenticator, 1Password…) et saisissez le code affiché.';
      }
      toggleBtn.textContent = 'Utiliser un code de récupération';
    }

    codeInput.focus();
  });
}

function focusFirstInvalid() {
  const invalid = document.querySelector('[aria-invalid="true"]');
  if (invalid instanceof HTMLElement) {
    invalid.focus();
  }
}

function bootAuth() {
  initPasswordToggles();
  initPasswordStrengthMeters();
  initTwoFactorModeToggle();
  focusFirstInvalid();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootAuth, { once: true });
} else {
  bootAuth();
}
