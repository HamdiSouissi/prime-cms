/**
 * Prime Starter — Markdown editor (`prime-starter-markdown`).
 *
 * The core saves `content_json` only: each change rewrites it as a one-block document,
 * `{version: 1, blocks: [{type: 'prime-starter/markdown', data: {markdown}}]}`, which the
 * server validates and renders like any other block.
 */

const BLOCK = 'prime-starter/markdown';

export function toDocument(markdown) {
  return JSON.stringify({ version: 1, blocks: [{ id: 'b_markdown', type: BLOCK, data: { markdown } }] });
}

export function initMarkdownEditor(root = document) {
  root.querySelectorAll('[data-prime-starter-markdown]').forEach((editor) => {
    const input = editor.querySelector('[data-prime-starter-markdown-input]');
    const target = editor.querySelector('[data-prime-custom-editor-input]');
    if (!input || !target || editor.dataset.ready === '1') {
      return;
    }
    editor.dataset.ready = '1';

    const sync = () => {
      target.value = toDocument(input.value);
      target.dispatchEvent(new Event('input', { bubbles: true }));
    };
    input.addEventListener('input', sync);
    // A document written with Prime Editor becomes Markdown on the first save, not on load.
    input.form?.addEventListener('submit', sync);
  });
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initMarkdownEditor());
  } else {
    initMarkdownEditor();
  }
}
