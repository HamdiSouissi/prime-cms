/**
 * Prime CMS — L3 `Prime.t()` front-end translation helper contract tests.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, test } from './run.mjs';
import {
  choice,
  initTranslations,
  messages,
  resetTranslations,
  t,
} from '../../public/assets/admin/js/i18n.js';

/**
 * Minimal stand-in for the `<script type="application/json" data-prime-translations>` block
 * injected by the admin layout (no DOM implementation is available in this runner).
 *
 * @param {Record<string, unknown>|null} payload
 * @returns {{querySelector: (selector: string) => ({textContent: string}|null)}}
 */
function rootWith(payload) {
  const text = payload === null ? '' : JSON.stringify(payload);

  return {
    querySelector: (selector) =>
      selector.includes('data-prime-translations') ? { textContent: text } : null,
  };
}

describe('Prime i18n — front-end catalogue helpers', () => {
  test('unknown keys fall back to the key itself, like the PHP translator', () => {
    resetTranslations();

    assert.equal(t('core.actions.save'), 'core.actions.save');
    assert.equal(choice('core.media.count', 3), 'core.media.count');
  });

  test('initTranslations reads the embedded payload and reports locale metadata', () => {
    resetTranslations();

    const result = initTranslations(
      rootWith({
        locale: 'fr',
        direction: 'ltr',
        messages: { 'core.actions.save': 'Enregistrer', 'admin.media.count': ':count média|:count médias' },
        fallback: { 'core.actions.cancel': 'Cancel' },
      }),
    );

    assert.equal(result.locale, 'fr');
    assert.equal(result.direction, 'ltr');
    assert.equal(result.count, 2);
    assert.equal(t('core.actions.save'), 'Enregistrer');
    assert.equal(t('core.actions.cancel'), 'Cancel', 'Fallback catalogue must be consulted.');
  });

  test('named placeholders are replaced and unknown ones are preserved', () => {
    resetTranslations();
    initTranslations(
      rootWith({
        locale: 'fr',
        direction: 'ltr',
        messages: { 'admin.users.created': 'Utilisateur « :name » créé par :author.' },
        fallback: {},
      }),
    );

    assert.equal(
      t('admin.users.created', { name: 'Camille' }),
      'Utilisateur « Camille » créé par :author.',
    );
  });

  test('positional %s placeholders are replaced in order', () => {
    resetTranslations();
    initTranslations(
      rootWith({
        locale: 'fr',
        direction: 'ltr',
        messages: { 'admin.redirect.created': 'Redirection %s → %s.' },
        fallback: {},
      }),
    );

    assert.equal(t('admin.redirect.created', { 0: '/a', 1: '/b' }), 'Redirection /a → /b.');
  });

  test('choice selects the plural segment and injects :count', () => {
    resetTranslations();
    initTranslations(
      rootWith({
        locale: 'fr',
        direction: 'ltr',
        messages: { 'core.media.count': 'un média|:count médias' },
        fallback: {},
      }),
    );

    assert.equal(choice('core.media.count', 1), 'un média');
    assert.equal(choice('core.media.count', 4), '4 médias');
  });

  test('a truncated catalogue degrades to the shipped markup instead of throwing', () => {
    resetTranslations();
    initTranslations({ querySelector: () => ({ textContent: '{not json' }) });

    assert.equal(t('core.actions.save'), 'core.actions.save');
    assert.deepEqual(messages(), {});
  });

  test('the admin bundle loads the catalogue before any module runs', async () => {
    // Regression: `bootstrap()` once skipped `initTranslations()`, so every `tr()` call fell back
    // to the shipped French literal and the English admin rendered French.
    const bundle = readFileSync(new URL('../../public/assets/admin/js/admin.js', import.meta.url), 'utf8');
    const bootstrap = bundle.slice(bundle.indexOf('function bootstrap()'));
    const firstCall = bootstrap.match(/^\s*([a-zA-Z]+)\(/m);

    assert.equal(firstCall?.[1], 'initTranslations');
  });

  test('initTranslations is idempotent and safe without a catalogue block', () => {
    resetTranslations();
    initTranslations({ querySelector: () => null });
    const second = initTranslations(rootWith(null));

    assert.equal(second.count, 0);
    assert.equal(second.locale, 'fr');
  });
});
