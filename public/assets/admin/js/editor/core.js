/**
 * Prime Editor — Main block editor controller (ES2022, zero dependencies, WCAG AA accessible).
 */

import { registerCoreBlocks } from './blocks.js';
import { HistoryStack } from './history.js';
import {
  resolveBlockArrowNavigation,
  resolveInsertionFocus,
  resolvePrimaryFocusSelector,
  resolveTitleKeyNavigation,
  resolveUndoRedoFocusIndex,
} from './navigation.js';
import { parsePastedHtmlToBlocks, parsePlainTextToBlocks } from './paste.js';
import { defaultBlockRegistry } from './registry.js';
import {
  blocksToPlainText,
  CONVERTIBLE_TYPES,
  convertBlock,
  createDefaultBlock,
  deserializeDocument,
  serializeDocument,
} from './serializer.js';
import { SlashMenu } from './slash-menu.js';
import { InlineToolbar } from './toolbar.js';
import { countWords, estimateReadingTime, generateBlockId, stripHtml } from './utils.js';
import { tr } from '../i18n.js';

registerCoreBlocks(defaultBlockRegistry);

// Resolved lazily: this module is imported before `initTranslations()` fills the catalogue.
const blockConvertLabels = () => ({
  paragraph: tr('admin.js.editor.bloc_paragraphe', 'Paragraphe'),
  heading: tr('admin.js.editor.bloc_titre', 'Titre'),
  quote: tr('admin.js.editor.bloc_citation', 'Citation'),
  list: tr('admin.js.editor.bloc_liste', 'Liste'),
});

function iconSvg(name, size = 15) {
  return `<svg class="pc-icon" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="/assets/admin/icons/sprite.svg#${name}"></use></svg>`;
}

export class PrimeEditor {
  constructor(rootEl) {
    this.root = rootEl;
    this.textarea = rootEl.querySelector('[data-prime-editor-input]');
    this.canvas = rootEl.querySelector('[data-prime-editor-canvas]');
    this.statsEl = document.querySelector('[data-prime-editor-stats]');
    this.liveRegion = rootEl.querySelector('[data-prime-editor-live]');
    this.undoBtn = document.querySelector('[data-prime-editor-undo]');
    this.redoBtn = document.querySelector('[data-prime-editor-redo]');
    this.addRootBtn = rootEl.querySelector('[data-prime-editor-add]');
    this.autosaveStatusEl = document.querySelector('[data-prime-autosave-status]');
    this.titleInput = document.querySelector('[data-prime-content-title]');
    this.form = rootEl.closest('form');

    this.config = this.readConfig(rootEl);
    const configCaps = Array.isArray(this.config.capabilities) ? this.config.capabilities.map((s) => String(s).trim()).filter(Boolean) : [];
    const dataCaps = (rootEl.dataset.capabilities || '').split(',').map((s) => s.trim()).filter(Boolean);
    this.capabilities = new Set(configCaps.length > 0 ? configCaps : dataCaps);
    this.registry = defaultBlockRegistry;

    const initialDoc = deserializeDocument(this.textarea ? this.textarea.value : '');
    this.blocks = initialDoc.blocks;
    this.history = new HistoryStack(this.blocks, 100);
    this.dirty = false;
    this.lockLost = false;
    this.historyTimer = null;
    this.dragSourceIndex = null;
    this.openMenuIndex = null;

    this.toolbar = new InlineToolbar(this.root, () => this.scheduleHistoryPush());
    this.slashMenu = new SlashMenu(this.registry, this.capabilities, (def, ctx) => {
      this.handleBlockChoice(def, ctx);
    });

    this.bindGlobalEvents();
    this.renderAll();
    this.syncTextarea();
    this.updateStats();
    this.updateHistoryButtons();
    this.initAutosaveAndLocks();

    // Re-render if a plugin registers a custom block asynchronously after DOMContentLoaded
    this.unsubscribeRegistry = this.registry.onRegister(() => {
      this.renderAll();
    });
  }

  readConfig(rootEl) {
    const scriptEl = rootEl.querySelector('script[data-prime-editor-config]');
    if (!scriptEl) {
      return {};
    }
    try {
      const parsed = JSON.parse(scriptEl.textContent || '{}');
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
      return {};
    }
  }

