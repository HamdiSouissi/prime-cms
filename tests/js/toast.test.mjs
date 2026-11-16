/**
 * Prime CMS — L0 Toast defensive API & codebase signature contract tests.
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, test } from './run.mjs';
import { normalizeToastArgs, showToast } from '../../public/assets/admin/js/toast.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(__dirname, '../..');

function collectJsFiles(dir) {
  const results = [];
  if (!fs.existsSync(dir)) return results;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      results.push(...collectJsFiles(full));
    } else if (entry.name.endsWith('.js')) {
      results.push(full);
    }
  }
  return results;
}

describe('Prime Toast — L0 defensive normalization & zero [object Object]', () => {
  test('normalizeToastArgs handles standard (message, type, options) signature', () => {
    const res = normalizeToastArgs('Enregistré avec succès.', 'success', { title: 'Bravo', duration: 3000 });
    assert.equal(res.message, 'Enregistré avec succès.');
    assert.equal(res.type, 'success');
    assert.equal(res.title, 'Bravo');
    assert.equal(res.duration, 3000);
  });

  test('normalizeToastArgs defensively converts legacy object payloads { title, message, type }', () => {
    const titleOnly = normalizeToastArgs({ type: 'danger', title: 'Création impossible.' });
    assert.equal(titleOnly.message, 'Création impossible.');
    assert.equal(titleOnly.type, 'danger');
    assert.equal(titleOnly.title, undefined);

    const messageOnly = normalizeToastArgs({ type: 'success', message: 'Terme ajouté.' });
    assert.equal(messageOnly.message, 'Terme ajouté.');
    assert.equal(messageOnly.type, 'success');

    const both = normalizeToastArgs({ type: 'warning', title: 'Attention', message: 'Doublon détecté.' });
    assert.equal(both.title, 'Attention');
    assert.equal(both.message, 'Doublon détecté.');
    assert.equal(both.type, 'warning');
  });

  test('showToast never renders [object Object] even when given an object payload', () => {
    const created = [];
    const makeEl = (tag) => {
      const attrs = {};
      const children = [];
      const el = {
        tagName: tag.toUpperCase(),
        className: '',
        textContent: '',
        children,
        setAttribute(k, v) {
          attrs[k] = String(v);
        },
        getAttribute(k) {
          return attrs[k] ?? null;
        },
        appendChild(child) {
          children.push(child);
          return child;
        },
        addEventListener() {},
        remove() {},
      };
      created.push(el);
      return el;
    };

    const stack = makeEl('div');
    const prevDoc = globalThis.document;
    const prevWin = globalThis.window;
    const prevHtml = globalThis.HTMLElement;

    class FakeHTMLElement {}
    Object.setPrototypeOf(stack, FakeHTMLElement.prototype);

    globalThis.HTMLElement = FakeHTMLElement;
    globalThis.document = {
      querySelector: (sel) => (sel === '[data-prime-toast-stack]' ? stack : null),
      createElement: (tag) => makeEl(tag),
      createElementNS: (_ns, tag) => makeEl(tag),
      body: { appendChild() {} },
    };
    globalThis.window = {
      setTimeout: () => 1,
      clearTimeout: () => {},
    };

    try {
      const el = showToast({ type: 'success', title: '« Actualités » ajouté.' });
      assert.equal(el.className, 'pc-toast pc-toast--success');
      const texts = created.map((n) => n.textContent).filter(Boolean);
      assert.ok(texts.includes('« Actualités » ajouté.'));
      assert.ok(!texts.some((t) => t.includes('[object Object]')));
    } finally {
      globalThis.document = prevDoc;
      globalThis.window = prevWin;
      globalThis.HTMLElement = prevHtml;
    }
  });

  test('no admin JS file calls Prime.toast with an object literal as first argument', () => {
    const files = collectJsFiles(path.join(REPO_ROOT, 'public/assets/admin/js'));
    assert.ok(files.length > 0);
    for (const file of files) {
      const source = fs.readFileSync(file, 'utf8');
      assert.doesNotMatch(
        source,
        /\.toast\s*(?:\?\.)?\(\s*\{/,
        `${path.relative(REPO_ROOT, file)} must not call toast({ ... }) with an object`,
      );
    }
  });
});
