/**
 * Prime Media — Reusable Media Picker modal (`Prime.media.open(...)`) and
 * declarative `[data-prime-media-field]` widget for featured images, custom `media` fields,
 * and user avatars.
 */

import { SelectionState } from './selection.js';
import { UploadQueue } from './upload-queue.js';

const KIND_ICONS = {
  image: 'image',
  document: 'file-text',
  audio: 'music',
  video: 'video',
  archive: 'archive',
  other: 'file',
};

function iconSvg(name, size = 18) {
  return `<svg class="pc-icon" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="/assets/admin/icons/sprite.svg#${name}"></use></svg>`;
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

let activePickerSession = null;

/**
 * Ensure the shared reusable Media Picker modal exists on `document.body`.
 */
function ensurePickerModal() {
  let root = document.getElementById('pc-media-picker-modal');
  if (root) {
    return root;
  }

  root = document.createElement('div');
  root.id = 'pc-media-picker-modal';
  root.className = 'pc-modal-backdrop pc-media-picker-backdrop';
  root.hidden = true;
  root.innerHTML = `
    <div class="pc-modal pc-modal--xl pc-media-picker" role="dialog" aria-modal="true" aria-labelledby="pc-media-picker-title">
      <div class="pc-modal__header">
        <div>
          <h2 class="pc-modal__title" id="pc-media-picker-title" data-prime-picker-title>Sélectionner un média</h2>
          <p class="pc-text-xs pc-text-muted" data-prime-picker-subtitle>Choisissez un fichier existant ou téléversez-en un nouveau.</p>
        </div>
        <button type="button" class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm" data-prime-picker-close aria-label="Fermer la fenêtre">
          ${iconSvg('x', 18)}
        </button>
      </div>

      <div class="pc-media-picker__tabs" role="tablist" aria-label="Source du média">
        <button type="button" class="pc-media-picker__tab is-active" role="tab" aria-selected="true" data-prime-picker-tab="library">
          ${iconSvg('grid', 15)}
          <span>Médiathèque</span>
        </button>
        <button type="button" class="pc-media-picker__tab" role="tab" aria-selected="false" data-prime-picker-tab="upload">
          ${iconSvg('upload', 15)}
          <span>Téléverser</span>
        </button>
      </div>

      <div class="pc-media-picker__panel" data-prime-picker-panel="library">
        <div class="pc-media-picker__toolbar">
          <div class="pc-media-picker__search">
            <label class="pc-sr-only" for="pc-picker-search-input">Rechercher un média</label>
            <input id="pc-picker-search-input" type="search" class="pc-input pc-input--sm" placeholder="Rechercher par nom, titre ou texte alternatif…" data-prime-picker-search>
          </div>
          <select class="pc-select pc-select--sm" aria-label="Dossier" data-prime-picker-folder>
            <option value="">Tous les dossiers</option>
            <option value="unclassified">Non classés</option>
          </select>
          <select class="pc-select pc-select--sm" aria-label="Type de fichier" data-prime-picker-kind>
            <option value="">Tous les types</option>
            <option value="image">Images</option>
            <option value="document">Documents</option>
            <option value="audio">Audio</option>
            <option value="video">Vidéo</option>
            <option value="archive">Archives</option>
          </select>
        </div>

        <div class="pc-media-picker__body" data-prime-picker-scroll>
          <div class="pc-media-grid pc-media-grid--picker" role="listbox" data-prime-picker-grid></div>
          <div class="pc-media-picker__empty" data-prime-picker-empty hidden>
            <p class="pc-text-sm pc-text-muted" data-prime-picker-empty-text>Aucun média trouvé.</p>
          </div>
          <div class="pc-media-picker__more" data-prime-picker-more-wrap hidden>
            <button type="button" class="pc-btn pc-btn--secondary pc-btn--sm" data-prime-picker-more>Charger plus</button>
          </div>
        </div>
      </div>

      <div class="pc-media-picker__panel" data-prime-picker-panel="upload" hidden>
        <div class="pc-media-picker__upload-zone" data-prime-picker-dropzone>
          <div class="pc-media-picker__upload-icon">${iconSvg('upload', 28)}</div>
          <p class="pc-media-picker__upload-title">Déposez vos fichiers ici</p>
          <p class="pc-text-xs pc-text-muted">Les fichiers téléversés seront automatiquement sélectionnés.</p>
          <label class="pc-btn pc-btn--secondary pc-btn--sm" style="margin-top:12px;">
            <span>Parcourir l’ordinateur</span>
            <input type="file" multiple hidden data-prime-picker-file-input>
          </label>
        </div>
        <div class="pc-media-picker__upload-list" data-prime-picker-upload-list></div>
      </div>

      <div class="pc-modal__footer pc-media-picker__footer">
        <div class="pc-media-picker__selection-summary" data-prime-picker-summary>Aucun média sélectionné</div>
        <div class="pc-media-picker__actions">
          <button type="button" class="pc-btn pc-btn--ghost pc-btn--sm" data-prime-picker-close>Annuler</button>
          <button type="button" class="pc-btn pc-btn--primary pc-btn--sm" data-prime-picker-confirm disabled>
            ${iconSvg('check', 16)}
            <span data-prime-picker-confirm-label>Insérer la sélection</span>
          </button>
        </div>
      </div>
    </div>
  `;

  document.body.appendChild(root);

  // Close on backdrop click
  root.addEventListener('click', (event) => {
    if (event.target === root) {
      closePickerModal();
    }
  });

  // Close buttons
  root.querySelectorAll('[data-prime-picker-close]').forEach((btn) => {
    btn.addEventListener('click', () => closePickerModal());
  });

  // Escape key closes picker
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && activePickerSession && !root.hidden) {
      event.preventDefault();
      closePickerModal();
    }
  });

  return root;
}

