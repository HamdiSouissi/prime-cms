/**
 * Plugins management & persistent dashboard notices interactions (L8 + L9).
 * Pure vanilla ES2022 module — zero inline scripts, zero external libraries.
 */

import { openModal } from './modal.js';
import { showToast } from './toast.js';

export function initAdminPlugins() {
  initNoticeDismissals();
  initDeleteModal();
  initUploadDrawer();
  initActionLoadingStates();
}

function initNoticeDismissals() {
  document.querySelectorAll('form[data-prime-notice-dismiss]').forEach((form) => {
    if (!(form instanceof HTMLFormElement)) {
      return;
    }

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const noticeEl = form.closest('[data-prime-notice]');
      const noticeId = noticeEl instanceof HTMLElement ? noticeEl.getAttribute('data-prime-notice') : null;

      try {
        const response = await fetch(form.action, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: new FormData(form),
        });

        if (!response.ok) {
          form.submit();
          return;
        }

        if (noticeEl instanceof HTMLElement) {
          noticeEl.remove();
        }

        if (noticeId) {
          document.querySelectorAll(`[data-prime-notice-id="${CSS.escape(noticeId)}"]`).forEach((el) => el.remove());
        }

        const badge = document.querySelector('[data-prime-notice-badge]');
        if (badge instanceof HTMLElement) {
          const next = Math.max(0, (parseInt(badge.textContent || '0', 10) || 0) - 1);
          if (next === 0) {
            badge.remove();
          } else {
            badge.textContent = String(next);
          }
        }
      } catch {
        form.submit();
      }
    });
  });
}

function initDeleteModal() {
  const modal = document.getElementById('prime-plugin-delete-modal');
  if (!(modal instanceof HTMLDialogElement)) {
    return;
  }

  const nameEl = modal.querySelector('[data-prime-delete-plugin-name]');
  const slugEl = modal.querySelector('[data-prime-delete-plugin-slug]');
  const form = modal.querySelector('[data-prime-delete-plugin-form]');

  document.querySelectorAll('[data-prime-plugin-delete]').forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }

    button.addEventListener('click', () => {
      const slug = button.getAttribute('data-prime-plugin-slug') || '';
      const name = button.getAttribute('data-prime-plugin-name') || slug;
      if (!slug) {
        return;
      }

      if (nameEl instanceof HTMLElement) {
        nameEl.textContent = name;
      }
      if (slugEl instanceof HTMLElement) {
        slugEl.textContent = slug;
      }
      if (form instanceof HTMLFormElement) {
        form.action = `/admin/plugins/${encodeURIComponent(slug)}/uninstall`;
      }

      openModal(modal);
    });
  });
}

function initActionLoadingStates() {
  document.querySelectorAll('form[data-prime-plugin-action-form]').forEach((form) => {
    if (!(form instanceof HTMLFormElement)) {
      return;
    }

    form.addEventListener('submit', () => {
      const btn = form.querySelector('button[type="submit"]');
      if (btn instanceof HTMLButtonElement) {
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
      }
    });
  });
}

