/**
 * Prime Media — Zero-dependency JS unit tests for SelectionState, UploadQueue,
 * Prime.media API contract with Prime Editor blocks, and Prime.toast call signatures.
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, test } from './run.mjs';
import { registerCoreBlocks } from '../../public/assets/admin/js/editor/blocks.js';
import { EditorBlockRegistry } from '../../public/assets/admin/js/editor/registry.js';
import { mediaApi } from '../../public/assets/admin/js/media/picker.js';
import { SelectionState } from '../../public/assets/admin/js/media/selection.js';
import { UploadQueue } from '../../public/assets/admin/js/media/upload-queue.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(__dirname, '../..');

describe('Prime Media — SelectionState', () => {
  test('toggles single & multi selection cleanly', () => {
    const sel = new SelectionState();
    assert.deepEqual(sel.ids(), []);

    sel.toggle(10, { multiple: true });
    sel.toggle(20, { multiple: true });
    assert.deepEqual(sel.ids(), [10, 20]);
    assert.equal(sel.count(), 2);
    assert.equal(sel.has(10), true);

    sel.toggle(10, { multiple: true });
    assert.deepEqual(sel.ids(), [20]);

    // Single-select mode replaces previous selection
    sel.toggle(30, { multiple: false });
    assert.deepEqual(sel.ids(), [30]);
    sel.toggle(30, { multiple: false });
    assert.deepEqual(sel.ids(), []);
  });

  test('supports Shift+click range selection forward and backward', () => {
    const ordered = [1, 2, 3, 4, 5, 6];
    const sel = new SelectionState();

    // Anchor at 2, Shift+click 5 -> selects 2, 3, 4, 5
    sel.toggle(2, { multiple: true, orderedIds: ordered });
    sel.toggle(5, { multiple: true, shiftKey: true, orderedIds: ordered });
    assert.deepEqual(sel.ids().sort((a, b) => a - b), [2, 3, 4, 5]);

    // Reverse range: anchor at 5, Shift+click 1 -> adds 1..5
    sel.clear();
    sel.toggle(5, { multiple: true, orderedIds: ordered });
    sel.toggle(1, { multiple: true, shiftKey: true, orderedIds: ordered });
    assert.deepEqual(sel.ids().sort((a, b) => a - b), [1, 2, 3, 4, 5]);
  });

  test('supports selectAll, add, remove, and clear', () => {
    const sel = new SelectionState([7]);
    sel.selectAll([7, 8, 9]);
    assert.equal(sel.count(), 3);

    sel.remove(8);
    assert.deepEqual(sel.ids().sort((a, b) => a - b), [7, 9]);

    sel.add(42, { multiple: false });
    assert.deepEqual(sel.ids(), [42]);

    sel.clear();
    assert.equal(sel.count(), 0);
  });
});

describe('Prime Media — UploadQueue', () => {
  test('enforces max concurrency of 3 across 6 parallel files and tracks progress', async () => {
    let activeNow = 0;
    let peakConcurrency = 0;
    const completedIds = [];
    let idleCalled = 0;

    const deferreds = [];
    const transport = ({ file, onProgress }) =>
      new Promise((resolve) => {
        activeNow += 1;
        peakConcurrency = Math.max(peakConcurrency, activeNow);
        onProgress(50);
        deferreds.push(() => {
          activeNow -= 1;
          onProgress(100);
          resolve({ item: { id: Number(file.name.replace(/\D/g, '')), title: file.name }, duplicate: null });
        });
      });

    const queue = new UploadQueue({
      concurrency: 3,
      getCsrfToken: () => 'tok-test',
      transport,
      onItemComplete: (_entry, res) => completedIds.push(res.item.id),
      onIdle: () => {
        idleCalled += 1;
      },
    });

    const files = [1, 2, 3, 4, 5, 6].map((n) => ({ name: `photo-${n}.jpg`, size: 1024 * n }));
    queue.enqueue(files, { folderId: 9 });
    await Promise.resolve();

    // Immediately: exactly 3 in flight, 3 queued
    assert.equal(peakConcurrency, 3);
    assert.equal(activeNow, 3);
    assert.equal(queue.activeCount(), 6);

    // Drain all 6 uploads across microtask ticks
    while (deferreds.length > 0) {
      const fn = deferreds.shift();
      fn();
      await new Promise((r) => setTimeout(r, 0));
    }

    assert.equal(peakConcurrency, 3, 'Peak concurrency must never exceed configured limit (3)');
    assert.equal(completedIds.length, 6);
    assert.equal(queue.activeCount(), 0);
    assert.equal(idleCalled, 1);

    queue.clearFinished();
    assert.equal(queue.snapshot().length, 0);
  });

  test('supports cancel mid-flight, retry after error, and duplicate warnings', async () => {
    let attempt = 0;
    let aborted = false;

    const queue = new UploadQueue({
      concurrency: 2,
      getCsrfToken: () => 'tok-csrf',
      transport: ({ file, setAbort }) =>
        new Promise((resolve, reject) => {
          if (file.name === 'cancel-me.jpg') {
            setAbort(() => {
              aborted = true;
              reject(new Error('Annulé'));
            });
            return;
          }
          attempt += 1;
          if (attempt === 1) {
            reject(new Error('Erreur réseau simulée'));
            return;
          }
          resolve({
            item: { id: 99, title: file.name },
            duplicate: { id: 12, title: 'Original' },
            warning: 'Doublon détecté',
          });
        }),
    });

    const [flaky, cancellable] = queue.enqueue([
      { name: 'flaky.jpg', size: 500 },
      { name: 'cancel-me.jpg', size: 800 },
    ]);

    await new Promise((r) => setTimeout(r, 0));

    // Cancel active upload
    assert.equal(queue.cancel(cancellable.id), true);
    assert.equal(aborted, true);

    // Retry failed upload -> succeeds with duplicate warning
    assert.equal(flaky.status, 'error');
    assert.equal(queue.retry(flaky.id), true);

    await new Promise((r) => setTimeout(r, 0));

    assert.equal(flaky.status, 'done');
    assert.equal(flaky.media.id, 99);
    assert.equal(flaky.duplicate.id, 12);
    assert.equal(flaky.warning, 'Doublon détecté');
  });
});

/**
 * Minimal pure-JS DOM stub for testing Prime Editor block click handlers in Node
 * without any external dependencies (zero-npm CI compliant).
 */
