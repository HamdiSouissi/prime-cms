/**
 * Aurora theme client interactions (<= 8 KB, defer, zero dependencies).
 * Accessible mobile navigation drawer + expandable header search bar.
 */
(() => {
  const navToggle = document.querySelector('[data-aurora-nav-toggle]');
  const navPanel = document.querySelector('[data-aurora-nav]');
  const searchToggle = document.querySelector('[data-aurora-search-toggle]');
  const searchBar = document.querySelector('[data-aurora-search-bar]');

  const closeNav = () => {
    if (!navToggle || !navPanel) return;
    navPanel.classList.remove('is-open');
    navToggle.setAttribute('aria-expanded', 'false');
  };

  const closeSearch = () => {
    if (!searchToggle || !searchBar) return;
    searchBar.hidden = true;
    searchToggle.setAttribute('aria-expanded', 'false');
  };

  if (navToggle && navPanel) {
    navToggle.addEventListener('click', () => {
      const opening = !navPanel.classList.contains('is-open');
      if (opening) closeSearch();
      navPanel.classList.toggle('is-open', opening);
      navToggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
      if (opening) {
        const firstLink = navPanel.querySelector('a');
        if (firstLink instanceof HTMLElement) firstLink.focus();
      }
    });
  }

  if (searchToggle && searchBar) {
    searchToggle.addEventListener('click', () => {
      const opening = searchBar.hidden;
      if (opening) closeNav();
      searchBar.hidden = !opening;
      searchToggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
      if (opening) {
        const input = searchBar.querySelector('input[type="search"]');
        if (input instanceof HTMLInputElement) input.focus();
      }
    });
  }

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (navPanel && navPanel.classList.contains('is-open')) {
      closeNav();
      if (navToggle instanceof HTMLElement) navToggle.focus();
    }
    if (searchBar && !searchBar.hidden) {
      closeSearch();
      if (searchToggle instanceof HTMLElement) searchToggle.focus();
    }
  });
})();
