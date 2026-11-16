/**
 * Prime CMS Admin — Dynamic block registration for Prime Editor & Zones sidebar quick-insert.
 *
 * Unified data format across JS & PHP: `{ name: string, attrs: Record<string, any> }`.
 */

import { editorApi } from './editor/core.js';

/**
 * Fallback catalog used only when editor config has not provided `dynamicBlocks`.
 */
const FALLBACK_CATALOG = [
  {
    name: 'latest_entries',
    label: 'Derniers contenus',
    description: 'Affiche la liste des derniers articles ou contenus publiés.',
    defaults: { title: 'Derniers articles', type: 'post', limit: 5, show_image: false, show_excerpt: false },
    fields: [
      { name: 'title', type: 'text', label: 'Titre affiché', placeholder: 'Derniers articles', default: 'Derniers articles' },
      {
        name: 'type',
        type: 'select',
        label: 'Type de contenu',
        options: [
          { value: 'post', label: 'Articles' },
          { value: 'page', label: 'Pages' },
        ],
        default: 'post',
      },
      { name: 'limit', type: 'number', label: 'Nombre d’éléments (1 à 12)', min: 1, max: 12, step: 1, default: 5 },
      { name: 'show_image', type: 'toggle', label: 'Afficher l’image mise en avant', default: false },
      { name: 'show_excerpt', type: 'toggle', label: 'Afficher l’extrait', default: false },
    ],
  },
  {
    name: 'term_list',
    label: 'Liste de termes',
    description: 'Affiche les catégories ou étiquettes du site.',
    defaults: { title: 'Catégories', taxonomy: 'category', show_count: true },
    fields: [
      { name: 'title', type: 'text', label: 'Titre affiché', placeholder: 'Catégories', default: 'Catégories' },
      {
        name: 'taxonomy',
        type: 'select',
        label: 'Taxonomie',
        options: [
          { value: 'category', label: 'Catégories' },
          { value: 'tag', label: 'Étiquettes' },
        ],
        default: 'category',
      },
      { name: 'show_count', type: 'toggle', label: 'Afficher le nombre de contenus', default: true },
    ],
  },
  {
    name: 'menu',
    label: 'Menu de navigation',
    description: 'Affiche un menu de navigation enregistré.',
    defaults: { title: '', menu_id: 0 },
    fields: [
      { name: 'title', type: 'text', label: 'Titre affiché (optionnel)', placeholder: 'Navigation', default: '' },
      {
        name: 'menu_id',
        type: 'select',
        label: 'Menu à afficher',
        options: [{ value: '0', label: 'Aucun menu enregistré' }],
        default: '0',
      },
    ],
  },
  {
    name: 'search_form',
    label: 'Formulaire de recherche',
    description: 'Champ de recherche accessible pointant vers la page de recherche.',
    defaults: {},
    fields: [],
  },
];

/**
 * Read the dynamic block catalog from editor config or DOM script tag.
 *
 * @param {Record<string, any> | null | undefined} config
 * @returns {Array<Record<string, any>>}
 */
export function resolveDynamicCatalog(config = null) {
  if (config && Array.isArray(config.dynamicBlocks) && config.dynamicBlocks.length > 0) {
    return config.dynamicBlocks;
  }

  if (typeof document !== 'undefined') {
    const configScript = document.querySelector('script[data-prime-editor-config]');
    if (configScript) {
      try {
        const parsed = JSON.parse(configScript.textContent || '{}');
        if (Array.isArray(parsed.dynamicBlocks) && parsed.dynamicBlocks.length > 0) {
          return parsed.dynamicBlocks;
        }
      } catch {
        // Fallback below
      }
    }
  }

  return FALLBACK_CATALOG;
}

/**
 * Normalize block data strictly to `{ name, attrs }` merged with catalog defaults.
 *
 * @param {any} rawData
 * @param {Array<Record<string, any>>} catalog
 * @returns {{ name: string, attrs: Record<string, any>, entry: Record<string, any> | null }}
 */