  bindGlobalEvents() {
    if (this.titleInput instanceof HTMLInputElement) {
      this.titleInput.addEventListener('keydown', (e) => {
        if (e.ctrlKey || e.metaKey || e.altKey || e.shiftKey) {
          return;
        }
        const nav = resolveTitleKeyNavigation(e.key, this.blocks.length);
        if (nav.action === 'none') {
          return;
        }
        e.preventDefault();
        if (nav.action === 'create_and_focus_first') {
          this.blocks.push(createDefaultBlock('paragraph'));
          this.afterMutation(0, tr('admin.js.editor.core.paragraphe_pret', 'Paragraphe prêt.'), false);
          return;
        }
        this.focusBlock(nav.index, nav.atEnd);
      });
      this.titleInput.addEventListener('input', () => {
        this.updateStats();
      });
    }

    if (this.undoBtn) {
      this.undoBtn.addEventListener('click', () => this.undo());
    }
    if (this.redoBtn) {
      this.redoBtn.addEventListener('click', () => this.redo());
    }
    if (this.addRootBtn) {
      this.addRootBtn.addEventListener('click', (e) => {
        const rect = e.currentTarget.getBoundingClientRect();
        this.slashMenu.open(rect, { mode: 'append', index: this.blocks.length - 1 }, '', true);
      });
    }

    document.addEventListener('keydown', (e) => {
      const mod = e.ctrlKey || e.metaKey;
      if (mod && e.key.toLowerCase() === 's') {
        e.preventDefault();
        this.submitForm();
        return;
      }

      const activeInEditor =
        this.root.contains(document.activeElement) ||
        document.activeElement === this.undoBtn ||
        document.activeElement === this.redoBtn;

      if (mod && e.key.toLowerCase() === 'z' && activeInEditor) {
        e.preventDefault();
        if (e.shiftKey) {
          this.redo();
        } else {
          this.undo();
        }
        return;
      }
      if (mod && e.key.toLowerCase() === 'y' && activeInEditor) {
        e.preventDefault();
        this.redo();
      }
    });

    document.addEventListener('click', (e) => {
      if (this.openMenuIndex !== null && e.target instanceof Element && !e.target.closest('.pc-ed-block-actions')) {
        this.openMenuIndex = null;
        this.root.querySelectorAll('.pc-ed-block-popover').forEach((p) => {
          p.hidden = true;
        });
      }
    });

    if (this.form) {
      this.form.addEventListener('input', () => {
        this.dirty = true;
      });
      this.form.addEventListener('submit', () => {
        this.syncTextarea();
        this.dirty = false;
      });
    }

    window.addEventListener('beforeunload', (e) => {
      if (this.dirty) {
        e.preventDefault();
        e.returnValue = '';
      }
    });
  }

  announce(message) {
    if (this.liveRegion) {
      this.liveRegion.textContent = '';
      window.requestAnimationFrame(() => {
        this.liveRegion.textContent = message;
      });
    }
  }

