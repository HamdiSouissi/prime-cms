/**
 * Prime CMS Admin — Live SERP preview and character counters in the content editor SEO panel.
 */

import { tr } from './i18n.js';

/**
 * Compute live SEO counters and preview strings.
 *
 * @param {{ seoTitle: string, fallbackTitle: string, seoDesc: string, fallbackDesc: string, siteName: string, sep: string }} input
 */
export function computeSerpPreview(input) {
  const rawTitle = (input.seoTitle || '').trim();
  const baseTitle = rawTitle || (input.fallbackTitle || '').trim() || 'Sans titre';
  const renderedTitle = rawTitle || (input.siteName ? `${baseTitle} ${input.sep || '—'} ${input.siteName}` : baseTitle);
  const desc = (input.seoDesc || '').trim() || (input.fallbackDesc || '').trim() || tr('admin.js.seo.preview_fallback', 'Aperçu de la méta-description dans les résultats de recherche.');

  return {
    title: renderedTitle,
    titleLength: renderedTitle.length,
    titleStatus: renderedTitle.length > 60 ? 'warn' : 'ok',
    description: desc,
    descriptionLength: desc.length,
    descriptionStatus: desc.length > 160 ? 'warn' : 'ok',
  };
}

export function initSeoPreview() {
  const panel = document.querySelector('[data-prime-seo-panel]');
  if (!(panel instanceof HTMLElement)) {
    return;
  }

  const seoTitleInput = panel.querySelector('[data-prime-seo-title]');
  const seoDescInput = panel.querySelector('[data-prime-seo-description]');
  const titlePreview = panel.querySelector('[data-prime-serp-title]');
  const descPreview = panel.querySelector('[data-prime-serp-description]');
  const titleCount = panel.querySelector('[data-prime-seo-title-count]');
  const descCount = panel.querySelector('[data-prime-seo-desc-count]');
  const entryTitleInput = document.querySelector('[data-prime-content-title]');
  const excerptInput = document.querySelector('textarea[name="excerpt"]');

  const siteName = panel.dataset.primeSiteName || '';
  const sep = panel.dataset.primeSeoSep || '—';

  const refresh = () => {
    const state = computeSerpPreview({
      seoTitle: seoTitleInput instanceof HTMLInputElement ? seoTitleInput.value : '',
      fallbackTitle: entryTitleInput instanceof HTMLInputElement ? entryTitleInput.value : '',
      seoDesc: seoDescInput instanceof HTMLTextAreaElement ? seoDescInput.value : '',
      fallbackDesc: excerptInput instanceof HTMLTextAreaElement ? excerptInput.value : '',
      siteName,
      sep,
    });

    if (titlePreview) {
      titlePreview.textContent = state.title;
    }
    if (descPreview) {
      descPreview.textContent = state.description;
    }
    if (titleCount) {
      titleCount.textContent = `${state.titleLength} / 60`;
      titleCount.classList.toggle('is-over', state.titleStatus === 'warn');
    }
    if (descCount) {
      descCount.textContent = `${state.descriptionLength} / 160`;
      descCount.classList.toggle('is-over', state.descriptionStatus === 'warn');
    }
  };

  [seoTitleInput, seoDescInput, entryTitleInput, excerptInput].forEach((el) => {
    if (el) {
      el.addEventListener('input', refresh);
    }
  });

  refresh();
}