export function closePickerModal() {
  const root = document.getElementById('pc-media-picker-modal');
  if (!root) {
    return;
  }
  root.hidden = true;
  document.body.classList.remove('pc-modal-open');

  if (activePickerSession) {
    const { previousFocus, cleanup } = activePickerSession;
    activePickerSession = null;
    if (typeof cleanup === 'function') {
      cleanup();
    }
    if (previousFocus instanceof HTMLElement) {
      previousFocus.focus();
    }
  }
}

/**
 * Open the reusable Prime Media Picker modal.
 *
 * @param {{
 *   multiple?: boolean,
 *   kinds?: string[],
 *   selected?: number[],
 *   title?: string,
 *   confirmLabel?: string,
 *   onSelect?: (selection: object | object[]) => void
 * }} [options]
 */
export function openMediaPicker(options = {}) {
  const root = ensurePickerModal();
  if (activePickerSession) {
    closePickerModal();
  }

  const multiple = Boolean(options.multiple);
  const kinds = Array.isArray(options.kinds) ? options.kinds.map((k) => String(k).trim()).filter(Boolean) : [];
  const initialSelected = Array.isArray(options.selected)
    ? options.selected.map((v) => Number(v)).filter((n) => Number.isInteger(n) && n > 0)
    : [];
  const onSelect = typeof options.onSelect === 'function' ? options.onSelect : () => {};

  const titleEl = root.querySelector('[data-prime-picker-title]');
  const confirmBtn = root.querySelector('[data-prime-picker-confirm]');
  const confirmLabelEl = root.querySelector('[data-prime-picker-confirm-label]');
  const summaryEl = root.querySelector('[data-prime-picker-summary]');
  const gridEl = root.querySelector('[data-prime-picker-grid]');
  const emptyEl = root.querySelector('[data-prime-picker-empty]');
  const emptyTextEl = root.querySelector('[data-prime-picker-empty-text]');
  const moreWrap = root.querySelector('[data-prime-picker-more-wrap]');
  const moreBtn = root.querySelector('[data-prime-picker-more]');
  const searchInput = root.querySelector('[data-prime-picker-search]');
  const folderSelect = root.querySelector('[data-prime-picker-folder]');
  const kindSelect = root.querySelector('[data-prime-picker-kind]');
  const dropzone = root.querySelector('[data-prime-picker-dropzone]');
  const fileInput = root.querySelector('[data-prime-picker-file-input]');
  const uploadListEl = root.querySelector('[data-prime-picker-upload-list]');
  const tabBtns = Array.from(root.querySelectorAll('[data-prime-picker-tab]'));
  const panels = Array.from(root.querySelectorAll('[data-prime-picker-panel]'));

  if (titleEl) {
    titleEl.textContent = options.title || (multiple ? 'Sélectionner des médias' : 'Sélectionner un média');
  }
  if (confirmLabelEl) {
    confirmLabelEl.textContent = options.confirmLabel || (multiple ? 'Insérer la sélection' : 'Choisir ce média');
  }

  if (kindSelect instanceof HTMLSelectElement) {
    kindSelect.value = kinds.length === 1 ? kinds[0] : '';
    kindSelect.hidden = kinds.length === 1;
  }
  if (searchInput instanceof HTMLInputElement) {
    searchInput.value = '';
  }
  if (folderSelect instanceof HTMLSelectElement) {
    folderSelect.value = '';
  }
  if (gridEl) {
    gridEl.setAttribute('aria-multiselectable', multiple ? 'true' : 'false');
  }

  const selection = new SelectionState(initialSelected);
  /** @type {Map<number, object>} */
  const itemsById = new Map();
  /** @type {Array<object>} */
  let visibleItems = [];
  let page = 1;
  let hasMore = false;
  let searchTimer = null;
  let aborted = false;

  const setActiveTab = (name) => {
    tabBtns.forEach((btn) => {
      const isMatch = btn.getAttribute('data-prime-picker-tab') === name;
      btn.classList.toggle('is-active', isMatch);
      btn.setAttribute('aria-selected', isMatch ? 'true' : 'false');
    });
    panels.forEach((panel) => {
      panel.hidden = panel.getAttribute('data-prime-picker-panel') !== name;
    });
  };
  setActiveTab('library');

  const updateSelectionUi = () => {
    const ids = selection.ids();
    const count = ids.length;
    if (confirmBtn instanceof HTMLButtonElement) {
      confirmBtn.disabled = count === 0;
    }
    if (summaryEl) {
      if (count === 0) {
        summaryEl.textContent = 'Aucun média sélectionné';
      } else if (count === 1) {
        const one = itemsById.get(ids[0]);
        summaryEl.textContent = one ? `1 média sélectionné : ${one.display_title}` : '1 média sélectionné';
      } else {
        summaryEl.textContent = `${count} médias sélectionnés`;
      }
    }

    if (gridEl) {
      gridEl.querySelectorAll('[data-prime-picker-card]').forEach((card) => {
        const id = Number(card.getAttribute('data-prime-picker-card'));
        const isSel = selection.has(id);
        card.classList.toggle('is-selected', isSel);
        card.setAttribute('aria-selected', isSel ? 'true' : 'false');
      });
    }
  };

  const renderCard = (item) => {
    const card = document.createElement('button');
    card.type = 'button';
    card.className = 'pc-media-card pc-media-card--picker';
    card.setAttribute('role', 'option');
    card.setAttribute('data-prime-picker-card', String(item.id));
    const isSel = selection.has(item.id);
    card.classList.toggle('is-selected', isSel);
    card.setAttribute('aria-selected', isSel ? 'true' : 'false');

    const thumbHtml = item.kind === 'image'
      ? `<img src="${escapeHtml(item.thumbnail_url || item.url)}" alt="${escapeHtml(item.alt || item.display_title)}" style="object-position:${escapeHtml(item.focal_css || '50% 50%')}" loading="lazy">`
      : `<span class="pc-media-card__kind-icon">${iconSvg(KIND_ICONS[item.kind] || 'file', 30)}<span class="pc-media-card__ext">.${escapeHtml(item.extension || '')}</span></span>`;

    card.innerHTML = `
      <span class="pc-media-card__check" aria-hidden="true">${iconSvg('check', 14)}</span>
      <span class="pc-media-card__thumb">${thumbHtml}</span>
      <span class="pc-media-card__meta">
        <span class="pc-media-card__name" title="${escapeHtml(item.display_title)}">${escapeHtml(item.display_title)}</span>
        <span class="pc-media-card__sub">${escapeHtml(item.human_size || '')}</span>
      </span>
    `;

    card.addEventListener('click', (event) => {
      const orderedIds = visibleItems.map((m) => Number(m.id));
      selection.toggle(item.id, { multiple, shiftKey: event.shiftKey, orderedIds });
      updateSelectionUi();
    });

    card.addEventListener('dblclick', () => {
      if (!multiple) {
        selection.add(item.id, { multiple: false });
        updateSelectionUi();
        confirmSelection();
      }
    });

    return card;
  };

  const renderGrid = (append = false) => {
    if (!gridEl) return;
    if (!append) {
      gridEl.innerHTML = '';
    }
    const itemsToRender = append ? visibleItems.slice(gridEl.children.length) : visibleItems;
    itemsToRender.forEach((item) => {
      gridEl.appendChild(renderCard(item));
    });

    if (emptyEl) {
      emptyEl.hidden = visibleItems.length > 0;
      if (emptyTextEl) {
        emptyTextEl.textContent = searchInput?.value.trim()
          ? 'Aucun média ne correspond à cette recherche.'
          : 'Aucun média disponible. Utilisez l’onglet « Téléverser » pour en ajouter.';
      }
    }
    if (moreWrap) {
      moreWrap.hidden = !hasMore;
    }
    updateSelectionUi();
  };

  const loadItems = async (nextPage = 1, append = false) => {
    if (aborted) return;
    page = nextPage;
    const params = new URLSearchParams();
    params.set('page', String(page));
    params.set('per_page', '24');

    const q = searchInput instanceof HTMLInputElement ? searchInput.value.trim() : '';
    if (q) params.set('q', q);

    const folderVal = folderSelect instanceof HTMLSelectElement ? folderSelect.value : '';
    if (folderVal) params.set('folder', folderVal);

    if (kinds.length > 0) {
      const selectedKind = kindSelect instanceof HTMLSelectElement && kindSelect.value ? kindSelect.value : '';
      if (selectedKind && kinds.includes(selectedKind)) {
        params.set('kind', selectedKind);
      } else {
        params.set('kinds', kinds.join(','));
      }
    } else if (kindSelect instanceof HTMLSelectElement && kindSelect.value) {
      params.set('kind', kindSelect.value);
    }

    if (!append && gridEl) {
      gridEl.innerHTML = Array.from({ length: 8 })
        .map(() => '<div class="pc-media-card pc-media-card--skeleton"><div class="pc-skeleton" style="aspect-ratio:1/1;"></div></div>')
        .join('');
    }

    try {
      const res = await fetch(`/admin/media/api/items?${params.toString()}`, {
        headers: { Accept: 'application/json' },
      });
      if (!res.ok || aborted) return;
      const data = await res.json();
      const fetched = Array.isArray(data.items) ? data.items : [];
      fetched.forEach((m) => itemsById.set(Number(m.id), m));
      visibleItems = append ? [...visibleItems, ...fetched] : fetched;
      hasMore = Boolean(data.has_more);
      renderGrid(append);
    } catch {
      if (!aborted && gridEl) {
        gridEl.innerHTML = '';
        if (emptyEl) emptyEl.hidden = false;
      }
    }
  };

  const loadFolders = async () => {
    if (!(folderSelect instanceof HTMLSelectElement)) return;
    try {
      const res = await fetch('/admin/media/api/folders', { headers: { Accept: 'application/json' } });
      if (!res.ok || aborted) return;
      const data = await res.json();
      const flat = Array.isArray(data.flat_folders) ? data.flat_folders : [];
      folderSelect.innerHTML = `
        <option value="">Tous les dossiers (${Number(data.counts?.all ?? 0)})</option>
        <option value="unclassified">Non classés (${Number(data.counts?.unclassified ?? 0)})</option>
      `;
      flat.forEach((f) => {
        const opt = document.createElement('option');
        opt.value = String(f.id);
        opt.textContent = `${'— '.repeat(Number(f.depth || 0))}${f.name} (${Number(f.media_count || 0)})`;
        folderSelect.appendChild(opt);
      });
    } catch {
      // Ignore folder load error cleanly
    }
  };

  const renderUploadQueue = (entries) => {
    if (!uploadListEl) return;
    uploadListEl.innerHTML = '';
    entries.forEach((entry) => {
      const row = document.createElement('div');
      row.className = `pc-upload-row pc-upload-row--${entry.status}`;
      const statusText =
        entry.status === 'done'
          ? entry.warning || 'Téléversé et sélectionné'
          : entry.status === 'error'
            ? entry.error || 'Échec'
            : entry.status === 'cancelled'
              ? 'Annulé'
              : `${entry.progress}%`;

      row.innerHTML = `
        <div class="pc-upload-row__head">
          <span class="pc-upload-row__name">${escapeHtml(entry.name)}</span>
          <span class="pc-upload-row__status">${escapeHtml(statusText)}</span>
        </div>
        <div class="pc-progress pc-progress--sm"><div class="pc-progress__bar" style="width:${entry.progress}%"></div></div>
      `;
      uploadListEl.appendChild(row);
    });
  };

  const queue = new UploadQueue({
    concurrency: 3,
    uploadUrl: '/admin/media/api/upload',
    getCsrfToken: csrfToken,
    onChange: renderUploadQueue,
    onItemComplete: (_entry, res) => {
      if (aborted || !res.item) return;
      const uploaded = res.item;
      if (kinds.length > 0 && !kinds.includes(uploaded.kind)) {
        window.Prime?.toast?.({
          type: 'warning',
          title: `« ${uploaded.original_name} » a été ajouté mais son type (${uploaded.kind_label}) n’est pas compatible avec ce champ.`,
        });
        return;
      }
      itemsById.set(Number(uploaded.id), uploaded);
      visibleItems = [uploaded, ...visibleItems.filter((m) => Number(m.id) !== Number(uploaded.id))];
      selection.add(uploaded.id, { multiple });
      renderGrid(false);
      updateSelectionUi();
    },
  });

  const confirmSelection = () => {
    const ids = selection.ids();
    if (ids.length === 0) return;
    const resolved = ids.map((id) => itemsById.get(id)).filter(Boolean);
    if (resolved.length === 0) return;
    closePickerModal();
    onSelect(multiple ? resolved : resolved[0]);
  };

  // Wire listeners with clean teardown
  const listeners = [];
  const on = (el, type, handler) => {
    if (!el) return;
    el.addEventListener(type, handler);
    listeners.push(() => el.removeEventListener(type, handler));
  };

  tabBtns.forEach((btn) => {
    on(btn, 'click', () => setActiveTab(btn.getAttribute('data-prime-picker-tab') || 'library'));
  });
  on(searchInput, 'input', () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadItems(1, false), 220);
  });
  on(folderSelect, 'change', () => loadItems(1, false));
  on(kindSelect, 'change', () => loadItems(1, false));
  on(moreBtn, 'click', () => loadItems(page + 1, true));
  on(confirmBtn, 'click', confirmSelection);

  on(fileInput, 'change', () => {
    if (fileInput instanceof HTMLInputElement && fileInput.files && fileInput.files.length > 0) {
      const targetFolder = folderSelect instanceof HTMLSelectElement && /^\d+$/.test(folderSelect.value)
        ? Number(folderSelect.value)
        : null;
      queue.enqueue(fileInput.files, { folderId: targetFolder });
      fileInput.value = '';
    }
  });

  if (dropzone instanceof HTMLElement) {
    on(dropzone, 'dragover', (e) => {
      e.preventDefault();
      dropzone.classList.add('is-drag-over');
    });
    on(dropzone, 'dragleave', () => dropzone.classList.remove('is-drag-over'));
    on(dropzone, 'drop', (e) => {
      e.preventDefault();
      dropzone.classList.remove('is-drag-over');
      if (e.dataTransfer?.files?.length) {
        const targetFolder = folderSelect instanceof HTMLSelectElement && /^\d+$/.test(folderSelect.value)
          ? Number(folderSelect.value)
          : null;
        queue.enqueue(e.dataTransfer.files, { folderId: targetFolder });
      }
    });
  }

  if (uploadListEl) {
    uploadListEl.innerHTML = '';
  }

  activePickerSession = {
    previousFocus: document.activeElement,
    cleanup: () => {
      aborted = true;
      window.clearTimeout(searchTimer);
      listeners.forEach((off) => off());
    },
  };

  root.hidden = false;
  document.body.classList.add('pc-modal-open');
  if (searchInput instanceof HTMLElement) {
    searchInput.focus();
  }

  loadFolders();
  loadItems(1, false);
}

