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
