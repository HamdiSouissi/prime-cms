/**
 * Registry-driven settings screens: show a field only when the field it depends on has the
 * expected value (`data-prime-setting-condition='{"field":"mail.driver","value":["smtp","queue"]}'`),
 * and move focus to the error summary after a failed save.
 */

/**
 * Current value of a setting inside a form (`settings[<name>]`), as a list of strings.
 *
 * @param {HTMLFormElement} form
 * @param {string} name
 * @returns {string[]}
 */
export function currentValues(form, name) {
  const controls = Array.from(form.elements).filter(
    (el) => el instanceof HTMLElement && el.getAttribute('name') === `settings[${name}]`,
  );
  const values = [];
  controls.forEach((el) => {
    if (el instanceof HTMLInputElement) {
      if (el.type === 'hidden') {
        return;
      }
      if (el.type === 'checkbox' || el.type === 'radio') {
        if (el.checked) {
          values.push(el.value);
        }
        return;
      }
    }
    if ('value' in el) {
      values.push(String(el.value));
    }
  });

  // An unchecked toggle only leaves its hidden "0" behind.
  if (values.length === 0 && controls.some((el) => el instanceof HTMLInputElement && el.type === 'checkbox')) {
    values.push('0');
  }

  return values;
}

/**
 * @param {{field?: unknown, value?: unknown}} condition
 * @param {string[]} values
 * @returns {boolean}
 */
export function conditionMet(condition, values) {
  const expected = Array.isArray(condition.value) ? condition.value.map(String) : [String(condition.value ?? '')];
  return values.some((value) => expected.includes(value));
}

function applyConditions(form) {
  form.querySelectorAll('[data-prime-setting-condition]').forEach((wrapper) => {
    let condition;
    try {
      condition = JSON.parse(wrapper.getAttribute('data-prime-setting-condition') || '{}');
    } catch {
      return;
    }
    if (!condition || typeof condition.field !== 'string') {
      return;
    }
    const visible = conditionMet(condition, currentValues(form, condition.field));
    wrapper.hidden = !visible;
    wrapper.querySelectorAll('input, select, textarea').forEach((control) => {
      // Hidden fields still post their value (so nothing is lost), but must not block validation.
      if (control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement) {
        if (!visible && control.required) {
          control.dataset.primeWasRequired = '1';
          control.required = false;
        } else if (visible && control.dataset.primeWasRequired === '1') {
          control.required = true;
          delete control.dataset.primeWasRequired;
        }
      }
    });
  });
}

export function initSettingsAdmin(root = document) {
  root.querySelectorAll('form[data-prime-settings-form], form').forEach((form) => {
    if (!(form instanceof HTMLFormElement) || !form.querySelector('[data-prime-setting-condition]')) {
      return;
    }
    applyConditions(form);
    form.addEventListener('change', () => applyConditions(form));
    form.addEventListener('input', () => applyConditions(form));
  });

  const summary = root.querySelector('[data-prime-settings-errors]');
  if (summary instanceof HTMLElement) {
    summary.focus();
  }

  initDatePreviews(root);
  initUnsavedWarnings(root);
  initTimezoneFilters(root);
}

/**
 * Normalise une chaîne pour la comparaison : minuscules, sans accents.
 */
