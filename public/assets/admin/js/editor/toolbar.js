/**
 * Prime Editor — Floating inline formatting toolbar (Bold, Italic, Code, Link + shortcuts).
 */

import { tr } from '../i18n.js';
import { sanitizeHref } from './utils.js';

export class InlineToolbar {
  constructor(editorRoot, onFormatApplied) {
    this.editorRoot = editorRoot;
    this.onFormatApplied = onFormatApplied;
    this.savedRange = null;

    this.el = document.createElement('div');
    this.el.className = 'pc-ed-inline-toolbar';
    this.el.setAttribute('role', 'toolbar');
    this.el.setAttribute('aria-label', tr('admin.js.editor.toolbar.label', 'Mise en forme du texte'));
    this.el.hidden = true;

    this.buttonsRow = document.createElement('div');
    this.buttonsRow.className = 'pc-ed-inline-toolbar__row';

    this.linkForm = document.createElement('div');
    this.linkForm.className = 'pc-ed-inline-toolbar__link';
    this.linkForm.hidden = true;

    this.buildButtons();
    this.buildLinkPopover();

    this.el.append(this.buttonsRow, this.linkForm);
    document.body.appendChild(this.el);

    this.onSelectionChange = this.handleSelectionChange.bind(this);
    this.onMouseDown = this.handleOutsideClick.bind(this);
    document.addEventListener('selectionchange', this.onSelectionChange);
    document.addEventListener('mousedown', this.onMouseDown);
  }

