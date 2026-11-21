/**
 * Prime Media — Full Admin Library controller (`/admin/media`).
 * Supports full-page drag-and-drop, 3-way parallel upload queue, hierarchical folder tree
 * (drag-to-move, create/rename/move/delete), Grid/List views, progressive pagination + infinite scroll,
 * Shift+click multi-select + bulk move/delete, detail drawer with interactive & keyboard focal-point
 * picker, usages list, regenerate, replace, download, and full grid keyboard navigation.
 */

import { SelectionState } from './selection.js';
import { UploadQueue } from './upload-queue.js';
import { tr, trChoice } from '../i18n.js';

const VIEW_STORAGE_KEY = 'prime.media.view';

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

function formatMonthLabel(ym) {
  if (!/^\d{4}-\d{2}$/.test(ym)) return ym;
  const [y, m] = ym.split('-');
  const names = [
    'Janvier', tr('admin.js.media.library.fevrier', 'Février'), 'Mars', 'Avril', 'Mai', 'Juin',
    'Juillet', tr('admin.js.media.library.aout', 'Août'), 'Septembre', 'Octobre', 'Novembre', tr('admin.js.media.library.decembre', 'Décembre'),
  ];
  const idx = Number(m) - 1;
  return `${names[idx] || m} ${y}`;
}

async function apiJson(url, options = {}) {
  const headers = {
    Accept: 'application/json',
    ...(options.headers || {}),
  };
  if (options.method && options.method !== 'GET') {
    headers['X-CSRF-Token'] = csrfToken();
    if (options.body && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
    }
  }
  const res = await fetch(url, { ...options, headers });
  let data = {};
  try {
    data = await res.json();
  } catch {
    data = {};
  }
  if (!res.ok) {
    throw new Error(data.error || `Erreur HTTP ${res.status}`);
  }
  return data;
}

