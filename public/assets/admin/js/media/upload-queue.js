/**
 * Prime Media — Parallel upload queue (default concurrency 3) with real-time progress,
 * duplicate detection warnings, retry, and cancel/abort support.
 */

import { tr } from '../i18n.js';
let nextItemSeq = 1;

/**
 * Default XHR transport with upload progress reporting and abort support.
 *
 * @param {{
 *   file: File,
 *   folderId: number | null,
 *   url: string,
 *   csrfToken: string,
 *   onProgress: (percent: number) => void,
 *   setAbort: (abortFn: () => void) => void
 * }} params
 * @returns {Promise<{ item: object, duplicate?: object | null, warning?: string | null }>}
 */
export function xhrUploadTransport({ file, folderId, url, csrfToken, onProgress, setAbort }) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.setRequestHeader('Accept', 'application/json');
    if (csrfToken) {
      xhr.setRequestHeader('X-CSRF-Token', csrfToken);
    }

    setAbort(() => {
      xhr.abort();
    });

    xhr.upload.addEventListener('progress', (event) => {
      if (event.lengthComputable && event.total > 0) {
        onProgress(Math.min(99, Math.round((event.loaded / event.total) * 100)));
      }
    });

    xhr.addEventListener('load', () => {
      let data = {};
      try {
        data = JSON.parse(xhr.responseText || '{}');
      } catch {
        data = {};
      }

      if (xhr.status >= 200 && xhr.status < 300 && data.item) {
        onProgress(100);
        resolve(data);
        return;
      }

      const message =
        typeof data.error === 'string' && data.error.trim() !== ''
          ? data.error
          : `Erreur HTTP ${xhr.status} lors du téléversement.`;
      reject(new Error(message));
    });

    xhr.addEventListener('error', () => {
      reject(new Error(tr('admin.js.media.upload-queue.erreur_reseau_lors_du_televersement', 'Erreur réseau lors du téléversement.')));
    });

    xhr.addEventListener('abort', () => {
      const err = new Error(tr('admin.js.media.upload-queue.televersement_annule', 'Téléversement annulé.'));
      err.name = 'AbortError';
      reject(err);
    });

    const formData = new FormData();
    formData.append('file', file);
    if (folderId !== null && folderId > 0) {
      formData.append('folder_id', String(folderId));
    }

    xhr.send(formData);
  });
}