  renderAll(focusIndex = null, focusAtEnd = false, focusOpts = null) {
    if (!this.canvas) {
      return;
    }

    this.canvas.innerHTML = '';

    this.blocks.forEach((block, index) => {
      const wrapper = document.createElement('div');
      wrapper.className = 'pc-ed-block';
      wrapper.dataset.blockId = block.id;
      wrapper.dataset.blockType = block.type;
      wrapper.dataset.blockIndex = String(index);
      wrapper.setAttribute('role', 'region');
      const def = this.registry.get(block.type);
      wrapper.setAttribute('aria-label', `Bloc ${index + 1} : ${def ? def.title : block.type}`);

      // Drag and drop handlers (block reorder OR direct external image upload)
      wrapper.addEventListener('dragover', (e) => {
        e.preventDefault();
        wrapper.classList.add('is-drag-over');
      });
      wrapper.addEventListener('dragleave', () => wrapper.classList.remove('is-drag-over'));
      wrapper.addEventListener('drop', (e) => {
        e.preventDefault();
        wrapper.classList.remove('is-drag-over');
        if (this.dragSourceIndex !== null && this.dragSourceIndex !== index) {
          this.moveBlock(this.dragSourceIndex, index);
          this.dragSourceIndex = null;
          return;
        }
        this.dragSourceIndex = null;
        const droppedFiles = Array.from(e.dataTransfer?.files || []).filter((f) => f.type.startsWith('image/'));
        if (droppedFiles.length > 0) {
          void this.uploadAndInsertImageFiles(droppedFiles, index);
        }
      });

      // Left gutter: "+" inserter & drag/actions handle
      const gutter = document.createElement('div');
      gutter.className = 'pc-ed-block-gutter';

      const plusBtn = document.createElement('button');
      plusBtn.type = 'button';
      plusBtn.className = 'pc-ed-gutter-btn';
      plusBtn.title = 'Insérer un bloc après celui-ci';
      plusBtn.setAttribute('aria-label', tr('admin.js.editor.core.inserer_un_bloc_apres', 'Insérer un bloc après'));
      plusBtn.innerHTML = iconSvg('plus', 15);
      plusBtn.addEventListener('click', (e) => {
        const rect = e.currentTarget.getBoundingClientRect();
        this.slashMenu.open(rect, { mode: 'after', index }, '', true);
      });

      const actionsWrap = document.createElement('div');
      actionsWrap.className = 'pc-ed-block-actions';

      const handleBtn = document.createElement('button');
      handleBtn.type = 'button';
      handleBtn.className = 'pc-ed-gutter-btn pc-ed-drag-handle';
      handleBtn.draggable = true;
      handleBtn.title = 'Options du bloc (Alt+↑ / Alt+↓ pour déplacer)';
      handleBtn.setAttribute('aria-label', tr('admin.js.editor.core.options_et_deplacement_du_bloc', 'Options et déplacement du bloc'));
      handleBtn.setAttribute('aria-haspopup', 'menu');
      handleBtn.innerHTML = iconSvg('grip-vertical', 15);

      handleBtn.addEventListener('dragstart', (e) => {
        this.dragSourceIndex = index;
        wrapper.classList.add('is-dragging');
        if (e.dataTransfer) {
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/plain', block.id);
        }
      });
      handleBtn.addEventListener('dragend', () => {
        wrapper.classList.remove('is-dragging');
        this.dragSourceIndex = null;
      });

      const popover = this.buildBlockMenu(block, index);
      handleBtn.addEventListener('click', () => {
        const willOpen = popover.hidden;
        this.root.querySelectorAll('.pc-ed-block-popover').forEach((p) => {
          p.hidden = true;
        });
        popover.hidden = !willOpen;
        this.openMenuIndex = willOpen ? index : null;
      });

      actionsWrap.append(handleBtn, popover);
      gutter.append(plusBtn, actionsWrap);

      // Block main content
      const contentHost = document.createElement('div');
      contentHost.className = 'pc-ed-block-content';

      if (def) {
        const rendered = def.render({
          id: block.id,
          index,
          blockCount: this.blocks.length,
          data: block.data,
          config: this.config,
          capabilities: this.capabilities,
          isCaretAtEdge: (el, edge) => this.isCaretAtEdge(el, edge),
          focusBlock: (targetIdx, atEnd = false) => this.focusBlock(targetIdx, atEnd),
          focusTitle: (atEnd = true) => this.focusTitle(atEnd),
          focusSubElement: (targetIdx, selector, matchIndex, atEnd = false) =>
            this.focusBlock(targetIdx, atEnd, { selector, matchIndex }),
          updateData: (patch, opts = {}) => {
            block.data = { ...block.data, ...patch };
            this.dirty = true;
            this.syncTextarea();
            this.updateStats();
            if (opts.recordHistory) {
              this.pushHistoryNow();
            } else {
              this.scheduleHistoryPush();
            }
            if (opts.rerender) {
              const subFocus = opts.focusSelector
                ? { selector: opts.focusSelector, matchIndex: opts.focusMatchIndex ?? 0 }
                : null;
              this.renderAll(index, Boolean(opts.focusAtEnd), subFocus);
            }
          },
          handleSlashTrigger: (editableEl) => {
            const plain = stripHtml(editableEl.innerHTML);
            if (plain.startsWith('/') && !plain.includes(' ')) {
              const rect = editableEl.getBoundingClientRect();
              const q = plain.slice(1);
              if (!this.slashMenu.isOpen) {
                this.slashMenu.open(rect, { mode: 'replace', index }, q, false);
              } else {
                this.slashMenu.filter(q);
              }
            } else if (this.slashMenu.isOpen) {
              this.slashMenu.close();
            }
          },
          handleRichKeyDown: (e, editableEl, opts = {}) => this.onRichKeyDown(e, editableEl, index, opts),
          handlePaste: (e, editableEl) => this.onRichPaste(e, editableEl, index),
        });
        contentHost.appendChild(rendered);
      } else {
        const fallback = document.createElement('div');
        fallback.className = 'pc-ed-unknown-block';
        fallback.textContent = `Bloc personnalisé « ${block.type} » (module client non chargé — données conservées).`;
        contentHost.appendChild(fallback);
      }

      wrapper.append(gutter, contentHost);
      this.canvas.appendChild(wrapper);
    });

    if (focusIndex !== null) {
      this.focusBlock(focusIndex, focusAtEnd, focusOpts);
    }
  }

