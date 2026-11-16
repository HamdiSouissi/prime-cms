/**
 * Prime Editor — Keyboard-accessible Slash Menu (`/`) and Block Inserter (`+`).
 */

import { tr } from '../i18n.js';
function iconMarkup(name) {
  return `<svg class="pc-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="/assets/admin/icons/sprite.svg#${name}"></use></svg>`;
}

export class SlashMenu {
  constructor(registry, capabilities, onSelect) {
    this.registry = registry;
    this.capabilities = capabilities;
    this.onSelect = onSelect;
    this.isOpen = false;
    this.activeIndex = 0;
    this.items = [];
    this.context = null;

    this.el = document.createElement('div');
    this.el.className = 'pc-ed-slash-menu';
    this.el.setAttribute('role', 'dialog');
    this.el.setAttribute('aria-label', tr('admin.js.editor.slash-menu.inserer_un_bloc', 'Insérer un bloc'));
    this.el.hidden = true;

    this.searchInput = document.createElement('input');
    this.searchInput.type = 'search';
    this.searchInput.className = 'pc-ed-slash-search';
    this.searchInput.placeholder = 'Filtrer un bloc (ex. titre, image, tableau)…';
    this.searchInput.setAttribute('aria-label', 'Filtrer les types de blocs');

    this.listEl = document.createElement('div');
    this.listEl.className = 'pc-ed-slash-list';
    this.listEl.setAttribute('role', 'listbox');

    this.el.append(this.searchInput, this.listEl);
    document.body.appendChild(this.el);

    this.searchInput.addEventListener('input', () => {
      this.renderItems(this.searchInput.value);
    });

    this.searchInput.addEventListener('keydown', (e) => this.handleKeyDown(e));

    this.onOutsideClick = (e) => {
      if (this.isOpen && e.target instanceof Node && !this.el.contains(e.target)) {
        this.close();
      }
    };
    document.addEventListener('mousedown', this.onOutsideClick);
  }

  open(anchorRect, context, initialQuery = '', focusSearch = false) {
    this.context = context;
    this.isOpen = true;
    this.el.hidden = false;
    this.searchInput.value = initialQuery;
    this.searchInput.hidden = !focusSearch;
    this.renderItems(initialQuery);

    const menuWidth = 300;
    const left = Math.max(12, Math.min(window.innerWidth - menuWidth - 12, anchorRect.left));
    const top = Math.min(window.innerHeight - 60, anchorRect.bottom + 6);

    this.el.style.left = `${left + window.scrollX}px`;
    this.el.style.top = `${top + window.scrollY}px`;

    if (focusSearch) {
      this.searchInput.focus();
    }
  }

  filter(query) {
    if (!this.isOpen) {
      return;
    }
    this.renderItems(query);
  }

  renderItems(query) {
    this.items = this.registry.search(query, this.capabilities);
    this.activeIndex = 0;
    this.listEl.innerHTML = '';

    if (this.items.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'pc-ed-slash-empty';
      empty.textContent = 'Aucun bloc correspondant.';
      this.listEl.appendChild(empty);
      return;
    }

    this.items.forEach((block, idx) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = `pc-ed-slash-item${idx === 0 ? ' is-active' : ''}`;
      btn.setAttribute('role', 'option');
      btn.setAttribute('aria-selected', idx === 0 ? 'true' : 'false');
      btn.innerHTML = `
        <span class="pc-ed-slash-icon">${iconMarkup(block.icon || 'sparkles')}</span>
        <span class="pc-ed-slash-meta">
          <span class="pc-ed-slash-title"></span>
          <span class="pc-ed-slash-desc"></span>
        </span>
      `;
      btn.querySelector('.pc-ed-slash-title').textContent = block.title;
      btn.querySelector('.pc-ed-slash-desc').textContent = block.description;

      btn.addEventListener('mouseenter', () => this.setActiveIndex(idx));
      btn.addEventListener('mousedown', (e) => e.preventDefault());
      btn.addEventListener('click', () => this.select(idx));

      this.listEl.appendChild(btn);
    });
  }

  setActiveIndex(idx) {
    if (this.items.length === 0) {
      return;
    }
    this.activeIndex = (idx + this.items.length) % this.items.length;
    const nodes = this.listEl.querySelectorAll('.pc-ed-slash-item');
    nodes.forEach((node, i) => {
      const active = i === this.activeIndex;
      node.classList.toggle('is-active', active);
      node.setAttribute('aria-selected', active ? 'true' : 'false');
      if (active) {
        node.scrollIntoView({ block: 'nearest' });
      }
    });
  }

  handleKeyDown(event) {
    if (!this.isOpen) {
      return false;
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      this.setActiveIndex(this.activeIndex + 1);
      return true;
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault();
      this.setActiveIndex(this.activeIndex - 1);
      return true;
    }
    if (event.key === 'Enter') {
      event.preventDefault();
      this.select(this.activeIndex);
      return true;
    }
    if (event.key === 'Escape') {
      event.preventDefault();
      this.close();
      return true;
    }
    return false;
  }

  select(index) {
    const chosen = this.items[index];
    const ctx = this.context;
    this.close();
    if (chosen && this.onSelect) {
      this.onSelect(chosen, ctx);
    }
  }

  close() {
    this.isOpen = false;
    this.el.hidden = true;
    this.context = null;
  }

  destroy() {
    document.removeEventListener('mousedown', this.onOutsideClick);
    this.el.remove();
  }
}
