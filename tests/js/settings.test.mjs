/**
 * Prime CMS — conditional settings fields (Réglages › E-mails, Avancé…).
 */

import assert from 'node:assert/strict';
import { describe, test } from './run.mjs';
import { conditionMet } from '../../public/assets/admin/js/settings-admin.js';

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
