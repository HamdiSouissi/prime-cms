/**
 * Prime Media — Zero-dependency JS unit tests for SelectionState and UploadQueue.
 */

import assert from 'node:assert/strict';
import { describe, test } from './run.mjs';
import { SelectionState } from '../../public/assets/admin/js/media/selection.js';
import { UploadQueue } from '../../public/assets/admin/js/media/upload-queue.js';

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