  buildBlockMenu(block, index) {
    const menu = document.createElement('div');
    menu.className = 'pc-ed-block-popover';
    menu.setAttribute('role', 'menu');
    menu.hidden = true;

    const addAction = (label, icon, disabled, onClick, danger = false) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = `pc-ed-menu-btn${danger ? ' is-danger' : ''}`;
      btn.setAttribute('role', 'menuitem');
      btn.disabled = disabled;
      btn.innerHTML = `${iconSvg(icon, 14)}<span></span>`;
      btn.querySelector('span').textContent = label;
      btn.addEventListener('click', () => {
        menu.hidden = true;
        onClick();
      });
      menu.appendChild(btn);
    };

    addAction('Monter (Alt+↑)', 'arrow-up', index === 0, () => this.moveBlock(index, index - 1));
    addAction('Descendre (Alt+↓)', 'arrow-down', index === this.blocks.length - 1, () => this.moveBlock(index, index + 1));
    addAction('Dupliquer', 'copy', false, () => this.duplicateBlock(index));

    if (CONVERTIBLE_TYPES.includes(block.type)) {
      const sep = document.createElement('div');
      sep.className = 'pc-ed-menu-sep';
      menu.appendChild(sep);

      const lbl = document.createElement('div');
      lbl.className = 'pc-ed-menu-heading';
      lbl.textContent = 'Convertir en';
      menu.appendChild(lbl);

      CONVERTIBLE_TYPES.filter((t) => t !== block.type).forEach((targetType) => {
        addAction(blockConvertLabels()[targetType] || targetType, 'sparkles', false, () => {
          this.blocks[index] = convertBlock(this.blocks[index], targetType);
          this.afterMutation(index, tr('admin.js.editor.bloc_converti_en', { type: blockConvertLabels()[targetType] || targetType }));
        });
      });
    }

    const sepDel = document.createElement('div');
    sepDel.className = 'pc-ed-menu-sep';
    menu.appendChild(sepDel);

