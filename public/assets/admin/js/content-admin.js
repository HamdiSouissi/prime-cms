/**
 * Prime CMS Admin — Content list, editor sidebar, taxonomy quick-create, and entry relation UX.
 */

import { slugify } from './editor/utils.js';

/**
 * Auto-sync permalink slug from title on new entries until the user edits the slug field manually.
 */
function initSlugSync() {
  const titleInput = document.querySelector('[data-prime-content-title]');
  const slugInput = document.querySelector('[data-prime-content-slug]');
  const previewSlug = document.querySelector('[data-prime-slug-preview]');
  if (!(titleInput instanceof HTMLInputElement) || !(slugInput instanceof HTMLInputElement)) {
    return;
  }

  let userTouched = slugInput.dataset.locked === '1' || slugInput.value.trim() !== '';

  const updatePreview = (val) => {
    if (previewSlug) {
      previewSlug.textContent = val || 'slug-automatique';
    }
  };

  slugInput.addEventListener('input', () => {
    userTouched = true;
    updatePreview(slugInput.value.trim());
  });

  titleInput.addEventListener('input', () => {
    if (!userTouched) {
      const generated = slugify(titleInput.value);
      slugInput.value = generated;
      updatePreview(generated);
    }
  });
}

/**
 * Collapsible editor inspector sidebar (desktop toggle + < 1024px slide-over drawer).
 */
function initEditorSidebarToggle() {
  const layout = document.querySelector('[data-prime-editor-layout]');
  const toggles = document.querySelectorAll('[data-prime-editor-sidebar-toggle]');
  const backdrop = document.querySelector('[data-prime-editor-sidebar-backdrop]');

  if (!(layout instanceof HTMLElement) || toggles.length === 0) {
    return;
  }

  const toggle = (forceState) => {
    const isDesktop = window.innerWidth >= 1024;
    if (isDesktop) {
      const collapsed = typeof forceState === 'boolean' ? !forceState : !layout.classList.contains('is-sidebar-collapsed');
      layout.classList.toggle('is-sidebar-collapsed', collapsed);
    } else {
      const open = typeof forceState === 'boolean' ? forceState : !layout.classList.contains('is-sidebar-open');
      layout.classList.toggle('is-sidebar-open', open);
      if (backdrop instanceof HTMLElement) {
        backdrop.hidden = !open;
      }
    }
  };

  toggles.forEach((btn) => btn.addEventListener('click', () => toggle()));
  if (backdrop instanceof HTMLElement) {
    backdrop.addEventListener('click', () => toggle(false));
  }
}

/**
 * Quick-create hierarchical taxonomy terms (e.g. categories) directly from the editor sidebar.
 */
function initQuickTermCreate() {
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  document.querySelectorAll('[data-prime-quick-term]').forEach((box) => {
    const input = box.querySelector('[data-prime-quick-term-input]');
    const button = box.querySelector('[data-prime-quick-term-submit]');
    const list = box.closest('[data-prime-taxonomy-panel]')?.querySelector('[data-prime-term-list]');
    const endpoint = box.getAttribute('data-prime-endpoint') || '';
    const taxName = box.getAttribute('data-prime-taxonomy') || '';

    if (!(input instanceof HTMLInputElement) || !(button instanceof HTMLButtonElement) || !list || !endpoint) {
      return;
    }

    const submit = async () => {
      const name = input.value.trim();
      if (!name) {
        input.focus();
        return;
      }
      button.disabled = true;

      try {
        const res = await fetch(endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-Token': csrfToken,
          },
          body: JSON.stringify({ name }),
        });
        const data = await res.json();
        if (!res.ok || !data.term) {
          window.Prime?.toast?.(data.error || 'Création impossible.', 'danger');
          return;
        }

        const label = document.createElement('label');
        label.className = 'pc-term-check';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.name = `terms[${taxName}][]`;
        cb.value = String(data.term.id);
        cb.checked = true;
        const span = document.createElement('span');
        span.textContent = data.term.name;
        label.append(cb, span);
        list.prepend(label);

        input.value = '';
        window.Prime?.toast?.(`« ${data.term.name} » ajouté.`, 'success');
      } catch {
        window.Prime?.toast?.('Erreur réseau lors de la création.', 'danger');
      } finally {
        button.disabled = false;
      }
    };

    button.addEventListener('click', submit);
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        submit();
      }
    });
  });
}

/**
 * Custom `entry` relation field live search (debounced GET `/admin/content/search-entries`).
 */
