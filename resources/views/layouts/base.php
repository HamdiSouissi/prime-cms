<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $this->e($title ?? 'Prime CMS') ?></title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= $this->e(asset('brand/favicon-32.png')) ?>">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= $this->e(asset('brand/apple-touch-icon.png')) ?>">
  <style>
    :root {
      --brand-50: #FFF4F1;
      --brand-100: #FDE3DC;
      --brand-200: #FAC4B6;
      --brand-400: #F28468;
      --brand-500: #ED6647;
      --brand-600: #C9441F;
      --brand-700: #A8381A;

      --ink-900: #1A1D29;
      --ink-700: #3A3F52;
      --ink-500: #6B7085;
      --ink-300: #C9CCD6;
      --ink-200: #E6E4E1;
      --ink-100: #F3F1EE;
      --canvas: #FAF8F6;
      --surface: #FFFFFF;

      --violet: #7C5CFF;
      --teal: #14B8A6;
      --sky: #3B82F6;
      --amber: #F5A524;
      --success: #1F9D63;
      --danger: #D92D4A;

      --radius-badge: 6px;
      --radius-btn: 10px;
      --radius-tile: 12px;
      --radius-card: 14px;
      --shadow-card: 0 1px 2px rgba(26, 29, 41, .06), 0 4px 16px rgba(26, 29, 41, .06);
      --font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
    }

    *, *::before, *::after {
      box-sizing: border-box;
    }

    html {
      font-size: 14px;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    body {
      margin: 0;
      min-height: 100vh;
      background-color: var(--canvas);
      color: var(--ink-700);
      font-family: var(--font-sans);
      line-height: 1.55;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 24px 16px;
    }

    a {
      color: var(--brand-600);
      text-decoration: none;
      transition: color 160ms ease;
    }

    a:hover {
      color: var(--brand-700);
      text-decoration: underline;
    }

    a:focus-visible,
    button:focus-visible {
      outline: 2px solid var(--brand-500);
      outline-offset: 2px;
    }

    .pc-shell {
      width: 100%;
      max-width: 560px;
    }

    .pc-header {
      text-align: center;
      margin-bottom: 24px;
    }

    .pc-logo {
      width: 64px;
      height: 64px;
      display: block;
      margin: 0 auto 16px;
      border-radius: 16px;
      box-shadow: 0 4px 14px rgba(237, 102, 71, .22);
    }

    .pc-title-row {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .pc-title {
      margin: 0;
      font-size: 2rem;
      font-weight: 700;
      color: var(--ink-900);
      letter-spacing: -0.02em;
      line-height: 1.2;
    }

    .pc-subtitle {
      margin: 8px 0 0;
      font-size: 1.14rem;
      color: var(--ink-500);
    }

    .pc-card {
      background: var(--surface);
      border: 1px solid var(--ink-200);
      border-radius: var(--radius-card);
      box-shadow: var(--shadow-card);
      padding: 24px;
    }

    .pc-card-title {
      margin: 0 0 16px;
      font-size: 0.93rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--ink-500);
    }

    .pc-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 3px 10px;
      border-radius: var(--radius-badge);
      font-size: 0.86rem;
      font-weight: 600;
      line-height: 1.4;
      white-space: nowrap;
    }

    .pc-badge--brand {
      background: var(--brand-50);
      color: var(--brand-600);
      border: 1px solid var(--brand-100);
    }

    .pc-badge--success {
      background: #ECFDF3;
      color: #157A4B;
      border: 1px solid #C6F2DB;
    }

    .pc-badge--danger {
      background: #FFF0F3;
      color: #B81D39;
      border: 1px solid #FCCCD6;
    }

    .pc-badge--neutral {
      background: var(--ink-100);
      color: var(--ink-700);
      border: 1px solid var(--ink-200);
    }

    .pc-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      display: inline-block;
      flex-shrink: 0;
    }

    .pc-dot--success {
      background: var(--success);
    }

    .pc-dot--danger {
      background: var(--danger);
    }

    .pc-list {
      list-style: none;
      margin: 0;
      padding: 0;
    }

    .pc-list-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 11px 0;
      border-bottom: 1px solid var(--ink-100);
    }

    .pc-list-item:last-child {
      border-bottom: none;
      padding-bottom: 0;
    }

    .pc-list-item:first-child {
      padding-top: 0;
    }

    .pc-label {
      color: var(--ink-700);
      font-weight: 500;
    }

    .pc-mono {
      font-family: var(--font-mono);
      font-size: 0.9rem;
    }

    .pc-divider {
      height: 1px;
      background: var(--ink-200);
      margin: 18px 0;
    }

    .pc-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 10px 18px;
      border-radius: var(--radius-btn);
      font-size: 1rem;
      font-weight: 600;
      border: 1px solid transparent;
      cursor: pointer;
      transition: background-color 160ms ease, border-color 160ms ease, transform 160ms ease;
      text-decoration: none;
    }

    .pc-btn--primary {
      background: var(--brand-600);
      color: #FFFFFF;
    }

    .pc-btn--primary:hover {
      background: var(--brand-700);
      color: #FFFFFF;
      text-decoration: none;
    }

    .pc-footer {
      margin-top: 20px;
      text-align: center;
      font-size: 0.9rem;
      color: var(--ink-500);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .pc-error-card {
      text-align: center;
      padding: 36px 28px;
    }

    .pc-error-heading {
      margin: 16px 0 8px;
      font-size: 1.7rem;
      font-weight: 700;
      color: var(--ink-900);
      letter-spacing: -0.02em;
    }

    .pc-error-text {
      margin: 0 auto 24px;
      max-width: 400px;
      color: var(--ink-500);
      font-size: 1rem;
    }

    @media (max-width: 480px) {
      body {
        padding: 16px 12px;
      }

      .pc-card {
        padding: 18px;
      }

      .pc-error-card {
        padding: 28px 18px;
      }

      .pc-title {
        font-size: 1.7rem;
      }

      .pc-list-item {
        flex-wrap: wrap;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after {
        transition: none !important;
        animation: none !important;
      }
    }
  </style>
  <?= $this->section('head') ?>
</head>
<body>
  <main class="pc-shell">
    <?= $this->section('content') ?>
  </main>
</body>
</html>
