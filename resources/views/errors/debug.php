<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Erreur <?= $this->e($status ?? 500) ?> — <?= $this->e($exceptionClass ?? 'Exception') ?> · Prime CMS</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= $this->e(asset('brand/favicon-32.png')) ?>">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= $this->e(asset('brand/apple-touch-icon.png')) ?>">
  <style>
    :root {
      --brand-50: #FFF4F1;
      --brand-100: #FDE3DC;
      --brand-500: #ED6647;
      --brand-600: #C9441F;
      --brand-700: #A8381A;
      --ink-900: #1A1D29;
      --ink-700: #3A3F52;
      --ink-500: #6B7085;
      --ink-200: #E6E4E1;
      --ink-100: #F3F1EE;
      --canvas: #FAF8F6;
      --surface: #FFFFFF;
      --danger: #D92D4A;
      --radius-badge: 6px;
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
    }

    body {
      margin: 0;
      min-height: 100vh;
      background: var(--canvas);
      color: var(--ink-700);
      font-family: var(--font-sans);
      line-height: 1.55;
    }

    .dbg-banner {
      background: var(--ink-900);
      color: #FFFFFF;
      padding: 24px 20px;
      border-bottom: 3px solid var(--brand-500);
    }

    .dbg-container {
      max-width: 1040px;
      margin: 0 auto;
    }

    .dbg-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 16px;
    }

    .dbg-brand {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      font-weight: 600;
      color: #FFFFFF;
    }

    .dbg-brand img {
      width: 28px;
      height: 28px;
      border-radius: 7px;
    }

    .dbg-pill {
      display: inline-flex;
      align-items: center;
      padding: 3px 10px;
      border-radius: var(--radius-badge);
      font-family: var(--font-mono);
      font-size: 0.85rem;
      font-weight: 600;
    }

    .dbg-pill--coral {
      background: rgba(237, 102, 71, .18);
      color: #FDE3DC;
      border: 1px solid rgba(237, 102, 71, .4);
    }

    .dbg-pill--request {
      background: rgba(255, 255, 255, .1);
      color: #E6E4E1;
    }

    .dbg-class {
      font-family: var(--font-mono);
      font-size: 0.95rem;
      color: #F28468;
      margin: 0 0 6px;
      word-break: break-word;
    }

    .dbg-message {
      margin: 0 0 12px;
      font-size: 1.55rem;
      font-weight: 700;
      color: #FFFFFF;
      line-height: 1.3;
      word-break: break-word;
    }

    .dbg-location {
      font-family: var(--font-mono);
      font-size: 0.9rem;
      color: #C9CCD6;
      word-break: break-all;
    }

    .dbg-main {
      padding: 24px 20px 48px;
    }

    .dbg-card {
      background: var(--surface);
      border: 1px solid var(--ink-200);
      border-radius: var(--radius-card);
      box-shadow: var(--shadow-card);
      margin-bottom: 20px;
      overflow: hidden;
    }

    .dbg-card-header {
      padding: 14px 20px;
      border-bottom: 1px solid var(--ink-200);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
      background: #FCFBF9;
    }

    .dbg-card-title {
      margin: 0;
      font-size: 0.95rem;
      font-weight: 700;
      color: var(--ink-900);
    }

    .dbg-code-wrap {
      overflow-x: auto;
      padding: 10px 0;
      font-family: var(--font-mono);
      font-size: 0.9rem;
      line-height: 1.65;
    }

    .dbg-code-line {
      display: flex;
      padding: 1px 16px 1px 0;
      white-space: pre;
    }

    .dbg-code-line--active {
      background: var(--brand-50);
      border-left: 3px solid var(--brand-500);
    }

    .dbg-line-num {
      display: inline-block;
      min-width: 56px;
      padding: 0 14px 0 12px;
      text-align: right;
      color: var(--ink-500);
      user-select: none;
      flex-shrink: 0;
    }

    .dbg-code-line--active .dbg-line-num {
      color: var(--brand-600);
      font-weight: 700;
      padding-left: 9px;
    }

    .dbg-line-text {
      color: var(--ink-900);
    }

    .dbg-trace-list {
      list-style: none;
      margin: 0;
      padding: 0;
    }

    .dbg-trace-item {
      padding: 12px 20px;
      border-bottom: 1px solid var(--ink-100);
      display: flex;
      gap: 14px;
      align-items: baseline;
    }

    .dbg-trace-item:last-child {
      border-bottom: none;
    }

    .dbg-frame-idx {
      font-family: var(--font-mono);
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--brand-600);
      min-width: 28px;
      flex-shrink: 0;
    }

    .dbg-frame-body {
      min-width: 0;
      flex: 1;
    }

    .dbg-frame-call {
      font-family: var(--font-mono);
      font-size: 0.9rem;
      font-weight: 600;
      color: var(--ink-900);
      word-break: break-word;
    }

    .dbg-frame-file {
      font-family: var(--font-mono);
      font-size: 0.85rem;
      color: var(--ink-500);
      margin-top: 2px;
      word-break: break-all;
    }

    @media (max-width: 480px) {
      .dbg-banner {
        padding: 18px 14px;
      }

      .dbg-main {
        padding: 16px 14px 36px;
      }

      .dbg-message {
        font-size: 1.25rem;
      }

      .dbg-trace-item {
        padding: 10px 14px;
        gap: 10px;
      }
    }
  </style>