function initUploadDrawer() {
  const form = document.querySelector('[data-prime-plugin-upload-form]');
  if (!(form instanceof HTMLFormElement)) {
    return;
  }

  const dropzone = form.querySelector('[data-prime-dropzone]');
  const fileInput = form.querySelector('[data-prime-dropzone-input]');
  const titleEl = form.querySelector('[data-prime-dropzone-title]');
  const hintEl = form.querySelector('[data-prime-dropzone-hint]');
  const progressBox = form.querySelector('[data-prime-upload-progress]');
  const progressBar = form.querySelector('[data-prime-upload-bar]');
  const progressWrap = form.querySelector('[data-prime-upload-bar-wrap]');
  const progressPct = form.querySelector('[data-prime-upload-percent]');
  const progressStatus = form.querySelector('[data-prime-upload-status]');
  const errorBox = form.querySelector('[data-prime-upload-error]');
  const errorText = form.querySelector('[data-prime-upload-error-text]');
  const conflictBox = form.querySelector('[data-prime-upload-conflict]');
  const conflictText = form.querySelector('[data-prime-upload-conflict-text]');
  const confirmReplaceBtn = form.querySelector('[data-prime-upload-confirm-replace]');
  const replaceField = form.querySelector('[data-prime-upload-replace]');
  const forceField = form.querySelector('[data-prime-upload-force]');
  const submitBtn = form.querySelector('[data-prime-upload-submit]');

  if (!(fileInput instanceof HTMLInputElement) || !(dropzone instanceof HTMLElement)) {
    return;
  }

  const maxMb = parseInt(form.getAttribute('data-prime-max-mb') || '20', 10) || 20;
  let pendingForceOnConfirm = false;

  const resetAlerts = () => {
    if (errorBox instanceof HTMLElement) errorBox.hidden = true;
    if (conflictBox instanceof HTMLElement) conflictBox.hidden = true;
  };

  const showError = (msg) => {
    if (errorBox instanceof HTMLElement && errorText instanceof HTMLElement) {
      errorText.textContent = msg;
      errorBox.hidden = false;
    }
  };

  const setSelectedFileLabel = (file) => {
    resetAlerts();
    if (replaceField instanceof HTMLInputElement) replaceField.value = '0';
    if (forceField instanceof HTMLInputElement) forceField.value = '0';

    if (!file) {
      return;
    }

    const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
    if (titleEl instanceof HTMLElement) {
      titleEl.textContent = file.name;
    }
    if (hintEl instanceof HTMLElement) {
      hintEl.textContent = `${sizeMb} Mo · Prêt pour l’installation`;
    }

    if (!file.name.toLowerCase().endsWith('.zip')) {
      showError('Seules les archives au format .zip sont acceptées.');
    } else if (file.size > maxMb * 1024 * 1024) {
      showError(`L’archive dépasse la taille maximale autorisée (${maxMb} Mo).`);
    }
  };

  fileInput.addEventListener('change', () => {
    const file = fileInput.files && fileInput.files[0];
    setSelectedFileLabel(file || null);
  });

  dropzone.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      fileInput.click();
    }
  });

  ['dragenter', 'dragover'].forEach((evt) => {
    dropzone.addEventListener(evt, (event) => {
      event.preventDefault();
      dropzone.classList.add('pc-dropzone--dragover');
    });
  });

  ['dragleave', 'drop'].forEach((evt) => {
    dropzone.addEventListener(evt, (event) => {
      event.preventDefault();
      dropzone.classList.remove('pc-dropzone--dragover');
    });
  });

  dropzone.addEventListener('drop', (event) => {
    if (!(event instanceof DragEvent) || !event.dataTransfer || !event.dataTransfer.files.length) {
      return;
    }
    const dt = new DataTransfer();
    dt.items.add(event.dataTransfer.files[0]);
    fileInput.files = dt.files;
    setSelectedFileLabel(event.dataTransfer.files[0]);
  });

  const performUpload = () => {
    const file = fileInput.files && fileInput.files[0];
    if (!file) {
      showError('Veuillez sélectionner une archive ZIP de plugin.');
      return;
    }

    if (!file.name.toLowerCase().endsWith('.zip')) {
      showError('Seules les archives au format .zip sont acceptées.');
      return;
    }

    if (file.size > maxMb * 1024 * 1024) {
      showError(`L’archive dépasse la taille maximale autorisée (${maxMb} Mo).`);
      return;
    }

    resetAlerts();
    if (progressBox instanceof HTMLElement) progressBox.hidden = false;
    if (submitBtn instanceof HTMLButtonElement) submitBtn.disabled = true;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.action, true);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    xhr.upload.addEventListener('progress', (ev) => {
      if (!ev.lengthComputable) {
        return;
      }
      const pct = Math.min(95, Math.round((ev.loaded / ev.total) * 100));
      if (progressBar instanceof HTMLElement) progressBar.style.width = `${pct}%`;
      if (progressWrap instanceof HTMLElement) progressWrap.setAttribute('aria-valuenow', String(pct));
      if (progressPct instanceof HTMLElement) progressPct.textContent = `${pct} %`;
      if (progressStatus instanceof HTMLElement) {
        progressStatus.textContent = pct >= 95 ? 'Validation et extraction sécurisée…' : 'Téléversement en cours…';
      }
    });

    xhr.addEventListener('load', () => {
      if (submitBtn instanceof HTMLButtonElement) submitBtn.disabled = false;
      if (progressBar instanceof HTMLElement) progressBar.style.width = '100%';
      if (progressPct instanceof HTMLElement) progressPct.textContent = '100 %';

      let payload = null;
      try {
        payload = JSON.parse(xhr.responseText);
      } catch {
        payload = null;
      }

      if (xhr.status >= 200 && xhr.status < 300 && payload && payload.ok) {
        showToast({
          title: payload.replaced ? 'Plugin remplacé' : 'Plugin installé',
          message: payload.message || 'Le plugin a été installé avec succès.',
          type: 'success',
        });
        window.location.assign('/admin/plugins');
        return;
      }

      if (progressBox instanceof HTMLElement) progressBox.hidden = true;

      if (xhr.status === 409 && payload && payload.conflict) {
        pendingForceOnConfirm = Boolean(payload.is_downgrade_or_same);
        if (conflictBox instanceof HTMLElement && conflictText instanceof HTMLElement) {
          conflictText.textContent = payload.message;
          conflictBox.hidden = false;
        }
        if (confirmReplaceBtn instanceof HTMLButtonElement) {
          confirmReplaceBtn.textContent = payload.is_downgrade_or_same
            ? `Forcer le remplacement par la version ${payload.incoming_version}`
            : `Remplacer par la version ${payload.incoming_version}`;
        }
        return;
      }

      showError((payload && payload.message) || 'Une erreur est survenue lors de l’installation du plugin.');
    });

    xhr.addEventListener('error', () => {
      if (submitBtn instanceof HTMLButtonElement) submitBtn.disabled = false;
      if (progressBox instanceof HTMLElement) progressBox.hidden = true;
      showError('Erreur réseau pendant le téléversement de l’archive.');
    });

    xhr.send(new FormData(form));
  };

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    performUpload();
  });

  if (confirmReplaceBtn instanceof HTMLButtonElement) {
    confirmReplaceBtn.addEventListener('click', () => {
      if (replaceField instanceof HTMLInputElement) replaceField.value = '1';
      if (forceField instanceof HTMLInputElement) forceField.value = pendingForceOnConfirm ? '1' : '0';
      performUpload();
    });
  }
}
