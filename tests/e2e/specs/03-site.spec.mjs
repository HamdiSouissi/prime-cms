// Thème Aurora : accessibilité de 7 pages publiques et menu mobile au clavier.
import { test, expect } from '@playwright/test';
import { expectAccessible } from '../helpers.mjs';

test('pages publiques d’Aurora sans violation axe sérieuse', async ({ page }) => {
  await page.goto('/blog');
  const article = await page.locator('article.aurora-card a').first().getAttribute('href');
  const category = await page.locator('a[href^="/categorie/"]').first().getAttribute('href');

  for (const [label, url, status] of [
    ['accueil', '/', 200],
    ['blog', '/blog', 200],
    ['article', article, 200],
    ['page', '/a-propos', 200],
    ['catégorie', category, 200],
    ['recherche', '/recherche?q=site', 200],
    ['page introuvable', '/cette-page-n-existe-pas', 404],
  ]) {
    const response = await page.goto(url);
    expect(response?.status(), url).toBe(status);
    await expectAccessible(page, `Aurora — ${label}`);
  }
});

test('menu mobile utilisable au clavier', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/');
  const toggle = page.locator('[data-aurora-nav-toggle]');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');

  await toggle.focus();
  await page.keyboard.press('Enter');
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  const nav = page.locator('#aurora-primary-nav');
  await expect(nav.getByRole('link', { name: 'Contact' })).toBeVisible();

  await page.keyboard.press('Escape');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(toggle).toBeFocused();
});
