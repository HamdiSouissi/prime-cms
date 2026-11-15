/**
 * Prime Editor — Extensible block registry (supports `window.Prime.editor.registerBlock(...)`).
 */

const BLOCK_TYPE_RE = /^[a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?$/;

export class EditorBlockRegistry {
  constructor() {
    /** @type {Map<string, object>} */
    this.blocks = new Map();
    /** @type {Set<Function>} */
    this.listeners = new Set();
  }

  /**
   * Register a block type definition.
   *
   * @param {{
   *   type: string,
   *   title: string,
   *   description?: string,
   *   icon?: string,
   *   category?: string,
   *   keywords?: string[],
   *   requiresCapability?: string|null,
   *   defaultData?: () => object,
   *   render: (ctx: object) => HTMLElement
   * }} definition
   */
  registerBlock(definition) {
    if (!definition || typeof definition !== 'object') {
      throw new Error('Prime Editor: block definition must be an object.');
    }
    const type = String(definition.type || '').trim();
    if (!BLOCK_TYPE_RE.test(type)) {
      throw new Error(`Prime Editor: invalid block type "${type}".`);
    }
    const title = String(definition.title || '').trim();
    if (!title) {
      throw new Error(`Prime Editor: block "${type}" must specify a title.`);
    }
    if (typeof definition.render !== 'function') {
      throw new Error(`Prime Editor: block "${type}" must define a render() function.`);
    }

    const normalized = {
      type,
      title,
      description: String(definition.description || ''),
      icon: String(definition.icon || 'sparkles'),
      category: String(definition.category || 'plugins'),
      keywords: Array.isArray(definition.keywords) ? definition.keywords.map(String) : [],
      requiresCapability: definition.requiresCapability ?? null,
      defaultData: typeof definition.defaultData === 'function' ? definition.defaultData : () => ({}),
      render: definition.render,
    };

    this.blocks.set(type, normalized);
    this.listeners.forEach((fn) => fn(normalized));
    return normalized;
  }

  get(type) {
    return this.blocks.get(type) || null;
  }

  has(type) {
    return this.blocks.has(type);
  }

  list() {
    return Array.from(this.blocks.values());
  }

  /**
   * Search registered blocks by query string across title, type, description, and keywords.
   */
  search(query, allowedCapabilities = null) {
    const needle = String(query || '').trim().toLowerCase();
    return this.list().filter((block) => {
      if (block.requiresCapability && allowedCapabilities && !allowedCapabilities.has(block.requiresCapability)) {
        return false;
      }
      if (!needle) {
        return true;
      }
      const haystack = [block.type, block.title, block.description, ...block.keywords].join(' ').toLowerCase();
      return haystack.includes(needle);
    });
  }

  onRegister(listener) {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }
}

export const defaultBlockRegistry = new EditorBlockRegistry();
