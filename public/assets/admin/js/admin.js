/**
 * Prime CMS Admin — Main ES2022 entry point.
 * Zero external dependencies, strict CSP compliant (no inline executable scripts).
 */

import { initAdminUsers } from './admin-users.js';
import { initAdminPlugins } from './plugins.js';
import { initContentAdmin } from './content-admin.js';
import { editorApi, initPrimeEditor } from './editor/core.js';
import { initMediaLibrary } from './media/library.js';
import { initMediaFields, mediaApi } from './media/picker.js';
import { initCommandPalette, openCommandPalette } from './command-palette.js';
import { closeDrawer, initDrawers, openDrawer } from './drawer.js';
import { initDropdowns } from './dropdown.js';
import { closeModal, initModals, openModal } from './modal.js';
import { initSidebar } from './sidebar.js';
import { initTabs } from './tabs.js';
import { initToasts, showToast } from './toast.js';
import { initTooltips } from './tooltip.js';

/**
 * Progressive enhancement for password visibility toggle buttons.
 */
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

    const reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
    button.setAttribute(
      'aria-label',
      reveal ? 'Masquer le mot de passe' : 'Afficher le mot de passe',
    );

    const iconUse = button.querySelector('use');
    if (iconUse) {
      const href = iconUse.getAttribute('href') || '';
      const base = href.split('#')[0];
      iconUse.setAttribute('href', `${base}#${reveal ? 'eye-off' : 'eye'}`);
    }
  });
}

/**
 * Progressive enhancement for bulk row selection checkboxes in tables.
 */
function initBulkTables() {
  document.querySelectorAll('[data-prime-table-bulk]').forEach((wrapper) => {
    if (!(wrapper instanceof HTMLElement)) {
      return;
    }

    const selectAll = wrapper.querySelector('[data-prime-table-select-all]');
    const rowBoxes = Array.from(wrapper.querySelectorAll('[data-prime-table-select-row]')).filter(
      (el) => el instanceof HTMLInputElement,
    );
    const bulkBar = wrapper.querySelector('[data-prime-table-bulk-bar]');
    const countEl = wrapper.querySelector('[data-prime-table-bulk-count]');
    const clearBtn = wrapper.querySelector('[data-prime-table-bulk-clear]');

    if (rowBoxes.length === 0) {
      return;
    }

    function sync() {
      const checked = rowBoxes.filter((box) => box.checked);
      const count = checked.length;

      rowBoxes.forEach((box) => {
        const row = box.closest('tr');
        if (row) {
          row.classList.toggle('is-selected', box.checked);
        }
      });

      if (selectAll instanceof HTMLInputElement) {
        selectAll.checked = count > 0 && count === rowBoxes.length;
        selectAll.indeterminate = count > 0 && count < rowBoxes.length;
      }

      if (bulkBar instanceof HTMLElement) {
        bulkBar.hidden = count === 0;
      }

      if (countEl instanceof HTMLElement) {
        countEl.textContent =
          count > 1 ? `${count} éléments sélectionnés` : `${count} élément sélectionné`;
      }
    }

    if (selectAll instanceof HTMLInputElement) {
      selectAll.addEventListener('change', () => {
        rowBoxes.forEach((box) => {
          box.checked = selectAll.checked;
        });
        sync();
      });
    }

    rowBoxes.forEach((box) => {
      box.addEventListener('change', sync);
    });

    if (clearBtn instanceof HTMLElement) {
      clearBtn.addEventListener('click', () => {
        rowBoxes.forEach((box) => {
          box.checked = false;
        });
        sync();
      });
    }

    sync();
  });
}

function bootstrap() {
  initSidebar();
  initDropdowns();
  initModals();
  initDrawers();
  initTabs();
  initToasts();
  initTooltips();
  initCommandPalette();
  initPasswordToggles();
  initBulkTables();
  initAdminUsers();
  initAdminPlugins();
  initContentAdmin();
  initPrimeEditor();
  initMediaLibrary();
  initMediaFields();
}

window.Prime = Object.freeze({
  toast: showToast,
  openCommandPalette,
  openModal,
  closeModal,
  openDrawer,
  closeDrawer,
  editor: editorApi,
  media: mediaApi,
});

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
} else {
  bootstrap();
}