export class UploadQueue {
  /**
   * @param {{
   *   concurrency?: number,
   *   uploadUrl?: string,
   *   getCsrfToken?: () => string,
   *   transport?: typeof xhrUploadTransport,
   *   onChange?: (items: Array<object>) => void,
   *   onItemComplete?: (entry: object, response: object) => void,
   *   onItemError?: (entry: object, error: Error) => void,
   *   onIdle?: () => void
   * }} [options]
   */
  constructor(options = {}) {
    this.concurrency = Math.max(1, Number(options.concurrency || 3));
    this.uploadUrl = options.uploadUrl || '/admin/media/api/upload';
    this.getCsrfToken =
      options.getCsrfToken ||
      (() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
    this.transport = options.transport || xhrUploadTransport;
    this.onChange = options.onChange || (() => {});
    this.onItemComplete = options.onItemComplete || (() => {});
    this.onItemError = options.onItemError || (() => {});
    this.onIdle = options.onIdle || (() => {});

    /** @type {Array<{ id: string, file: File, name: string, size: number, folderId: number | null, status: string, progress: number, error: string | null, media: object | null, duplicate: object | null, warning: string | null, _abort: (() => void) | null }>} */
    this.items = [];
    this.inFlight = 0;
  }

  /**
   * Add one or more File objects to the queue and kick off parallel processing.
   *
   * @param {FileList | File[]} files
   * @param {{ folderId?: number | null }} [options]
   */
  enqueue(files, { folderId = null } = {}) {
    const list = Array.from(files || []);
    const added = [];

    for (const file of list) {
      const entry = {
        id: `up_${nextItemSeq++}`,
        file,
        name: file.name || 'fichier',
        size: Number(file.size || 0),
        folderId: folderId && Number(folderId) > 0 ? Number(folderId) : null,
        status: 'queued',
        progress: 0,
        error: null,
        media: null,
        duplicate: null,
        warning: null,
        _abort: null,
      };
      this.items.push(entry);
      added.push(entry);
    }

    this.emitChange();
    this.pump();
    return added;
  }

  /**
   * Retry a failed or cancelled upload entry.
   *
   * @param {string} id
   */
  retry(id) {
    const entry = this.items.find((item) => item.id === id);
    if (!entry || (entry.status !== 'error' && entry.status !== 'cancelled')) {
      return false;
    }

    entry.status = 'queued';
    entry.progress = 0;
    entry.error = null;
    entry.duplicate = null;
    entry.warning = null;
    entry._abort = null;

    this.emitChange();
    this.pump();
    return true;
  }

  /**
   * Cancel a queued or actively uploading entry.
   *
   * @param {string} id
   */
  cancel(id) {
    const entry = this.items.find((item) => item.id === id);
    if (!entry) {
      return false;
    }

    if (entry.status === 'queued') {
      entry.status = 'cancelled';
      entry.error = 'Annulé';
      this.emitChange();
      return true;
    }

    if (entry.status === 'uploading') {
      entry.status = 'cancelled';
      entry.error = 'Annulé';
      if (typeof entry._abort === 'function') {
        try {
          entry._abort();
        } catch {
          // Ignore abort callback errors
        }
      }
      this.emitChange();
      return true;
    }

    return false;
  }

  /**
   * Remove completed and cancelled items from the queue list.
   */
  clearFinished() {
    this.items = this.items.filter((item) => item.status === 'queued' || item.status === 'uploading');
    this.emitChange();
  }

  activeCount() {
    return this.items.filter((i) => i.status === 'queued' || i.status === 'uploading').length;
  }

  snapshot() {
    return this.items.map(({ _abort, ...rest }) => ({ ...rest }));
  }

  pump() {
    while (this.inFlight < this.concurrency) {
      const next = this.items.find((item) => item.status === 'queued');
      if (!next) {
        break;
      }
      this.startItem(next);
    }

    if (this.inFlight === 0 && !this.items.some((item) => item.status === 'queued')) {
      this.onIdle();
    }
  }

  startItem(entry) {
    entry.status = 'uploading';
    entry.progress = 1;
    entry.error = null;
    this.inFlight += 1;
    this.emitChange();

    const csrfToken = this.getCsrfToken();

    Promise.resolve()
      .then(() =>
        this.transport({
          file: entry.file,
          folderId: entry.folderId,
          url: this.uploadUrl,
          csrfToken,
          onProgress: (pct) => {
            if (entry.status === 'uploading') {
              entry.progress = Math.max(0, Math.min(100, pct));
              this.emitChange();
            }
          },
          setAbort: (fn) => {
            entry._abort = fn;
          },
        }),
      )
      .then((res) => {
        if (entry.status === 'cancelled') {
          return;
        }
        entry.status = 'done';
        entry.progress = 100;
        entry.media = res.item || null;
        entry.duplicate = res.duplicate || null;
        entry.warning = res.warning || null;
        entry._abort = null;
        this.emitChange();
        this.onItemComplete(entry, res);
      })
      .catch((err) => {
        entry._abort = null;
        if (entry.status === 'cancelled' || (err && err.name === 'AbortError')) {
          entry.status = 'cancelled';
          entry.error = 'Annulé';
          this.emitChange();
          return;
        }
        entry.status = 'error';
        entry.error = err instanceof Error ? err.message : String(err);
        this.emitChange();
        this.onItemError(entry, err instanceof Error ? err : new Error(String(err)));
      })
      .finally(() => {
        this.inFlight = Math.max(0, this.inFlight - 1);
        this.pump();
      });
  }

  emitChange() {
    this.onChange(this.snapshot());
  }
}
