/**
 * Prime Editor — Pure ES2022 unit tests (history, paste cleaner, serializer, block registry, utils).
 */

import assert from 'node:assert/strict';
import { describe, test } from './run.mjs';

import { HistoryStack } from '../../public/assets/admin/js/editor/history.js';
import {
  resolveBlockArrowNavigation,
  resolveInsertionFocus,
  resolveListItemKeyAction,
  resolvePrimaryFocusSelector,
  resolveTitleKeyNavigation,
  resolveUndoRedoFocusIndex,
  shouldBlockGlobalSlashShortcut,
} from '../../public/assets/admin/js/editor/navigation.js';
import { parsePastedHtmlToBlocks, parsePlainTextToBlocks } from '../../public/assets/admin/js/editor/paste.js';
import { EditorBlockRegistry } from '../../public/assets/admin/js/editor/registry.js';
import {
  blocksToPlainText,
  convertBlock,
  createDefaultBlock,
  deserializeDocument,
  serializeDocument,
} from '../../public/assets/admin/js/editor/serializer.js';
import {
  countWords,
  estimateReadingTime,
  parseEmbedUrl,
  sanitizeHref,
  sanitizeInlineHtml,
  slugify,
} from '../../public/assets/admin/js/editor/utils.js';

describe('Editor Utils', () => {
  test('slugify handles French accents, ligatures, and punctuation', () => {
    assert.equal(slugify("L'été à Paris : cœur & œuvre !"), 'l-ete-a-paris-coeur-oeuvre');
    assert.equal(slugify('Straße über München'), 'strasse-uber-munchen');
    assert.equal(slugify(''), '');
  });

  test('countWords and estimateReadingTime compute accurate stats', () => {
    assert.equal(countWords('Bonjour Prime CMS, éditeur natif !'), 5);
    assert.equal(countWords(''), 0);
    assert.equal(estimateReadingTime(0), 0);
    assert.equal(estimateReadingTime(120), 1);
    assert.equal(estimateReadingTime(450), 3);
  });

  test('sanitizeHref allows safe schemes and blocks XSS schemes', () => {
    assert.equal(sanitizeHref('https://primecms.org/docs'), 'https://primecms.org/docs');
    assert.equal(sanitizeHref('/admin/content/page'), '/admin/content/page');
    assert.equal(sanitizeHref('mailto:hello@example.com'), 'mailto:hello@example.com');
    assert.equal(sanitizeHref('javascript:alert(1)'), null);
    assert.equal(sanitizeHref('  JaVaScRiPt:alert(1)'), null);
    assert.equal(sanitizeHref('data:text/html,<script>alert(1)</script>'), null);
    assert.equal(sanitizeHref('vbscript:msgbox(1)'), null);
    assert.equal(sanitizeHref('//evil.test/phish'), null);
  });

  test('parseEmbedUrl accepts only YouTube (nocookie) and Vimeo', () => {
    const yt = parseEmbedUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    assert.equal(yt?.provider, 'youtube');
    assert.equal(yt?.embedUrl, 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');

    const ytShort = parseEmbedUrl('https://youtu.be/dQw4w9WgXcQ');
    assert.equal(ytShort?.embedUrl, 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');

    const vimeo = parseEmbedUrl('https://vimeo.com/76979871');
    assert.equal(vimeo?.provider, 'vimeo');
    assert.equal(vimeo?.embedUrl, 'https://player.vimeo.com/video/76979871');

    assert.equal(parseEmbedUrl('https://evil.test/embed/123'), null);
    assert.equal(parseEmbedUrl('javascript:alert(1)'), null);
  });

  test('sanitizeInlineHtml preserves safe tags and strips scripts and handlers', () => {
    const dirty =
      '<strong onclick="alert(1)">Gras</strong> <script>alert(1)</script>' +
      '<a href="https://example.com" target="_blank" style="color:red">Lien</a> ' +
      '<a href="javascript:alert(2)">Bad</a>';
    const clean = sanitizeInlineHtml(dirty);
    assert.match(clean, /<strong>Gras<\/strong>/);
    assert.match(clean, /<a href="https:\/\/example\.com" target="_blank" rel="noopener noreferrer">Lien<\/a>/);
    assert.doesNotMatch(clean, /<script/i);
    assert.doesNotMatch(clean, /onclick/i);
    assert.doesNotMatch(clean, /javascript:/i);
  });
});

describe('HistoryStack (Undo / Redo)', () => {
  test('pushes snapshots, skips duplicates, and handles undo/redo cleanly', () => {
    const history = new HistoryStack([{ id: '1', text: 'A' }], 5);
    assert.equal(history.canUndo(), false);
    assert.equal(history.canRedo(), false);

    // Duplicate push ignored
    assert.equal(history.push([{ id: '1', text: 'A' }]), false);
    assert.equal(history.size(), 1);

    // Push B then C
    assert.equal(history.push([{ id: '1', text: 'B' }]), true);
    assert.equal(history.push([{ id: '1', text: 'C' }]), true);
    assert.equal(history.canUndo(), true);
    assert.equal(history.canRedo(), false);

    // Undo to B then A
    assert.deepEqual(history.undo(), [{ id: '1', text: 'B' }]);
    assert.equal(history.canRedo(), true);
    assert.deepEqual(history.undo(), [{ id: '1', text: 'A' }]);
    assert.equal(history.canUndo(), false);

    // Redo to B, then branch to D (clears C)
    assert.deepEqual(history.redo(), [{ id: '1', text: 'B' }]);
    history.push([{ id: '1', text: 'D' }]);
    assert.equal(history.canRedo(), false);
    assert.deepEqual(history.current(), [{ id: '1', text: 'D' }]);
  });

  test('respects maxSize cap by discarding oldest states', () => {
    const history = new HistoryStack(0, 3);
    history.push(1);
    history.push(2);
    history.push(3);
    assert.equal(history.size(), 3);
    assert.equal(history.undo(), 2);
    assert.equal(history.undo(), 1);
    assert.equal(history.canUndo(), false);
  });
});

describe('Document Serializer & Block Conversion', () => {
  test('deserializes empty, malformed, and valid JSON into canonical schema', () => {
    const empty = deserializeDocument('');
    assert.equal(empty.version, 1);
    assert.equal(empty.blocks.length, 1);
    assert.equal(empty.blocks[0].type, 'paragraph');

    const bad = deserializeDocument('{invalid json');
    assert.equal(bad.blocks.length, 1);

    const valid = deserializeDocument(
      JSON.stringify({
        version: 1,
        blocks: [
          { id: 'h1', type: 'heading', data: { level: 3, text: 'Chapitre 1' } },
          { id: 'p1', type: 'paragraph', data: { text: 'Bonjour <strong>Prime</strong>' } },
        ],
      }),
    );
    assert.equal(valid.blocks.length, 2);
    assert.equal(valid.blocks[0].type, 'heading');

    const json = serializeDocument(valid.blocks);
    const reparsed = JSON.parse(json);
    assert.equal(reparsed.version, 1);
    assert.equal(reparsed.blocks[0].data.anchor, 'chapitre-1');
  });

  test('converts between paragraph, heading, quote, and list cleanly', () => {
    const para = createDefaultBlock('paragraph', { id: 'x1', data: { text: 'Ligne 1<br>Ligne 2' } });
    const list = convertBlock(para, 'list');
    assert.equal(list.type, 'list');
    assert.equal(list.data.items.length, 2);
    assert.equal(list.data.items[0].text, 'Ligne 1');

    const quote = convertBlock(list, 'quote');
    assert.equal(quote.type, 'quote');
    assert.equal(quote.data.text, 'Ligne 1<br>Ligne 2');

    const heading = convertBlock(quote, 'heading');
    assert.equal(heading.type, 'heading');
    assert.equal(heading.data.level, 2);
    assert.equal(heading.data.anchor, 'ligne-1-ligne-2');
  });

  test('extracts clean plain text across heterogeneous blocks', () => {
    const text = blocksToPlainText([
      { id: '1', type: 'heading', data: { level: 2, text: 'Titre principal' } },
      { id: '2', type: 'paragraph', data: { text: 'Avec <strong>gras</strong>.' } },
      { id: '3', type: 'list', data: { style: 'unordered', items: [{ text: 'Item A', children: ['Sous B'] }] } },
    ]);
    assert.match(text, /Titre principal/);
    assert.match(text, /Avec gras\./);
    assert.match(text, /Item A/);
    assert.match(text, /Sous B/);
  });
});

describe('Paste Cleaner (Word / Google Docs / Plain Text)', () => {
  test('strips Word/MSO/style/script junk and extracts structured blocks', () => {
    const wordHtml = `
      <!--[if gte mso 9]><xml><w:WordDocument></w:WordDocument></xml><![endif]-->
      <style>.MsoNormal { font-family: Calibri; }</style>
      <script>alert("xss")</script>
      <h1 style="mso-style-name:Title;color:red">Mon grand titre</h1>
      <p class="MsoNormal" style="margin:0"><span style="font-size:12pt">Un paragraphe avec <b>gras</b> et <a href="https://primecms.org" onclick="evil()">lien</a>.</span></p>
      <ul><li style="mso-list:l0">Premier point</li><li>Second point</li></ul>
      <blockquote><p>Une citation inspirante</p></blockquote>
      <hr>
    `;

    const blocks = parsePastedHtmlToBlocks(wordHtml);
    assert.equal(blocks.length, 5);
    assert.equal(blocks[0].type, 'heading');
    assert.equal(blocks[0].data.level, 2); // h1 clamped to h2
    assert.equal(blocks[0].data.text, 'Mon grand titre');

    assert.equal(blocks[1].type, 'paragraph');
    assert.match(blocks[1].data.text, /<b>gras<\/b>/);
    assert.match(blocks[1].data.text, /<a href="https:\/\/primecms\.org">lien<\/a>/);
    assert.doesNotMatch(blocks[1].data.text, /style=|onclick=|MsoNormal|<script/i);

    assert.equal(blocks[2].type, 'list');
    assert.equal(blocks[2].data.items.length, 2);

    assert.equal(blocks[3].type, 'quote');
    assert.equal(blocks[4].type, 'separator');
  });

  test('converts plain text paragraphs, markdown headings, bullets, and quotes', () => {
    const text = '## Introduction\n\nPremier paragraphe.\n\n- Alpha\n- Beta\n\n> Citation simple';
    const blocks = parsePlainTextToBlocks(text);

    assert.equal(blocks.length, 4);
    assert.equal(blocks[0].type, 'heading');
    assert.equal(blocks[0].data.level, 2);
    assert.equal(blocks[1].type, 'paragraph');
    assert.equal(blocks[2].type, 'list');
    assert.equal(blocks[2].data.items.length, 2);
    assert.equal(blocks[3].type, 'quote');
  });
});

describe('EditorBlockRegistry (Extensibility)', () => {
  test('registers custom plugin blocks and filters by search and capabilities', () => {
    const reg = new EditorBlockRegistry();
    reg.registerBlock({
      type: 'hello-prime/highlight',
      title: 'Mise en avant',
      description: 'Encart spécial du plugin Hello Prime.',
      keywords: ['spotlight', 'badge'],
      render: () => ({}),
    });
    reg.registerBlock({
      type: 'html',
      title: 'HTML libre',
      requiresCapability: 'content.unfiltered_html',
      render: () => ({}),
    });

    assert.equal(reg.has('hello-prime/highlight'), true);

    // Without capability, html is hidden from slash search
    const forAuthor = reg.search('', new Set(['content.create']));
    assert.equal(forAuthor.length, 1);
    assert.equal(forAuthor[0].type, 'hello-prime/highlight');

    // With capability, both appear
    const forAdmin = reg.search('', new Set(['content.unfiltered_html']));
    assert.equal(forAdmin.length, 2);

    // Wildcard admin capability `*` also unlocks restricted blocks
    const forSuperAdmin = reg.search('', new Set(['*']));
    assert.equal(forSuperAdmin.length, 2);

    // Keyword search matches
    const hits = reg.search('spotlight', new Set());
    assert.equal(hits.length, 1);
    assert.equal(hits[0].type, 'hello-prime/highlight');

    // Invalid block name rejected
    assert.throws(() => reg.registerBlock({ type: 'INVALID!', title: 'Bad', render: () => ({}) }));
  });
});

describe('Keyboard Navigation, Focus Resolution & Slash Shortcut Guard (C3)', () => {
  test('title Enter and ArrowDown focus first block or create one when canvas is empty', () => {
    assert.deepEqual(resolveTitleKeyNavigation('Enter', 3), { action: 'focus_block', index: 0, atEnd: false });
    assert.deepEqual(resolveTitleKeyNavigation('ArrowDown', 1), { action: 'focus_block', index: 0, atEnd: false });
    assert.deepEqual(resolveTitleKeyNavigation('Enter', 0), { action: 'create_and_focus_first', index: 0, atEnd: false });
    assert.deepEqual(resolveTitleKeyNavigation('ArrowDown', 0), { action: 'create_and_focus_first', index: 0, atEnd: false });
    assert.deepEqual(resolveTitleKeyNavigation('ArrowUp', 2), { action: 'none', index: -1, atEnd: false });
    assert.deepEqual(resolveTitleKeyNavigation('a', 2), { action: 'none', index: -1, atEnd: false });
  });

  test('ArrowUp at start of first block returns to title; boundary arrows jump between blocks', () => {
    assert.deepEqual(
      resolveBlockArrowNavigation({ key: 'ArrowUp', blockIndex: 0, blockCount: 3, atStart: true, atEnd: false }),
      { action: 'focus_title', index: -1, atEnd: true },
    );
    assert.deepEqual(
      resolveBlockArrowNavigation({ key: 'ArrowUp', blockIndex: 0, blockCount: 3, atStart: false, atEnd: false }),
      { action: 'none', index: -1, atEnd: false },
    );
    assert.deepEqual(
      resolveBlockArrowNavigation({ key: 'ArrowUp', blockIndex: 2, blockCount: 3, atStart: true, atEnd: false }),
      { action: 'focus_block', index: 1, atEnd: true },
    );
    assert.deepEqual(
      resolveBlockArrowNavigation({ key: 'ArrowDown', blockIndex: 1, blockCount: 3, atStart: false, atEnd: true }),
      { action: 'focus_block', index: 2, atEnd: false },
    );
    assert.deepEqual(
      resolveBlockArrowNavigation({ key: 'ArrowDown', blockIndex: 2, blockCount: 3, atStart: false, atEnd: true }),
      { action: 'none', index: -1, atEnd: false },
    );
  });

  test('list item keyboard navigation inserts, removes, and moves cleanly across items and blocks', () => {
    assert.deepEqual(
      resolveListItemKeyAction({
        key: 'Enter',
        shiftKey: false,
        itemIndex: 0,
        itemCount: 1,
        isEmpty: false,
        atStart: false,
        atEnd: true,
        blockIndex: 2,
        blockCount: 3,
      }),
      { action: 'insert_item', itemIndex: 1, blockIndex: 2, atEnd: false },
    );

    assert.deepEqual(
      resolveListItemKeyAction({
        key: 'Enter',
        shiftKey: true,
        itemIndex: 0,
        itemCount: 1,
        isEmpty: false,
        atStart: false,
        atEnd: true,
        blockIndex: 2,
        blockCount: 3,
      }),
      { action: 'none', itemIndex: 0, blockIndex: 2, atEnd: false },
    );

    assert.deepEqual(
      resolveListItemKeyAction({
        key: 'Backspace',
        itemIndex: 1,
        itemCount: 2,
        isEmpty: true,
        atStart: true,
        atEnd: true,
        blockIndex: 2,
        blockCount: 3,
      }),
      { action: 'remove_item', itemIndex: 0, blockIndex: 2, atEnd: true },
    );

    // ArrowUp on first item of block 0 returns to title
    assert.deepEqual(
      resolveListItemKeyAction({
        key: 'ArrowUp',
        itemIndex: 0,
        itemCount: 2,
        isEmpty: false,
        atStart: true,
        atEnd: false,
        blockIndex: 0,
        blockCount: 2,
      }),
      { action: 'focus_title', itemIndex: -1, blockIndex: -1, atEnd: true },
    );

    // ArrowDown on last item jumps to next block
    assert.deepEqual(
      resolveListItemKeyAction({
        key: 'ArrowDown',
        itemIndex: 1,
        itemCount: 2,
        isEmpty: false,
        atStart: false,
        atEnd: true,
        blockIndex: 0,
        blockCount: 2,
      }),
      { action: 'focus_block', itemIndex: -1, blockIndex: 1, atEnd: false },
    );
  });

  test('slash menu insertion computes replace/after/append focus and appends paragraph after separator', () => {
    assert.deepEqual(
      resolveInsertionFocus({ mode: 'replace', contextIndex: 1, blockCount: 2, insertedType: 'heading' }),
      { insertIndex: 1, replaceExisting: true, focusIndex: 1, appendTrailingParagraph: false },
    );
    assert.deepEqual(
      resolveInsertionFocus({ mode: 'after', contextIndex: 0, blockCount: 2, insertedType: 'list' }),
      { insertIndex: 1, replaceExisting: false, focusIndex: 1, appendTrailingParagraph: false },
    );
    assert.deepEqual(
      resolveInsertionFocus({ mode: 'replace', contextIndex: 1, blockCount: 2, insertedType: 'separator' }),
      { insertIndex: 1, replaceExisting: true, focusIndex: 2, appendTrailingParagraph: true },
    );
    assert.deepEqual(
      resolveInsertionFocus({ mode: 'append', contextIndex: 0, blockCount: 3, insertedType: 'code' }),
      { insertIndex: 3, replaceExisting: false, focusIndex: 3, appendTrailingParagraph: false },
    );
  });

  test('resolves primary focus selectors and clamps undo/redo focus safely', () => {
    assert.equal(resolvePrimaryFocusSelector('paragraph'), '.pc-ed-p-input');
    assert.equal(resolvePrimaryFocusSelector('heading'), '.pc-ed-h-input');
    assert.equal(resolvePrimaryFocusSelector('list'), '.pc-ed-list-input');
    assert.equal(resolvePrimaryFocusSelector('code'), '.pc-ed-code-textarea');
    assert.match(resolvePrimaryFocusSelector('custom/block'), /\.pc-ed-richtext/);

    assert.equal(resolveUndoRedoFocusIndex(2, 4), 2);
    assert.equal(resolveUndoRedoFocusIndex(5, 2), 1);
    assert.equal(resolveUndoRedoFocusIndex(null, 3), 2);
    assert.equal(resolveUndoRedoFocusIndex(0, 0), null);
  });

  test('global slash command-palette shortcut is blocked in inputs, contenteditable, and editor root', () => {
    const mockEl = ({ tagName = 'DIV', isContentEditable = false, attrs = {}, matchClosest = false } = {}) => ({
      nodeType: 1,
      tagName,
      isContentEditable,
      getAttribute: (k) => (Object.prototype.hasOwnProperty.call(attrs, k) ? attrs[k] : null),
      closest: () => (matchClosest ? {} : null),
    });

    assert.equal(shouldBlockGlobalSlashShortcut(mockEl({ tagName: 'INPUT' })), true);
    assert.equal(shouldBlockGlobalSlashShortcut(mockEl({ tagName: 'TEXTAREA' })), true);
    assert.equal(shouldBlockGlobalSlashShortcut(mockEl({ tagName: 'SELECT' })), true);
    assert.equal(shouldBlockGlobalSlashShortcut(mockEl({ tagName: 'P', isContentEditable: true })), true);
    assert.equal(shouldBlockGlobalSlashShortcut(mockEl({ tagName: 'DIV', attrs: { contenteditable: 'true' } })), true);
    assert.equal(shouldBlockGlobalSlashShortcut(mockEl({ tagName: 'DIV', attrs: { contenteditable: '' } })), true);
    assert.equal(shouldBlockGlobalSlashShortcut(mockEl({ tagName: 'DIV', matchClosest: true })), true);

    // Text node nested inside an editable element must also block `/`
    const textNode = { nodeType: 3, parentElement: mockEl({ tagName: 'P', isContentEditable: true }) };
    assert.equal(shouldBlockGlobalSlashShortcut(textNode), true);

    // Active element inside editor blocks `/` even if event.target is body
    const bodyEl = mockEl({ tagName: 'BODY' });
    const editorEl = mockEl({ tagName: 'DIV', matchClosest: true });
    assert.equal(shouldBlockGlobalSlashShortcut(bodyEl, editorEl), true);

    // Outside any editable context (e.g., admin dashboard body/link), `/` remains allowed
    assert.equal(shouldBlockGlobalSlashShortcut(bodyEl, bodyEl), false);
    assert.equal(shouldBlockGlobalSlashShortcut(mockEl({ tagName: 'BUTTON' }), bodyEl), false);
  });
});