  buildButtons() {
    const actions = [
      { id: 'bold', label: 'Gras (Ctrl+B)', text: 'B', cls: 'is-bold', run: () => this.toggleTag('strong') },
      { id: 'italic', label: 'Italique (Ctrl+I)', text: 'I', cls: 'is-italic', run: () => this.toggleTag('em') },
      { id: 'code', label: 'Code (Ctrl+E)', text: '</>', cls: 'is-mono', run: () => this.toggleTag('code') },
      { id: 'link', label: tr('admin.js.editor.toolbar.link_title', 'Lien (Ctrl+K)'), text: tr('admin.js.editor.toolbar.link', 'Lien'), cls: '', run: () => this.openLinkForm() },
    ];

    actions.forEach((act) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = `pc-ed-itb-btn ${act.cls}`.trim();
      btn.textContent = act.text;
      btn.title = act.label;
      btn.setAttribute('aria-label', act.label);
      btn.addEventListener('mousedown', (e) => e.preventDefault());
      btn.addEventListener('click', () => act.run());
      this.buttonsRow.appendChild(btn);
    });
  }

  buildLinkPopover() {
    this.urlInput = document.createElement('input');
    this.urlInput.type = 'url';
    this.urlInput.className = 'pc-ed-itb-input';
    this.urlInput.placeholder = 'https://… ou /page';
    this.urlInput.setAttribute('aria-label', tr('admin.js.editor.toolbar.link_url', 'URL du lien'));

    const blankWrap = document.createElement('label');
    blankWrap.className = 'pc-ed-itb-check';
    this.blankCheck = document.createElement('input');
    this.blankCheck.type = 'checkbox';
    blankWrap.append(this.blankCheck, document.createTextNode(' Nouvel onglet'));

    const applyBtn = document.createElement('button');
    applyBtn.type = 'button';
    applyBtn.className = 'pc-ed-itb-apply';
    applyBtn.textContent = 'Appliquer';
    applyBtn.addEventListener('click', () => this.applyLink());

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'pc-ed-itb-cancel';
    removeBtn.textContent = 'Retirer';
    removeBtn.addEventListener('click', () => this.removeLink());

    this.urlInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        this.applyLink();
      } else if (e.key === 'Escape') {
        e.preventDefault();
        this.closeLinkForm();
      }
    });

    this.linkForm.append(this.urlInput, blankWrap, applyBtn, removeBtn);
  }

  handleShortcut(event) {
    const mod = event.ctrlKey || event.metaKey;
    if (!mod || event.altKey) {
      return false;
    }

    const key = event.key.toLowerCase();
    if (key === 'b') {
      event.preventDefault();
      this.toggleTag('strong');
      return true;
    }
    if (key === 'i') {
      event.preventDefault();
      this.toggleTag('em');
      return true;
    }
    if (key === 'e') {
      event.preventDefault();
      this.toggleTag('code');
      return true;
    }
    if (key === 'k') {
      event.preventDefault();
      this.saveCurrentSelection();
      this.positionNearSelection();
      this.el.hidden = false;
      this.openLinkForm();
      return true;
    }

    return false;
  }

  handleSelectionChange() {
    if (!this.linkForm.hidden) {
      return;
    }

    const sel = window.getSelection();
    if (!sel || sel.isCollapsed || sel.rangeCount === 0) {
      this.hide();
      return;
    }

    const range = sel.getRangeAt(0);
    const editable = this.findRichEditable(range.commonAncestorContainer);
    if (!editable || !this.editorRoot.contains(editable)) {
      this.hide();
      return;
    }

    this.savedRange = range.cloneRange();
    this.el.hidden = false;
    this.positionNearSelection();
  }

  positionNearSelection() {
    const sel = window.getSelection();
    const range = this.savedRange || (sel && sel.rangeCount > 0 ? sel.getRangeAt(0) : null);
    if (!range) {
      return;
    }

    const rect = range.getBoundingClientRect();
    if (!rect || (rect.width === 0 && rect.height === 0)) {
      return;
    }

    const toolbarWidth = this.el.offsetWidth || 200;
    const left = Math.max(12, Math.min(window.innerWidth - toolbarWidth - 12, rect.left + rect.width / 2 - toolbarWidth / 2));
    const top = Math.max(12, rect.top - (this.el.offsetHeight || 40) - 8);

    this.el.style.left = `${left + window.scrollX}px`;
    this.el.style.top = `${top + window.scrollY}px`;
  }

  toggleTag(tagName) {
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) {
      return;
    }
    const range = sel.getRangeAt(0);
    const editable = this.findRichEditable(range.commonAncestorContainer);
    if (!editable || range.collapsed) {
      return;
    }

    // If selection is already inside the tag, unwrap it
    const existing = this.findAncestorTag(range.commonAncestorContainer, editable, tagName);
    if (existing) {
      const parent = existing.parentNode;
      while (existing.firstChild) {
        parent.insertBefore(existing.firstChild, existing);
      }
      parent.removeChild(existing);
    } else {
      const wrapper = document.createElement(tagName);
      wrapper.appendChild(range.extractContents());
      range.insertNode(wrapper);
      sel.removeAllRanges();
      const nextRange = document.createRange();
      nextRange.selectNodeContents(wrapper);
      sel.addRange(nextRange);
    }

    editable.dispatchEvent(new Event('input', { bubbles: true }));
    if (this.onFormatApplied) {
      this.onFormatApplied(editable);
    }
  }

  saveCurrentSelection() {
    const sel = window.getSelection();
    if (sel && sel.rangeCount > 0) {
      this.savedRange = sel.getRangeAt(0).cloneRange();
    }
  }

  openLinkForm() {
    this.saveCurrentSelection();
    const editable = this.savedRange ? this.findRichEditable(this.savedRange.commonAncestorContainer) : null;
    const existingAnchor = editable && this.savedRange
      ? this.findAncestorTag(this.savedRange.commonAncestorContainer, editable, 'a')
      : null;

    this.urlInput.value = existingAnchor ? existingAnchor.getAttribute('href') || '' : '';
    this.blankCheck.checked = existingAnchor ? existingAnchor.getAttribute('target') === '_blank' : false;
    this.linkForm.hidden = false;
    this.positionNearSelection();
    this.urlInput.focus();
  }

  closeLinkForm() {
    this.linkForm.hidden = true;
  }

  applyLink() {
    const safeUrl = sanitizeHref(this.urlInput.value);
    if (!safeUrl || !this.savedRange) {
      this.urlInput.focus();
      return;
    }

    const editable = this.findRichEditable(this.savedRange.commonAncestorContainer);
    if (!editable) {
      this.hide();
      return;
    }

    const sel = window.getSelection();
    if (sel) {
      sel.removeAllRanges();
      sel.addRange(this.savedRange);
    }

    const existingAnchor = this.findAncestorTag(this.savedRange.commonAncestorContainer, editable, 'a');
    if (existingAnchor) {
      existingAnchor.setAttribute('href', safeUrl);
      if (this.blankCheck.checked) {
        existingAnchor.setAttribute('target', '_blank');
        existingAnchor.setAttribute('rel', 'noopener noreferrer');
      } else {
        existingAnchor.removeAttribute('target');
        existingAnchor.removeAttribute('rel');
      }
    } else if (!this.savedRange.collapsed) {
      const a = document.createElement('a');
      a.setAttribute('href', safeUrl);
      if (this.blankCheck.checked) {
        a.setAttribute('target', '_blank');
        a.setAttribute('rel', 'noopener noreferrer');
      }
      a.appendChild(this.savedRange.extractContents());
      this.savedRange.insertNode(a);
    }

    editable.dispatchEvent(new Event('input', { bubbles: true }));
    this.closeLinkForm();
    this.hide();
  }

  removeLink() {
    if (!this.savedRange) {
      this.closeLinkForm();
      return;
    }
    const editable = this.findRichEditable(this.savedRange.commonAncestorContainer);
    const existingAnchor = editable
      ? this.findAncestorTag(this.savedRange.commonAncestorContainer, editable, 'a')
      : null;

    if (existingAnchor && existingAnchor.parentNode) {
      while (existingAnchor.firstChild) {
        existingAnchor.parentNode.insertBefore(existingAnchor.firstChild, existingAnchor);
      }
      existingAnchor.parentNode.removeChild(existingAnchor);
      editable.dispatchEvent(new Event('input', { bubbles: true }));
    }

    this.closeLinkForm();
    this.hide();
  }

  handleOutsideClick(event) {
    if (!this.el.hidden && event.target instanceof Node && !this.el.contains(event.target)) {
      if (!this.linkForm.hidden) {
        this.closeLinkForm();
        this.hide();
      }
    }
  }

  hide() {
    this.el.hidden = true;
    this.linkForm.hidden = true;
  }

  findRichEditable(node) {
    const el = node instanceof Element ? node : node?.parentElement;
    return el ? el.closest('.pc-ed-richtext') : null;
  }

  findAncestorTag(node, boundary, tagName) {
    const target = tagName.toLowerCase();
    let cur = node instanceof Element ? node : node?.parentElement;
    while (cur && cur !== boundary) {
      if (cur.tagName.toLowerCase() === target) {
        return cur;
      }
      cur = cur.parentElement;
    }
    return null;
  }

  destroy() {
    document.removeEventListener('selectionchange', this.onSelectionChange);
    document.removeEventListener('mousedown', this.onMouseDown);
    this.el.remove();
  }
}
