/**
 * Prime Editor — Pure immutable Undo / Redo history stack (zero DOM dependencies).
 */

function deepClone(value) {
  return JSON.parse(JSON.stringify(value));
}

export class HistoryStack {
  /**
   * @param {any} initialState
   * @param {number} maxSize
   */
  constructor(initialState, maxSize = 100) {
    this.maxSize = Math.max(2, maxSize);
    this.entries = [deepClone(initialState)];
    this.index = 0;
  }

  /**
   * Push a new state snapshot. Discards any redo branch and skips identical consecutive states.
   *
   * @param {any} state
   * @returns {boolean} Whether a new snapshot was recorded
   */
  push(state) {
    const serialized = JSON.stringify(state);
    const currentSerialized = JSON.stringify(this.entries[this.index]);
    if (serialized === currentSerialized) {
      return false;
    }

    if (this.index < this.entries.length - 1) {
      this.entries = this.entries.slice(0, this.index + 1);
    }

    this.entries.push(JSON.parse(serialized));
    if (this.entries.length > this.maxSize) {
      this.entries.shift();
    }
    this.index = this.entries.length - 1;
    return true;
  }

  canUndo() {
    return this.index > 0;
  }

  canRedo() {
    return this.index < this.entries.length - 1;
  }

  undo() {
    if (!this.canUndo()) {
      return null;
    }
    this.index -= 1;
    return deepClone(this.entries[this.index]);
  }

  redo() {
    if (!this.canRedo()) {
      return null;
    }
    this.index += 1;
    return deepClone(this.entries[this.index]);
  }

  current() {
    return deepClone(this.entries[this.index]);
  }

  size() {
    return this.entries.length;
  }

  reset(state) {
    this.entries = [deepClone(state)];
    this.index = 0;
  }
}
