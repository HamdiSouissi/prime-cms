/**
 * Prime CMS — Phase 8 Appearance & SEO JS unit tests (zero dependencies).
 */

import assert from 'node:assert/strict';
import { describe, test } from './run.mjs';
import { resolveCssVarValue } from '../../public/assets/admin/js/customizer.js';
import {
  MAX_MENU_DEPTH,
  flattenMenuTree,
  indentMenuItem,
  moveMenuItem,
  nestMenuItems,
  normalizeDepths,
  outdentMenuItem,
} from '../../public/assets/admin/js/menus-admin.js';
import { computeSerpPreview } from '../../public/assets/admin/js/seo-preview.js';
import {
  buildDynamicBlockElement,
  formatDynamicSummary,
  normalizeDynamicBlockData,
  registerDynamicBlock,
} from '../../public/assets/admin/js/zones-admin.js';
import { editorApi } from '../../public/assets/admin/js/editor/core.js';

describe('Customizer CSS variable resolver', () => {
  test('resolves colors, range units, and font maps cleanly', () => {
    assert.equal(resolveCssVarValue({ type: 'color', value: '#C9441F' }), '#C9441F');
    assert.equal(resolveCssVarValue({ type: 'range', value: '1240', cssUnit: 'px' }), '1240px');
    assert.equal(
      resolveCssVarValue({
        type: 'font',
        value: 'serif',
        cssMap: { sans: 'Inter, sans-serif', serif: 'Georgia, serif' },
      }),
      'Georgia, serif',
    );
  });
});

describe('Menus editor hierarchy helpers (max 3 levels)', () => {
  test('flattens and rebuilds 3-level nested trees without losing order or clamping beyond depth 2', () => {
    assert.equal(MAX_MENU_DEPTH, 2);

    const tree = [
      {
        label: 'Accueil',
        type: 'custom',
        url: '/',
        children: [
          {
            label: 'À propos',
            type: 'entry',
            target_id: 2,
            children: [
              {
                label: 'Équipe',
                type: 'entry',
                target_id: 3,
                children: [{ label: 'Trop profond', type: 'custom', url: '/x' }],
              },
            ],
          },
        ],
      },
    ];

    const flat = flattenMenuTree(tree);
    assert.equal(flat.length, 4);
    assert.deepEqual(
      flat.map((i) => i.depth),
      [0, 1, 2, 2],
    );

    const rebuilt = nestMenuItems(flat);
    assert.equal(rebuilt.length, 1);
    assert.equal(rebuilt[0].children.length, 1);
    assert.equal(rebuilt[0].children[0].children.length, 2);
    assert.equal(rebuilt[0].children[0].children[0].label, 'Équipe');
    assert.equal(rebuilt[0].children[0].children[1].label, 'Trop profond');
  });

  test('keyboard reorder, indent, and outdent enforce valid depth invariants', () => {
    let list = [
      { label: 'Un', type: 'custom', url: '/1', depth: 0 },
      { label: 'Deux', type: 'custom', url: '/2', depth: 0 },
      { label: 'Trois', type: 'custom', url: '/3', depth: 0 },
    ];

    // Cannot indent first element
    assert.equal(indentMenuItem(list, 0)[0].depth, 0);

    // Indent item 1 -> depth 1, then item 2 twice -> depth 2, third indent stays capped at 2
    list = indentMenuItem(list, 1);
    assert.equal(list[1].depth, 1);
    list = indentMenuItem(list, 2);
    assert.equal(list[2].depth, 1);
    list = indentMenuItem(list, 2);
    assert.equal(list[2].depth, 2);
    list = indentMenuItem(list, 2);
    assert.equal(list[2].depth, 2);

    // Outdent item 2 back to 1
    list = outdentMenuItem(list, 2);
    assert.equal(list[2].depth, 1);

    // Move item 2 up to index 0 -> normalizeDepths forces index 0 back to depth 0
    list = moveMenuItem(list, 2, -2);
    assert.equal(list[0].label, 'Trois');
    assert.equal(list[0].depth, 0);

    // Orphaned deep item gets normalized cleanly
    const normalized = normalizeDepths([
      { label: 'A', depth: 2 },
      { label: 'B', depth: 2 },
    ]);
    assert.deepEqual(
      normalized.map((n) => n.depth),
      [0, 1],
    );
  });
});

describe('SEO SERP preview helper', () => {
  test('computes fallback title/description and flags over-limit counters', () => {
    const ok = computeSerpPreview({
      seoTitle: '',
      fallbackTitle: 'Mon article',
      seoDesc: '',
      fallbackDesc: 'Un extrait concis.',
      siteName: 'Prime CMS',
      sep: '—',
    });
    assert.equal(ok.title, 'Mon article — Prime CMS');
    assert.equal(ok.titleStatus, 'ok');
    assert.equal(ok.description, 'Un extrait concis.');
    assert.equal(ok.descriptionStatus, 'ok');

    const over = computeSerpPreview({
      seoTitle: 'A'.repeat(65),
      fallbackTitle: 'Court',
      seoDesc: 'B'.repeat(170),
      fallbackDesc: '',
      siteName: 'Prime CMS',
      sep: '—',
    });
    assert.equal(over.titleStatus, 'warn');
    assert.equal(over.descriptionStatus, 'warn');
  });
});

