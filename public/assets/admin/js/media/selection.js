/**
 * Prime Media — Pure ES2022 multi-selection state manager with Shift+click range support.
 */

export class SelectionState {
  constructor(initialIds = []) {
    /** @type {Set<number>} */
    this.selected = new Set();
    /** @type {number | null} */
    this.anchorId = null;

    for (const raw of initialIds) {
      const id = Number(raw);
      if (Number.isInteger(id) && id > 0) {
        this.selected.add(id);
      }
    }
  }

  /**
   * Toggle or select an item ID, supporting single-select, multi-select, and Shift+click ranges.
   *
   * @param {number} rawId
   * @param {{ multiple?: boolean, shiftKey?: boolean, orderedIds?: number[] }} [options]
   * @returns {number[]} Updated list of selected IDs
   */
  toggle(rawId, { multiple = true, shiftKey = false, orderedIds = [] } = {}) {
    const id = Number(rawId);
    if (!Number.isInteger(id) || id <= 0) {
      return this.ids();
    }

    if (!multiple) {
      if (this.selected.has(id) && this.selected.size === 1) {
        this.selected.clear();
        this.anchorId = null;
      } else {
        this.selected.clear();
        this.selected.add(id);
        this.anchorId = id;
      }
      return this.ids();
    }

    if (shiftKey && this.anchorId !== null && orderedIds.length > 0) {
      const normalized = orderedIds.map((v) => Number(v));
      const startIdx = normalized.indexOf(this.anchorId);
      const endIdx = normalized.indexOf(id);

      if (startIdx !== -1 && endIdx !== -1) {
        const [low, high] = startIdx <= endIdx ? [startIdx, endIdx] : [endIdx, startIdx];
        for (let i = low; i <= high; i += 1) {
          const rangeId = normalized[i];
          if (Number.isInteger(rangeId) && rangeId > 0) {
            this.selected.add(rangeId);
          }
        }
        return this.ids();
      }
    }

    if (this.selected.has(id)) {
      this.selected.delete(id);
      if (this.anchorId === id) {
        this.anchorId = this.selected.size > 0 ? Array.from(this.selected).pop() ?? null : null;
      }
    } else {
      this.selected.add(id);
      this.anchorId = id;
    }

    return this.ids();
  }

  /**
   * Explicitly add an ID to the selection (e.g., auto-selecting newly uploaded files in picker).
   *
   * @param {number} rawId
   * @param {{ multiple?: boolean }} [options]
   */
  add(rawId, { multiple = true } = {}) {
    const id = Number(rawId);
    if (!Number.isInteger(id) || id <= 0) {
      return this.ids();
    }
    if (!multiple) {
      this.selected.clear();
    }
    this.selected.add(id);
    this.anchorId = id;
    return this.ids();
  }

  /**
   * Remove a specific ID (e.g., after deletion).
   *
   * @param {number} rawId
   */
  remove(rawId) {
    const id = Number(rawId);
    this.selected.delete(id);
    if (this.anchorId === id) {
      this.anchorId = this.selected.size > 0 ? Array.from(this.selected).pop() ?? null : null;
    }
    return this.ids();
  }

  /**
   * Select all visible IDs at once.
   *
   * @param {number[]} orderedIds
   */
  selectAll(orderedIds) {
    for (const raw of orderedIds) {
      const id = Number(raw);
      if (Number.isInteger(id) && id > 0) {
        this.selected.add(id);
      }
    }
    if (orderedIds.length > 0) {
      this.anchorId = Number(orderedIds[orderedIds.length - 1]);
    }
    return this.ids();
  }

  clear() {
    this.selected.clear();
    this.anchorId = null;
    return [];
  }

  has(rawId) {
    return this.selected.has(Number(rawId));
  }

  count() {
    return this.selected.size;
  }

  /**
   * @returns {number[]}
   */
  ids() {
    return Array.from(this.selected);
  }
}