    addAction('Supprimer le bloc', 'trash', false, () => this.removeBlock(index), true);
    return menu;
  }

  onRichKeyDown(event, editableEl, index, opts = {}) {
    if (this.slashMenu.isOpen && this.slashMenu.handleKeyDown(event)) {
      return;
    }

    if (this.toolbar.handleShortcut(event)) {
      return;
    }

    // Alt + Up / Down moves the focused block smoothly
    if (event.altKey && event.key === 'ArrowUp') {
      event.preventDefault();
      if (index > 0) this.moveBlock(index, index - 1);
      return;
    }
    if (event.altKey && event.key === 'ArrowDown') {
      event.preventDefault();
      if (index < this.blocks.length - 1) this.moveBlock(index, index + 1);
      return;
    }

    // Enter creates a new paragraph below (Shift+Enter inserts soft <br>)
    if (event.key === 'Enter' && !event.shiftKey && opts.allowSplit) {
      event.preventDefault();
      const newBlock = createDefaultBlock('paragraph');
      this.blocks.splice(index + 1, 0, newBlock);
      this.afterMutation(index + 1, tr('admin.js.editor.core.nouveau_paragraphe_ajoute', 'Nouveau paragraphe ajouté.'));
      return;
    }

    // Backspace on an empty block removes it and moves focus to previous block
    if (event.key === 'Backspace' && stripHtml(editableEl.innerHTML) === '' && this.blocks.length > 1) {
      event.preventDefault();
      this.removeBlock(index);
      return;
    }

    // ArrowUp / ArrowDown across block boundaries (and ArrowUp at start of first block back into title)
    if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
      const nav = resolveBlockArrowNavigation({
        key: event.key,
        blockIndex: index,
        blockCount: this.blocks.length,
        atStart: this.isCaretAtEdge(editableEl, 'start'),
        atEnd: this.isCaretAtEdge(editableEl, 'end'),
      });
      if (nav.action === 'focus_title') {
        event.preventDefault();
        this.focusTitle(true);
      } else if (nav.action === 'focus_block') {
        event.preventDefault();
        this.focusBlock(nav.index, nav.atEnd);
      }
    }
  }

  onRichPaste(event, editableEl, index) {
    const clipboard = event.clipboardData;
    if (!clipboard) {
      return;
    }

    const imageFiles = Array.from(clipboard.files || []).filter((f) => f.type.startsWith('image/'));
    if (imageFiles.length > 0) {
      event.preventDefault();
      void this.uploadAndInsertImageFiles(imageFiles, index);
      return;
    }

    const html = clipboard.getData('text/html');
    const plain = clipboard.getData('text/plain');
    const parsed = html ? parsePastedHtmlToBlocks(html) : parsePlainTextToBlocks(plain);

    if (parsed.length === 0) {
      return;
    }

    // Single inline paragraph paste -> insert cleanly at caret
    if (parsed.length === 1 && parsed[0].type === 'paragraph' && stripHtml(editableEl.innerHTML) !== '') {
      event.preventDefault();
      const sel = window.getSelection();
      if (sel && sel.rangeCount > 0) {
        const range = sel.getRangeAt(0);
        range.deleteContents();
        const frag = range.createContextualFragment(parsed[0].data.text);
        range.insertNode(frag);
        sel.collapseToEnd();
        editableEl.dispatchEvent(new Event('input', { bubbles: true }));
      }
      return;
    }

    // Multi-block or empty block paste -> replace empty paragraph or insert parsed blocks cleanly
    event.preventDefault();
    const isCurrentEmpty = this.blocks[index].type === 'paragraph' && stripHtml(editableEl.innerHTML) === '';
    if (isCurrentEmpty) {
      this.blocks.splice(index, 1, ...parsed);
      this.afterMutation(index + parsed.length - 1, `${parsed.length} bloc(s) collé(s) et nettoyé(s).`);
    } else {
      this.blocks.splice(index + 1, 0, ...parsed);
      this.afterMutation(index + parsed.length, `${parsed.length} bloc(s) collé(s) et nettoyé(s).`);
    }
  }

  insertBlock(type, customData = null) {
    const def = this.registry.get(type);
    const defaultData = def && typeof def.defaultData === 'function' ? def.defaultData() : {};
    const mergedData = customData && typeof customData === 'object'
      ? { ...defaultData, ...customData }
      : defaultData;

    const newBlock = createDefaultBlock(type, {
      id: generateBlockId(),
      data: mergedData,
    });

    // Replace lone empty starter paragraph cleanly (e.g. empty zone)
    const isSingleEmptyParagraph = this.blocks.length === 1
      && this.blocks[0].type === 'paragraph'
      && stripHtml(String(this.blocks[0].data?.text || '')) === '';

    let focusIndex;
    if (isSingleEmptyParagraph) {
      this.blocks.splice(0, 1, newBlock);
      focusIndex = 0;
    } else {
      this.blocks.push(newBlock);
      focusIndex = this.blocks.length - 1;
    }

    const label = def?.title || type;
    this.afterMutation(focusIndex, `Bloc ${label} inséré.`, false);
    return newBlock;
  }

  handleBlockChoice(def, ctx) {
    const newBlock = createDefaultBlock(def.type, {
      id: generateBlockId(),
      data: typeof def.defaultData === 'function' ? def.defaultData() : {},
    });

    const plan = resolveInsertionFocus({
      mode: ctx?.mode || 'append',
      contextIndex: ctx?.index ?? this.blocks.length - 1,
      blockCount: this.blocks.length,
      insertedType: def.type,
    });

    const inserted = plan.appendTrailingParagraph ? [newBlock, createDefaultBlock('paragraph')] : [newBlock];

    if (plan.replaceExisting) {
      this.blocks.splice(plan.insertIndex, 1, ...inserted);
    } else {
      this.blocks.splice(plan.insertIndex, 0, ...inserted);
    }

    this.afterMutation(plan.focusIndex, `Bloc ${def.title} inséré.`, false);
  }

  async uploadAndInsertImageFiles(files, afterIndex) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
      || document.querySelector('input[name="_token"]')?.value
      || '';
    const uploaded = [];

    for (const file of files) {
      const formData = new FormData();
      formData.append('file', file);
      try {
        const res = await fetch('/admin/media/api/upload', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrf,
          },
          body: formData,
        });
        const data = await res.json();
        if (res.ok && data?.media) {
          uploaded.push(data.media);
        } else {
          window.Prime?.toast?.(data?.message || `Échec du téléversement de ${file.name}.`, 'danger');
        }
      } catch {
        window.Prime?.toast?.(`Erreur réseau lors du téléversement de ${file.name}.`, 'danger');
      }
    }

    if (uploaded.length === 0) {
      return;
    }

    const newBlocks = uploaded.map((m) =>
      createDefaultBlock('image', {
        id: generateBlockId(),
        data: {
          mediaId: Number(m.id),
          url: m.url || '',
          alt: m.alt || m.alt_text || m.title || m.original_name || m.original_filename || tr('admin.js.editor.core.image_importee', 'Image importée'),
          decorative: false,
          caption: m.caption || '',
          alignment: 'normal',
        },
      }),
    );

    const insertAt = Math.min(this.blocks.length, Math.max(0, afterIndex + 1));
    this.blocks.splice(insertAt, 0, ...newBlocks);
    this.afterMutation(insertAt, `${newBlocks.length} image(s) téléversée(s) et insérée(s).`);
    window.Prime?.toast?.(`${newBlocks.length} image(s) ajoutée(s) depuis le presse-papiers.`, 'success');
  }

  moveBlock(fromIndex, toIndex) {
    if (toIndex < 0 || toIndex >= this.blocks.length || fromIndex === toIndex) {
      return;
    }
    const [moved] = this.blocks.splice(fromIndex, 1);
    this.blocks.splice(toIndex, 0, moved);
    this.afterMutation(toIndex, `Bloc déplacé en position ${toIndex + 1}.`);
  }

  duplicateBlock(index) {
    const clone = JSON.parse(JSON.stringify(this.blocks[index]));
    clone.id = generateBlockId();
    this.blocks.splice(index + 1, 0, clone);
    this.afterMutation(index + 1, tr('admin.js.editor.core.bloc_duplique', 'Bloc dupliqué.'));
  }

  removeBlock(index) {
    if (this.blocks.length === 1) {
      this.blocks[0] = createDefaultBlock('paragraph');
      this.afterMutation(0, tr('admin.js.editor.core.bloc_reinitialise', 'Bloc réinitialisé.'));
      return;
    }
    this.blocks.splice(index, 1);
    const nextFocus = Math.max(0, index - 1);
    this.afterMutation(nextFocus, tr('admin.js.editor.core.bloc_supprime', 'Bloc supprimé.'), true);
  }

  afterMutation(focusIndex, announcement, focusAtEnd = false) {
    this.dirty = true;
    this.syncTextarea();
    this.pushHistoryNow();
    this.renderAll(focusIndex, focusAtEnd);
    this.updateStats();
    if (announcement) {
      this.announce(announcement);
    }
  }

  focusTitle(atEnd = true) {
    if (!(this.titleInput instanceof HTMLInputElement)) {
      return;
    }
    this.titleInput.focus();
    const pos = atEnd ? this.titleInput.value.length : 0;
    try {
      this.titleInput.setSelectionRange(pos, pos);
    } catch {
      // Ignore selection range errors on unsupported input types
    }
  }

  getActiveBlockIndex() {
    const active = document.activeElement;
    if (!(active instanceof Element)) {
      return null;
    }
    const blockEl = active.closest('[data-block-index]');
    if (!(blockEl instanceof HTMLElement)) {
      return null;
    }
    const parsed = Number.parseInt(blockEl.dataset.blockIndex || '', 10);
    return Number.isNaN(parsed) ? null : parsed;
  }

  focusBlock(index, atEnd = false, focusOpts = null) {
    const blockEl = this.canvas?.querySelector(`[data-block-index="${index}"]`);
    if (!blockEl) {
      return;
    }

    const blockType = blockEl.getAttribute('data-block-type') || '';
    let target = null;

    if (focusOpts && focusOpts.selector) {
      const matches = blockEl.querySelectorAll(focusOpts.selector);
      const matchIdx = Math.max(0, Math.min(focusOpts.matchIndex ?? 0, matches.length - 1));
      target = matches[matchIdx] || null;
    }

    if (!target) {
      const primarySelector = resolvePrimaryFocusSelector(blockType);
      target = blockEl.querySelector(primarySelector) || blockEl.querySelector('.pc-ed-richtext, textarea, input:not([type="hidden"])');
    }

    if (!(target instanceof HTMLElement)) {
      return;
    }
    target.focus();
    if (target.isContentEditable) {
      const range = document.createRange();
      range.selectNodeContents(target);
      range.collapse(!atEnd);
      const sel = window.getSelection();
      if (sel) {
        sel.removeAllRanges();
        sel.addRange(range);
      }
    } else if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) {
      const pos = atEnd ? target.value.length : 0;
      try {
        target.setSelectionRange(pos, pos);
      } catch {
        // Ignore on non-selectable input types
      }
    }
  }

  isCaretAtEdge(el, edge) {
    if (stripHtml(el.innerHTML) === '') {
      return true;
    }
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0 || !sel.isCollapsed) {
      return false;
    }
    const range = sel.getRangeAt(0);
    const probe = range.cloneRange();
    probe.selectNodeContents(el);
    if (edge === 'start') {
      probe.setEnd(range.startContainer, range.startOffset);
      return probe.toString().length === 0;
    }
    probe.setStart(range.endContainer, range.endOffset);
    return probe.toString().length === 0;
  }

  scheduleHistoryPush() {
    window.clearTimeout(this.historyTimer);
    this.historyTimer = window.setTimeout(() => this.pushHistoryNow(), 500);
  }

  pushHistoryNow() {
    window.clearTimeout(this.historyTimer);
    this.historyTimer = null;
    this.history.push(this.blocks);
    this.updateHistoryButtons();
  }

  undo() {
    if (this.historyTimer !== null) {
      this.pushHistoryNow();
    }
    const prev = this.history.undo();
    if (!prev) {
      return;
    }
    const activeIdx = this.getActiveBlockIndex();
    this.blocks = prev;
    this.dirty = true;
    this.syncTextarea();
    const focusIdx = resolveUndoRedoFocusIndex(activeIdx, this.blocks.length);
    this.renderAll(focusIdx, true);
    this.updateStats();
    this.updateHistoryButtons();
    this.announce(tr('admin.js.editor.core.action_annulee', 'Action annulée.'));
  }

  redo() {
    const next = this.history.redo();
    if (!next) {
      return;
    }
    const activeIdx = this.getActiveBlockIndex();
    this.blocks = next;
    this.dirty = true;
    this.syncTextarea();
    const focusIdx = resolveUndoRedoFocusIndex(activeIdx, this.blocks.length);
    this.renderAll(focusIdx, true);
    this.updateStats();
    this.updateHistoryButtons();
    this.announce(tr('admin.js.editor.core.action_retablie', 'Action rétablie.'));
  }

  updateHistoryButtons() {
    if (this.undoBtn) this.undoBtn.disabled = !this.history.canUndo();
    if (this.redoBtn) this.redoBtn.disabled = !this.history.canRedo();
  }

  syncTextarea() {
    if (this.textarea) {
      this.textarea.value = serializeDocument(this.blocks);
    }
  }

  updateStats() {
    if (!this.statsEl) {
      return;
    }
    const titleVal = document.querySelector('[data-prime-content-title]')?.value || '';
    const plain = `${titleVal}\n${blocksToPlainText(this.blocks)}`;
    const words = countWords(plain);
    const minutes = estimateReadingTime(words);
    const wordLabel = words > 1 ? `${words} mots` : `${words} mot`;
    const readLabel = minutes > 0 ? `· ${minutes} min de lecture` : '';
    this.statsEl.textContent = `${wordLabel} ${readLabel}`.trim();
  }

  submitForm() {
    if (!this.form) {
      return false;
    }
    this.syncTextarea();
    const submitted = submitFormPreservingStatus(this.form, { lockLost: this.lockLost });
    if (submitted) {
      this.dirty = false;
    }
    return submitted;
  }

  initAutosaveAndLocks() {
    const autosaveUrl = String(this.config.autosaveUrl || this.root.dataset.autosaveUrl || '');
    const autosaveIntervalMs = Number(this.config.autosaveIntervalMs || 30000);
    const heartbeatUrl = String(this.config.heartbeatUrl || this.root.dataset.lockHeartbeatUrl || '');
    const heartbeatIntervalMs = Number(this.config.heartbeatIntervalMs || 30000);
    const releaseUrl = String(this.config.releaseUrl || this.root.dataset.lockReleaseUrl || '');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // Periodic autosave for existing entries when dirty
    if (autosaveUrl) {
      this.autosaveTimer = window.setInterval(async () => {
        if (!this.dirty) {
          return;
        }
        this.syncTextarea();
        const title = document.querySelector('[data-prime-content-title]')?.value || '';
        const excerpt = document.querySelector('textarea[name="excerpt"]')?.value || '';

        try {
          const res = await fetch(autosaveUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              Accept: 'application/json',
              'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify({
              title,
              excerpt,
              content_json: this.textarea?.value || '{"version":1,"blocks":[]}',
            }),
          });
          if (res.ok && this.autosaveStatusEl) {
            const now = new Date();
            const hh = String(now.getHours()).padStart(2, '0');
            const mm = String(now.getMinutes()).padStart(2, '0');
            this.autosaveStatusEl.textContent = tr('admin.js.editor.core.autosauvegarde_a', 'Autosauvegardé à :time', { time: `${hh}:${mm}` });
          }
        } catch {
          // Silent retry on next interval
        }
      }, autosaveIntervalMs);
    }

    // Periodic edit lock heartbeat (only shows lock-lost banner when lock is genuinely lost)
    if (heartbeatUrl) {
      this.heartbeatTimer = window.setInterval(async () => {
        try {
          const res = await fetch(heartbeatUrl, {
            method: 'POST',
            headers: {
              Accept: 'application/json',
              'X-CSRF-Token': csrfToken,
            },
          });
          let payload = {};
          try {
            payload = await res.json();
          } catch {
            payload = {};
          }
          if (res.status === 409 || payload.locked_by_other === true || payload.status === 'lost') {
            this.handleLockLost(payload.holder || payload.locked_by || 'un autre utilisateur');
          }
        } catch {
          // Transient network error ignored
        }
      }, heartbeatIntervalMs);
    }

    // Release lock cleanly on navigation away
    if (releaseUrl && csrfToken) {
      window.addEventListener('pagehide', () => {
        if (navigator.sendBeacon) {
          const body = new URLSearchParams({ _csrf_token: csrfToken });
          navigator.sendBeacon(releaseUrl, body);
        }
      });
    }
  }

  handleLockLost(userName) {
    this.lockLost = true;
    window.clearInterval(this.autosaveTimer);
    window.clearInterval(this.heartbeatTimer);

    const banner = document.querySelector('[data-prime-lock-lost-banner]');
    if (banner instanceof HTMLElement) {
      const msgEl = banner.querySelector('[data-prime-lock-lost-user]');
      if (msgEl) {
        msgEl.textContent = `${userName} a pris le contrôle de ce contenu.`;
      }
      banner.hidden = false;
    }

    document.querySelectorAll('[data-prime-primary-save], [data-prime-secondary-save]').forEach((btn) => {
      if (btn instanceof HTMLButtonElement) {
        btn.disabled = true;
      }
    });

    this.announce(`Attention : ${userName} a pris la main sur ce contenu. Enregistrement désactivé.`);
  }
}