export function initMediaLibrary() {
  const root = document.querySelector('[data-prime-media-library]');
  if (!(root instanceof HTMLElement)) {
    return;
  }

  const bootstrapEl = root.querySelector('script[data-prime-media-bootstrap]');
  if (!bootstrapEl) {
    return;
  }

  let config = {};
  try {
    config = JSON.parse(bootstrapEl.textContent || '{}');
  } catch {
    return;
  }

  const canUpload = Boolean(config.canUpload);
  const apiBase = String(config.apiBase || '/admin/media/api');

  const state = {
    items: Array.isArray(config.initialItems) ? [...config.initialItems] : [],
    total: Number(config.initialTotal || 0),
    page: 1,
    hasMore: Boolean(config.initialHasMore),
    loading: false,
    view: (() => {
      try {
        return window.localStorage.getItem(VIEW_STORAGE_KEY) === 'list' ? 'list' : 'grid';
      } catch {
        return 'grid';
      }
    })(),
    filters: {
      folder: String(config.initialFilters?.folder || ''),
      kind: String(config.initialFilters?.kind || ''),
      month: String(config.initialFilters?.month || ''),
      mine: Boolean(config.initialFilters?.mine),
      unused: Boolean(config.initialFilters?.unused),
      q: String(config.initialFilters?.q || ''),
      sort: String(config.initialFilters?.sort || 'created_at'),
      dir: String(config.initialFilters?.dir || 'desc'),
    },
    folders: Array.isArray(config.folders) ? config.folders : [],
    flatFolders: Array.isArray(config.flatFolders) ? config.flatFolders : [],
    counts: config.counts || { all: 0, unclassified: 0 },
    months: Array.isArray(config.months) ? config.months : [],
    activeMedia: null,
    activeUsages: [],
    focalDraft: { x: 0.5, y: 0.5 },
  };

  const selection = new SelectionState();

  // DOM references
  const uploadTriggerBtn = root.querySelector('[data-prime-media-upload-trigger]');
  const uploadFileInput = root.querySelector('[data-prime-media-upload-input]');
  const dropOverlay = root.querySelector('[data-prime-media-drop-overlay]');
  const folderTreeEl = root.querySelector('[data-prime-media-folder-tree]');
  const mobileFolderSelect = root.querySelector('[data-prime-media-folder-mobile]');
  const createFolderBtn = root.querySelector('[data-prime-media-folder-create]');
  const searchInput = root.querySelector('[data-prime-media-search]');
  const kindSelect = root.querySelector('[data-prime-media-filter-kind]');
  const monthSelect = root.querySelector('[data-prime-media-filter-month]');
  const mineToggle = root.querySelector('[data-prime-media-filter-mine]');
  const unusedToggle = root.querySelector('[data-prime-media-filter-unused]');
  const sortSelect = root.querySelector('[data-prime-media-sort]');
  const viewButtons = Array.from(root.querySelectorAll('[data-prime-media-view]'));
  const bulkBar = root.querySelector('[data-prime-media-bulk-bar]');
  const bulkCountEl = root.querySelector('[data-prime-media-bulk-count]');
  const bulkMoveSelect = root.querySelector('[data-prime-media-bulk-folder]');
  const bulkMoveBtn = root.querySelector('[data-prime-media-bulk-move]');
  const bulkDeleteBtn = root.querySelector('[data-prime-media-bulk-delete]');
  const bulkClearBtn = root.querySelector('[data-prime-media-bulk-clear]');
  const gridContainer = root.querySelector('[data-prime-media-grid]');
  const listContainer = root.querySelector('[data-prime-media-list]');
  const listTbody = root.querySelector('[data-prime-media-list-body]');
  const emptyWrap = root.querySelector('[data-prime-media-empty]');
  const emptyTitle = root.querySelector('[data-prime-media-empty-title]');
  const emptyDesc = root.querySelector('[data-prime-media-empty-desc]');
  const emptyResetBtn = root.querySelector('[data-prime-media-empty-reset]');
  const emptyUploadBtn = root.querySelector('[data-prime-media-empty-upload]');
  const loadMoreWrap = root.querySelector('[data-prime-media-more-wrap]');
  const loadMoreBtn = root.querySelector('[data-prime-media-more-btn]');
  const sentinelEl = root.querySelector('[data-prime-media-sentinel]');
  const totalBadge = root.querySelector('[data-prime-media-total-label]');
  const queuePanel = root.querySelector('[data-prime-media-queue-panel]');
  const queueTitle = root.querySelector('[data-prime-media-queue-title]');
  const queueList = root.querySelector('[data-prime-media-queue-list]');
  const queueClearBtn = root.querySelector('[data-prime-media-queue-clear]');
  const liveRegion = root.querySelector('[data-prime-media-live]');

  // Detail Drawer elements
  const drawerBackdrop = document.getElementById('pc-media-detail-drawer');
  const drawerTitle = drawerBackdrop?.querySelector('[data-prime-drawer-media-title]');
  const drawerPreview = drawerBackdrop?.querySelector('[data-prime-drawer-preview]');
  const drawerFocalHint = drawerBackdrop?.querySelector('[data-prime-drawer-focal-hint]');
  const drawerMetaSpecs = drawerBackdrop?.querySelector('[data-prime-drawer-specs]');
  const drawerUrlInput = drawerBackdrop?.querySelector('[data-prime-drawer-url]');
  const drawerCopyUrlBtn = drawerBackdrop?.querySelector('[data-prime-drawer-copy-url]');
  const drawerForm = drawerBackdrop?.querySelector('[data-prime-drawer-form]');
  const drawerFolderSelect = drawerBackdrop?.querySelector('[data-prime-drawer-folder]');
  const drawerAltInput = drawerBackdrop?.querySelector('[data-prime-drawer-alt]');
  const drawerTitleInput = drawerBackdrop?.querySelector('[data-prime-drawer-title]');
  const drawerCaptionInput = drawerBackdrop?.querySelector('[data-prime-drawer-caption]');
  const drawerDescInput = drawerBackdrop?.querySelector('[data-prime-drawer-description]');
  const drawerSaveBtn = drawerBackdrop?.querySelector('[data-prime-drawer-save]');
  const drawerUsagesList = drawerBackdrop?.querySelector('[data-prime-drawer-usages]');
  const drawerRegenBtn = drawerBackdrop?.querySelector('[data-prime-drawer-regenerate]');
  const drawerReplaceBtn = drawerBackdrop?.querySelector('[data-prime-drawer-replace-btn]');
  const drawerReplaceInput = drawerBackdrop?.querySelector('[data-prime-drawer-replace-input]');
  const drawerDownloadLink = drawerBackdrop?.querySelector('[data-prime-drawer-download]');
  const drawerDeleteBtn = drawerBackdrop?.querySelector('[data-prime-drawer-delete]');

  // Folder & Confirm Modals
  const folderModal = document.getElementById('pc-media-folder-modal');
  const folderModalTitle = folderModal?.querySelector('[data-prime-folder-modal-title]');
  const folderModalForm = folderModal?.querySelector('[data-prime-folder-modal-form]');
  const folderNameInput = folderModal?.querySelector('[data-prime-folder-modal-name]');
  const folderParentSelect = folderModal?.querySelector('[data-prime-folder-modal-parent]');
  let editingFolderId = null;

  const confirmModal = document.getElementById('pc-media-confirm-modal');
  const confirmTitle = confirmModal?.querySelector('[data-prime-confirm-title]');
  const confirmMessage = confirmModal?.querySelector('[data-prime-confirm-message]');
  const confirmWarning = confirmModal?.querySelector('[data-prime-confirm-warning]');
  const confirmBtn = confirmModal?.querySelector('[data-prime-confirm-submit]');
  let pendingConfirmAction = null;

  function announce(msg) {
    if (liveRegion) {
      liveRegion.textContent = '';
      window.setTimeout(() => {
        liveRegion.textContent = msg;
      }, 30);
    }
  }

  function hasActiveFilters() {
    return Boolean(
      state.filters.q ||
        state.filters.kind ||
        state.filters.month ||
        state.filters.mine ||
        state.filters.unused ||
        state.filters.folder,
    );
  }

  // ---- Folder Tree & Selects ----
  function renderFolderSelectOptions(selectEl, { includeRoot = true, rootLabel = tr('admin.js.media.library.root', 'Aucun dossier (racine)'), excludeId = null } = {}) {
    if (!(selectEl instanceof HTMLSelectElement)) return;
    const prev = selectEl.value;
    selectEl.innerHTML = '';
    if (includeRoot) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = rootLabel;
      selectEl.appendChild(opt);
    }
    state.flatFolders.forEach((f) => {
      if (excludeId !== null && Number(f.id) === Number(excludeId)) return;
      const opt = document.createElement('option');
      opt.value = String(f.id);
      opt.textContent = `${'— '.repeat(Number(f.depth || 0))}${f.name}`;
      selectEl.appendChild(opt);
    });
    if (prev && Array.from(selectEl.options).some((o) => o.value === prev)) {
      selectEl.value = prev;
    }
  }

  function renderFolders() {
    if (folderTreeEl) {
      folderTreeEl.innerHTML = '';

      const addSpecialRow = (key, label, count, iconName) => {
        const li = document.createElement('li');
        li.className = 'pc-folder-item';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `pc-folder-btn${state.filters.folder === key ? ' is-active' : ''}`;
        btn.setAttribute('data-prime-folder-drop', key === 'unclassified' ? 'root' : '');
        btn.innerHTML = `
          <span class="pc-folder-btn__label">${iconSvg(iconName, 16)}<span>${escapeHtml(label)}</span></span>
          <span class="pc-folder-btn__count">${Number(count || 0)}</span>
        `;
        btn.addEventListener('click', () => {
          state.filters.folder = key;
          syncMobileFolderSelect();
          renderFolders();
          fetchItems(1, false);
        });
        if (key === 'unclassified' && canUpload) {
          bindFolderDropTarget(btn, null);
        }
        li.appendChild(btn);
        folderTreeEl.appendChild(li);
      };

      addSpecialRow('', tr('admin.js.media.library.tous_les_medias', 'Tous les médias'), state.counts.all, 'layers');
      addSpecialRow('unclassified', tr('admin.js.media.library.non_classes', 'Non classés'), state.counts.unclassified, 'inbox');

      const renderBranch = (nodes, depth) => {
        nodes.forEach((node) => {
          const li = document.createElement('li');
          li.className = 'pc-folder-item';
          li.style.setProperty('--folder-depth', String(depth));

          const row = document.createElement('div');
          row.className = `pc-folder-row${state.filters.folder === String(node.id) ? ' is-active' : ''}`;
          row.setAttribute('data-prime-folder-id', String(node.id));

          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'pc-folder-btn';
          btn.innerHTML = `
            <span class="pc-folder-btn__label">${iconSvg('folder', 16)}<span>${escapeHtml(node.name)}</span></span>
            <span class="pc-folder-btn__count">${Number(node.media_count || 0)}</span>
          `;
          btn.addEventListener('click', () => {
            state.filters.folder = String(node.id);
            syncMobileFolderSelect();
            renderFolders();
            fetchItems(1, false);
          });

          row.appendChild(btn);

          if (canUpload) {
            bindFolderDropTarget(row, Number(node.id));

            const actions = document.createElement('div');
            actions.className = 'pc-folder-row__actions';

            const editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'pc-btn pc-btn--ghost pc-btn--icon pc-btn--xs';
            editBtn.setAttribute('aria-label', tr('admin.js.media.library.folder_edit', 'Renommer ou déplacer le dossier :name', { name: node.name }));
            editBtn.innerHTML = iconSvg('pencil', 13);
            editBtn.addEventListener('click', (e) => {
              e.stopPropagation();
              openFolderModal(node);
            });

            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'pc-btn pc-btn--ghost pc-btn--icon pc-btn--xs';
            delBtn.setAttribute('aria-label', tr('admin.js.media.library.folder_delete', 'Supprimer le dossier :name', { name: node.name }));
            delBtn.innerHTML = iconSvg('trash', 13);
            delBtn.addEventListener('click', (e) => {
              e.stopPropagation();
              confirmDeleteFolder(node);
            });

            actions.append(editBtn, delBtn);
            row.appendChild(actions);
          }

          li.appendChild(row);
          folderTreeEl.appendChild(li);

          if (Array.isArray(node.children) && node.children.length > 0) {
            renderBranch(node.children, depth + 1);
          }
        });
      };

      renderBranch(state.folders, 0);
    }

    // Mobile folder dropdown
    if (mobileFolderSelect instanceof HTMLSelectElement) {
      mobileFolderSelect.innerHTML = `
        <option value="">${escapeHtml(tr('admin.js.media.library.tous_les_medias', 'Tous les médias'))} (${Number(state.counts.all || 0)})</option>
        <option value="unclassified">${escapeHtml(tr('admin.js.media.picker.unclassified', 'Non classés'))} (${Number(state.counts.unclassified || 0)})</option>
      `;
      state.flatFolders.forEach((f) => {
        const opt = document.createElement('option');
        opt.value = String(f.id);
        opt.textContent = `${'— '.repeat(Number(f.depth || 0))}${f.name} (${Number(f.media_count || 0)})`;
        mobileFolderSelect.appendChild(opt);
      });
      syncMobileFolderSelect();
    }

    renderFolderSelectOptions(bulkMoveSelect, { includeRoot: true, rootLabel: tr('admin.js.media.library.non_classes_racine', 'Non classés (racine)') });
    renderFolderSelectOptions(drawerFolderSelect, { includeRoot: true, rootLabel: tr('admin.js.media.library.aucun_dossier_non_classe', 'Aucun dossier (non classé)') });
  }

  function syncMobileFolderSelect() {
    if (mobileFolderSelect instanceof HTMLSelectElement) {
      mobileFolderSelect.value = state.filters.folder;
    }
  }

  function bindFolderDropTarget(el, targetFolderId) {
    el.addEventListener('dragover', (e) => {
      if (e.dataTransfer?.types?.includes('application/x-prime-media-ids')) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        el.classList.add('is-drag-over');
      }
    });
    el.addEventListener('dragleave', () => el.classList.remove('is-drag-over'));
    el.addEventListener('drop', async (e) => {
      const raw = e.dataTransfer?.getData('application/x-prime-media-ids');
      if (!raw) return;
      e.preventDefault();
      e.stopPropagation();
      el.classList.remove('is-drag-over');
      try {
        const ids = JSON.parse(raw);
        if (!Array.isArray(ids) || ids.length === 0) return;
        await moveMediaIds(ids, targetFolderId);
      } catch {
        // Ignore invalid drop payload
      }
    });
  }

  async function refreshFolders() {
    try {
      const data = await apiJson(`${apiBase}/folders`);
      state.folders = data.folders || [];
      state.flatFolders = data.flat_folders || [];
      state.counts = data.counts || state.counts;
      renderFolders();
    } catch {
      // Ignore transient folder refresh error
    }
  }

  function openFolderModal(folder = null) {
    if (!folderModal) return;
    editingFolderId = folder ? Number(folder.id) : null;
    if (folderModalTitle) {
      folderModalTitle.textContent = folder ? tr('admin.js.media.library.edit_folder', 'Modifier le dossier « :name »', { name: folder.name }) : tr('admin.js.media.library.new_folder', 'Nouveau dossier');
    }
    if (folderNameInput instanceof HTMLInputElement) {
      folderNameInput.value = folder ? folder.name : '';
    }
    renderFolderSelectOptions(folderParentSelect, {
      includeRoot: true,
      rootLabel: tr('admin.js.media.library.racine_aucun_parent', 'Racine (aucun parent)'),
      excludeId: editingFolderId,
    });
    if (folderParentSelect instanceof HTMLSelectElement) {
      folderParentSelect.value = folder && folder.parent_id ? String(folder.parent_id) : '';
    }
    window.Prime?.openModal?.('pc-media-folder-modal');
    window.setTimeout(() => folderNameInput?.focus(), 40);
  }

  function confirmDeleteFolder(folder) {
    openConfirmModal({
      title: `Supprimer le dossier « ${folder.name} » ?`,
      message: tr('admin.js.media.library.les_medias_et_sous_dossiers', 'Les médias et sous-dossiers contenus dans ce dossier ne seront pas supprimés : ils seront déplacés vers le dossier parent.'),
      warning: null,
      confirmLabel: tr('admin.js.media.library.delete_folder', 'Supprimer le dossier'),
      onConfirm: async () => {
        const res = await apiJson(`${apiBase}/folders/${folder.id}/delete`, { method: 'POST', body: '{}' });
        state.folders = res.folders || [];
        state.flatFolders = res.flat_folders || [];
        state.counts = res.counts || state.counts;
        if (state.filters.folder === String(folder.id)) {
          state.filters.folder = folder.parent_id ? String(folder.parent_id) : '';
        }
        renderFolders();
        await fetchItems(1, false);
        window.Prime?.toast?.(`Dossier « ${folder.name} » supprimé.`, 'success');
      },
    });
  }

  folderModalForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = folderNameInput instanceof HTMLInputElement ? folderNameInput.value.trim() : '';
    const parentRaw = folderParentSelect instanceof HTMLSelectElement ? folderParentSelect.value : '';
    const parentId = parentRaw ? Number(parentRaw) : null;
    if (!name) return;

    try {
      const url = editingFolderId ? `${apiBase}/folders/${editingFolderId}` : `${apiBase}/folders`;
      const res = await apiJson(url, {
        method: 'POST',
        body: JSON.stringify({ name, parent_id: parentId }),
      });
      state.folders = res.folders || [];
      state.flatFolders = res.flat_folders || [];
      state.counts = res.counts || state.counts;
      renderFolders();
      window.Prime?.closeModal?.('pc-media-folder-modal');
      window.Prime?.toast?.(
        editingFolderId ? `Dossier « ${name} » mis à jour.` : `Dossier « ${name} » créé.`,
        'success',
      );
    } catch (err) {
      window.Prime?.toast?.(err instanceof Error ? err.message : tr('admin.js.media.library.erreur_dossier', 'Erreur dossier.'), 'danger');
    }
  });

  // ---- Confirm Modal ----
  function openConfirmModal({ title, message, warning, confirmLabel = tr('admin.js.media.library.delete', 'Supprimer'), onConfirm }) {
    if (!confirmModal) return;
    if (confirmTitle) confirmTitle.textContent = title;
    if (confirmMessage) confirmMessage.textContent = message;
    if (confirmWarning) {
      if (warning) {
        confirmWarning.textContent = warning;
        confirmWarning.hidden = false;
      } else {
        confirmWarning.hidden = true;
      }
    }
    if (confirmBtn) confirmBtn.textContent = confirmLabel;
    pendingConfirmAction = onConfirm;
    window.Prime?.openModal?.('pc-media-confirm-modal');
  }

  confirmBtn?.addEventListener('click', async () => {
    if (!pendingConfirmAction) return;
    const action = pendingConfirmAction;
    confirmBtn.disabled = true;
    try {
      await action();
      pendingConfirmAction = null;
      window.Prime?.closeModal?.('pc-media-confirm-modal');
    } catch (err) {
      window.Prime?.toast?.(err instanceof Error ? err.message : tr('admin.js.media.library.operation_impossible', 'Opération impossible.'), 'danger');
    } finally {
      confirmBtn.disabled = false;
    }
  });

  // ---- Bulk & Selection ----
  function syncSelectionUi() {
    const count = selection.count();
    if (bulkBar instanceof HTMLElement) {
      bulkBar.hidden = count === 0;
    }
    if (bulkCountEl) {
      bulkCountEl.textContent = trChoice('admin.js.media.library.selected', count, '{0} :count média sélectionné|{1} :count média sélectionné|[2,*] :count médias sélectionnés');
    }

    root.querySelectorAll('[data-prime-media-card]').forEach((card) => {
      const id = Number(card.getAttribute('data-prime-media-card'));
      const sel = selection.has(id);
      card.classList.toggle('is-selected', sel);
      card.setAttribute('aria-selected', sel ? 'true' : 'false');
      const cb = card.querySelector('[data-prime-media-checkbox]');
      if (cb instanceof HTMLInputElement) cb.checked = sel;
    });

    root.querySelectorAll('[data-prime-media-row]').forEach((row) => {
      const id = Number(row.getAttribute('data-prime-media-row'));
      const sel = selection.has(id);
      row.classList.toggle('is-selected', sel);
      const cb = row.querySelector('[data-prime-media-checkbox]');
      if (cb instanceof HTMLInputElement) cb.checked = sel;
    });
  }

  async function moveMediaIds(ids, folderId) {
    try {
      await apiJson(`${apiBase}/bulk`, {
        method: 'POST',
        body: JSON.stringify({ action: 'move', ids, folder_id: folderId }),
      });
      selection.clear();
      syncSelectionUi();
      await Promise.all([refreshFolders(), fetchItems(1, false)]);
      window.Prime?.toast?.(
        ids.length > 1 ? `${ids.length} médias déplacés.` : tr('admin.js.media.library.media_deplace', 'Média déplacé.'),
        'success',
      );
    } catch (err) {
      window.Prime?.toast?.(err instanceof Error ? err.message : tr('admin.js.media.library.deplacement_impossible', 'Déplacement impossible.'), 'danger');
    }
  }

  bulkMoveBtn?.addEventListener('click', () => {
    const ids = selection.ids();
    if (ids.length === 0) return;
    const raw = bulkMoveSelect instanceof HTMLSelectElement ? bulkMoveSelect.value : '';
    const folderId = raw ? Number(raw) : null;
    moveMediaIds(ids, folderId);
  });

  bulkDeleteBtn?.addEventListener('click', () => {
    const ids = selection.ids();
    if (ids.length === 0) return;
    const usedItems = state.items.filter((m) => ids.includes(Number(m.id)) && Number(m.usage_count || 0) > 0);
    const warning =
      usedItems.length > 0
        ? `Attention : ${usedItems.length} média(s) sélectionné(s) sont actuellement utilisés dans un ou plusieurs contenus.`
        : null;

    openConfirmModal({
      title: ids.length > 1 ? tr('admin.js.media.library.delete_many_title', 'Supprimer :count médias ?', { count: ids.length }) : tr('admin.js.media.library.supprimer_1_media', 'Supprimer 1 média ?'),
      message: tr('admin.js.media.library.les_fichiers_originaux_et_toutes', 'Les fichiers originaux et toutes leurs variantes générées seront définitivement supprimés du disque.'),
      warning,
      confirmLabel: ids.length > 1 ? tr('admin.js.media.library.delete_many', 'Supprimer (:count)', { count: ids.length }) : tr('admin.js.media.library.delete', 'Supprimer'),
      onConfirm: async () => {
        await apiJson(`${apiBase}/bulk`, {
          method: 'POST',
          body: JSON.stringify({ action: 'delete', ids }),
        });
        selection.clear();
        syncSelectionUi();
        await Promise.all([refreshFolders(), fetchItems(1, false)]);
        window.Prime?.toast?.(
          ids.length > 1 ? `${ids.length} médias supprimés.` : tr('admin.js.media.library.media_supprime', 'Média supprimé.'),
          'success',
        );
      },
    });
  });

  bulkClearBtn?.addEventListener('click', () => {
    selection.clear();
    syncSelectionUi();
  });

  // ---- Grid & List Rendering ----
  function renderViewSwitcher() {
    viewButtons.forEach((btn) => {
      const active = btn.getAttribute('data-prime-media-view') === state.view;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    if (gridContainer instanceof HTMLElement) {
      gridContainer.hidden = state.view !== 'grid' || state.items.length === 0;
    }
    if (listContainer instanceof HTMLElement) {
      listContainer.hidden = state.view !== 'list' || state.items.length === 0;
    }
  }

  function renderMonths() {
    if (!(monthSelect instanceof HTMLSelectElement)) return;
    const prev = state.filters.month;
    monthSelect.innerHTML = tr('admin.js.media.library.all_dates_option', '<option value="">Toutes les dates</option>');
    state.months.forEach((ym) => {
      const opt = document.createElement('option');
      opt.value = ym;
      opt.textContent = formatMonthLabel(ym);
      monthSelect.appendChild(opt);
    });
    monthSelect.value = prev;
  }

  function renderItems() {
    if (totalBadge) {
      totalBadge.textContent = tr(state.total > 1 ? 'admin.js.media.library.medias_count' : 'admin.js.media.library.media_count', state.total > 1 ? ':count médias' : ':count média', { count: state.total });
    }

    if (state.items.length === 0) {
      if (gridContainer) gridContainer.hidden = true;
      if (listContainer) listContainer.hidden = true;
      if (emptyWrap instanceof HTMLElement) {
        emptyWrap.hidden = false;
        const filtered = hasActiveFilters();
        if (emptyTitle) {
          emptyTitle.textContent = filtered ? tr('admin.js.media.library.aucun_media_ne_correspond_a', 'Aucun média ne correspond à ces critères') : tr('admin.js.media.library.votre_mediatheque_est_vide', 'Votre médiathèque est vide');
        }
        if (emptyDesc) {
          emptyDesc.textContent = filtered
            ? tr('admin.js.media.library.essayez_d_elargir_votre_recherche', 'Essayez d’élargir votre recherche ou de réinitialiser les filtres actifs.')
            : tr('admin.js.media.library.glissez_deposez_des_images_documents', 'Glissez-déposez des images, documents, fichiers audio ou vidéos n’importe où sur cette page pour commencer.');
        }
        if (emptyResetBtn instanceof HTMLElement) emptyResetBtn.hidden = !filtered;
        if (emptyUploadBtn instanceof HTMLElement) emptyUploadBtn.hidden = filtered || !canUpload;
      }
      if (loadMoreWrap instanceof HTMLElement) loadMoreWrap.hidden = true;
      syncSelectionUi();
      return;
    }

    if (emptyWrap instanceof HTMLElement) emptyWrap.hidden = true;

    const orderedIds = state.items.map((m) => Number(m.id));

    if (gridContainer) {
      gridContainer.innerHTML = '';
      // ARIA grid = grid > row > gridcell. The row is `display: contents`, so the CSS grid
      // still lays the cards out freely.
      const row = document.createElement('div');
      row.className = 'pc-media-grid__row';
      row.setAttribute('role', 'row');
      gridContainer.appendChild(row);
      state.items.forEach((item, index) => {
        const card = document.createElement('article');
        card.className = 'pc-media-card';
        card.setAttribute('tabindex', index === 0 ? '0' : '-1');
        card.setAttribute('role', 'gridcell');
        card.setAttribute('data-prime-media-card', String(item.id));
        card.setAttribute('data-prime-card-index', String(index));
        card.setAttribute('aria-label', `${item.display_title} (${item.kind_label}, ${item.human_size})`);

        if (canUpload && item.can_edit) {
          card.draggable = true;
          card.addEventListener('dragstart', (e) => {
            const dragIds = selection.has(item.id) ? selection.ids() : [Number(item.id)];
            e.dataTransfer?.setData('application/x-prime-media-ids', JSON.stringify(dragIds));
            if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
          });
        }

        const thumb =
          item.kind === 'image'
            ? `<img src="${escapeHtml(item.thumbnail_url || item.url)}" alt="${escapeHtml(item.alt || item.display_title)}" style="object-position:${escapeHtml(item.focal_css || '50% 50%')}" loading="lazy">`
            : `<span class="pc-media-card__kind-icon">${iconSvg(KIND_ICONS[item.kind] || 'file', 34)}<span class="pc-media-card__ext">.${escapeHtml(item.extension || '')}</span></span>`;

        const usageBadge =
          Number(item.usage_count || 0) > 0
            ? `<span class="pc-media-card__usage" title="${escapeHtml(tr('admin.js.media.library.used_in', 'Utilisé dans :count contenu(s)', { count: item.usage_count }))}">${item.usage_count}</span>`
            : '';

        card.innerHTML = `
          <label class="pc-media-card__select" title="${escapeHtml(tr('admin.js.media.library.select_hint', 'Sélectionner (Maj+clic pour une plage)'))}">
            <input type="checkbox" data-prime-media-checkbox aria-label="${escapeHtml(tr('admin.js.media.library.select_item', 'Sélectionner :name', { name: item.display_title }))}">
          </label>
          ${usageBadge}
          <div class="pc-media-card__thumb">${thumb}</div>
          <div class="pc-media-card__meta">
            <span class="pc-media-card__name" title="${escapeHtml(item.display_title)}">${escapeHtml(item.display_title)}</span>
            <span class="pc-media-card__sub">
              <span class="pc-badge pc-badge--neutral pc-badge--xs">${escapeHtml((item.extension || item.kind_label).toUpperCase())}</span>
              <span>${escapeHtml(item.human_size)}</span>
            </span>
          </div>
        `;

        const checkboxWrap = card.querySelector('.pc-media-card__select');
        checkboxWrap?.addEventListener('click', (e) => {
          e.stopPropagation();
          selection.toggle(item.id, { multiple: true, shiftKey: e.shiftKey, orderedIds });
          syncSelectionUi();
        });

        card.addEventListener('click', (e) => {
          if (e.shiftKey) {
            selection.toggle(item.id, { multiple: true, shiftKey: true, orderedIds });
            syncSelectionUi();
            return;
          }
          openDetailDrawer(item.id);
        });

        card.addEventListener('keydown', (e) => handleGridKeydown(e, index, item, orderedIds));

        row.appendChild(card);
      });
    }

    if (listTbody) {
      listTbody.innerHTML = '';
      state.items.forEach((item) => {
        const rowEl = document.createElement('tr');
        rowEl.setAttribute('data-prime-media-row', String(item.id));

        const thumb =
          item.kind === 'image'
            ? `<img class="pc-media-list__thumb" src="${escapeHtml(item.thumbnail_url || item.url)}" alt="" style="object-position:${escapeHtml(item.focal_css || '50% 50%')}" loading="lazy">`
            : `<span class="pc-media-list__icon">${iconSvg(KIND_ICONS[item.kind] || 'file', 18)}</span>`;

        const dims = item.width && item.height ? `${item.width} × ${item.height}` : '—';
        const dateLabel = item.created_at ? item.created_at.slice(0, 10) : '—';

        rowEl.innerHTML = `
          <td class="pc-table__check">
            <input type="checkbox" class="pc-checkbox" data-prime-media-checkbox aria-label="${escapeHtml(tr('admin.js.media.library.select_item', 'Sélectionner :name', { name: item.display_title }))}">
          </td>
          <td class="pc-table__primary">
            <button type="button" class="pc-media-list__open" data-prime-row-open>
              ${thumb}
              <span class="pc-media-list__names">
                <span class="pc-media-list__title">${escapeHtml(item.display_title)}</span>
                <span class="pc-media-list__file pc-mono">${escapeHtml(item.original_name)}</span>
              </span>
            </button>
          </td>
          <td><span class="pc-badge pc-badge--neutral">${escapeHtml(item.kind_label)}</span></td>
          <td class="pc-mono pc-text-xs">${escapeHtml(dims)}</td>
          <td class="pc-text-xs">${escapeHtml(item.human_size)}</td>
          <td class="pc-text-xs pc-text-muted">${escapeHtml(dateLabel)}</td>
          <td class="pc-text-xs">${Number(item.usage_count || 0) > 0 ? escapeHtml(tr('admin.js.media.library.usage_count', ':count usage(s)', { count: item.usage_count })) : `<span class="pc-text-muted">${escapeHtml(tr('admin.js.media.library.unused', 'Non utilisé'))}</span>`}</td>
          <td class="pc-table__actions">
            <button type="button" class="pc-btn pc-btn--ghost pc-btn--sm" data-prime-row-open>${escapeHtml(tr('admin.js.media.library.details', 'Détails'))}</button>
          </td>
        `;

        const cb = rowEl.querySelector('[data-prime-media-checkbox]');
        cb?.addEventListener('click', (e) => {
          e.stopPropagation();
          selection.toggle(item.id, { multiple: true, shiftKey: e.shiftKey, orderedIds });
          syncSelectionUi();
        });

        rowEl.querySelectorAll('[data-prime-row-open]').forEach((btn) => {
          btn.addEventListener('click', () => openDetailDrawer(item.id));
        });

        listTbody.appendChild(rowEl);
      });
    }

    renderViewSwitcher();
    syncSelectionUi();

    if (loadMoreWrap instanceof HTMLElement) {
      loadMoreWrap.hidden = !state.hasMore;
    }
  }

  function handleGridKeydown(event, index, item, orderedIds) {
    if (!gridContainer) return;
    const cards = Array.from(gridContainer.querySelectorAll('[data-prime-media-card]'));
    if (cards.length === 0) return;

    if (event.key === 'Enter') {
      event.preventDefault();
      openDetailDrawer(item.id);
      return;
    }
    if (event.key === ' ') {
      event.preventDefault();
      selection.toggle(item.id, { multiple: true, shiftKey: event.shiftKey, orderedIds });
      syncSelectionUi();
      return;
    }

    const firstRect = cards[0].getBoundingClientRect();
    const cols = Math.max(1, Math.round(gridContainer.clientWidth / Math.max(120, firstRect.width + 14)));
    let nextIdx = null;
    if (event.key === 'ArrowRight') nextIdx = Math.min(cards.length - 1, index + 1);
    else if (event.key === 'ArrowLeft') nextIdx = Math.max(0, index - 1);
    else if (event.key === 'ArrowDown') nextIdx = Math.min(cards.length - 1, index + cols);
    else if (event.key === 'ArrowUp') nextIdx = Math.max(0, index - cols);
    else if (event.key === 'Home') nextIdx = 0;
    else if (event.key === 'End') nextIdx = cards.length - 1;

    if (nextIdx !== null && nextIdx !== index) {
      event.preventDefault();
      cards[index].setAttribute('tabindex', '-1');
      cards[nextIdx].setAttribute('tabindex', '0');
      cards[nextIdx].focus();
    }
  }

  function renderSkeletons() {
    if (state.view === 'grid' && gridContainer) {
      gridContainer.hidden = false;
      gridContainer.innerHTML = Array.from({ length: 12 })
        .map(
          () =>
            '<div class="pc-media-card pc-media-card--skeleton"><div class="pc-skeleton" style="aspect-ratio:1/1;"></div><div style="padding:10px;"><div class="pc-skeleton" style="height:12px;width:75%;margin-bottom:6px;"></div><div class="pc-skeleton" style="height:10px;width:45%;"></div></div></div>',
        )
        .join('');
    }
  }

  async function fetchItems(nextPage = 1, append = false) {
    if (state.loading) return;
    state.loading = true;
    state.page = nextPage;

    if (!append) {
      renderSkeletons();
    } else if (loadMoreBtn instanceof HTMLButtonElement) {
      loadMoreBtn.disabled = true;
      loadMoreBtn.textContent = tr('admin.js.media.library.loading', 'Chargement…');
    }

    const params = new URLSearchParams();
    params.set('page', String(nextPage));
    params.set('per_page', '24');
    if (state.filters.folder) params.set('folder', state.filters.folder);
    if (state.filters.kind) params.set('kind', state.filters.kind);
    if (state.filters.month) params.set('month', state.filters.month);
    if (state.filters.mine) params.set('mine', '1');
    if (state.filters.unused) params.set('unused', '1');
    if (state.filters.q) params.set('q', state.filters.q);
    params.set('sort', state.filters.sort);
    params.set('dir', state.filters.dir);

    try {
      const data = await apiJson(`${apiBase}/items?${params.toString()}`);
      const fetched = Array.isArray(data.items) ? data.items : [];
      state.items = append ? [...state.items, ...fetched] : fetched;
      state.total = Number(data.total || 0);
      state.hasMore = Boolean(data.has_more);
      if (data.counts) {
        state.counts = data.counts;
      }
      if (Array.isArray(data.months)) {
        state.months = data.months;
        renderMonths();
      }
      renderItems();
    } catch (err) {
      window.Prime?.toast?.(err instanceof Error ? err.message : tr('admin.js.media.library.erreur_de_chargement', 'Erreur de chargement.'), 'danger');
    } finally {
      state.loading = false;
      if (loadMoreBtn instanceof HTMLButtonElement) {
        loadMoreBtn.disabled = false;
        loadMoreBtn.textContent = 'Charger plus';
      }
    }
  }

  // ---- Detail Drawer + Interactive/Keyboard Focal-Point Picker ----
  async function openDetailDrawer(mediaId) {
    try {
      const data = await apiJson(`${apiBase}/items/${mediaId}`);
      if (!data.item) return;
      state.activeMedia = data.item;
      state.activeUsages = Array.isArray(data.usages) ? data.usages : [];
      state.focalDraft = {
        x: Number(data.item.focal_x ?? 0.5),
        y: Number(data.item.focal_y ?? 0.5),
      };
      populateDetailDrawer();
      window.Prime?.openDrawer?.('pc-media-detail-drawer');
    } catch (err) {
      window.Prime?.toast?.(err instanceof Error ? err.message : tr('admin.js.media.library.media_introuvable', 'Média introuvable.'), 'danger');
    }
  }

  function populateDetailDrawer() {
    const item = state.activeMedia;
    if (!item || !drawerBackdrop) return;

    if (drawerTitle) drawerTitle.textContent = item.display_title;

    if (drawerPreview) {
      drawerPreview.innerHTML = '';
      if (item.kind === 'image') {
        const stage = document.createElement('div');
        stage.className = 'pc-focal-stage';
        if (item.can_edit) {
          stage.setAttribute('tabindex', '0');
          stage.setAttribute('role', 'application');
          stage.setAttribute(
            'aria-label',
            tr('admin.js.media.library.focal_image', "Point focal de l'image : :x % horizontal, :y % vertical. Utilisez les flèches pour déplacer le point focal.", { x: Math.round(state.focalDraft.x * 100), y: Math.round(state.focalDraft.y * 100) }),
          );
        }

        const img = document.createElement('img');
        img.src = item.medium_url || item.url;
        img.alt = item.alt || item.display_title;
        img.draggable = false;

        const dot = document.createElement('span');
        dot.className = 'pc-focal-dot';
        dot.setAttribute('aria-hidden', 'true');

        const coords = document.createElement('span');
        coords.className = 'pc-focal-coords pc-mono';

        const syncFocalDot = () => {
          const pctX = Math.round(state.focalDraft.x * 1000) / 10;
          const pctY = Math.round(state.focalDraft.y * 1000) / 10;
          dot.style.left = `${pctX}%`;
          dot.style.top = `${pctY}%`;
          coords.textContent = `${Math.round(pctX)}% × ${Math.round(pctY)}%`;
          stage.setAttribute(
            'aria-label',
            tr('admin.js.media.library.focal_point', 'Point focal : :x % horizontal, :y % vertical. Utilisez les flèches directionnelles pour ajuster.', { x: Math.round(pctX), y: Math.round(pctY) }),
          );
        };
        syncFocalDot();

        if (item.can_edit) {
          const updateFromPointer = (clientX, clientY) => {
            const rect = img.getBoundingClientRect();
            if (rect.width <= 0 || rect.height <= 0) return;
            state.focalDraft.x = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
            state.focalDraft.y = Math.max(0, Math.min(1, (clientY - rect.top) / rect.height));
            syncFocalDot();
          };

          let dragging = false;
          stage.addEventListener('pointerdown', (e) => {
            dragging = true;
            stage.setPointerCapture?.(e.pointerId);
            updateFromPointer(e.clientX, e.clientY);
          });
          stage.addEventListener('pointermove', (e) => {
            if (dragging) updateFromPointer(e.clientX, e.clientY);
          });
          stage.addEventListener('pointerup', () => {
            dragging = false;
          });

          stage.addEventListener('keydown', (e) => {
            const step = e.shiftKey ? 0.1 : 0.02;
            let changed = true;
            if (e.key === 'ArrowLeft') state.focalDraft.x = Math.max(0, state.focalDraft.x - step);
            else if (e.key === 'ArrowRight') state.focalDraft.x = Math.min(1, state.focalDraft.x + step);
            else if (e.key === 'ArrowUp') state.focalDraft.y = Math.max(0, state.focalDraft.y - step);
            else if (e.key === 'ArrowDown') state.focalDraft.y = Math.min(1, state.focalDraft.y + step);
            else if (e.key === 'Home') {
              state.focalDraft.x = 0.5;
              state.focalDraft.y = 0.5;
            } else {
              changed = false;
            }

            if (changed) {
              e.preventDefault();
              syncFocalDot();
              announce(`Point focal ${Math.round(state.focalDraft.x * 100)}% par ${Math.round(state.focalDraft.y * 100)}%`);
            }
          });
        }

        stage.append(img, dot, coords);
        drawerPreview.appendChild(stage);
        if (drawerFocalHint instanceof HTMLElement) {
          drawerFocalHint.hidden = !item.can_edit;
        }
      } else if (item.kind === 'audio') {
        drawerPreview.innerHTML = `
          <div class="pc-media-preview-box">
            ${iconSvg('music', 36)}
            <audio controls preload="metadata" src="${escapeHtml(item.url)}" style="width:100%;margin-top:12px;"></audio>
          </div>
        `;
        if (drawerFocalHint instanceof HTMLElement) drawerFocalHint.hidden = true;
      } else if (item.kind === 'video') {
        drawerPreview.innerHTML = `
          <div class="pc-media-preview-box pc-media-preview-box--video">
            <video controls preload="metadata" src="${escapeHtml(item.url)}" style="width:100%;max-height:260px;border-radius:8px;background:#111;"></video>
          </div>
        `;
        if (drawerFocalHint instanceof HTMLElement) drawerFocalHint.hidden = true;
      } else {
        drawerPreview.innerHTML = `
          <div class="pc-media-preview-box">
            ${iconSvg(KIND_ICONS[item.kind] || 'file', 40)}
            <p class="pc-text-sm" style="margin:8px 0 12px;font-weight:500;">${escapeHtml(item.original_name)}</p>
            <a href="${escapeHtml(item.url)}" target="_blank" rel="noopener" class="pc-btn pc-btn--secondary pc-btn--sm">
              ${iconSvg('external-link', 14)}
              <span>${escapeHtml(tr('admin.js.media.library.open_file', 'Ouvrir le fichier'))}</span>
            </a>
          </div>
        `;
        if (drawerFocalHint instanceof HTMLElement) drawerFocalHint.hidden = true;
      }
    }

    if (drawerMetaSpecs) {
      const dims = item.width && item.height ? `${item.width} × ${item.height} px` : '—';
      const variantsCount = item.variants ? Object.keys(item.variants).filter((k) => !k.startsWith('_')).length : 0;
      drawerMetaSpecs.innerHTML = `
        <dt>${escapeHtml(tr('admin.js.media.library.file', 'Fichier'))}</dt><dd class="pc-mono">${escapeHtml(item.original_name)}</dd>
        <dt>Type</dt><dd>${escapeHtml(item.kind_label)} <span class="pc-mono pc-text-muted">(${escapeHtml(item.mime_type)})</span></dd>
        <dt>Poids</dt><dd>${escapeHtml(item.human_size)}</dd>
        ${item.kind === 'image' ? `<dt>Dimensions</dt><dd>${escapeHtml(dims)} (${variantsCount} variante${variantsCount > 1 ? 's' : ''})</dd>` : ''}
        <dt>${escapeHtml(tr('admin.js.media.library.uploaded_by', 'Téléversé par'))}</dt><dd>${escapeHtml(item.author_name || tr('admin.js.media.library.systeme', 'Système'))} · ${escapeHtml((item.created_at || '').slice(0, 16))} UTC</dd>
      `;
    }

    if (drawerUrlInput instanceof HTMLInputElement) {
      drawerUrlInput.value = `${window.location.origin}${item.url}`;
    }

    renderFolderSelectOptions(drawerFolderSelect, { includeRoot: true, rootLabel: tr('admin.js.media.library.aucun_dossier_non_classe', 'Aucun dossier (non classé)') });
    if (drawerFolderSelect instanceof HTMLSelectElement) {
      drawerFolderSelect.value = item.folder_id ? String(item.folder_id) : '';
      drawerFolderSelect.disabled = !item.can_edit;
    }
    if (drawerAltInput instanceof HTMLInputElement) {
      drawerAltInput.value = item.alt || '';
      drawerAltInput.disabled = !item.can_edit;
    }
    if (drawerTitleInput instanceof HTMLInputElement) {
      drawerTitleInput.value = item.title || '';
      drawerTitleInput.disabled = !item.can_edit;
    }
    if (drawerCaptionInput instanceof HTMLTextAreaElement) {
      drawerCaptionInput.value = item.caption || '';
      drawerCaptionInput.disabled = !item.can_edit;
    }
    if (drawerDescInput instanceof HTMLTextAreaElement) {
      drawerDescInput.value = item.description || '';
      drawerDescInput.disabled = !item.can_edit;
    }
    if (drawerSaveBtn instanceof HTMLButtonElement) {
      drawerSaveBtn.hidden = !item.can_edit;
    }

    if (drawerUsagesList) {
      if (state.activeUsages.length === 0) {
        drawerUsagesList.innerHTML = tr('admin.js.media.library.no_usage_html', '<li class="pc-text-xs pc-text-muted">Ce média n’est référencé dans aucun contenu.</li>');
      } else {
        drawerUsagesList.innerHTML = state.activeUsages
          .map(
            (u) => `
              <li class="pc-media-usage-item">
                <a href="${escapeHtml(u.edit_url)}" class="pc-media-usage-link">
                  <span>${escapeHtml(u.title)}</span>
                  <span class="pc-badge pc-badge--neutral pc-badge--xs">${escapeHtml(u.context_label)}</span>
                </a>
              </li>
            `,
          )
          .join('');
      }
    }

    if (drawerRegenBtn instanceof HTMLElement) {
      drawerRegenBtn.hidden = !(item.kind === 'image' && item.can_edit);
    }
    if (drawerReplaceBtn instanceof HTMLElement) {
      drawerReplaceBtn.hidden = !item.can_edit;
    }
    if (drawerDownloadLink instanceof HTMLAnchorElement) {
      drawerDownloadLink.href = item.url;
      drawerDownloadLink.setAttribute('download', item.original_name || item.filename);
    }
    if (drawerDeleteBtn instanceof HTMLElement) {
      drawerDeleteBtn.hidden = !item.can_delete;
    }
  }

  drawerCopyUrlBtn?.addEventListener('click', async () => {
    if (!(drawerUrlInput instanceof HTMLInputElement)) return;
    try {
      await navigator.clipboard.writeText(drawerUrlInput.value);
      window.Prime?.toast?.(tr('admin.js.media.library.url_copiee_dans_le_presse', 'URL copiée dans le presse-papiers.'), 'success');
    } catch {
      drawerUrlInput.select();
      window.Prime?.toast?.(tr('admin.js.media.library.url_selectionnee_appuyez_sur_ctrl', 'URL sélectionnée — appuyez sur Ctrl+C pour copier.'), 'info');
    }
  });

  drawerForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const item = state.activeMedia;
    if (!item || !item.can_edit) return;
    if (drawerSaveBtn instanceof HTMLButtonElement) drawerSaveBtn.disabled = true;

    try {
      const payload = {
        folder_id: drawerFolderSelect instanceof HTMLSelectElement && drawerFolderSelect.value ? Number(drawerFolderSelect.value) : null,
        alt: drawerAltInput instanceof HTMLInputElement ? drawerAltInput.value : '',
        title: drawerTitleInput instanceof HTMLInputElement ? drawerTitleInput.value : '',
        caption: drawerCaptionInput instanceof HTMLTextAreaElement ? drawerCaptionInput.value : '',
        description: drawerDescInput instanceof HTMLTextAreaElement ? drawerDescInput.value : '',
      };
      if (item.kind === 'image') {
        payload.focal_x = Math.round(state.focalDraft.x * 1000) / 1000;
        payload.focal_y = Math.round(state.focalDraft.y * 1000) / 1000;
      }

      const res = await apiJson(`${apiBase}/items/${item.id}`, {
        method: 'POST',
        body: JSON.stringify(payload),
      });
      state.activeMedia = res.item;
      state.activeUsages = res.usages || state.activeUsages;
      state.items = state.items.map((m) => (Number(m.id) === Number(res.item.id) ? res.item : m));
      populateDetailDrawer();
      renderItems();
      await refreshFolders();
      window.Prime?.toast?.(tr('admin.js.media.library.metadonnees_enregistrees', 'Métadonnées enregistrées.'), 'success');
    } catch (err) {
      window.Prime?.toast?.(err instanceof Error ? err.message : 'Enregistrement impossible.', 'danger');
    } finally {
      if (drawerSaveBtn instanceof HTMLButtonElement) drawerSaveBtn.disabled = false;
    }
  });

  drawerRegenBtn?.addEventListener('click', async () => {
    const item = state.activeMedia;
    if (!item) return;
    drawerRegenBtn.disabled = true;
    try {
      const res = await apiJson(`${apiBase}/items/${item.id}/regenerate`, { method: 'POST', body: '{}' });
      state.activeMedia = res.item;
      state.items = state.items.map((m) => (Number(m.id) === Number(res.item.id) ? res.item : m));
      populateDetailDrawer();
      renderItems();
      window.Prime?.toast?.(tr('admin.js.media.library.variantes_d_image_regenerees', 'Variantes d’image régénérées.'), 'success');
    } catch (err) {
      window.Prime?.toast?.(err instanceof Error ? err.message : tr('admin.js.media.library.regeneration_impossible', 'Régénération impossible.'), 'danger');
    } finally {
      drawerRegenBtn.disabled = false;
    }
  });

  drawerReplaceBtn?.addEventListener('click', () => {
    drawerReplaceInput?.click();
  });

  drawerReplaceInput?.addEventListener('change', async () => {
    const item = state.activeMedia;
    if (!item || !(drawerReplaceInput instanceof HTMLInputElement) || !drawerReplaceInput.files?.[0]) return;

    const formData = new FormData();
    formData.append('file', drawerReplaceInput.files[0]);
    drawerReplaceInput.value = '';

    try {
      const res = await apiJson(`${apiBase}/items/${item.id}/replace`, {
        method: 'POST',
        body: formData,
      });
      state.activeMedia = res.item;
      state.activeUsages = res.usages || state.activeUsages;
      state.items = state.items.map((m) => (Number(m.id) === Number(res.item.id) ? res.item : m));
      populateDetailDrawer();
      renderItems();
      window.Prime?.toast?.(tr('admin.js.media.library.fichier_remplace_en_conservant_l', 'Fichier remplacé en conservant l’identifiant et l’URL.'), 'success');
    } catch (err) {
      window.Prime?.toast?.(err instanceof Error ? err.message : tr('admin.js.media.library.remplacement_refuse', 'Remplacement refusé.'), 'danger');
    }
  });

  drawerDeleteBtn?.addEventListener('click', () => {
    const item = state.activeMedia;
    if (!item) return;
    const usageNames = state.activeUsages.map((u) => `« ${u.title} »`).slice(0, 5);
    const warning =
      state.activeUsages.length > 0
        ? `Attention : ce média est utilisé dans ${state.activeUsages.length} contenu(s) (${usageNames.join(', ')}${state.activeUsages.length > 5 ? '…' : ''}).`
        : null;

    openConfirmModal({
      title: tr('admin.js.media.library.supprimer_media_confirmation', 'Supprimer « :title » ?', { title: item.display_title }),
      message: tr('admin.js.media.library.le_fichier_original_et_toutes', 'Le fichier original et toutes ses variantes seront définitivement supprimés du serveur.'),
      warning,
      confirmLabel: tr('admin.js.media.library.supprimer_definitivement', 'Supprimer définitivement'),
      onConfirm: async () => {
        await apiJson(`${apiBase}/items/${item.id}/delete`, { method: 'POST', body: '{}' });
        selection.remove(item.id);
        window.Prime?.closeDrawer?.('pc-media-detail-drawer');
        state.activeMedia = null;
        await Promise.all([refreshFolders(), fetchItems(1, false)]);
        window.Prime?.toast?.(tr('admin.js.media.library.media_supprime_nomme', '« :title » supprimé.', { title: item.display_title }), 'success');
      },
    });
  });

  // ---- Parallel Upload Queue (3 concurrent) + Full-page Drag-and-Drop ----
  const renderQueuePanel = (entries) => {
    if (!(queuePanel instanceof HTMLElement) || !queueList) return;
    if (entries.length === 0) {
      queuePanel.hidden = true;
      return;
    }

    queuePanel.hidden = false;
    const active = entries.filter((e) => e.status === 'queued' || e.status === 'uploading').length;
    const done = entries.filter((e) => e.status === 'done').length;
    const failed = entries.filter((e) => e.status === 'error').length;

    if (queueTitle) {
      if (active > 0) {
        queueTitle.textContent = tr('admin.js.media.library.queue_running', 'Téléversement en cours (:done/:total)', { done, total: entries.length });
      } else if (failed > 0) {
        queueTitle.textContent = tr('admin.js.media.library.queue_done_errors', 'Téléversement terminé (:count erreur(s))', { count: failed });
      } else {
        queueTitle.textContent = tr('admin.js.media.library.queue_done', ':count fichier(s) téléversé(s)', { count: done });
      }
    }

    queueList.innerHTML = '';
    entries.forEach((entry) => {
      const li = document.createElement('li');
      li.className = `pc-upload-row pc-upload-row--${entry.status}`;

      const statusMeta =
        entry.status === 'done'
          ? tr('admin.js.media.library.termine', 'Terminé')
          : entry.status === 'error'
            ? entry.error || tr('admin.js.media.library.error', 'Erreur')
            : entry.status === 'cancelled'
              ? tr('admin.js.media.library.annule', 'Annulé')
              : entry.status === 'queued'
                ? 'En attente'
                : `${entry.progress}%`;

      li.innerHTML = `
        <div class="pc-upload-row__head">
          <span class="pc-upload-row__name" title="${escapeHtml(entry.name)}">${escapeHtml(entry.name)}</span>
          <div class="pc-upload-row__actions">
            <span class="pc-upload-row__status">${escapeHtml(statusMeta)}</span>
            ${entry.status === 'error' || entry.status === 'cancelled' ? `<button type="button" class="pc-btn pc-btn--ghost pc-btn--xs" data-prime-q-retry="${entry.id}">${escapeHtml(tr('admin.js.media.library.retry', 'Réessayer'))}</button>` : ''}
            ${entry.status === 'queued' || entry.status === 'uploading' ? `<button type="button" class="pc-btn pc-btn--ghost pc-btn--xs" data-prime-q-cancel="${entry.id}">${escapeHtml(tr('admin.js.media.library.cancel', 'Annuler'))}</button>` : ''}
          </div>
        </div>
        <div class="pc-progress pc-progress--sm"><div class="pc-progress__bar" style="width:${entry.progress}%"></div></div>
        ${entry.duplicate ? `<div class="pc-upload-row__dup">${iconSvg('alert-triangle', 13)} <span>${escapeHtml(tr('admin.js.media.library.duplicate', 'Doublon détecté :'))} <button type="button" class="pc-link-btn" data-prime-q-open-dup="${Number(entry.duplicate.id)}">${escapeHtml(entry.duplicate.title)}</button></span></div>` : ''}
      `;

      li.querySelector('[data-prime-q-retry]')?.addEventListener('click', () => queue.retry(entry.id));
      li.querySelector('[data-prime-q-cancel]')?.addEventListener('click', () => queue.cancel(entry.id));
      li.querySelector('[data-prime-q-open-dup]')?.addEventListener('click', (e) => {
        const dupId = Number(e.currentTarget.getAttribute('data-prime-q-open-dup'));
        if (dupId > 0) openDetailDrawer(dupId);
      });

      queueList.appendChild(li);
    });
  };

  const queue = new UploadQueue({
    concurrency: 3,
    uploadUrl: `${apiBase}/upload`,
    getCsrfToken: csrfToken,
    onChange: renderQueuePanel,
    onItemComplete: (_entry, res) => {
      if (res.item) {
        state.items = [res.item, ...state.items.filter((m) => Number(m.id) !== Number(res.item.id))];
        state.total += 1;
        state.counts.all = Number(state.counts.all || 0) + 1;
        if (!res.item.folder_id) {
          state.counts.unclassified = Number(state.counts.unclassified || 0) + 1;
        }
        renderItems();
        if (res.warning) {
          window.Prime?.toast?.(res.warning, 'warning');
        }
      }
    },
    onIdle: () => {
      refreshFolders();
    },
  });

  function enqueueUserFiles(fileList) {
    if (!canUpload || !fileList || fileList.length === 0) return;
    const activeFolder = /^\d+$/.test(state.filters.folder) ? Number(state.filters.folder) : null;
    queue.enqueue(fileList, { folderId: activeFolder });
  }

  uploadTriggerBtn?.addEventListener('click', () => uploadFileInput?.click());
  emptyUploadBtn?.addEventListener('click', () => uploadFileInput?.click());
  uploadFileInput?.addEventListener('change', () => {
    if (uploadFileInput instanceof HTMLInputElement && uploadFileInput.files?.length) {
      enqueueUserFiles(uploadFileInput.files);
      uploadFileInput.value = '';
    }
  });
  queueClearBtn?.addEventListener('click', () => queue.clearFinished());

  // Full-page drag-and-drop for external files
  if (canUpload && dropOverlay instanceof HTMLElement) {
    let dragDepth = 0;
    const isExternalFileDrag = (e) =>
      Boolean(e.dataTransfer?.types?.includes('Files')) &&
      !e.dataTransfer?.types?.includes('application/x-prime-media-ids');

    window.addEventListener('dragenter', (e) => {
      if (!isExternalFileDrag(e)) return;
      e.preventDefault();
      dragDepth += 1;
      dropOverlay.hidden = false;
    });
    window.addEventListener('dragover', (e) => {
      if (!isExternalFileDrag(e)) return;
      e.preventDefault();
    });
    window.addEventListener('dragleave', (e) => {
      if (!isExternalFileDrag(e)) return;
      dragDepth = Math.max(0, dragDepth - 1);
      if (dragDepth === 0) dropOverlay.hidden = true;
    });
    window.addEventListener('drop', (e) => {
      if (!isExternalFileDrag(e)) return;
      e.preventDefault();
      dragDepth = 0;
      dropOverlay.hidden = true;
      if (e.dataTransfer?.files?.length) {
        enqueueUserFiles(e.dataTransfer.files);
      }
    });
  }

  // ---- Toolbar Filters, Search, Sort, View & Infinite Scroll ----
  let searchTimer = null;
  searchInput?.addEventListener('input', () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
      state.filters.q = searchInput instanceof HTMLInputElement ? searchInput.value.trim() : '';
      fetchItems(1, false);
    }, 240);
  });

  kindSelect?.addEventListener('change', () => {
    state.filters.kind = kindSelect instanceof HTMLSelectElement ? kindSelect.value : '';
    fetchItems(1, false);
  });

  monthSelect?.addEventListener('change', () => {
    state.filters.month = monthSelect instanceof HTMLSelectElement ? monthSelect.value : '';
    fetchItems(1, false);
  });

  mineToggle?.addEventListener('click', () => {
    state.filters.mine = !state.filters.mine;
    mineToggle.classList.toggle('is-active', state.filters.mine);
    mineToggle.setAttribute('aria-pressed', state.filters.mine ? 'true' : 'false');
    fetchItems(1, false);
  });

  unusedToggle?.addEventListener('click', () => {
    state.filters.unused = !state.filters.unused;
    unusedToggle.classList.toggle('is-active', state.filters.unused);
    unusedToggle.setAttribute('aria-pressed', state.filters.unused ? 'true' : 'false');
    fetchItems(1, false);
  });

  sortSelect?.addEventListener('change', () => {
    if (!(sortSelect instanceof HTMLSelectElement)) return;
    const [sortCol, sortDir] = sortSelect.value.split(':');
    state.filters.sort = sortCol || 'created_at';
    state.filters.dir = sortDir || 'desc';
    fetchItems(1, false);
  });

  mobileFolderSelect?.addEventListener('change', () => {
    state.filters.folder = mobileFolderSelect instanceof HTMLSelectElement ? mobileFolderSelect.value : '';
    renderFolders();
    fetchItems(1, false);
  });

  createFolderBtn?.addEventListener('click', () => openFolderModal(null));

  viewButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      const targetView = btn.getAttribute('data-prime-media-view') === 'list' ? 'list' : 'grid';
      state.view = targetView;
      try {
        window.localStorage.setItem(VIEW_STORAGE_KEY, targetView);
      } catch {
        // Ignore storage errors in private mode
      }
      renderViewSwitcher();
    });
  });

  emptyResetBtn?.addEventListener('click', () => {
    state.filters = { folder: '', kind: '', month: '', mine: false, unused: false, q: '', sort: 'created_at', dir: 'desc' };
    if (searchInput instanceof HTMLInputElement) searchInput.value = '';
    if (kindSelect instanceof HTMLSelectElement) kindSelect.value = '';
    if (monthSelect instanceof HTMLSelectElement) monthSelect.value = '';
    if (sortSelect instanceof HTMLSelectElement) sortSelect.value = 'created_at:desc';
    mineToggle?.classList.remove('is-active');
    mineToggle?.setAttribute('aria-pressed', 'false');
    unusedToggle?.classList.remove('is-active');
    unusedToggle?.setAttribute('aria-pressed', 'false');
    renderFolders();
    fetchItems(1, false);
  });

  loadMoreBtn?.addEventListener('click', () => {
    if (state.hasMore && !state.loading) {
      fetchItems(state.page + 1, true);
    }
  });

  if (sentinelEl instanceof HTMLElement && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((e) => e.isIntersecting) && state.hasMore && !state.loading) {
          fetchItems(state.page + 1, true);
        }
      },
      { rootMargin: '220px' },
    );
    observer.observe(sentinelEl);
  }

  // Initial render
  renderMonths();
  renderFolders();
  renderItems();

  if (config.openMediaId && Number(config.openMediaId) > 0) {
    openDetailDrawer(Number(config.openMediaId));
  }
}