function initEntryRelationFields() {
  document.querySelectorAll('[data-prime-entry-relation]').forEach((wrap) => {
    const searchInput = wrap.querySelector('[data-prime-entry-search]');
    const hiddenInput = wrap.querySelector('[data-prime-entry-value]');
    const resultsBox = wrap.querySelector('[data-prime-entry-results]');
    const selectedBadge = wrap.querySelector('[data-prime-entry-selected]');
    const clearBtn = wrap.querySelector('[data-prime-entry-clear]');
    const endpoint = wrap.getAttribute('data-prime-endpoint') || '/admin/content/search-entries';
    const types = wrap.getAttribute('data-prime-types') || '';

    if (!(searchInput instanceof HTMLInputElement) || !(hiddenInput instanceof HTMLInputElement) || !resultsBox) {
      return;
    }

    let timer = null;
    searchInput.addEventListener('input', () => {
      window.clearTimeout(timer);
      const q = searchInput.value.trim();
      if (q.length < 2) {
        resultsBox.hidden = true;
        resultsBox.innerHTML = '';
        return;
      }

      timer = window.setTimeout(async () => {
        try {
          const url = `${endpoint}?q=${encodeURIComponent(q)}${types ? `&types=${encodeURIComponent(types)}` : ''}`;
          const res = await fetch(url, { headers: { Accept: 'application/json' } });
          if (!res.ok) return;
          const data = await res.json();
          resultsBox.innerHTML = '';
          const items = Array.isArray(data.items) ? data.items : [];
          if (items.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'pc-entry-rel-empty';
            empty.textContent = 'Aucun contenu trouvé.';
            resultsBox.appendChild(empty);
          } else {
            items.forEach((item) => {
              const btn = document.createElement('button');
              btn.type = 'button';
              btn.className = 'pc-entry-rel-option';
              btn.textContent = `${item.title} (${item.type_label})`;
              btn.addEventListener('click', () => {
                hiddenInput.value = String(item.id);
                searchInput.value = item.title;
                if (selectedBadge) {
                  selectedBadge.textContent = `#${item.id} — ${item.title}`;
                  selectedBadge.hidden = false;
                }
                if (clearBtn instanceof HTMLElement) clearBtn.hidden = false;
                resultsBox.hidden = true;
              });
              resultsBox.appendChild(btn);
            });
          }
          resultsBox.hidden = false;
        } catch {
          resultsBox.hidden = true;
        }
      }, 220);
    });

    if (clearBtn instanceof HTMLElement) {
      clearBtn.addEventListener('click', () => {
        hiddenInput.value = '';
        searchInput.value = '';
        if (selectedBadge) selectedBadge.hidden = true;
        clearBtn.hidden = true;
      });
    }
  });
}

/**
 * Show/hide scheduled datetime field cleanly depending on selected status.
 */
function initStatusDateSync() {
  const statusSelect = document.querySelector('[data-prime-status-select]');
  const dateWrap = document.querySelector('[data-prime-publish-date-wrap]');
  if (!(statusSelect instanceof HTMLSelectElement) || !(dateWrap instanceof HTMLElement)) {
    return;
  }

  const sync = () => {
    const show = ['published', 'scheduled', 'private'].includes(statusSelect.value);
    dateWrap.hidden = !show;
  };

  statusSelect.addEventListener('change', sync);
  sync();
}

/**
 * Confirmation modal wiring for named permanent delete actions (entries & terms).
 */
function initConfirmDeleteButtons() {
  const modal = document.getElementById('pc-confirm-delete-modal');
  if (!modal) {
    return;
  }

  const titleEl = modal.querySelector('[data-prime-confirm-heading]');
  const bodyEl = modal.querySelector('[data-prime-confirm-body]');
  const formEl = modal.querySelector('[data-prime-confirm-form]');

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const trigger = target.closest('[data-prime-confirm-delete]');
    if (!(trigger instanceof HTMLElement)) return;

    event.preventDefault();
    const action = trigger.getAttribute('data-prime-action') || '';
    const itemName = trigger.getAttribute('data-prime-item-name') || 'cet élément';
    const warning = trigger.getAttribute('data-prime-warning') || 'Cette action est définitive et irréversible.';

    if (titleEl) titleEl.textContent = `Supprimer « ${itemName} » ?`;
    if (bodyEl) bodyEl.textContent = warning;
    if (formEl instanceof HTMLFormElement) formEl.action = action;

    window.Prime?.openModal?.('pc-confirm-delete-modal');
  });
}

/**
 * Toggle between secondary action buttons ("Enregistrer le brouillon", "Soumettre en relecture", "Publier").
 */
function initQuickStatusButtons() {
  document.querySelectorAll('[data-prime-submit-status]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const targetStatus = btn.getAttribute('data-prime-submit-status');
      const statusSelect = document.querySelector('[data-prime-status-select]');
      if (targetStatus && statusSelect instanceof HTMLSelectElement) {
        statusSelect.value = targetStatus;
      }
    });
  });
}

export function initContentAdmin() {
  initSlugSync();
  initEditorSidebarToggle();
  initQuickTermCreate();
  initEntryRelationFields();
  initStatusDateSync();
  initConfirmDeleteButtons();
  initQuickStatusButtons();
}
