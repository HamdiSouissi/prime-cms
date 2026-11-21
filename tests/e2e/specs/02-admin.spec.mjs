// Parcours de l'administration sur le site installé par 01-install.
import { test, expect } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import { ADMIN, expectAccessible, login, totp } from '../helpers.mjs';

const IMAGE = fileURLToPath(new URL('../fixtures/illustration.png', import.meta.url));

test.describe.configure({ mode: 'serial' });

test('connexion refusée avec un mauvais mot de passe, page de connexion accessible', async ({ page }) => {
  await page.goto('/admin/login');
  await expectAccessible(page, 'connexion');
  await page.fill('input[name=email]', ADMIN.email);
  await page.fill('input[name=password]', 'mauvais-mot-de-passe');
  await page.click('form button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/login/);
});

test('écrans principaux de l’administration sans violation axe sérieuse', async ({ page }) => {
  await login(page);
  for (const [label, url] of [
    ['tableau de bord', '/admin'],
    ['liste des articles', '/admin/content/post'],
    ['édition d’un article', '/admin/content/post/create'],
    ['médiathèque', '/admin/media'],
    ['réglages généraux', '/admin/settings/general'],
    ['outils — état du système', '/admin/tools/system'],
    ['outils — mises à jour', '/admin/tools/updates'],
    ['profil', '/admin/profile'],
    ['plugins', '/admin/plugins'],
  ]) {
    await page.goto(url);
    await expect(page.locator('main')).toBeVisible();
    await expectAccessible(page, label);
  }
});

test('un article avec image est publié et rendu sur le site', async ({ page }) => {
  await login(page);
  await page.goto('/admin/content/post/create');
  await page.fill('input[name=title]', 'Visite de l’atelier');

  await page.click('[data-prime-media-choose]');
  const picker = page.locator('.pc-media-picker');
  await picker.locator('[data-prime-picker-tab=upload]').click();
  await picker.locator('[data-prime-picker-file-input]').setInputFiles(IMAGE);
  const confirm = picker.locator('[data-prime-picker-confirm]');
  await expect(confirm).toBeEnabled({ timeout: 20_000 });
  await confirm.click();
  await expect(page.locator('input[name=featured_media_id]')).not.toHaveValue('');

  await Promise.all([
    page.waitForURL(/\/admin\/content\/post\/\d+\/edit/),
    page.locator('form[action="/admin/content/post"] button[type=submit]', { hasText: 'Publier' }).click(),
  ]);

  await page.goto('/blog/visite-de-l-atelier');
  await expect(page.getByRole('heading', { level: 1, name: 'Visite de l’atelier' })).toBeVisible();
  await expect(page.locator('img[src*="/uploads/"]').first()).toBeVisible();
});

test('menu, recherche et page publique', async ({ page }) => {
  await page.goto('/');
  const nav = page.getByRole('navigation').first();
  await nav.getByRole('link', { name: 'Contact' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Contact' })).toBeVisible();

  await page.locator('[aria-controls=aurora-header-search]').click();
  await page.fill('#aurora-header-q', 'atelier');
  await Promise.all([page.waitForURL(/q=atelier/), page.press('#aurora-header-q', 'Enter')]);
  await expect(page.getByRole('link', { name: 'Visite de l’atelier' }).first()).toBeVisible();
});

test('réglages : le slogan modifié apparaît sur le site', async ({ page }) => {
  await login(page);
  await page.goto('/admin/settings/general');
  const tagline = page.locator('[name="settings[site.tagline]"]');
  await tagline.fill('Lumière et matière');
  await Promise.all([page.waitForURL(/\/admin\/settings\/general/), page.locator('form[action="/admin/settings/general"] button[type=submit]').click()]);
  await expect(page.locator('[name="settings[site.tagline]"]')).toHaveValue('Lumière et matière');
  await page.goto('/');
  await expect(page.locator('body')).toContainText('Lumière et matière');
});

test('langue de l’interface : passage à l’anglais puis retour au français', async ({ page }) => {
  await login(page);
  await page.goto('/admin/profile');
  await page.selectOption('select[name=locale]', 'en');
  await Promise.all([page.waitForLoadState('load'), page.locator('form[action="/admin/profile/info"] button[type=submit]').click()]);
  await page.goto('/admin');
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  await expect(page.getByRole('link', { name: 'Dashboard' }).first()).toBeVisible();

  await page.goto('/admin/profile');
  await page.selectOption('select[name=locale]', 'fr');
  await page.locator('form[action="/admin/profile/info"] button[type=submit]').click();
  await page.goto('/admin');
  await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
});

test('jeton d’API créé dans le profil puis utilisé', async ({ page, request }) => {
  await login(page);
  await page.goto('/admin/profile');
  await page.getByRole('tab', { name: /API/ }).click();
  const form = page.locator('form[action="/admin/profile/api-tokens"]');
  await form.locator('input[name=name]').fill('Intégration e2e');
  await Promise.all([page.waitForLoadState('load'), form.locator('button[type=submit]').click()]);

  const token = (await page.locator('[data-prime-new-token]').textContent())?.trim() ?? '';
  expect(token).toMatch(/^pcat_[a-f0-9]{8}_[a-f0-9]{40}$/);

  const me = await request.get('/api/v1/me', { headers: { Authorization: `Bearer ${token}` } });
  expect(me.status()).toBe(200);
  expect((await me.json()).data.email).toBe(ADMIN.email);

  const anonymous = await request.get('/api/v1/me');
  expect(anonymous.status()).toBe(401);
});

test('prime-starter : désactivation puis réactivation', async ({ page }) => {
  await login(page);
  await page.goto('/admin/plugins');
  await Promise.all([page.waitForLoadState('load'), page.locator('form[action="/admin/plugins/prime-starter/deactivate"] button').click()]);
  await expect(page.locator('form[action="/admin/plugins/prime-starter/activate"]')).toBeVisible();
  expect((await page.request.get('/notes')).status()).toBe(404);

  await Promise.all([page.waitForLoadState('load'), page.locator('form[action="/admin/plugins/prime-starter/activate"] button').click()]);
  await expect(page.locator('form[action="/admin/plugins/prime-starter/deactivate"]')).toBeVisible();
  expect((await page.request.get('/notes')).status()).toBe(200);
});

test('mise à jour depuis Outils › Mises à jour', async ({ page }) => {
  await login(page);
  await page.goto('/admin/tools/updates');
  await page.locator('label[for=backup-manual]').click();
  await page.locator('label[for=backup-done]').click();
  await Promise.all([page.waitForLoadState('load'), page.locator('form[action="/admin/tools/updates/run"] button[type=submit]').click()]);
  await expect(page.getByText(/Mise à jour terminée/)).toBeVisible();
  await expect(page.getByText('À jour', { exact: true })).toBeVisible();
});

test('double authentification activée puis utilisée à la connexion', async ({ page, context }) => {
  await login(page);
  await page.goto('/admin/profile');
  await page.getByRole('tab', { name: /Sécurité/ }).click();
  await Promise.all([page.waitForLoadState('load'), page.locator('form[action="/admin/profile/2fa/start"] button').click()]);
  const secret = (await page.locator('#pc-totp-secret-display').textContent())?.replace(/\s/g, '') ?? '';
  expect(secret.length).toBeGreaterThanOrEqual(16);
  if (!(await page.locator('#profile-2fa-confirm-code').isVisible())) {
    await page.getByRole('tab', { name: /Sécurité/ }).click();
  }
  await page.fill('#profile-2fa-confirm-code', totp(secret));
  await Promise.all([page.waitForLoadState('load'), page.locator('#profile-2fa-confirm-code').press('Enter')]);
  await expect(page.getByRole('tab', { name: /2FA/ })).toBeVisible();

  await context.clearCookies();
  // A code is accepted once: wait for the next 30-second window before signing in again.
  await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 1_000);
  await login(page, { code: () => totp(secret) });
  await expect(page).toHaveURL(/\/admin\/?$/);
});
