/**
 * Native <dialog> modal manager with backdrop click dismissal and focus restoration.
 */

/** @type {Map<HTMLDialogElement, HTMLElement|null>} */
const openers = new Map();

/**
 * Open a `<dialog>` modal by ID or element reference.
 *
 * @param {string|HTMLDialogElement} target
 */
export function openModal(target) {
  const dialog = typeof target === 'string' ? document.getElementById(target) : target;
  if (!(dialog instanceof HTMLDialogElement)) {
    return;
  }

  const active = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  openers.set(dialog, active);

  if (!dialog.open) {
    dialog.showModal();
  }
}

/**
 * Close a `<dialog>` modal and restore focus to the triggering control.
 *
 * @param {string|HTMLDialogElement} target
 */
export function closeModal(target) {
  const dialog = typeof target === 'string' ? document.getElementById(target) : target;
  if (!(dialog instanceof HTMLDialogElement)) {
    return;
  }

  if (dialog.open) {
    dialog.close();
  }
}

export function initModals() {
  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    const openTrigger = target.closest('[data-modal-open]');
    if (openTrigger instanceof HTMLElement) {
      const modalId = openTrigger.getAttribute('data-modal-open');
      if (modalId) {
        event.preventDefault();
        openModal(modalId);
      }
      return;
    }

    const closeTrigger = target.closest('[data-modal-close]');
    if (closeTrigger instanceof HTMLElement) {
      const dialog = closeTrigger.closest('dialog');
      if (dialog instanceof HTMLDialogElement) {
        event.preventDefault();
        closeModal(dialog);
      }
      return;
    }

    // Backdrop click closes native <dialog> when clicking directly on the dialog surface outside content
    if (target instanceof HTMLDialogElement && target.classList.contains('pc-modal')) {
      const rect = target.getBoundingClientRect();
      const clickedOutside =
        event.clientX < rect.left ||
        event.clientX > rect.right ||
        event.clientY < rect.top ||
        event.clientY > rect.bottom;
      if (clickedOutside) {
        closeModal(target);
      }
    }
  });

  document.querySelectorAll('dialog.pc-modal').forEach((dialog) => {
    if (!(dialog instanceof HTMLDialogElement)) {
      return;
    }
    dialog.addEventListener('close', () => {
      const opener = openers.get(dialog);
      openers.delete(dialog);
      if (opener && document.contains(opener)) {
        opener.focus();
      }
    });
  });
}