export function normalizeDynamicBlockData(rawData, catalog) {
  const list = Array.isArray(catalog) && catalog.length > 0 ? catalog : FALLBACK_CATALOG;
  const src = rawData && typeof rawData === 'object' ? rawData : {};

  let name = typeof src.name === 'string' && src.name.trim() !== '' ? src.name.trim() : '';
  let entry = list.find((item) => item && item.name === name) || null;
  if (!entry) {
    entry = list[0] || null;
    name = entry ? String(entry.name) : 'latest_entries';
  }

  const defaults = entry && entry.defaults && typeof entry.defaults === 'object' ? { ...entry.defaults } : {};
  const rawAttrs = src.attrs && typeof src.attrs === 'object' && !Array.isArray(src.attrs) ? src.attrs : {};
  const attrs = { ...defaults, ...rawAttrs };

  return { name, attrs, entry };
}

/**
 * Coerce a boolean value from JS / PHP serialized values.
 *
 * @param {any} value
 * @returns {boolean}
 */
function toBoolean(value) {
  if (typeof value === 'boolean') return value;
  if (typeof value === 'number') return value !== 0;
  if (typeof value === 'string') {
    return ['1', 'true', 'yes', 'on'].includes(value.trim().toLowerCase());
  }
  return false;
}

/**
 * Build a concise human-readable French summary of a dynamic block's current settings.
 *
 * @param {Record<string, any> | null} entry
 * @param {Record<string, any>} attrs
 * @returns {string}
 */
export function formatDynamicSummary(entry, attrs) {
  if (!entry) {
    return 'Bloc dynamique';
  }

  const fields = Array.isArray(entry.fields) ? entry.fields : [];
  if (fields.length === 0) {
    return 'Aucun réglage requis';
  }

  const parts = [];
  for (const field of fields) {
    if (!field || typeof field.name !== 'string') continue;
    const key = field.name;
    const val = attrs[key] !== undefined ? attrs[key] : field.default;

    if (field.type === 'text') {
      const str = typeof val === 'string' ? val.trim() : '';
      if (str !== '') {
        parts.push(`« ${str} »`);
      }
    } else if (field.type === 'select') {
      const options = Array.isArray(field.options) ? field.options : [];
      const match = options.find((opt) => String(opt.value) === String(val ?? ''));
      const label = match ? String(match.label) : String(val ?? '');
      if (label !== '') {
        parts.push(key === 'menu_id' ? `Menu : ${label}` : label);
      }
    } else if (field.type === 'number') {
      const num = Number(val ?? field.default ?? 0);
      if (!Number.isNaN(num)) {
        if (key === 'limit') {
          parts.push(num > 1 ? `${num} éléments` : `${num} élément`);
        } else {
          parts.push(`${field.label} : ${num}`);
        }
      }
    } else if (field.type === 'toggle') {
      const active = toBoolean(val);
      if (key === 'show_image') {
        parts.push(active ? 'avec image' : 'sans image');
      } else if (key === 'show_excerpt') {
        parts.push(active ? 'avec extrait' : 'sans extrait');
      } else if (key === 'show_count') {
        parts.push(active ? 'avec compteur' : 'sans compteur');
      } else if (key === 'show_stars') {
        parts.push(active ? 'avec étoiles' : 'sans étoiles');
      } else {
        parts.push(`${field.label} : ${active ? 'oui' : 'non'}`);
      }
    }
  }

  return parts.length > 0 ? parts.join(' · ') : (entry.description || 'Paramètres par défaut');
}

/**
 * Build the interactive DOM element for a dynamic block inside Prime Editor.
 *
 * @param {{ data: any, updateData: (patch: Record<string, any>) => void, catalog: Array<Record<string, any>> }} ctx
 * @returns {HTMLElement}
 */