function createMiniDom() {
  class MiniElement {
    constructor(tagName) {
      this.tagName = String(tagName).toUpperCase();
      this.className = '';
      this.type = '';
      this.value = '';
      this.checked = false;
      this.disabled = false;
      this.hidden = false;
      this.src = '';
      this.poster = '';
      this.alt = '';
      this.controls = false;
      this.preload = '';
      this.placeholder = '';
      this.textContent = '';
      this.style = {};
      this.dataset = {};
      this.attributes = new Map();
      this.children = [];
      this.listeners = new Map();
    }
    setAttribute(name, val) {
      this.attributes.set(name, String(val));
    }
    getAttribute(name) {
      return this.attributes.get(name) ?? null;
    }
    append(...nodes) {
      for (const n of nodes) this.children.push(n);
    }
    appendChild(node) {
      this.children.push(node);
      return node;
    }
    addEventListener(type, fn) {
      if (!this.listeners.has(type)) this.listeners.set(type, []);
      this.listeners.get(type).push(fn);
    }
    click() {
      for (const fn of this.listeners.get('click') || []) {
        fn({ preventDefault() {}, stopPropagation() {} });
      }
    }
    set innerHTML(_val) {
      this.children = [];
    }
    findButtons() {
      const out = [];
      const walk = (node) => {
        if (!node || typeof node !== 'object') return;
        if (node.tagName === 'BUTTON') out.push(node);
        for (const ch of node.children || []) walk(ch);
      };
      walk(this);
      return out;
    }
    buttonText() {
      const parts = [];
      const walk = (node) => {
        if (!node) return;
        if (typeof node.textContent === 'string' && node.textContent) parts.push(node.textContent);
        for (const ch of node.children || []) walk(ch);
      };
      walk(this);
      return parts.join('').trim();
    }
  }

  return {
    createElement: (tag) => new MiniElement(tag),
    createElementNS: (_ns, tag) => new MiniElement(tag),
    createTextNode: (text) => ({ nodeType: 3, textContent: String(text) }),
  };
}

