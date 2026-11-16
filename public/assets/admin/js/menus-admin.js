/**
 * Prime CMS Admin — Visual Navigation Menus Editor (`GET /admin/appearance/menus`).
 *
 * Supports drag & drop AND full keyboard reordering (up/down/indent/outdent),
 * strictly enforcing a maximum nesting depth of 3 levels (depths 0, 1, 2).
 */

import { showToast } from './toast.js';
import { tr } from './i18n.js';

export const MAX_MENU_DEPTH = 2; // 0 = root, 1 = child, 2 = grandchild (3 levels max)

/**
 * Flatten a nested menu tree into a flat list with explicit `depth` (0..2).
 *
 * @param {Array<Record<string, any>>} nodes
 * @param {number} depth
 * @returns {Array<Record<string, any>>}
 */
export function flattenMenuTree(nodes, depth = 0) {
  const out = [];
  const clamped = Math.min(depth, MAX_MENU_DEPTH);
  for (const node of nodes || []) {
    out.push({
      label: String(node.label || ''),
      type: String(node.type || 'custom'),
      target_id: node.target_id ? Number(node.target_id) : null,
      url: node.url ? String(node.url) : '',
      target: node.target === '_blank' ? '_blank' : '_self',
      css_class: node.css_class ? String(node.css_class) : '',
      depth: clamped,
    });
    if (Array.isArray(node.children) && node.children.length > 0) {
      out.push(...flattenMenuTree(node.children, clamped + 1));
    }
  }
  return out;
}

/**
 * Rebuild a nested tree (max 3 levels) from a flat list with `depth` values.
 *
 * @param {Array<Record<string, any>>} flat
 * @returns {Array<Record<string, any>>}
 */
export function nestMenuItems(flat) {
  const roots = [];
  const stack = []; // stack[d] = last node at depth d

  for (const raw of flat) {
    let depth = Math.max(0, Math.min(MAX_MENU_DEPTH, Number(raw.depth || 0)));
    // Cannot jump deeper than previous item's depth + 1
    const maxAllowed = stack.length === 0 ? 0 : Math.min(MAX_MENU_DEPTH, stack.length);
    if (depth > maxAllowed) {
      depth = maxAllowed;
    }

    const node = {
      label: String(raw.label || '').trim() || 'Lien',
      type: String(raw.type || 'custom'),
      target_id: raw.target_id ? Number(raw.target_id) : null,
      url: raw.url ? String(raw.url) : null,
      target: raw.target === '_blank' ? '_blank' : '_self',
      css_class: raw.css_class ? String(raw.css_class) : null,
      children: [],
    };

    if (depth === 0 || !stack[depth - 1]) {
      roots.push(node);
      stack.length = 1;
      stack[0] = node;
    } else {
      stack[depth - 1].children.push(node);
      stack.length = depth + 1;
      stack[depth] = node;
    }
  }

  return roots;
}

/**
 * Pure list operations for keyboard & drag-and-drop editing.
 */
export function moveMenuItem(flat, index, delta) {
  const next = flat.slice();
  const target = index + delta;
  if (index < 0 || index >= next.length || target < 0 || target >= next.length) {
    return next;
  }
  const [moved] = next.splice(index, 1);
  next.splice(target, 0, moved);
  return normalizeDepths(next);
}

export function indentMenuItem(flat, index) {
  if (index <= 0 || index >= flat.length) {
    return flat.slice();
  }
  const next = flat.map((item) => ({ ...item }));
  const prevDepth = next[index - 1].depth;
  const maxDepth = Math.min(MAX_MENU_DEPTH, prevDepth + 1);
  if (next[index].depth < maxDepth) {
    next[index].depth += 1;
  }
  return normalizeDepths(next);
}

export function outdentMenuItem(flat, index) {
  if (index < 0 || index >= flat.length) {
    return flat.slice();
  }
  const next = flat.map((item) => ({ ...item }));
  if (next[index].depth > 0) {
    next[index].depth -= 1;
  }
  return normalizeDepths(next);
}

export function normalizeDepths(flat) {
  const out = flat.map((item) => ({ ...item }));
  for (let i = 0; i < out.length; i += 1) {
    if (i === 0) {
      out[i].depth = 0;
    } else {
      const maxD = Math.min(MAX_MENU_DEPTH, out[i - 1].depth + 1);
      out[i].depth = Math.max(0, Math.min(maxD, out[i].depth));
    }
  }
  return out;
}

const TYPE_LABELS = {
  entry: 'Contenu',
  term: 'Taxonomie',
  archive: 'Archive',
  url: 'Lien',
  custom: 'Lien',
};