export function buildDynamicBlockElement({ data, updateData, catalog }) {
  const list = Array.isArray(catalog) && catalog.length > 0 ? catalog : FALLBACK_CATALOG;
  const state = normalizeDynamicBlockData(data, list);

  const wrap = document.createElement('div');
  wrap.className = 'pc-ed-dynamic';
  wrap.dataset.primeDynamicBlock = state.name;

  // 1. Readable preview header (block title + live summary of configured settings)
  const preview = document.createElement('div');
  preview.className = 'pc-ed-dynamic__preview';

  const previewMeta = document.createElement('div');
  previewMeta.className = 'pc-ed-dynamic__preview-meta';

  const badge = document.createElement('span');
  badge.className = 'pc-badge pc-badge--brand';
  badge.textContent = 'Bloc dynamique';

  const titleEl = document.createElement('strong');
  titleEl.className = 'pc-ed-dynamic__title';
  titleEl.setAttribute('data-prime-dynamic-title', '');

  const summaryEl = document.createElement('p');
  summaryEl.className = 'pc-ed-dynamic__summary';
  summaryEl.setAttribute('data-prime-dynamic-summary', '');

  previewMeta.append(badge, titleEl);
  preview.append(previewMeta, summaryEl);

  // 2. Block type selector
  const selectorField = document.createElement('div');
  selectorField.className = 'pc-field pc-ed-dynamic__selector';
  const selectorLabel = document.createElement('label');
  selectorLabel.className = 'pc-label';
  selectorLabel.textContent = 'Type de bloc dynamique';

  const nameSelect = document.createElement('select');
  nameSelect.className = 'pc-select';
  nameSelect.setAttribute('data-prime-dynamic-name', '');
  list.forEach((item) => {
    const opt = document.createElement('option');
    opt.value = String(item.name);
    opt.textContent = String(item.label || item.name);
    if (state.name === item.name) {
      opt.selected = true;
    }
    nameSelect.append(opt);
  });
  selectorField.append(selectorLabel, nameSelect);

  // 3. Per-block settings form container
  const fieldsHost = document.createElement('div');
  fieldsHost.className = 'pc-ed-dynamic__fields';
  fieldsHost.setAttribute('data-prime-dynamic-fields', '');

  const emitChange = () => {
    wrap.dataset.primeDynamicBlock = state.name;
    titleEl.textContent = state.entry ? String(state.entry.label || state.name) : state.name;
    summaryEl.textContent = formatDynamicSummary(state.entry, state.attrs);
    if (typeof updateData === 'function') {
      updateData({ name: state.name, attrs: { ...state.attrs } });
    }
  };

  const renderFields = () => {
    fieldsHost.innerHTML = '';
    const entry = state.entry;
    const fields = entry && Array.isArray(entry.fields) ? entry.fields : [];

    titleEl.textContent = entry ? String(entry.label || state.name) : state.name;
    summaryEl.textContent = formatDynamicSummary(entry, state.attrs);

    if (fields.length === 0) {
      const emptyNote = document.createElement('p');
      emptyNote.className = 'pc-ed-dynamic__empty pc-text-xs pc-text-muted';
      emptyNote.textContent = 'Ce bloc ne nécessite aucun réglage supplémentaire.';
      fieldsHost.append(emptyNote);
      return;
    }

    const grid = document.createElement('div');
    grid.className = 'pc-ed-dynamic__grid';

    fields.forEach((field) => {
      if (!field || typeof field.name !== 'string') return;
      const key = field.name;
      const currentVal = state.attrs[key] !== undefined ? state.attrs[key] : field.default;

      if (field.type === 'toggle') {
        const toggleWrap = document.createElement('label');
        toggleWrap.className = 'pc-ed-dynamic__toggle';
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = toBoolean(currentVal);
        checkbox.setAttribute('data-prime-dynamic-field', key);
        checkbox.addEventListener('change', () => {
          state.attrs = { ...state.attrs, [key]: checkbox.checked };
          emitChange();
        });
        const labelSpan = document.createElement('span');
        labelSpan.textContent = String(field.label || key);
        toggleWrap.append(checkbox, labelSpan);
        grid.append(toggleWrap);
        return;
      }

      const fieldEl = document.createElement('div');
      fieldEl.className = 'pc-field';
      const labelEl = document.createElement('label');
      labelEl.className = 'pc-label';
      labelEl.textContent = String(field.label || key);

      let control;
      if (field.type === 'select') {
        const select = document.createElement('select');
        select.className = 'pc-select';
        select.setAttribute('data-prime-dynamic-field', key);
        const options = Array.isArray(field.options) ? field.options : [];
        options.forEach((optItem) => {
          const opt = document.createElement('option');
          opt.value = String(optItem.value);
          opt.textContent = String(optItem.label || optItem.value);
          if (String(currentVal ?? '') === String(optItem.value)) {
            opt.selected = true;
          }
          select.append(opt);
        });
        select.addEventListener('change', () => {
          const nextVal = key === 'menu_id' ? Number.parseInt(select.value, 10) || 0 : select.value;
          state.attrs = { ...state.attrs, [key]: nextVal };
          emitChange();
        });
        control = select;
      } else if (field.type === 'number') {
        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'pc-input';
        input.setAttribute('data-prime-dynamic-field', key);
        if (typeof field.min === 'number') input.min = String(field.min);
        if (typeof field.max === 'number') input.max = String(field.max);
        if (typeof field.step === 'number') input.step = String(field.step);
        input.value = String(currentVal ?? field.default ?? 1);
        const syncNumber = () => {
          let parsed = Number.parseInt(input.value, 10);
          if (Number.isNaN(parsed)) {
            parsed = typeof field.default === 'number' ? field.default : 1;
          }
          if (typeof field.min === 'number') parsed = Math.max(field.min, parsed);
          if (typeof field.max === 'number') parsed = Math.min(field.max, parsed);
          state.attrs = { ...state.attrs, [key]: parsed };
          emitChange();
        };
        input.addEventListener('input', syncNumber);
        input.addEventListener('change', () => {
          syncNumber();
          input.value = String(state.attrs[key]);
        });
        control = input;
      } else {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'pc-input';
        input.setAttribute('data-prime-dynamic-field', key);
        if (typeof field.placeholder === 'string') input.placeholder = field.placeholder;
        input.value = typeof currentVal === 'string' ? currentVal : String(currentVal ?? '');
        input.addEventListener('input', () => {
          state.attrs = { ...state.attrs, [key]: input.value };
          emitChange();
        });
        control = input;
      }

      fieldEl.append(labelEl, control);
      grid.append(fieldEl);
    });

    fieldsHost.append(grid);
  };

  nameSelect.addEventListener('change', () => {
    const nextEntry = list.find((item) => item && item.name === nameSelect.value) || null;
    state.name = nameSelect.value;
    state.entry = nextEntry;
    state.attrs = nextEntry && nextEntry.defaults && typeof nextEntry.defaults === 'object'
      ? { ...nextEntry.defaults }
      : {};
    renderFields();
    emitChange();
  });

  renderFields();

  // Persist normalized `{ name, attrs }` if input data was incomplete (e.g. brand-new block)
  if (
    !data
    || typeof data !== 'object'
    || data.name !== state.name
    || !data.attrs
    || typeof data.attrs !== 'object'
  ) {
    if (typeof updateData === 'function') {
      updateData({ name: state.name, attrs: { ...state.attrs } });
    }
  }

  wrap.append(preview, selectorField, fieldsHost);
  return wrap;
}

