// Outils partagés des specs : identifiants, connexion, audit axe, code TOTP.
import { createHmac } from 'node:crypto';
import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';

export const ADMIN = {
  name: 'Camille Martin',
  email: 'camille@prime.test',
  password: 'Prime-E2E-Corail-2027!',
};

export const DB = {
  host: process.env.E2E_DB_HOST ?? 'db',
  name: process.env.E2E_DB_NAME ?? 'prime_cms',
  user: process.env.E2E_DB_USER ?? 'prime',
  password: process.env.E2E_DB_PASSWORD ?? 'secret',
};

/** Fails on any `serious` or `critical` WCAG 2.2 A/AA violation, with a readable summary. */
export async function expectAccessible(page, label) {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
    .analyze();
  const blocking = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
  const summary = blocking.map((v) => `${v.impact} ${v.id}: ${v.help} — ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
  expect(summary, `axe sur « ${label} »`).toEqual([]);
}

export async function login(page, { email = ADMIN.email, password = ADMIN.password, code = null } = {}) {
  await page.goto('/admin/login');
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', password);
  await Promise.all([page.waitForURL(/\/admin(\/login\/two-factor)?/), page.click('form button[type=submit]')]);
  if (code !== null && page.url().includes('two-factor')) {
    await page.fill('input[name=code]', typeof code === 'function' ? code() : code);
    await Promise.all([page.waitForURL(/\/admin\/?$/), page.click('form button[type=submit]')]);
  }
}

/** RFC 6238 code (SHA-1, 6 digits, 30 s) from a base32 secret. */
export function totp(secret, at = Date.now()) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of secret.replace(/[\s=]/g, '').toUpperCase()) {
    bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
  }
  const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));
  const hmac = createHmac('sha1', key).update(counter).digest();
  const offset = hmac[hmac.length - 1] & 0xf;
  const value = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;
  return String(value).padStart(6, '0');
}