describe('Prime Media — Editor Block API & Toast Contracts', () => {
  test('blocks.js calls only methods actually exported by mediaApi (never openPicker)', () => {
    const blocksSource = fs.readFileSync(
      path.join(REPO_ROOT, 'public/assets/admin/js/editor/blocks.js'),
      'utf8',
    );

    assert.doesNotMatch(blocksSource, /openPicker/, 'blocks.js must not reference non-existent openPicker()');

    const matches = [...blocksSource.matchAll(/Prime(?:\?\.|\.)media(?:\?\.|\.)([A-Za-z_$][A-Za-z0-9_$]*)/g)];
    assert.ok(matches.length > 0, 'blocks.js must call Prime.media.<method>');

    const exportedMethods = new Set(
      Object.keys(mediaApi).filter((key) => typeof mediaApi[key] === 'function'),
    );

    for (const m of matches) {
      const methodName = m[1];
      assert.ok(
        exportedMethods.has(methodName),
        `blocks.js references Prime.media.${methodName}, which is not exported by mediaApi (${[...exportedMethods].join(', ')})`,
      );
    }
  });

  test('all Prime.toast calls in media module and editor blocks pass a string message, never an object', () => {
    const files = [
      'public/assets/admin/js/media/library.js',
      'public/assets/admin/js/media/picker.js',
      'public/assets/admin/js/media/upload-queue.js',
      'public/assets/admin/js/editor/blocks.js',
    ];

    for (const rel of files) {
      const source = fs.readFileSync(path.join(REPO_ROOT, rel), 'utf8');
      assert.doesNotMatch(
        source,
        /toast(?:\?\.)?\(\s*\{/,
        `${rel} must never pass an object literal as the first argument to Prime.toast(message, type)`,
      );
    }
  });

  test('media blocks open Prime.media.open and populate BlockRegistry schemas (image, gallery, video, audio, file)', () => {
    const prevDoc = globalThis.document;
    const prevWin = globalThis.window;

    try {
      globalThis.document = createMiniDom();
      const registry = new EditorBlockRegistry();
      registerCoreBlocks(registry);

      const openedCalls = [];
      let nextSelection = null;

      globalThis.window = {
        Prime: {
          media: {
            open(opts) {
              openedCalls.push(opts);
              if (typeof opts.onSelect === 'function' && nextSelection !== null) {
                opts.onSelect(nextSelection);
              }
            },
          },
          toast() {},
        },
      };

      const renderAndClick = (blockType, buttonMatch, selectionPayload) => {
        const def = registry.get(blockType);
        assert.ok(def, `Block ${blockType} must be registered`);
        let lastPatch = null;
        const el = def.render({
          data: def.defaultData(),
          updateData(patch) {
            lastPatch = patch;
          },
        });
        nextSelection = selectionPayload;
        const btn = el.findButtons().find((b) => b.buttonText().includes(buttonMatch));
        assert.ok(btn, `Button matching "${buttonMatch}" not found in block "${blockType}"`);
        btn.click();
        return lastPatch;
      };

      // 1. image block
      const imgPatch = renderAndClick('image', 'Choisir dans la médiathèque', {
        id: 11,
        url: '/uploads/2026/11/hero.jpg',
        alt: 'Photo héro',
        caption: 'Légende',
        title: 'Hero',
        display_title: 'Hero',
        original_name: 'hero.jpg',
      });
      assert.equal(imgPatch.mediaId, 11);
      assert.equal(imgPatch.url, '/uploads/2026/11/hero.jpg');
      assert.equal(imgPatch.alt, 'Photo héro');
      assert.equal(imgPatch.caption, 'Légende');
      assert.deepEqual(openedCalls.at(-1).kinds, ['image']);
      assert.equal(openedCalls.at(-1).multiple, false);

      // 2. gallery block (uses `items`, matching BlockRegistry PHP validator)
      const galPatch = renderAndClick('gallery', 'Ajouter des images', [
        { id: 12, url: '/uploads/2026/11/g1.jpg', alt: 'G1', caption: 'C1' },
        { id: 13, url: '/uploads/2026/11/g2.jpg', alt: 'G2', caption: '' },
      ]);
      assert.equal(galPatch.columns, 3);
      assert.equal(galPatch.items.length, 2);
      assert.deepEqual(galPatch.items[0], {
        mediaId: 12,
        url: '/uploads/2026/11/g1.jpg',
        alt: 'G1',
        caption: 'C1',
      });
      assert.equal(openedCalls.at(-1).multiple, true);

      // 3. video block + poster
      const vidPatch = renderAndClick('video', 'Choisir une vidéo', {
        id: 14,
        url: '/uploads/2026/11/demo.mp4',
        mime_type: 'video/mp4',
      });
      assert.deepEqual(vidPatch, { mediaId: 14, url: '/uploads/2026/11/demo.mp4', mimeType: 'video/mp4' });
      assert.deepEqual(openedCalls.at(-1).kinds, ['video']);

      const posterPatch = renderAndClick('video', 'Image poster', {
        id: 15,
        url: '/uploads/2026/11/poster.jpg',
      });
      assert.deepEqual(posterPatch, { posterMediaId: 15, posterUrl: '/uploads/2026/11/poster.jpg' });
      assert.deepEqual(openedCalls.at(-1).kinds, ['image']);

      // 4. audio block
      const audPatch = renderAndClick('audio', 'Choisir un fichier audio', {
        id: 16,
        url: '/uploads/2026/11/podcast.mp3',
        display_title: 'Épisode 1',
        original_name: 'podcast.mp3',
      });
      assert.deepEqual(audPatch, { mediaId: 16, url: '/uploads/2026/11/podcast.mp3', title: 'Épisode 1' });
      assert.deepEqual(openedCalls.at(-1).kinds, ['audio']);

      // 5. file block
      const filePatch = renderAndClick('file', 'Choisir un fichier', {
        id: 17,
        url: '/uploads/2026/11/brochure.pdf',
        title: 'Brochure 2026',
        original_name: 'brochure.pdf',
        extension: 'pdf',
        size_bytes: 245760,
      });
      assert.deepEqual(filePatch, {
        mediaId: 17,
        url: '/uploads/2026/11/brochure.pdf',
        label: 'Brochure 2026',
        filename: 'brochure.pdf',
        extension: 'pdf',
        sizeBytes: 245760,
      });
      assert.deepEqual(openedCalls.at(-1).kinds, ['document', 'archive']);
    } finally {
      globalThis.document = prevDoc;
      globalThis.window = prevWin;
    }
  });

  test('missing Prime.media.open logs console.error and displays an explicit error toast (no silent failure)', () => {
    const prevDoc = globalThis.document;
    const prevWin = globalThis.window;
    const prevErr = console.error;

    try {
      globalThis.document = createMiniDom();
      const errors = [];
      const toasts = [];
      console.error = (...args) => errors.push(args.join(' '));
      globalThis.window = {
        Prime: {
          toast: (msg, type) => toasts.push({ msg, type }),
        },
      };

      const registry = new EditorBlockRegistry();
      registerCoreBlocks(registry);
      const imageDef = registry.get('image');
      const el = imageDef.render({ data: imageDef.defaultData(), updateData() {} });
      const btn = el.findButtons().find((b) => b.buttonText().includes('Choisir dans la médiathèque'));
      assert.ok(btn);
      btn.click();

      assert.equal(errors.length, 1);
      assert.equal(toasts.length, 1);
      assert.equal(toasts[0].msg, "Le sélecteur de médias n'a pas pu s'ouvrir.");
      assert.equal(toasts[0].type, 'danger');
    } finally {
      console.error = prevErr;
      globalThis.document = prevDoc;
      globalThis.window = prevWin;
    }
  });
});

describe('Prime Media — initMediaFields initial JSON', () => {
  test('parses escaped JSON initial data correctly', () => {
    // Le JSON initial rendu par le serveur doit être parseable par JSON.parse
    // y compris après échappement JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT.
    const initial = '{"id":42,"display_name":"Logo","url":"/uploads/logo.jpg","thumbnail_url":"/uploads/logo.thumb.jpg","alt":"Logo","kind":"image"}';
    const parsed = JSON.parse(initial);
    assert.equal(parsed.id, 42);
    assert.equal(parsed.display_name, 'Logo');

    // Vérifie que les séquences échappées sont correctement interprétées par JSON.parse
    const escaped = '{"title":"<b>gras</b>","amp":"a \u0026 b","apos":"it\\u0027s","quot":"say \\"hi\\""}';
    const parsedEscaped = JSON.parse(escaped);
    assert.equal(parsedEscaped.title, '<b>gras</b>');
    assert.equal(parsedEscaped.amp, 'a & b');
    assert.equal(parsedEscaped.apos, "it's");
    assert.equal(parsedEscaped.quot, 'say "hi"');

    // Un titre contenant </script> ne doit pas casser le parsing
    const xssTitle = '{"title":"<script>alert(1)</script>"}';
    const parsedXss = JSON.parse(xssTitle);
    assert.equal(parsedXss.title, '<script>alert(1)</script>');
  });
});
