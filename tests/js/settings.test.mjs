/**
 * Prime CMS — conditional settings fields (Réglages › E-mails, Avancé…).
 */

import assert from 'node:assert/strict';
import { describe, test } from './run.mjs';
import { conditionMet, formatPhpDate, trackUnsavedChanges } from '../../public/assets/admin/js/settings-admin.js';

describe('Settings — conditional fields', () => {
  test('a list of expected values matches any of them', () => {
    const condition = { field: 'mail.driver', value: ['smtp', 'queue'] };
    assert.equal(conditionMet(condition, ['smtp']), true);
    assert.equal(conditionMet(condition, ['queue']), true);
    assert.equal(conditionMet(condition, ['log']), false);
    assert.equal(conditionMet(condition, []), false);
  });

  test('a scalar expected value is compared as a string (toggles post "1"/"0")', () => {
    const condition = { field: 'mail.log_enabled', value: '1' };
    assert.equal(conditionMet(condition, ['1']), true);
    assert.equal(conditionMet(condition, ['0']), false);
    assert.equal(conditionMet({ field: 'x', value: 1 }, ['1']), true);
  });
});

describe('Settings — live date format preview', () => {
  const date = new Date(2026, 10, 17, 9, 5, 7); // mardi 17 novembre 2026, 09:05:07
  const names = {
    months: ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
    days: ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'],
  };

  test('numeric presets', () => {
    assert.equal(formatPhpDate('d/m/Y', date, names), '17/11/2026');
    assert.equal(formatPhpDate('Y-m-d', date, names), '2026-11-17');
    assert.equal(formatPhpDate('H:i', date, names), '09:05');
    assert.equal(formatPhpDate('g:i A', date, names), '9:05 AM');
  });

  test('names come from the interface language and backslash escapes a literal', () => {
    assert.equal(formatPhpDate('l j F Y', date, names), 'mardi 17 novembre 2026');
    assert.equal(formatPhpDate('G\\hi', date, names), '9h05');
    assert.equal(formatPhpDate('F j, Y', date), 'November 17, 2026');
  });
});

describe('Settings — unsaved changes warning', () => {
  test('a form becomes dirty on input and clean again once submitted', () => {
    const listeners = {};
    const form = { addEventListener: (type, cb) => { listeners[type] = cb; } };
    const winListeners = {};
    const previousWindow = globalThis.window;
    globalThis.window = { addEventListener: (type, cb) => { winListeners[type] = cb; } };
    try {
      const state = trackUnsavedChanges(form);
      assert.equal(state.dirty, false);
      listeners.input();
      assert.equal(state.dirty, true);
      const event = { preventDefault() { this.prevented = true; }, returnValue: undefined };
      winListeners.beforeunload(event);
      assert.equal(event.prevented, true);
      listeners.submit();
      const later = { preventDefault() { this.prevented = true; } };
      winListeners.beforeunload(later);
      assert.equal(later.prevented, undefined);
    } finally {
      globalThis.window = previousWindow;
    }
  });
});