/**
 * Submit an editor form while strictly preserving the current entry status.
 *
 * Unlike clicking the primary CTA button (which carries `data-prime-submit-status="published"`
 * on new entries or `"pending"` for contributors), `Ctrl/Cmd+S` submits the form directly
 * without a submitter button so a draft stays a draft and a published entry updates in place.
 *
 * @param {HTMLFormElement | null | undefined} form
 * @param {{ lockLost?: boolean }} [options]
 * @returns {boolean} True when the form was submitted
 */
export function submitFormPreservingStatus(form, { lockLost = false } = {}) {
  if (!form || lockLost) {
    return false;
  }

  const saveButtons = Array.from(
    form.querySelectorAll('[data-prime-primary-save], [data-prime-secondary-save]'),
  );
  if (saveButtons.length > 0 && saveButtons.every((btn) => Boolean(btn.disabled))) {
    return false;
  }

  if (typeof form.requestSubmit === 'function') {
    form.requestSubmit();
  } else if (typeof form.submit === 'function') {
    form.submit();
  } else {
    return false;
  }

  return true;
}

export function initPrimeEditor() {
  const roots = document.querySelectorAll('[data-prime-editor]');
  const instances = [];
  roots.forEach((root) => {
    if (root instanceof HTMLElement) {
      instances.push(new PrimeEditor(root));
    }
  });
  return instances;
}

export const editorApi = Object.freeze({
  registerBlock: (definition) => defaultBlockRegistry.registerBlock(definition),
  getBlock: (type) => defaultBlockRegistry.get(type),
  listBlocks: () => defaultBlockRegistry.list(),
});
