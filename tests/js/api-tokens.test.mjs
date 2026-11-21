/**
 * Prime CMS — shortcuts of the API token creation form (Mon profil › Jetons d'API).
 */

import assert from 'node:assert/strict';
import { describe, test } from './run.mjs';
import { abilitiesForPreset } from '../../public/assets/admin/js/admin-users.js';

describe('API tokens — capability shortcuts', () => {
  const offered = ['dashboard.view', 'content.view', 'content.create', 'media.view', 'settings.manage'];

  test('read-only keeps the reading capabilities only', () => {
    assert.deepEqual([...abilitiesForPreset('readonly', offered)], ['dashboard.view', 'content.view', 'media.view']);
  });

  test('all ticks every capability offered, none clears them', () => {
    assert.equal(abilitiesForPreset('all', offered).size, offered.length);
    assert.equal(abilitiesForPreset('none', offered).size, 0);
    assert.equal(abilitiesForPreset('unknown', offered).size, 0);
  });
});
