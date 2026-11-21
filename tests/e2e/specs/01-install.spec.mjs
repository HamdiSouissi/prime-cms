// Installation web complète (assistant), avec le contenu de démonstration coché.
import { test, expect } from '@playwright/test';
import { ADMIN, DB, expectAccessible } from '../helpers.mjs';

test('l’assistant installe le site, accessible à chaque étape', async ({ page }) => {
  await page.goto('/install');
  await expectAccessible(page, 'installateur — bienvenue');
  await Promise.all([page.waitForURL('**/install/requirements'), page.click('form button[type=submit]')]);

  await expectAccessible(page, 'installateur — prérequis');
  await Promise.all([page.waitForURL('**/install/database'), page.click('main a.pc-btn--primary')]);

  await expectAccessible(page, 'installateur — base de données');
  await page.fill('#db-host', DB.host);
  await page.fill('#db-database', DB.name);
  await page.fill('#db-username', DB.user);
  await page.fill('#db-password', DB.password);
  await Promise.all([page.waitForURL('**/install/site'), page.click('button[value=continue]')]);

  await expectAccessible(page, 'installateur — site');
  await page.fill('#site-name', 'Atelier Lumière');
  await Promise.all([page.waitForURL('**/install/admin'), page.click('form button[type=submit]')]);

  await expectAccessible(page, 'installateur — administrateur');
  await page.fill('#admin-display-name', ADMIN.name);
  await page.fill('#admin-email', ADMIN.email);
  await page.fill('#admin-password', ADMIN.password);
  await page.fill('#admin-password-confirm', ADMIN.password);
  await Promise.all([page.waitForURL('**/install/run'), page.click('form button[type=submit]')]);

  await expectAccessible(page, 'installateur — récapitulatif');
  const demo = page.locator('#install-demo-content');
  await expect(demo).not.toBeChecked();
  await page.locator('label[for=install-demo-content]').click();
  await Promise.all([page.waitForURL('**/install/done', { timeout: 60_000 }), page.click('form[action="/install/run"] button[type=submit]')]);

  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await expectAccessible(page, 'installateur — terminé');

  await page.goto('/');
  await expect(page).toHaveTitle(/Atelier Lumière/);
  await expect(page.getByText('Un site prêt à accueillir vos contenus')).toBeVisible();
});
