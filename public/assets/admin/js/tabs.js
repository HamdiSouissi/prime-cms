/**
 * Accessible ARIA tabs with click and Arrow/Home/End keyboard navigation.
 */

export function initTabs() {
  document.querySelectorAll('[data-tabs]').forEach((root) => {
    if (!(root instanceof HTMLElement)) {
      return;
    }

    const tabs = Array.from(root.querySelectorAll('[role="tab"]')).filter(
      (el) => el instanceof HTMLElement,
    );
    const panels = Array.from(root.querySelectorAll('[role="tabpanel"]')).filter(
      (el) => el instanceof HTMLElement,
    );

    if (tabs.length === 0) {
      return;
    }

    /**
     * @param {HTMLElement} nextTab
     * @param {boolean} moveFocus
     */
    function activate(nextTab, moveFocus = false) {
      const targetId = nextTab.getAttribute('aria-controls');

      tabs.forEach((tab) => {
        const selected = tab === nextTab;
        tab.setAttribute('aria-selected', selected ? 'true' : 'false');
        tab.setAttribute('tabindex', selected ? '0' : '-1');
      });

      panels.forEach((panel) => {
        panel.hidden = panel.id !== targetId;
      });

      if (moveFocus) {
        nextTab.focus();
      }
    }

    tabs.forEach((tab, index) => {
      tab.addEventListener('click', () => activate(tab, false));

      tab.addEventListener('keydown', (event) => {
        let nextIndex = -1;
        if (event.key === 'ArrowRight') {
          nextIndex = (index + 1) % tabs.length;
        } else if (event.key === 'ArrowLeft') {
          nextIndex = (index - 1 + tabs.length) % tabs.length;
        } else if (event.key === 'Home') {
          nextIndex = 0;
        } else if (event.key === 'End') {
          nextIndex = tabs.length - 1;
        }

        if (nextIndex >= 0) {
          event.preventDefault();
          activate(tabs[nextIndex], true);
        }
      });
    });
  });
}
