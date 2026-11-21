// Prime CMS — tests de bout en bout et audit d'accessibilité.
// Les specs se suivent (01 → 03) sur une installation neuve : un seul worker, aucun parallélisme.
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './specs',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 90_000,
  expect: { timeout: 10_000 },
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  outputDir: './test-results',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8080',
    locale: 'fr-FR',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    ...devices['Desktop Chrome'],
    viewport: { width: 1360, height: 900 },
  },
});