/**
 * Minimal zero-dependency DOM node mock for testing `zones-admin.js` rendering & mutations in Node.
 */
function createMockDom() {
  class MockElement {
    constructor(tagName) {
      this.tagName = tagName.toUpperCase();
      this.className = '';
      this.dataset = {};
      this.attributes = {};
      this.children = [];
      this.listeners = {};
      this.textContent = '';
      this.value = '';
      this.type = '';
      this.checked = false;
      this.selected = false;
      this.min = '';
      this.max = '';
      this.step = '';
      this.placeholder = '';
    }
    set innerHTML(val) {
      if (val === '') {
        this.children = [];
        this.textContent = '';
      }
    }
    setAttribute(k, v) {
      this.attributes[k] = String(v);
      if (k.startsWith('data-')) {
        const camel = k.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase());
        this.dataset[camel] = String(v);
      }
    }
    getAttribute(k) {
      return this.attributes[k] ?? null;
    }
    append(...nodes) {
      for (const n of nodes) {
        if (n instanceof MockElement) {
          this.children.push(n);
          if (this.tagName === 'SELECT' && n.tagName === 'OPTION' && n.selected) {
            this.value = n.value;
          }
        }
      }
    }
    addEventListener(type, fn) {
      this.listeners[type] = this.listeners[type] || [];
      this.listeners[type].push(fn);
    }
    dispatch(type) {
      for (const fn of this.listeners[type] || []) {
        fn({ target: this });
      }
    }
    findAll(pred, acc = []) {
      if (pred(this)) acc.push(this);
      for (const c of this.children) c.findAll(pred, acc);
      return acc;
    }
    findOne(pred) {
      return this.findAll(pred)[0] || null;
    }
  }

  return {
    createElement: (tag) => new MockElement(tag),
    querySelector: () => null,
  };
}