/**
 * Progressive enhancement for declarative `[data-prime-media-field]` widgets
 * (featured image, custom `media` fields, user avatar).
 */
export function initMediaFields(scope = document) {
  scope.querySelectorAll('[data-prime-media-field]').forEach((container) => {
    if (!(container instanceof HTMLElement) || container.dataset.primeMediaFieldInit === '1') {
      return;
    }
    container.dataset.primeMediaFieldInit = '1';

    const input = container.querySelector('[data-prime-media-input]');
    const previewWrap = container.querySelector('[data-prime-media-preview]');
    const chooseBtn = container.querySelector('[data-prime-media-choose]');
    const clearBtn = container.querySelector('[data-prime-media-clear]');

    if (!(input instanceof HTMLInputElement) || !(previewWrap instanceof HTMLElement)) {
      return;
    }

    const multiple = container.dataset.primeMediaMultiple === '1' || container.dataset.primeMediaMultiple === 'true';
    const kinds = (container.dataset.primeMediaKinds || '')
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean);
    const title = container.dataset.primeMediaTitle || (multiple ? 'Sélectionner des médias' : 'Choisir un média');

    /** @type {Array<object>} */
    let selectedItems = [];
    const initialScript = container.querySelector('script[data-prime-media-initial]');
    const initialJson =
      initialScript instanceof HTMLScriptElement
        ? initialScript.textContent
        : container.getAttribute('data-prime-media-initial');
    if (initialJson) {
      try {
        const parsed = JSON.parse(initialJson);
        if (Array.isArray(parsed)) {
          selectedItems = parsed.filter((m) => m && m.id);
        } else if (parsed && parsed.id) {
          selectedItems = [parsed];
        }
      } catch {
        selectedItems = [];
      }
    }

    const syncInput = () => {
      if (multiple) {
        input.value = selectedItems.length > 0 ? JSON.stringify(selectedItems.map((m) => Number(m.id))) : '';
      } else {
        input.value = selectedItems[0] ? String(selectedItems[0].id) : '';
      }
      input.dispatchEvent(new Event('change', { bubbles: true }));
      input.dispatchEvent(new Event('input', { bubbles: true }));
    };

    const renderPreview = () => {
      previewWrap.innerHTML = '';
      previewWrap.hidden = selectedItems.length === 0;
      if (clearBtn instanceof HTMLElement) {
        clearBtn.hidden = selectedItems.length === 0;
      }
      if (chooseBtn instanceof HTMLElement) {
        const label = chooseBtn.querySelector('[data-prime-media-choose-label]');
        if (label) {
          label.textContent =
            selectedItems.length > 0
              ? multiple
                ? 'Modifier la sélection'
                : 'Remplacer'
              : 'Choisir dans la médiathèque';
        }
      }

      if (selectedItems.length === 0) {
        previewWrap.hidden = true;
        return;
      }

      previewWrap.hidden = false;
      selectedItems.forEach((item, index) => {
        const tile = document.createElement('div');
        tile.className = 'pc-media-field__tile';

        const visual =
          item.kind === 'image'
            ? `<img class="pc-media-field__thumb" src="${escapeHtml(item.thumbnail_url || item.url)}" alt="${escapeHtml(item.alt || item.display_title || '')}" style="object-position:${escapeHtml(item.focal_css || '50% 50%')}">`
            : `<span class="pc-media-field__icon">${iconSvg(KIND_ICONS[item.kind] || 'file', 20)}</span>`;

        tile.innerHTML = `
          ${visual}
          <span class="pc-media-field__info">
            <span class="pc-media-field__name">${escapeHtml(item.display_title || item.original_name || `#${item.id}`)}</span>
            <span class="pc-media-field__meta">${escapeHtml(item.human_size || '')}</span>
          </span>
          <button type="button" class="pc-btn pc-btn--ghost pc-btn--icon pc-btn--sm" data-prime-tile-remove aria-label="Retirer ce média">
            ${iconSvg('x', 14)}
          </button>
        `;

        tile.querySelector('[data-prime-tile-remove]')?.addEventListener('click', () => {
          selectedItems.splice(index, 1);
          syncInput();
          renderPreview();
        });

        previewWrap.appendChild(tile);
      });
    };

    chooseBtn?.addEventListener('click', () => {
      openMediaPicker({
        multiple,
        kinds,
        selected: selectedItems.map((m) => Number(m.id)),
        title,
        onSelect: (result) => {
          selectedItems = Array.isArray(result) ? result : result ? [result] : [];
          syncInput();
          renderPreview();
        },
      });
    });

    clearBtn?.addEventListener('click', () => {
      selectedItems = [];
      syncInput();
      renderPreview();
    });

    renderPreview();
  });
}

export const mediaApi = Object.freeze({
  open: openMediaPicker,
  close: closePickerModal,
  initFields: initMediaFields,
});
