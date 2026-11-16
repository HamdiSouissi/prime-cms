/**
 * Prime CMS Admin — Dynamic block registration for Prime Editor & Zones sidebar quick-insert.
 */

import { editorApi } from './editor/core.js';

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
    description: 'Contenu rendu dynamiquement côté serveur (articles récents, recherche, catégories, plugin)',
    category: 'widgets',
    keywords: ['dynamic', 'dynamique', 'widget', 'zone', 'articles', 'recherche', 'categories'],
    icon: 'blocks',
    defaultData: () => ({ name: 'recent_posts', attributes: {} }),
    render(block, { onChange }) {
      const wrap = document.createElement('div');
      wrap.className = 'pc-ed-dynamic';

      const data = block.data && typeof block.data === 'object' ? block.data : { name: 'recent_posts', attributes: {} };
      const configScript = document.querySelector('[data-prime-editor-config]');
      let catalog = [];
      if (configScript instanceof HTMLScriptElement) {
        try {
          const parsed = JSON.parse(configScript.textContent || '{}');
          if (Array.isArray(parsed.dynamicBlocks)) {
            catalog = parsed.dynamicBlocks;
          }
        } catch {
          catalog = [];
        }
      }

      const row = document.createElement('div');
      row.className = 'pc-grid-2';

      const nameField = document.createElement('div');
      nameField.className = 'pc-field';
      const nameLabel = document.createElement('label');
      nameLabel.className = 'pc-label';
      nameLabel.textContent = 'Bloc dynamique';

      let nameControl;
      if (catalog.length > 0) {
        const select = document.createElement('select');
        select.className = 'pc-select';
        catalog.forEach((entry) => {
          const opt = document.createElement('option');
          opt.value = String(entry.name);
          opt.textContent = String(entry.label || entry.name);
          if (data.name === entry.name) {
            opt.selected = true;
          }
          select.append(opt);
        });
        select.addEventListener('change', () => {
          onChange({ ...data, name: select.value });
        });
        nameControl = select;
      } else {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'pc-input';
        input.value = String(data.name || '');
        input.addEventListener('input', () => {
          onChange({ ...data, name: input.value.trim() });
        });
        nameControl = input;
      }
      nameField.append(nameLabel, nameControl);

      const attrsField = document.createElement('div');
      attrsField.className = 'pc-field';
      const attrsLabel = document.createElement('label');
      attrsLabel.className = 'pc-label';
      attrsLabel.textContent = 'Attributs (JSON)';
      const attrsInput = document.createElement('input');
      attrsInput.type = 'text';
      attrsInput.className = 'pc-input pc-text-mono';
      attrsInput.value = JSON.stringify(data.attributes || {});
      attrsInput.addEventListener('change', () => {
        try {
          const parsed = JSON.parse(attrsInput.value || '{}');
          if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
            attrsInput.removeAttribute('aria-invalid');
            onChange({ ...data, attributes: parsed });
          }
        } catch {
          attrsInput.setAttribute('aria-invalid', 'true');
        }
      });
      attrsField.append(attrsLabel, attrsInput);

      row.append(nameField, attrsField);
      wrap.append(row);
      return wrap;
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
          if (parsed && typeof parsed === 'object') {
            defaults = parsed;
          }
        } catch {
          defaults = {};
        }
      }
      if (typeof editor.insertBlock === 'function') {
        editor.insertBlock('dynamic', { name, attributes: defaults });
      } else if (Array.isArray(editor.blocks)) {
        editor.blocks.push({
          id: `dyn_${Date.now().toString(36)}`,
          type: 'dynamic',
          data: { name, attributes: defaults },
        });
        if (typeof editor.render === 'function') {
          editor.render();
        }
        if (typeof editor.syncToInput === 'function') {
          editor.syncToInput();
        }
      }
    });
  });
}