/**
 * Register the server-rendered `dynamic` block into Prime Editor's client registry.
 */
export function registerDynamicBlock() {
  if (editorApi.getBlock('dynamic')) {
    return;
  }

  editorApi.registerBlock({
    type: 'dynamic',
    title: 'Bloc dynamique',
    description: 'Contenu rendu dynamiquement côté serveur (derniers contenus, termes, menu, recherche ou plugin)',
    category: 'widgets',
    keywords: ['dynamic', 'dynamique', 'widget', 'zone', 'articles', 'recherche', 'categories', 'menu'],
    icon: 'sparkles',
    defaultData: () => {
      const catalog = resolveDynamicCatalog();
      const first = catalog[0] || FALLBACK_CATALOG[0];
      return {
        name: String(first.name),
        attrs: first.defaults && typeof first.defaults === 'object' ? { ...first.defaults } : {},
      };
    },
    render({ data, updateData, config }) {
      const catalog = resolveDynamicCatalog(config);
      return buildDynamicBlockElement({ data, updateData, catalog });
    },
  });
}

/**
 * Initialize quick-insert buttons for dynamic blocks on `/admin/appearance/zones`.
 *
 * @param {Array<any>} editorInstances
 */
export function initZonesAdmin(editorInstances) {
  const page = document.querySelector('[data-prime-zones-page]');
  if (!(page instanceof HTMLElement)) {
    return;
  }

  const editor = Array.isArray(editorInstances) && editorInstances.length > 0 ? editorInstances[0] : null;
  if (!editor) {
    return;
  }

  page.querySelectorAll('[data-prime-zone-add-dynamic]').forEach((btn) => {
    if (!(btn instanceof HTMLButtonElement)) {
      return;
    }
    btn.addEventListener('click', () => {
      const name = btn.dataset.primeZoneAddDynamic;
      if (!name) {
        return;
      }
      let defaults = {};
      if (btn.dataset.primeZoneDynDefaults) {
        try {
          const parsed = JSON.parse(btn.dataset.primeZoneDynDefaults);
          if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
            defaults = parsed;
          }
        } catch {
          defaults = {};
        }
      }
      if (typeof editor.insertBlock === 'function') {
        editor.insertBlock('dynamic', { name, attrs: defaults });
      }
    });
  });
}
