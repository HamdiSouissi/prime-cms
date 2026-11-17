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
}