</head>
<body>
  <header class="dbg-banner">
    <div class="dbg-container">
      <div class="dbg-top">
        <div class="dbg-brand">
          <img src="<?= $this->e(asset('brand/logo.svg')) ?>" alt="Prime CMS">
          <span>Prime CMS · Mode développement</span>
        </div>
        <div>
          <span class="dbg-pill dbg-pill--coral">HTTP <?= $this->e($status ?? 500) ?></span>
          <span class="dbg-pill dbg-pill--request"><?= $this->e(($requestMethod ?? 'GET') . ' ' . ($requestPath ?? '/')) ?></span>
        </div>
      </div>

      <p class="dbg-class"><?= $this->e($exceptionClass ?? 'Throwable') ?></p>
      <h1 class="dbg-message"><?= $this->e(($message ?? '') !== '' ? $message : 'Exception sans message') ?></h1>
      <div class="dbg-location">
        <?= $this->e($file ?? '') ?>:<strong><?= $this->e($line ?? 0) ?></strong>
      </div>
    </div>
  </header>

  <main class="dbg-main">
    <div class="dbg-container">
      <?php if (!empty($excerpt)): ?>
        <section class="dbg-card" aria-labelledby="excerpt-heading">
          <div class="dbg-card-header">
            <h2 id="excerpt-heading" class="dbg-card-title">Extrait du code source</h2>
            <span class="dbg-frame-file"><?= $this->e($file ?? '') ?></span>
          </div>
          <div class="dbg-code-wrap" role="region" aria-label="Code autour de la ligne en erreur">
            <?php foreach ($excerpt as $row): ?>
              <div class="dbg-code-line<?= !empty($row['highlight']) ? ' dbg-code-line--active' : '' ?>">
                <span class="dbg-line-num"><?= $this->e($row['number']) ?></span>
                <span class="dbg-line-text"><?= $this->e($row['code']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <section class="dbg-card" aria-labelledby="trace-heading">
        <div class="dbg-card-header">
          <h2 id="trace-heading" class="dbg-card-title">Pile d'appels (<?= $this->e(count($trace ?? [])) ?>)</h2>
        </div>
        <ol class="dbg-trace-list">
          <?php foreach (($trace ?? []) as $index => $frame): ?>
            <?php
              $call = '';
              if (isset($frame['class'])) {
                  $call .= $frame['class'] . ($frame['type'] ?? '::');
              }
              $call .= ($frame['function'] ?? '{main}') . '()';
              $frameLocation = isset($frame['file'])
                  ? $frame['file'] . ':' . ($frame['line'] ?? 0)
                  : '[appel interne]';
            ?>
            <li class="dbg-trace-item">
              <span class="dbg-frame-idx">#<?= $this->e($index) ?></span>
              <div class="dbg-frame-body">
                <div class="dbg-frame-call"><?= $this->e($call) ?></div>
                <div class="dbg-frame-file"><?= $this->e($frameLocation) ?></div>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      </section>
    </div>
  </main>
</body>
</html>
