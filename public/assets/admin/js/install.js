/**
 * Prime CMS — Installation Wizard client-side enhancements (vanilla ES2022).
 *
 * Strictly CSP-compliant (zero inline scripts), using `data-prime-*` hooks only:
 * - Password show/hide toggles (`data-prime-password-toggle`)
 * - Real-time password strength meter (`data-prime-password-strength`)
 * - On-blur accessible field validation (`data-prime-validate`)
 * - Auto-focus on the first server-flagged invalid input (`[aria-invalid="true"]`)
 * - Copy-to-clipboard for manual `.env` fallback (`data-prime-copy-target`)
 */

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
    const toggle = event.target.closest('[data-prime-password-toggle]');
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
    return { level: 0, label: 'Minimum 12 caractères recommandés (phrase secrète, majuscules, chiffres et symboles).' };
  }

  const lower = password.trim().toLowerCase();
  const compact = lower.replace(/[\s._-]+/g, '');
  if (/^(.)\1+$/.test(lower) || FORBIDDEN_PASSWORDS.has(compact)) {
    return { level: 1, label: 'Faible — ce mot de passe est trop courant ou répétitif.' };
  }

  if (context.siteName && context.siteName.length >= 4 && lower.includes(context.siteName.toLowerCase())) {
    return { level: 1, label: 'Faible — évitez d\'utiliser le nom du site dans le mot de passe.' };
  }

  if (context.email) {
    const local = context.email.toLowerCase().split('@')[0] || '';
    if (local.length >= 4 && lower.includes(local)) {
      return { level: 1, label: 'Faible — évitez de reprendre votre adresse e-mail.' };
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
    return { level: 4, label: 'Robuste — excellente résistance aux attaques par force brute.' };
  }
  if (password.length >= 12 && variety >= 3) {
    return { level: 3, label: 'Bon — ajoutez encore quelques caractères ou symboles pour le renforcer.' };
  }
  return { level: 2, label: 'Moyen — combinez majuscules, chiffres et symboles.' };
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

function setClientFieldError(input, message) {
  const wrapper = input.closest('.pc-field');
  if (!wrapper) {
    return;
  }

  let errorEl = wrapper.querySelector('[data-prime-client-error]');
  const serverError = wrapper.querySelector('.pc-field__error:not([data-prime-client-error])');
  if (serverError) {
    serverError.remove();
  }

  if (!message) {
    wrapper.classList.remove('pc-field--error');
    input.removeAttribute('aria-invalid');
    if (errorEl) {
      errorEl.remove();
    }
    return;
  }

  wrapper.classList.add('pc-field--error');
  input.setAttribute('aria-invalid', 'true');

  if (!errorEl) {
    errorEl = document.createElement('p');
    errorEl.className = 'pc-field__error';
    errorEl.setAttribute('role', 'alert');
    errorEl.setAttribute('data-prime-client-error', 'true');
    errorEl.id = `${input.id}-client-error`;
    wrapper.appendChild(errorEl);
  }

  errorEl.textContent = message;
  input.setAttribute('aria-describedby', errorEl.id);
}

function validateInputOnBlur(input) {
  const rule = input.getAttribute('data-prime-validate');
  const val = input.value;
  const trimmed = val.trim();

  if (rule === 'prefix') {
    if (!/^(?=.*[a-z0-9])[a-z0-9_]{1,19}_$/.test(trimmed)) {
      return 'Utilisez uniquement des lettres minuscules, des chiffres et « _ », en terminant par « _ » (ex. « pc_ »).';
    }
    return null;
  }

  if (rule === 'url') {
    if (!/^https?:\/\/[^\s/$.?#].[^\s]*$/i.test(trimmed)) {
      return 'Saisissez une URL complète commençant par http:// ou https://.';
    }
    return null;
  }

  if (rule === 'email') {
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) {
      return 'Saisissez une adresse e-mail valide (ex. « admin@monsite.fr »).';
    }
    return null;
  }

  if (rule === 'display-name') {
    if (trimmed.length < 2 || trimmed.length > 100) {
      return 'Indiquez un nom affiché compris entre 2 et 100 caractères.';
    }
    return null;
  }

  if (rule === 'admin-password') {
    if (val.length < 12) {
      return 'Le mot de passe doit contenir au moins 12 caractères.';
    }
    const lower = val.trim().toLowerCase();
    const compact = lower.replace(/[\s._-]+/g, '');
    if (/^(.)\1+$/.test(lower) || FORBIDDEN_PASSWORDS.has(compact)) {
      return 'Ce mot de passe est trop courant. Choisissez une phrase secrète plus variée.';
    }
    return null;
  }

  if (rule === 'password-confirm') {
    const primary = document.getElementById('admin-password');
    if (primary && val !== primary.value) {
      return 'Les deux mots de passe ne correspondent pas.';
    }
    return null;
  }

  return null;
}

function initBlurValidation() {
  const inputs = document.querySelectorAll('[data-prime-validate]');
  inputs.forEach((input) => {
    input.addEventListener('blur', () => {
      if (input.value === '' && !input.hasAttribute('aria-invalid')) {
        return;
      }
      setClientFieldError(input, validateInputOnBlur(input));
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
    const btn = event.target.closest('[data-prime-copy-target]');
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
  initBlurValidation();
  initCopyButtons();
  focusFirstInvalidField();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootInstaller, { once: true });
} else {
  bootInstaller();
}