function normalizeText(text) {
  return String(text ?? '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .trim();
}

/**
 * Filtre les <option> d'un <select> de fuseaux horaires selon la saisie dans
 * l'input [data-prime-settings-form] associé. Masque les <optgroup> vides.
 * Ne modifie jamais la valeur sélectionnée.
 */
function initTimezoneFilters(root) {
  root.querySelectorAll('.pc-timezone-filter').forEach((filterInput) => {
    if (!(filterInput instanceof HTMLInputElement)) {
      return;
    }

    const selectId = filterInput.getAttribute('aria-controls') || '';
    const select = root.getElementById ? root.getElementById(selectId) : null;
    if (!(select instanceof HTMLSelectElement)) {
      return;
    }

    const options = Array.from(select.querySelectorAll('option'));
    const groups = Array.from(select.querySelectorAll('optgroup'));

    // Valeur actuellement sélectionnée : on ne la touche jamais.
    const selectedValue = select.value;

    const render = () => {
      const query = normalizeText(filterInput.value);
      let anyVisible = false;

      options.forEach((option) => {
        const text = normalizeText(option.textContent);
        const match = query === '' || text.includes(query);
        option.hidden = !match;
        if (match) {
          anyVisible = true;
        }
      });

      groups.forEach((group) => {
        const visibleOptions = Array.from(group.querySelectorAll('option:not([hidden])'));
        group.hidden = visibleOptions.length === 0;
      });

      // Ne jamais changer la sélection : si l'option sélectionnée est masquée,
      // on laisse la valeur telle quelle (le formulaire l'envoie quand même).
    };

    filterInput.addEventListener('input', render);
    render();
  });
}

const pad = (n) => String(n).padStart(2, '0');

/**
 * Format a date with the PHP `date()` tokens the settings screen offers (a common subset),
 * using the interface month and weekday names. `\x` escapes a literal character.
 *
 * @param {string} format
 * @param {Date} date
 * @param {{months?: string[], days?: string[]}} names
 * @returns {string}
 */
export function formatPhpDate(format, date, names = {}) {
  const months = names.months && names.months.length === 12 ? names.months : ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  const days = names.days && names.days.length === 7 ? names.days : ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  const h = date.getHours();
  const tokens = {
    d: () => pad(date.getDate()),
    j: () => String(date.getDate()),
    D: () => days[date.getDay()].slice(0, 3),
    l: () => days[date.getDay()],
    N: () => String(date.getDay() === 0 ? 7 : date.getDay()),
    m: () => pad(date.getMonth() + 1),
    n: () => String(date.getMonth() + 1),
    F: () => months[date.getMonth()],
    M: () => months[date.getMonth()].slice(0, 3),
    Y: () => String(date.getFullYear()),
    y: () => String(date.getFullYear()).slice(-2),
    H: () => pad(h),
    G: () => String(h),
    h: () => pad(h % 12 === 0 ? 12 : h % 12),
    g: () => String(h % 12 === 0 ? 12 : h % 12),
    i: () => pad(date.getMinutes()),
    s: () => pad(date.getSeconds()),
    a: () => (h < 12 ? 'am' : 'pm'),
    A: () => (h < 12 ? 'AM' : 'PM'),
  };
  let out = '';
  for (let i = 0; i < format.length; i += 1) {
    const ch = format[i];
    if (ch === '\\' && i + 1 < format.length) {
      out += format[i + 1];
      i += 1;
    } else if (Object.prototype.hasOwnProperty.call(tokens, ch)) {
      out += tokens[ch]();
    } else {
      out += ch;
    }
  }
  return out;
}

function initDatePreviews(root) {
  root.querySelectorAll('[data-prime-date-preview]').forEach((preview) => {
    const input = root.getElementById ? root.getElementById(preview.getAttribute('data-prime-date-preview') || '') : null;
    const value = preview.querySelector('[data-prime-date-preview-value]');
    if (!(input instanceof HTMLInputElement) || !(value instanceof HTMLElement)) {
      return;
    }
    let names = {};
    try {
      names = { months: JSON.parse(preview.getAttribute('data-months') || '[]'), days: JSON.parse(preview.getAttribute('data-days') || '[]') };
    } catch {
      names = {};
    }
    const render = () => {
      value.textContent = input.value.trim() === '' ? '—' : formatPhpDate(input.value, new Date(), names);
    };
    input.addEventListener('input', render);
    render();
  });
}

/**
 * Warn before leaving a settings form with unsaved changes.
 *
 * @param {HTMLFormElement} form
 */
export function trackUnsavedChanges(form) {
  const state = { dirty: false };
  form.addEventListener('input', () => {
    state.dirty = true;
  });
  form.addEventListener('change', () => {
    state.dirty = true;
  });
  form.addEventListener('submit', () => {
    state.dirty = false;
  });
  window.addEventListener('beforeunload', (event) => {
    if (state.dirty) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
  return state;
}

function initUnsavedWarnings(root) {
  root.querySelectorAll('form[data-prime-settings-form]').forEach((form) => {
    if (form instanceof HTMLFormElement) {
      trackUnsavedChanges(form);
    }
  });
}