export function initMenusAdmin() {
  const form = document.querySelector('[data-prime-menu-editor]');
  if (!(form instanceof HTMLFormElement)) {
    return;
  }

  const treeContainer = form.querySelector('[data-prime-menu-tree]');
  const emptyHint = form.querySelector('[data-prime-menu-empty]');
  const hiddenInput = form.querySelector('[data-prime-menu-items-input]');
  const initialScript = form.querySelector('[data-prime-menu-initial]');
  if (!(treeContainer instanceof HTMLElement) || !(hiddenInput instanceof HTMLInputElement)) {
    return;
  }

  let items = [];
  let dirty = false;
  let dragIndex = null;

  if (initialScript instanceof HTMLScriptElement) {
    try {
      const parsed = JSON.parse(initialScript.textContent || '[]');
      items = normalizeDepths(flattenMenuTree(Array.isArray(parsed) ? parsed : []));
    } catch {
      items = [];
    }
  }

  const syncHidden = () => {
    hiddenInput.value = JSON.stringify(nestMenuItems(items));
  };

  const render = () => {
    treeContainer.innerHTML = '';
    if (emptyHint instanceof HTMLElement) {
      emptyHint.hidden = items.length > 0;
    }

    items.forEach((item, idx) => {
      const row = document.createElement('div');
      row.className = `pc-menu-node pc-menu-node--depth-${item.depth}`;
      row.draggable = true;
      row.dataset.primeMenuIndex = String(idx);

      row.addEventListener('dragstart', (e) => {
        dragIndex = idx;
        row.classList.add('is-dragging');
        if (e.dataTransfer) {
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/plain', String(idx));
        }
      });
      row.addEventListener('dragend', () => {
        dragIndex = null;
        row.classList.remove('is-dragging');
      });
      row.addEventListener('dragover', (e) => {
        e.preventDefault();
        row.classList.add('is-drag-over');
      });
      row.addEventListener('dragleave', () => {
        row.classList.remove('is-drag-over');
      });
      row.addEventListener('drop', (e) => {
        e.preventDefault();
        row.classList.remove('is-drag-over');
        if (dragIndex === null || dragIndex === idx) {
          return;
        }
        const next = items.slice();
        const [moved] = next.splice(dragIndex, 1);
        next.splice(idx, 0, moved);
        items = normalizeDepths(next);
        dirty = true;
        render();
      });

      const header = document.createElement('div');
      header.className = 'pc-menu-node__head';

      const titleWrap = document.createElement('div');
      titleWrap.className = 'pc-menu-node__title';

      const handle = document.createElement('span');
      handle.className = 'pc-menu-node__handle';
      handle.setAttribute('aria-hidden', 'true');
      handle.textContent = '⋮⋮';

      const labelSpan = document.createElement('strong');
      labelSpan.textContent = item.label || 'Lien';

      const badge = document.createElement('span');
      badge.className = 'pc-badge pc-badge--neutral';
      badge.textContent = TYPE_LABELS[item.type] || item.type;

      titleWrap.append(handle, labelSpan, badge);

      const controls = document.createElement('div');
      controls.className = 'pc-menu-node__controls';

      const mkBtn = (text, title, disabled, onClick) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'pc-btn pc-btn--ghost pc-btn--sm';
        b.textContent = text;
        b.title = title;
        b.setAttribute('aria-label', title);
        b.disabled = disabled;
        b.addEventListener('click', onClick);
        return b;
      };

      const canIndent = idx > 0 && item.depth < Math.min(MAX_MENU_DEPTH, items[idx - 1].depth + 1);
      const canOutdent = item.depth > 0;

      controls.append(
        mkBtn('↑', 'Monter', idx === 0, () => {
          items = moveMenuItem(items, idx, -1);
          dirty = true;
          render();
        }),
        mkBtn('↓', 'Descendre', idx === items.length - 1, () => {
          items = moveMenuItem(items, idx, 1);
          dirty = true;
          render();
        }),
        mkBtn('←', tr('admin.js.menus-admin.desindenter', 'Désindenter'), !canOutdent, () => {
          items = outdentMenuItem(items, idx);
          dirty = true;
          render();
        }),
        mkBtn('→', tr('admin.js.menus-admin.indenter_sous_menu', 'Indenter (sous-menu)'), !canIndent, () => {
          items = indentMenuItem(items, idx);
          dirty = true;
          render();
        }),
      );

      const toggleBtn = mkBtn(tr('admin.js.menus-admin.editer', 'Éditer'), tr('admin.js.menus-admin.modifier_les_details', 'Modifier les détails'), false, () => {
        details.hidden = !details.hidden;
      });
      const delBtn = mkBtn('×', 'Retirer du menu', false, () => {
        items.splice(idx, 1);
        items = normalizeDepths(items);
        dirty = true;
        render();
      });
      delBtn.classList.remove('pc-btn--ghost');
      delBtn.classList.add('pc-btn--danger-outline');
      controls.append(toggleBtn, delBtn);

      header.append(titleWrap, controls);

      const details = document.createElement('div');
      details.className = 'pc-menu-node__details';
      details.hidden = true;

      const labelField = document.createElement('div');
      labelField.className = 'pc-field';
      const labelL = document.createElement('label');
      labelL.className = 'pc-label';
      labelL.textContent = tr('admin.js.menus-admin.libelle_affiche', 'Libellé affiché');
      const labelIn = document.createElement('input');
      labelIn.type = 'text';
      labelIn.className = 'pc-input';
      labelIn.value = item.label;
      labelIn.addEventListener('input', () => {
        item.label = labelIn.value;
        labelSpan.textContent = item.label || 'Lien';
        dirty = true;
        syncHidden();
      });
      labelField.append(labelL, labelIn);
      details.append(labelField);

      if (item.type === 'custom' || item.type === 'url') {
        const urlField = document.createElement('div');
        urlField.className = 'pc-field';
        const urlL = document.createElement('label');
        urlL.className = 'pc-label';
        urlL.textContent = 'URL';
        const urlIn = document.createElement('input');
        urlIn.type = 'text';
        urlIn.className = 'pc-input';
        urlIn.value = item.url;
        urlIn.addEventListener('input', () => {
          item.url = urlIn.value;
          dirty = true;
          syncHidden();
        });
        urlField.append(urlL, urlIn);
        details.append(urlField);
      }

      const optsRow = document.createElement('div');
      optsRow.className = 'pc-grid-2';

      const targetWrap = document.createElement('label');
      targetWrap.className = 'pc-checkbox';
      const targetCb = document.createElement('input');
      targetCb.type = 'checkbox';
      targetCb.checked = item.target === '_blank';
      targetCb.addEventListener('change', () => {
        item.target = targetCb.checked ? '_blank' : '_self';
        dirty = true;
        syncHidden();
      });
      const targetSpan = document.createElement('span');
      targetSpan.textContent = 'Ouvrir dans un nouvel onglet';
      targetWrap.append(targetCb, targetSpan);

      const classField = document.createElement('div');
      classField.className = 'pc-field';
      const classL = document.createElement('label');
      classL.className = 'pc-label';
      classL.textContent = 'Classe CSS (optionnelle)';
      const classIn = document.createElement('input');
      classIn.type = 'text';
      classIn.className = 'pc-input';
      classIn.value = item.css_class;
      classIn.addEventListener('input', () => {
        item.css_class = classIn.value;
        dirty = true;
        syncHidden();
      });
      classField.append(classL, classIn);

      optsRow.append(targetWrap, classField);
      details.append(optsRow);

      row.append(header, details);
      treeContainer.append(row);
    });

    syncHidden();
  };

  // Add checked pages / posts / terms / archives
  document.querySelectorAll('[data-prime-menu-add-checked]').forEach((btn) => {
    if (!(btn instanceof HTMLButtonElement)) {
      return;
    }
    btn.addEventListener('click', () => {
      const container = btn.closest('details');
      if (!container) {
        return;
      }
      const checked = container.querySelectorAll('input[type="checkbox"][data-prime-menu-source]:checked');
      let added = 0;
      checked.forEach((cb) => {
        if (!(cb instanceof HTMLInputElement)) {
          return;
        }
        const type = cb.dataset.primeMenuSource || 'custom';
        const label = cb.dataset.primeSourceLabel || 'Lien';
        const targetId = cb.dataset.primeSourceId ? Number(cb.dataset.primeSourceId) : null;
        const url = cb.dataset.primeSourceUrl || '';
        items.push({
          label,
          type,
          target_id: targetId,
          url,
          target: '_self',
          css_class: '',
          depth: 0,
        });
        cb.checked = false;
        added += 1;
      });
      if (added > 0) {
        dirty = true;
        render();
        showToast(`${added} élément(s) ajouté(s) au menu.`, 'info');
      }
    });
  });

  // Add custom link
  const addCustomBtn = document.querySelector('[data-prime-menu-add-custom]');
  if (addCustomBtn instanceof HTMLButtonElement) {
    addCustomBtn.addEventListener('click', () => {
      const labelEl = document.querySelector('[data-prime-custom-label]');
      const urlEl = document.querySelector('[data-prime-custom-url]');
      const label = labelEl instanceof HTMLInputElement ? labelEl.value.trim() : '';
      const url = urlEl instanceof HTMLInputElement ? urlEl.value.trim() : '';
      if (!label || !url) {
        showToast(tr('admin.js.menus-admin.veuillez_saisir_un_libelle_et', 'Veuillez saisir un libellé et une URL.'), 'warning');
        return;
      }
      items.push({
        label,
        type: 'custom',
        target_id: null,
        url,
        target: '_self',
        css_class: '',
        depth: 0,
      });
      if (labelEl instanceof HTMLInputElement) {
        labelEl.value = '';
      }
      if (urlEl instanceof HTMLInputElement) {
        urlEl.value = '';
      }
      dirty = true;
      render();
    });
  }

  form.addEventListener('submit', () => {
    syncHidden();
    dirty = false;
  });

  window.addEventListener('beforeunload', (e) => {
    if (dirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  render();
}