const TEST_DYNAMIC_CATALOG = [
  {
    name: 'latest_entries',
    label: 'Derniers contenus',
    description: 'Liste récente.',
    defaults: { title: 'Derniers articles', type: 'post', limit: 5, show_image: false, show_excerpt: false },
    fields: [
      { name: 'title', type: 'text', label: 'Titre affiché', default: 'Derniers articles' },
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
      { name: 'limit', type: 'number', label: 'Nombre d’éléments', min: 1, max: 12, default: 5 },
      { name: 'show_image', type: 'toggle', label: 'Afficher l’image mise en avant', default: false },
      { name: 'show_excerpt', type: 'toggle', label: 'Afficher l’extrait', default: false },
    ],
  },
  {
    name: 'term_list',
    label: 'Liste de termes',
    description: 'Taxonomies.',
    defaults: { title: 'Catégories', taxonomy: 'category', show_count: true },
    fields: [
      { name: 'title', type: 'text', label: 'Titre affiché', default: 'Catégories' },
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
    description: 'Menu.',
    defaults: { title: '', menu_id: 2 },
    fields: [
      { name: 'title', type: 'text', label: 'Titre affiché', default: '' },
      {
        name: 'menu_id',
        type: 'select',
        label: 'Menu à afficher',
        options: [
          { value: '1', label: 'Navigation principale' },
          { value: '2', label: 'Pied de page' },
        ],
        default: '2',
      },
    ],
  },
  {
    name: 'search_form',
    label: 'Formulaire de recherche',
    description: 'Recherche.',
    defaults: {},
    fields: [],
  },
  {
    name: 'hello_testimonial',
    label: 'Témoignage au hasard (Hello Prime)',
    description: 'Témoignage.',
    defaults: { title: 'Avis client', min_rating: 4, show_stars: true, style: 'card' },
    fields: [
      { name: 'title', type: 'text', label: 'Titre affiché', default: 'Avis client' },
      { name: 'min_rating', type: 'number', label: 'Note minimale', min: 1, max: 5, default: 4 },
      { name: 'show_stars', type: 'toggle', label: 'Afficher les étoiles', default: true },
      {
        name: 'style',
        type: 'select',
        label: 'Style',
        options: [
          { value: 'card', label: 'Carte complète' },
          { value: 'compact', label: 'Compact' },
        ],
        default: 'card',
      },
    ],
  },
];

describe('Dynamic blocks editor UI ({ name, attrs })', () => {
  test('normalizes { name, attrs } cleanly with catalog defaults and formats French summaries', () => {
    const norm = normalizeDynamicBlockData({ name: 'latest_entries', attrs: { limit: 3, show_image: true } }, TEST_DYNAMIC_CATALOG);
    assert.equal(norm.name, 'latest_entries');
    assert.equal(norm.attrs.type, 'post');
    assert.equal(norm.attrs.limit, 3);
    assert.equal(norm.attrs.show_image, true);
    assert.equal(norm.attrs.show_excerpt, false);

    const summary = formatDynamicSummary(norm.entry, norm.attrs);
    assert.ok(summary.includes('Articles'));
    assert.ok(summary.includes('3 éléments'));
    assert.ok(summary.includes('avec image'));
    assert.ok(summary.includes('sans extrait'));

    const searchSummary = formatDynamicSummary(TEST_DYNAMIC_CATALOG[3], {});
    assert.equal(searchSummary, 'Aucun réglage requis');
  });

  test('renders per-block forms without raw JSON and updates { name, attrs } on every field change', () => {
    const prevDoc = globalThis.document;
    globalThis.document = createMockDom();
    try {
      let latest = null;
      const root = buildDynamicBlockElement({
        data: { name: 'latest_entries', attrs: { title: 'Récents', type: 'post', limit: 4, show_image: false, show_excerpt: false } },
        updateData: (patch) => {
          latest = patch;
        },
        catalog: TEST_DYNAMIC_CATALOG,
      });

      // Zero raw JSON textarea or attributes input
      assert.equal(root.findAll((el) => el.tagName === 'TEXTAREA').length, 0);
      assert.equal(root.findAll((el) => el.className.includes('pc-text-mono')).length, 0);

      // Readable preview title & summary
      const titleEl = root.findOne((el) => el.getAttribute('data-prime-dynamic-title') !== null);
      const summaryEl = root.findOne((el) => el.getAttribute('data-prime-dynamic-summary') !== null);
      assert.equal(titleEl.textContent, 'Derniers contenus');
      assert.ok(summaryEl.textContent.includes('4 éléments'));

      // Change number (clamped to max 12), toggle show_image, toggle show_excerpt, change select type
      const limitInput = root.findOne((el) => el.getAttribute('data-prime-dynamic-field') === 'limit');
      limitInput.value = '25';
      limitInput.dispatch('change');
      assert.equal(latest.name, 'latest_entries');
      assert.equal(latest.attrs.limit, 12);

      const imgToggle = root.findOne((el) => el.getAttribute('data-prime-dynamic-field') === 'show_image');
      imgToggle.checked = true;
      imgToggle.dispatch('change');
      assert.equal(latest.attrs.show_image, true);
      assert.ok(summaryEl.textContent.includes('avec image'));

      const excerptToggle = root.findOne((el) => el.getAttribute('data-prime-dynamic-field') === 'show_excerpt');
      excerptToggle.checked = true;
      excerptToggle.dispatch('change');
      assert.equal(latest.attrs.show_excerpt, true);

      const typeSelect = root.findOne((el) => el.getAttribute('data-prime-dynamic-field') === 'type');
      typeSelect.value = 'page';
      typeSelect.dispatch('change');
      assert.equal(latest.attrs.type, 'page');
      assert.ok(summaryEl.textContent.includes('Pages'));

      // Switch to search_form -> zero settings fields, clean summary
      const nameSelect = root.findOne((el) => el.getAttribute('data-prime-dynamic-name') !== null);
      nameSelect.value = 'search_form';
      nameSelect.dispatch('change');
      assert.equal(latest.name, 'search_form');
      assert.deepEqual(latest.attrs, {});
      assert.equal(root.findAll((el) => el.getAttribute('data-prime-dynamic-field') !== null).length, 0);
      assert.equal(summaryEl.textContent, 'Aucun réglage requis');

      // Switch to menu -> integer menu_id
      nameSelect.value = 'menu';
      nameSelect.dispatch('change');
      const menuSelect = root.findOne((el) => el.getAttribute('data-prime-dynamic-field') === 'menu_id');
      menuSelect.value = '1';
      menuSelect.dispatch('change');
      assert.equal(latest.name, 'menu');
      assert.equal(latest.attrs.menu_id, 1);

      // Switch to plugin block hello_testimonial -> all 4 field types generated from schema
      nameSelect.value = 'hello_testimonial';
      nameSelect.dispatch('change');
      assert.equal(titleEl.textContent, 'Témoignage au hasard (Hello Prime)');
      const styleSelect = root.findOne((el) => el.getAttribute('data-prime-dynamic-field') === 'style');
      styleSelect.value = 'compact';
      styleSelect.dispatch('change');
      assert.equal(latest.name, 'hello_testimonial');
      assert.equal(latest.attrs.style, 'compact');

      // Verify registerDynamicBlock() uses core.js single-object render({ data, updateData, config })
      registerDynamicBlock();
      const def = editorApi.getBlock('dynamic');
      assert.ok(def);
      let callbackPatch = null;
      const renderedFromCore = def.render({
        id: 'dyn_1',
        index: 0,
        blockCount: 1,
        data: { name: 'term_list', attrs: { taxonomy: 'tag', show_count: false } },
        config: { dynamicBlocks: TEST_DYNAMIC_CATALOG },
        updateData: (p) => {
          callbackPatch = p;
        },
      });
      const countToggle = renderedFromCore.findOne((el) => el.getAttribute('data-prime-dynamic-field') === 'show_count');
      assert.equal(countToggle.checked, false);
      countToggle.checked = true;
      countToggle.dispatch('change');
      assert.deepEqual(callbackPatch, {
        name: 'term_list',
        attrs: { title: 'Catégories', taxonomy: 'tag', show_count: true },
      });
    } finally {
      globalThis.document = prevDoc;
    }
  });
});

