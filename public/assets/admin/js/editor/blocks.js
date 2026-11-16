/**
 * Prime Editor — All 11 core block implementations registered on `defaultBlockRegistry`.
 */

import { resolveListItemKeyAction } from './navigation.js';
import { defaultBlockRegistry } from './registry.js';
import { escapeHtml, parseEmbedUrl, sanitizeInlineHtml, slugify, stripHtml } from './utils.js';

function createIconSvg(name, size = 16) {
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('class', 'pc-icon');
  svg.setAttribute('width', String(size));
  svg.setAttribute('height', String(size));
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('fill', 'none');
  svg.setAttribute('stroke', 'currentColor');
  svg.setAttribute('stroke-width', '1.5');
  svg.setAttribute('stroke-linecap', 'round');
  svg.setAttribute('stroke-linejoin', 'round');
  svg.setAttribute('aria-hidden', 'true');
  const use = document.createElementNS(ns, 'use');
  use.setAttribute('href', `/assets/admin/icons/sprite.svg#${name}`);
  svg.appendChild(use);
  return svg;
}

/**
 * Helper to create an accessible rich inline `contenteditable` field that cleanly
 * synchronizes sanitized inline HTML back to `onChange`.
 */
function createRichTextEl({ tag = 'div', className = '', html = '', placeholder = '', ariaLabel = '', onChange, onKeyDown, onPaste }) {
  const el = document.createElement(tag);
  el.className = `pc-ed-richtext ${className}`.trim();
  el.setAttribute('contenteditable', 'true');
  el.contentEditable = 'true';
  el.dataset.placeholder = placeholder;
  el.setAttribute('role', 'textbox');
  el.setAttribute('aria-multiline', 'true');
  if (ariaLabel) {
    el.setAttribute('aria-label', ariaLabel);
  }
  el.innerHTML = sanitizeInlineHtml(html);

  el.addEventListener('input', () => {
    onChange(sanitizeInlineHtml(el.innerHTML), el);
  });

  if (onKeyDown) {
    el.addEventListener('keydown', (e) => onKeyDown(e, el));
  }
  if (onPaste) {
    el.addEventListener('paste', (e) => onPaste(e, el));
  }

  return el;
}

export function registerCoreBlocks(registry = defaultBlockRegistry) {
  // 1. Paragraph
  registry.registerBlock({
    type: 'paragraph',
    title: 'Paragraphe',
    description: 'Texte enrichi standard.',
    icon: 'file-text',
    category: 'text',
    keywords: ['texte', 'paragraphe', 'p'],
    defaultData: () => ({ text: '' }),
    render: (ctx) => {
      const wrap = document.createElement('div');
      wrap.className = 'pc-ed-block-body pc-ed-paragraph';

      const editable = createRichTextEl({
        tag: 'p',
        className: 'pc-ed-p-input',
        html: ctx.data.text || '',
        placeholder: 'Écrivez du texte ou tapez « / » pour insérer un bloc…',
        ariaLabel: 'Paragraphe',
        onChange: (html, el) => {
          ctx.updateData({ text: html });
          ctx.handleSlashTrigger(el);
        },
        onKeyDown: (e, el) => ctx.handleRichKeyDown(e, el, { allowSplit: true }),
        onPaste: (e, el) => ctx.handlePaste(e, el),
      });

      wrap.appendChild(editable);
      return wrap;
    },
  });

  // 2. Heading (H2–H4 + anchor)
  registry.registerBlock({
    type: 'heading',
    title: 'Titre',
    description: 'Sous-titre H2, H3 ou H4 avec ancre automatique.',
    icon: 'heading',
    category: 'text',
    keywords: ['h2', 'h3', 'h4', 'titre', 'section'],
    defaultData: () => ({ level: 2, text: '', anchor: '' }),
    render: (ctx) => {
      const wrap = document.createElement('div');
      wrap.className = 'pc-ed-block-body pc-ed-heading';

      const controls = document.createElement('div');
      controls.className = 'pc-ed-inline-controls';

      const levelGroup = document.createElement('div');
      levelGroup.className = 'pc-ed-segmented';
      levelGroup.setAttribute('role', 'group');
      levelGroup.setAttribute('aria-label', 'Niveau du titre');

      const currentLevel = [2, 3, 4].includes(Number(ctx.data.level)) ? Number(ctx.data.level) : 2;
      [2, 3, 4].forEach((lvl) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `pc-ed-seg-btn${currentLevel === lvl ? ' is-active' : ''}`;
        btn.textContent = `H${lvl}`;
        btn.setAttribute('aria-pressed', currentLevel === lvl ? 'true' : 'false');
        btn.addEventListener('click', () => {
          ctx.updateData({ level: lvl }, { rerender: true, recordHistory: true });
        });
        levelGroup.appendChild(btn);
      });

      const anchorLabel = document.createElement('span');
      anchorLabel.className = 'pc-ed-anchor-pill';
      const anchorVal = ctx.data.anchor || slugify(stripHtml(ctx.data.text || ''), 80);
      anchorLabel.textContent = anchorVal ? `#${anchorVal}` : '#ancre';
      anchorLabel.title = 'Ancre générée automatiquement';

      controls.append(levelGroup, anchorLabel);

      const editable = createRichTextEl({
        tag: `h${currentLevel}`,
        className: `pc-ed-h-input pc-ed-h${currentLevel}`,
        html: ctx.data.text || '',
        placeholder: `Titre de niveau ${currentLevel}…`,
        ariaLabel: `Titre H${currentLevel}`,
        onChange: (html) => {
          const anchor = slugify(stripHtml(html), 80);
          anchorLabel.textContent = anchor ? `#${anchor}` : '#ancre';
          ctx.updateData({ text: html, anchor });
        },
        onKeyDown: (e, el) => ctx.handleRichKeyDown(e, el, { allowSplit: true }),
        onPaste: (e, el) => ctx.handlePaste(e, el),
      });

      wrap.append(controls, editable);
      return wrap;
    },
  });

  // 3. List (ul/ol + 1 nesting level)
  registry.registerBlock({
    type: 'list',
    title: 'Liste',
    description: 'Liste à puces ou numérotée (avec sous-éléments).',
    icon: 'list',
    category: 'text',
    keywords: ['liste', 'puces', 'numéros', 'ul', 'ol'],
    defaultData: () => ({ style: 'unordered', items: [{ text: '', children: [] }] }),
    render: (ctx) => {
      const wrap = document.createElement('div');
      wrap.className = 'pc-ed-block-body pc-ed-list';

      const style = ctx.data.style === 'ordered' ? 'ordered' : 'unordered';
      const items = Array.isArray(ctx.data.items) && ctx.data.items.length > 0
        ? ctx.data.items.map((it) =>
            typeof it === 'string' ? { text: it, children: [] } : { text: it.text || '', children: Array.isArray(it.children) ? [...it.children] : [] },
          )
        : [{ text: '', children: [] }];

      const controls = document.createElement('div');
      controls.className = 'pc-ed-inline-controls';

      const styleGroup = document.createElement('div');
      styleGroup.className = 'pc-ed-segmented';
      [
        { key: 'unordered', label: 'Puces', icon: 'list' },
        { key: 'ordered', label: 'Numéros', icon: 'list-ordered' },
      ].forEach((opt) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `pc-ed-seg-btn${style === opt.key ? ' is-active' : ''}`;
        btn.append(createIconSvg(opt.icon, 14), document.createTextNode(` ${opt.label}`));
        btn.setAttribute('aria-pressed', style === opt.key ? 'true' : 'false');
        btn.addEventListener('click', () => {
          ctx.updateData({ style: opt.key, items }, { rerender: true, recordHistory: true });
        });
        styleGroup.appendChild(btn);
      });

      const addBtn = document.createElement('button');
      addBtn.type = 'button';
      addBtn.className = 'pc-ed-mini-btn';
      addBtn.textContent = '+ Élément';
      addBtn.addEventListener('click', () => {
        items.push({ text: '', children: [] });
        ctx.updateData({ style, items }, { rerender: true, recordHistory: true });
      });

      controls.append(styleGroup, addBtn);
      wrap.appendChild(controls);

      const listEl = document.createElement(style === 'ordered' ? 'ol' : 'ul');
      listEl.className = 'pc-ed-list-items';

      items.forEach((item, idx) => {
        const li = document.createElement('li');
        li.className = 'pc-ed-list-item';

        const row = document.createElement('div');
        row.className = 'pc-ed-list-row';

        const input = createRichTextEl({
          tag: 'div',
          className: 'pc-ed-list-input',
          html: item.text,
          placeholder: 'Élément de liste…',
          ariaLabel: `Élément ${idx + 1}`,
          onChange: (html) => {
            items[idx].text = html;
            ctx.updateData({ style, items });
          },
          onKeyDown: (e, el) => {
            const nav = resolveListItemKeyAction({
              key: e.key,
              shiftKey: e.shiftKey,
              itemIndex: idx,
              itemCount: items.length,
              isEmpty: stripHtml(el.innerHTML) === '',
              atStart: ctx.isCaretAtEdge ? ctx.isCaretAtEdge(el, 'start') : false,
              atEnd: ctx.isCaretAtEdge ? ctx.isCaretAtEdge(el, 'end') : false,
              blockIndex: ctx.index,
              blockCount: ctx.blockCount ?? 1,
            });

            if (nav.action === 'insert_item') {
              e.preventDefault();
              items.splice(nav.itemIndex, 0, { text: '', children: [] });
              ctx.updateData(
                { style, items },
                {
                  rerender: true,
                  recordHistory: true,
                  focusSelector: '.pc-ed-list-items > .pc-ed-list-item > .pc-ed-list-row > .pc-ed-list-input',
                  focusMatchIndex: nav.itemIndex,
                  focusAtEnd: false,
                },
              );
              return;
            }
            if (nav.action === 'remove_item') {
              e.preventDefault();
              items.splice(idx, 1);
              ctx.updateData(
                { style, items },
                {
                  rerender: true,
                  recordHistory: true,
                  focusSelector: '.pc-ed-list-items > .pc-ed-list-item > .pc-ed-list-row > .pc-ed-list-input',
                  focusMatchIndex: nav.itemIndex,
                  focusAtEnd: true,
                },
              );
              return;
            }
            if (nav.action === 'focus_item') {
              e.preventDefault();
              ctx.focusSubElement?.(
                ctx.index,
                '.pc-ed-list-items > .pc-ed-list-item > .pc-ed-list-row > .pc-ed-list-input',
                nav.itemIndex,
                nav.atEnd,
              );
              return;
            }
            if (nav.action === 'focus_title') {
              e.preventDefault();
              ctx.focusTitle?.(true);
              return;
            }
            if (nav.action === 'focus_block') {
              e.preventDefault();
              ctx.focusBlock?.(nav.blockIndex, nav.atEnd);
              return;
            }

            ctx.handleRichKeyDown(e, el, { allowSplit: false });
          },
        });

        const subBtn = document.createElement('button');
        subBtn.type = 'button';
        subBtn.className = 'pc-ed-mini-btn';
        subBtn.title = 'Ajouter un sous-élément';
        subBtn.textContent = '+ Sous-élément';
        subBtn.addEventListener('click', () => {
          items[idx].children.push('');
          ctx.updateData({ style, items }, { rerender: true, recordHistory: true });
        });

        const delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'pc-ed-icon-btn';
        delBtn.setAttribute('aria-label', 'Supprimer l\'élément');
        delBtn.appendChild(createIconSvg('x', 14));
        delBtn.addEventListener('click', () => {
          if (items.length === 1) {
            items[0] = { text: '', children: [] };
          } else {
            items.splice(idx, 1);
          }
          ctx.updateData({ style, items }, { rerender: true, recordHistory: true });
        });

        row.append(input, subBtn, delBtn);
        li.appendChild(row);

        if (item.children.length > 0) {
          const subList = document.createElement(style === 'ordered' ? 'ol' : 'ul');
          subList.className = 'pc-ed-sublist';
          item.children.forEach((childText, cIdx) => {
            const subLi = document.createElement('li');
            subLi.className = 'pc-ed-list-row';
            const subInput = createRichTextEl({
              tag: 'div',
              className: 'pc-ed-list-input',
              html: childText,
              placeholder: 'Sous-élément…',
              ariaLabel: `Sous-élément ${idx + 1}.${cIdx + 1}`,
              onChange: (html) => {
                items[idx].children[cIdx] = html;
                ctx.updateData({ style, items });
              },
            });
            const subDel = document.createElement('button');
            subDel.type = 'button';
            subDel.className = 'pc-ed-icon-btn';
            subDel.setAttribute('aria-label', 'Supprimer le sous-élément');
            subDel.appendChild(createIconSvg('x', 13));
            subDel.addEventListener('click', () => {
              items[idx].children.splice(cIdx, 1);
              ctx.updateData({ style, items }, { rerender: true, recordHistory: true });
            });
            subLi.append(subInput, subDel);
            subList.appendChild(subLi);
          });
          li.appendChild(subList);
        }

        listEl.appendChild(li);
      });

      wrap.appendChild(listEl);
      return wrap;
    },
  });

  // 4. Quote
  registry.registerBlock({
    type: 'quote',
    title: 'Citation',
    description: 'Citation mise en valeur avec auteur optionnel.',
    icon: 'quote',
    category: 'text',
    keywords: ['citation', 'quote', 'auteur'],
    defaultData: () => ({ text: '', citation: '' }),
    render: (ctx) => {
      const wrap = document.createElement('blockquote');
      wrap.className = 'pc-ed-block-body pc-ed-quote';

      const quoteEl = createRichTextEl({
        tag: 'p',
        className: 'pc-ed-quote-text',
        html: ctx.data.text || '',
        placeholder: 'Écrivez la citation…',
        ariaLabel: 'Texte de la citation',
        onChange: (html) => ctx.updateData({ text: html }),
        onKeyDown: (e, el) => ctx.handleRichKeyDown(e, el, { allowSplit: false }),
        onPaste: (e, el) => ctx.handlePaste(e, el),
      });

      const citeEl = createRichTextEl({
        tag: 'cite',
        className: 'pc-ed-quote-cite',
        html: ctx.data.citation || '',
        placeholder: 'Auteur ou source (optionnel)…',
        ariaLabel: 'Auteur de la citation',
        onChange: (html) => ctx.updateData({ citation: html }),
      });

      wrap.append(quoteEl, citeEl);
      return wrap;
    },
  });

  // 5. Image (Media Library picker or URL, required alt unless decorative, caption, normal/wide/full)
  registry.registerBlock({
    type: 'image',
    title: 'Image',
    description: 'Image issue de la médiathèque ou via URL, avec texte alternatif et légende.',
    icon: 'image',
    category: 'media',
    keywords: ['image', 'photo', 'img', 'visuel', 'media'],
    defaultData: () => ({ mediaId: null, url: '', alt: '', decorative: false, caption: '', alignment: 'normal' }),
    render: (ctx) => {
      const wrap = document.createElement('div');
      wrap.className = 'pc-ed-block-body pc-ed-image';

      const pickerRow = document.createElement('div');
      pickerRow.className = 'pc-ed-media-picker-row';

      const pickBtn = document.createElement('button');
      pickBtn.type = 'button';
      pickBtn.className = 'pc-btn pc-btn--secondary pc-btn--sm';
      pickBtn.append(createIconSvg('image', 14), document.createTextNode(' Choisir dans la médiathèque'));

      const mediaBadge = document.createElement('span');
      mediaBadge.className = 'pc-badge pc-badge--neutral';
      mediaBadge.hidden = !ctx.data.mediaId;
      mediaBadge.textContent = ctx.data.mediaId ? `Média #${ctx.data.mediaId}` : '';

      pickerRow.append(pickBtn, mediaBadge);

      const preview = document.createElement('div');
      preview.className = 'pc-ed-image-preview';
      const syncPreview = (url, alt) => {
        preview.innerHTML = '';
        if (url && /^(https?:\/\/|\/)/i.test(url)) {
          const img = document.createElement('img');
          img.src = url;
          img.alt = alt || '';
          img.loading = 'lazy';
          preview.appendChild(img);
          preview.hidden = false;
        } else {
          preview.hidden = true;
        }
      };
      syncPreview(ctx.data.url || '', ctx.data.alt || '');

      const grid = document.createElement('div');
      grid.className = 'pc-ed-form-grid';

      const urlInput = document.createElement('input');
      urlInput.type = 'url';
      urlInput.className = 'pc-input';
      urlInput.value = ctx.data.url || '';
      urlInput.placeholder = 'https://exemple.com/image.jpg ou /uploads/photo.webp';
      urlInput.setAttribute('aria-label', 'URL de l\'image');

      const altInput = document.createElement('input');
      altInput.type = 'text';
      altInput.className = 'pc-input';
      altInput.value = ctx.data.alt || '';
      altInput.placeholder = 'Texte alternatif décrivant l\'image (obligatoire pour l\'accessibilité)';
      altInput.disabled = Boolean(ctx.data.decorative);
      altInput.setAttribute('aria-label', 'Texte alternatif de l\'image');

      const decWrap = document.createElement('label');
      decWrap.className = 'pc-ed-checkbox';
      const decCheck = document.createElement('input');
      decCheck.type = 'checkbox';
      decCheck.checked = Boolean(ctx.data.decorative);
      decWrap.append(decCheck, document.createTextNode(' Image purement décorative (alt vide)'));

      const captionInput = document.createElement('input');
      captionInput.type = 'text';
      captionInput.className = 'pc-input';
      captionInput.value = stripHtml(ctx.data.caption || '');
      captionInput.placeholder = 'Légende optionnelle…';
      captionInput.setAttribute('aria-label', 'Légende de l\'image');

      const alignGroup = document.createElement('div');
      alignGroup.className = 'pc-ed-segmented';
      const align = ['normal', 'wide', 'full'].includes(ctx.data.alignment) ? ctx.data.alignment : 'normal';
      [
        { key: 'normal', label: 'Normale' },
        { key: 'wide', label: 'Large' },
        { key: 'full', label: 'Pleine largeur' },
      ].forEach((opt) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `pc-ed-seg-btn${align === opt.key ? ' is-active' : ''}`;
        btn.textContent = opt.label;
        btn.addEventListener('click', () => {
          ctx.updateData({ alignment: opt.key }, { rerender: true, recordHistory: true });
        });
        alignGroup.appendChild(btn);
      });

      pickBtn.addEventListener('click', async () => {
        if (!window.Prime?.media?.openPicker) return;
        const chosen = await window.Prime.media.openPicker({
          multiple: false,
          kinds: ['image'],
          selected: ctx.data.mediaId ? [Number(ctx.data.mediaId)] : [],
          title: 'Choisir une image',
        });
        if (!chosen || chosen.length === 0) return;
        const media = chosen[0];
        const nextUrl = media.url || '';
        const nextAlt = media.alt_text || altInput.value.trim() || media.title || '';
        const nextCaption = captionInput.value.trim() || media.caption || '';
        ctx.updateData(
          {
            mediaId: Number(media.id),
            url: nextUrl,
            alt: decCheck.checked ? '' : nextAlt,
            caption: escapeHtml(nextCaption),
          },
          { rerender: true, recordHistory: true },
        );
      });

      urlInput.addEventListener('input', () => {
        const val = urlInput.value.trim();
        ctx.updateData({ url: val, mediaId: null });
        mediaBadge.hidden = true;
        syncPreview(val, altInput.value.trim());
      });
      altInput.addEventListener('input', () => ctx.updateData({ alt: altInput.value }));
      decCheck.addEventListener('change', () => {
        altInput.disabled = decCheck.checked;
        if (decCheck.checked) altInput.value = '';
        ctx.updateData({ decorative: decCheck.checked, alt: decCheck.checked ? '' : altInput.value }, { recordHistory: true });
      });
      captionInput.addEventListener('input', () => ctx.updateData({ caption: escapeHtml(captionInput.value) }));

      grid.append(urlInput, altInput, decWrap, captionInput, alignGroup);
      wrap.append(pickerRow, preview, grid);
      return wrap;
    },
  });

  // 6. Code
  registry.registerBlock({
    type: 'code',
    title: 'Code',
    description: 'Bloc de code source avec langage optionnel.',
    icon: 'code',
    category: 'formatting',
    keywords: ['code', 'pre', 'php', 'js', 'css', 'sql'],
    defaultData: () => ({ language: '', code: '' }),
    render: (ctx) => {
      const wrap = document.createElement('div');
      wrap.className = 'pc-ed-block-body pc-ed-code';

      const header = document.createElement('div');
      header.className = 'pc-ed-code-header';
      const langInput = document.createElement('input');
      langInput.type = 'text';
      langInput.className = 'pc-ed-code-lang';
      langInput.value = ctx.data.language || '';
      langInput.placeholder = 'Langage (ex. php, js, css, html, sql)';
      langInput.setAttribute('aria-label', 'Langage du bloc code');
      langInput.addEventListener('input', () => ctx.updateData({ language: langInput.value.trim().toLowerCase() }));
      header.appendChild(langInput);

      const textarea = document.createElement('textarea');
      textarea.className = 'pc-ed-code-textarea';
      textarea.value = ctx.data.code || '';
      textarea.rows = Math.max(4, String(ctx.data.code || '').split('\n').length + 1);
      textarea.placeholder = '// Collez ou saisissez votre code ici…';
      textarea.spellcheck = false;
      textarea.setAttribute('aria-label', 'Code source');
      textarea.addEventListener('input', () => {
        textarea.rows = Math.max(4, textarea.value.split('\n').length + 1);
        ctx.updateData({ code: textarea.value });
      });

      wrap.append(header, textarea);
      return wrap;
    },
  });

  // 7. Separator
  registry.registerBlock({
    type: 'separator',
    title: 'Séparateur',
    description: 'Ligne horizontale de séparation.',
    icon: 'minus',
    category: 'layout',
    keywords: ['separateur', 'hr', 'ligne'],
    defaultData: () => ({}),
    render: () => {
      const wrap = document.createElement('div');
      wrap.className = 'pc-ed-block-body pc-ed-separator';
      const hr = document.createElement('hr');
      wrap.appendChild(hr);
      return wrap;
    },
  });

  // 8. Embed (YouTube / Vimeo only)
  registry.registerBlock({
    type: 'embed',
    title: 'Vidéo (YouTube / Vimeo)',
    description: 'Intégration sécurisée YouTube ou Vimeo.',
    icon: 'video',
    category: 'media',
    keywords: ['video', 'youtube', 'vimeo', 'embed'],
    defaultData: () => ({ url: '', title: '' }),
    render: (ctx) => {
      const wrap = document.createElement('div');
      wrap.className = 'pc-ed-block-body pc-ed-embed';

      const status = document.createElement('div');
      status.className = 'pc-ed-embed-status';

      const urlInput = document.createElement('input');
      urlInput.type = 'url';
      urlInput.className = 'pc-input';
      urlInput.value = ctx.data.url || '';
      urlInput.placeholder = 'https://www.youtube.com/watch?v=... ou https://vimeo.com/...';
      urlInput.setAttribute('aria-label', 'URL YouTube ou Vimeo');

      const titleInput = document.createElement('input');
      titleInput.type = 'text';
      titleInput.className = 'pc-input';
      titleInput.value = ctx.data.title || '';
      titleInput.placeholder = 'Titre accessible de la vidéo (ex. Présentation Prime CMS)';
      titleInput.setAttribute('aria-label', 'Titre accessible de la vidéo');

      const syncStatus = (val) => {
        if (!val) {
          status.textContent = 'Seules les URL YouTube (youtube-nocookie.com) et Vimeo sont autorisées.';
          status.dataset.state = 'neutral';
          return;
        }
        const parsed = parseEmbedUrl(val);
        if (parsed) {
          status.textContent = `✓ ${parsed.provider === 'youtube' ? 'YouTube (mode sans cookie)' : 'Vimeo'} détecté (${parsed.id})`;
          status.dataset.state = 'ok';
        } else {
          status.textContent = 'URL non reconnue : utilisez un lien YouTube ou Vimeo valide.';
          status.dataset.state = 'error';
        }
      };
      syncStatus(ctx.data.url || '');

      urlInput.addEventListener('input', () => {
        const val = urlInput.value.trim();
        syncStatus(val);
        ctx.updateData({ url: val });
      });
      titleInput.addEventListener('input', () => ctx.updateData({ title: titleInput.value }));

      wrap.append(urlInput, titleInput, status);
      return wrap;
    },
  });

  // 9. Table
  registry.registerBlock({
    type: 'table',
    title: 'Tableau',
    description: 'Tableau éditable avec ligne d\'en-tête.',
    icon: 'table',
    category: 'layout',
    keywords: ['tableau', 'table', 'grille'],
    defaultData: () => ({ hasHeader: true, rows: [['Colonne 1', 'Colonne 2'], ['', '']] }),
    render: (ctx) => {
      const wrap = document.createElement('div');
      wrap.className = 'pc-ed-block-body pc-ed-table';

      const hasHeader = ctx.data.hasHeader !== false;
      const rows = Array.isArray(ctx.data.rows) && ctx.data.rows.length > 0
        ? ctx.data.rows.map((r) => (Array.isArray(r) ? [...r] : ['']))
        : [['Colonne 1', 'Colonne 2'], ['', '']];

      const controls = document.createElement('div');
      controls.className = 'pc-ed-inline-controls';

      const addRowBtn = document.createElement('button');
      addRowBtn.type = 'button';
      addRowBtn.className = 'pc-ed-mini-btn';
      addRowBtn.textContent = '+ Ligne';
      addRowBtn.addEventListener('click', () => {
        const cols = rows[0]?.length || 2;
        rows.push(Array(cols).fill(''));
        ctx.updateData({ hasHeader, rows }, { rerender: true, recordHistory: true });
      });

      const addColBtn = document.createElement('button');
      addColBtn.type = 'button';
      addColBtn.className = 'pc-ed-mini-btn';
      addColBtn.textContent = '+ Colonne';
      addColBtn.addEventListener('click', () => {
        rows.forEach((r) => r.push(''));
        ctx.updateData({ hasHeader, rows }, { rerender: true, recordHistory: true });
      });

      const headerToggle = document.createElement('label');
      headerToggle.className = 'pc-ed-checkbox';
      const headerChk = document.createElement('input');
      headerChk.type = 'checkbox';
      headerChk.checked = hasHeader;
      headerChk.addEventListener('change', () => {
        ctx.updateData({ hasHeader: headerChk.checked, rows }, { rerender: true, recordHistory: true });
      });
      headerToggle.append(headerChk, document.createTextNode(' Ligne d\'en-tête'));

      controls.append(addRowBtn, addColBtn, headerToggle);

      const tableWrap = document.createElement('div');
      tableWrap.className = 'pc-ed-table-scroll';
      const table = document.createElement('table');

      rows.forEach((row, rIdx) => {
        const tr = document.createElement('tr');
        const isHead = hasHeader && rIdx === 0;
        row.forEach((cellVal, cIdx) => {
          const cell = document.createElement(isHead ? 'th' : 'td');
          const cellEdit = createRichTextEl({
            tag: 'div',
            className: 'pc-ed-table-cell',
            html: cellVal,
            placeholder: isHead ? `En-tête ${cIdx + 1}` : '…',
            ariaLabel: `Cellule ligne ${rIdx + 1} colonne ${cIdx + 1}`,
            onChange: (html) => {
              rows[rIdx][cIdx] = html;
              ctx.updateData({ hasHeader, rows });
            },
          });
          cell.appendChild(cellEdit);
          tr.appendChild(cell);
        });
        table.appendChild(tr);
      });

      tableWrap.appendChild(table);
      wrap.append(controls, tableWrap);
      return wrap;
    },
  });

  // 10. Callout (info, success, warning, danger)
  registry.registerBlock({
    type: 'callout',
    title: 'Encadré',
    description: 'Bloc d\'attention coloré (info, succès, alerte, danger).',
    icon: 'info',
    category: 'layout',
    keywords: ['encadre', 'callout', 'note', 'info', 'alerte'],
    defaultData: () => ({ variant: 'info', title: '', text: '' }),
    render: (ctx) => {
      const variant = ['info', 'success', 'warning', 'danger'].includes(ctx.data.variant) ? ctx.data.variant : 'info';
      const wrap = document.createElement('div');
      wrap.className = `pc-ed-block-body pc-ed-callout pc-ed-callout--${variant}`;

      const controls = document.createElement('div');
      controls.className = 'pc-ed-segmented';
      [
        { key: 'info', label: 'Info' },
        { key: 'success', label: 'Succès' },
        { key: 'warning', label: 'Attention' },
        { key: 'danger', label: 'Danger' },
      ].forEach((opt) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `pc-ed-seg-btn${variant === opt.key ? ' is-active' : ''}`;
        btn.textContent = opt.label;
        btn.addEventListener('click', () => {
          ctx.updateData({ variant: opt.key }, { rerender: true, recordHistory: true });
        });
        controls.appendChild(btn);
      });

      const titleEl = createRichTextEl({
        tag: 'div',
        className: 'pc-ed-callout-title',
        html: ctx.data.title || '',
        placeholder: 'Titre de l\'encadré (optionnel)…',
        ariaLabel: 'Titre de l\'encadré',
        onChange: (html) => ctx.updateData({ title: html }),
      });

      const bodyEl = createRichTextEl({
        tag: 'div',
        className: 'pc-ed-callout-body',
        html: ctx.data.text || '',
        placeholder: 'Message mis en évidence…',
        ariaLabel: 'Texte de l\'encadré',
        onChange: (html) => ctx.updateData({ text: html }),
        onPaste: (e, el) => ctx.handlePaste(e, el),
      });

      wrap.append(controls, titleEl, bodyEl);
      return wrap;
    },
  });

  // 11. HTML (requires capability `content.unfiltered_html`)
  registry.registerBlock({
    type: 'html',
    title: 'HTML libre',
    description: 'HTML personnalisé (réservé aux rôles avec content.unfiltered_html).',
    icon: 'code',
    category: 'advanced',
    requiresCapability: 'content.unfiltered_html',
    keywords: ['html', 'brut', 'raw', 'custom'],
    defaultData: () => ({ html: '' }),
    render: (ctx) => {
      const wrap = document.createElement('div');
      wrap.className = 'pc-ed-block-body pc-ed-html';
      const canEditHtml = ctx.capabilities.has('content.unfiltered_html');

      const badge = document.createElement('div');
      badge.className = 'pc-ed-html-banner';
      badge.textContent = canEditHtml
        ? 'HTML libre (rendu tel quel — réservé aux rôles de confiance)'
        : 'Bloc HTML en lecture seule (la permission content.unfiltered_html est requise pour le modifier)';

      const textarea = document.createElement('textarea');
      textarea.className = 'pc-ed-code-textarea';
      textarea.value = ctx.data.html || '';
      textarea.rows = Math.max(4, String(ctx.data.html || '').split('\n').length + 1);
      textarea.spellcheck = false;
      textarea.readOnly = !canEditHtml;
      textarea.placeholder = '<div>Votre HTML personnalisé…</div>';
      textarea.setAttribute('aria-label', 'Code HTML libre');

      if (canEditHtml) {
        textarea.addEventListener('input', () => {
          textarea.rows = Math.max(4, textarea.value.split('\n').length + 1);
          ctx.updateData({ html: textarea.value });
        });
      }

      wrap.append(badge, textarea);
      return wrap;
    },
  });

  // 12. Gallery (multi-image grid from Media Library)
  registry.registerBlock({
    type: 'gallery',
    title: 'Galerie',
    description: 'Grille d\'images sélectionnées depuis la médiathèque (2 à 4 colonnes).',
    icon: 'images',
    category: 'media',
    keywords: ['galerie', 'gallery', 'photos', 'grille', 'images'],
    defaultData: () => ({ columns: 3, caption: '', items: [] }),
    render: (ctx) => {
      const wrap = document.createElement('div');
      wrap.className = 'pc-ed-block-body pc-ed-gallery';

      const columns = [2, 3, 4].includes(Number(ctx.data.columns)) ? Number(ctx.data.columns) : 3;
      const items = Array.isArray(ctx.data.items) ? ctx.data.items.map((it) => ({ ...it })) : [];

      const topRow = document.createElement('div');
      topRow.className = 'pc-ed-media-picker-row';

      const addBtn = document.createElement('button');
      addBtn.type = 'button';
      addBtn.className = 'pc-btn pc-btn--secondary pc-btn--sm';
      addBtn.append(createIconSvg('images', 14), document.createTextNode(' Ajouter des images'));
      addBtn.addEventListener('click', async () => {
        if (!window.Prime?.media?.openPicker) return;
        const chosen = await window.Prime.media.openPicker({
          multiple: true,
          kinds: ['image'],
          title: 'Sélectionner des images pour la galerie',
        });
        if (!chosen || chosen.length === 0) return;
        const next = [
          ...items,
          ...chosen.map((m) => ({
            mediaId: Number(m.id),
            url: m.url || m.thumbnail_url || '',
            alt: m.alt_text || m.title || '',
            caption: m.caption || '',
          })),
        ].slice(0, 50);
        ctx.updateData({ columns, items: next }, { rerender: true, recordHistory: true });
      });

      const colsGroup = document.createElement('div');
      colsGroup.className = 'pc-ed-segmented';
      [2, 3, 4].forEach((n) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `pc-ed-seg-btn${columns === n ? ' is-active' : ''}`;
        btn.textContent = `${n} col.`;
        btn.addEventListener('click', () => {
          ctx.updateData({ columns: n, items }, { rerender: true, recordHistory: true });
        });
        colsGroup.appendChild(btn);
      });

      topRow.append(addBtn, colsGroup);
      wrap.appendChild(topRow);

      if (items.length === 0) {
        const hint = document.createElement('p');
        hint.className = 'pc-text-sm pc-text-muted';
        hint.textContent = 'Aucune image sélectionnée. Cliquez sur « Ajouter des images » pour composer votre galerie.';
        wrap.appendChild(hint);
      } else {
        const grid = document.createElement('div');
        grid.className = 'pc-ed-gallery-grid';
        grid.setAttribute('data-prime-cols', String(columns));

        items.forEach((item, idx) => {
          const card = document.createElement('div');
          card.className = 'pc-ed-gallery-card';

          const img = document.createElement('img');
          img.src = item.url || '';
          img.alt = item.alt || '';
          img.loading = 'lazy';

          const tools = document.createElement('div');
          tools.className = 'pc-ed-gallery-card__tools';

          const moveLeft = document.createElement('button');
          moveLeft.type = 'button';
          moveLeft.className = 'pc-ed-icon-btn';
          moveLeft.disabled = idx === 0;
          moveLeft.setAttribute('aria-label', 'Déplacer à gauche');
          moveLeft.appendChild(createIconSvg('chevron-left', 13));
          moveLeft.addEventListener('click', () => {
            if (idx === 0) return;
            const copy = [...items];
            [copy[idx - 1], copy[idx]] = [copy[idx], copy[idx - 1]];
            ctx.updateData({ columns, items: copy }, { rerender: true, recordHistory: true });
          });

          const moveRight = document.createElement('button');
          moveRight.type = 'button';
          moveRight.className = 'pc-ed-icon-btn';
          moveRight.disabled = idx === items.length - 1;
          moveRight.setAttribute('aria-label', 'Déplacer à droite');
          moveRight.appendChild(createIconSvg('chevron-right', 13));
          moveRight.addEventListener('click', () => {
            if (idx >= items.length - 1) return;
            const copy = [...items];
            [copy[idx], copy[idx + 1]] = [copy[idx + 1], copy[idx]];
            ctx.updateData({ columns, items: copy }, { rerender: true, recordHistory: true });
          });

          const removeBtn = document.createElement('button');
          removeBtn.type = 'button';
          removeBtn.className = 'pc-ed-icon-btn';
          removeBtn.setAttribute('aria-label', 'Retirer de la galerie');
          removeBtn.appendChild(createIconSvg('x', 13));
          removeBtn.addEventListener('click', () => {
            const copy = items.filter((_, i) => i !== idx);
            ctx.updateData({ columns, items: copy }, { rerender: true, recordHistory: true });
          });

          tools.append(moveLeft, moveRight, removeBtn);

          const cap = document.createElement('input');
          cap.type = 'text';
          cap.className = 'pc-ed-gallery-card__cap';
          cap.value = stripHtml(item.caption || '');
          cap.placeholder = 'Légende…';
          cap.setAttribute('aria-label', `Légende image ${idx + 1}`);
          cap.addEventListener('input', () => {
            items[idx].caption = escapeHtml(cap.value);
            ctx.updateData({ columns, items });
          });

          card.append(img, tools, cap);
          grid.appendChild(card);
        });

        wrap.appendChild(grid);
      }

      const galleryCaption = document.createElement('input');
      galleryCaption.type = 'text';
      galleryCaption.className = 'pc-input';
      galleryCaption.style.marginTop = '10px';
      galleryCaption.value = stripHtml(ctx.data.caption || '');
      galleryCaption.placeholder = 'Légende globale de la galerie (optionnelle)…';
      galleryCaption.setAttribute('aria-label', 'Légende de la galerie');
      galleryCaption.addEventListener('input', () => ctx.updateData({ caption: escapeHtml(galleryCaption.value) }));

      wrap.appendChild(galleryCaption);
      return wrap;
    },
  });

  // 13. Video (self-hosted HTML5 video)
  registry.registerBlock({
    type: 'video',
    title: 'Vidéo (fichier local)',
    description: 'Lecteur vidéo HTML5 hébergé dans votre médiathèque (MP4, WebM).',
    icon: 'video',
    category: 'media',
    keywords: ['video', 'mp4', 'webm', 'film', 'media'],
    defaultData: () => ({ mediaId: null, url: '', mimeType: '', posterMediaId: null, posterUrl: '', caption: '', controls: true, muted: false, loop: false }),
    render: (ctx) => {
      const wrap = document.createElement('div');
      wrap.className = 'pc-ed-block-body pc-ed-video';

      const row = document.createElement('div');
      row.className = 'pc-ed-media-picker-row';

      const pickVideo = document.createElement('button');
      pickVideo.type = 'button';
      pickVideo.className = 'pc-btn pc-btn--secondary pc-btn--sm';
      pickVideo.append(createIconSvg('video', 14), document.createTextNode(' Choisir une vidéo'));
      pickVideo.addEventListener('click', async () => {
        if (!window.Prime?.media?.openPicker) return;
        const chosen = await window.Prime.media.openPicker({
          multiple: false,
          kinds: ['video'],
          selected: ctx.data.mediaId ? [Number(ctx.data.mediaId)] : [],
          title: 'Choisir une vidéo',
        });
        if (!chosen || chosen.length === 0) return;
        const m = chosen[0];
        ctx.updateData({ mediaId: Number(m.id), url: m.url || '', mimeType: m.mime_type || '' }, { rerender: true, recordHistory: true });
      });

      const pickPoster = document.createElement('button');
      pickPoster.type = 'button';
      pickPoster.className = 'pc-btn pc-btn--ghost pc-btn--sm';
      pickPoster.append(createIconSvg('image', 14), document.createTextNode(ctx.data.posterUrl ? ' Changer le poster' : ' Image poster'));
      pickPoster.addEventListener('click', async () => {
        if (!window.Prime?.media?.openPicker) return;
        const chosen = await window.Prime.media.openPicker({
          multiple: false,
          kinds: ['image'],
          selected: ctx.data.posterMediaId ? [Number(ctx.data.posterMediaId)] : [],
          title: 'Choisir une image poster',
        });
        if (!chosen || chosen.length === 0) return;
        const m = chosen[0];
        ctx.updateData({ posterMediaId: Number(m.id), posterUrl: m.url || '' }, { rerender: true, recordHistory: true });
      });

      row.append(pickVideo, pickPoster);
      wrap.appendChild(row);

      if (ctx.data.url) {
        const video = document.createElement('video');
        video.controls = true;
        video.preload = 'metadata';
        video.src = ctx.data.url;
        if (ctx.data.posterUrl) video.poster = ctx.data.posterUrl;
        video.style.maxWidth = '100%';
        video.style.maxHeight = '260px';
        video.style.borderRadius = '8px';
        video.style.marginBottom = '8px';
        wrap.appendChild(video);
      }

      const opts = document.createElement('div');
      opts.className = 'pc-ed-media-picker-row';
      [
        { key: 'controls', label: 'Contrôles', val: ctx.data.controls !== false },
        { key: 'muted', label: 'Muet', val: Boolean(ctx.data.muted) },
        { key: 'loop', label: 'Boucle', val: Boolean(ctx.data.loop) },
      ].forEach((opt) => {
        const lbl = document.createElement('label');
        lbl.className = 'pc-ed-checkbox';
        const chk = document.createElement('input');
        chk.type = 'checkbox';
        chk.checked = opt.val;
        chk.addEventListener('change', () => ctx.updateData({ [opt.key]: chk.checked }, { recordHistory: true }));
        lbl.append(chk, document.createTextNode(` ${opt.label}`));
        opts.appendChild(lbl);
      });

      const capInput = document.createElement('input');
      capInput.type = 'text';
      capInput.className = 'pc-input';
      capInput.value = stripHtml(ctx.data.caption || '');
      capInput.placeholder = 'Légende de la vidéo (optionnelle)…';
      capInput.addEventListener('input', () => ctx.updateData({ caption: escapeHtml(capInput.value) }));

      wrap.append(opts, capInput);
      return wrap;
    },
  });

  // 14. Audio (self-hosted HTML5 audio)
  registry.registerBlock({
    type: 'audio',
    title: 'Audio',
    description: 'Lecteur audio HTML5 (MP3, OGG, WAV, M4A) depuis la médiathèque.',
    icon: 'music',
    category: 'media',
    keywords: ['audio', 'podcast', 'son', 'mp3', 'musique'],
    defaultData: () => ({ mediaId: null, url: '', title: '', caption: '' }),
    render: (ctx) => {
      const wrap = document.createElement('div');
      wrap.className = 'pc-ed-block-body pc-ed-audio';

      const row = document.createElement('div');
      row.className = 'pc-ed-media-picker-row';
      const pickBtn = document.createElement('button');
      pickBtn.type = 'button';
      pickBtn.className = 'pc-btn pc-btn--secondary pc-btn--sm';
      pickBtn.append(createIconSvg('music', 14), document.createTextNode(' Choisir un fichier audio'));
      pickBtn.addEventListener('click', async () => {
        if (!window.Prime?.media?.openPicker) return;
        const chosen = await window.Prime.media.openPicker({
          multiple: false,
          kinds: ['audio'],
          selected: ctx.data.mediaId ? [Number(ctx.data.mediaId)] : [],
          title: 'Choisir un fichier audio',
        });
        if (!chosen || chosen.length === 0) return;
        const m = chosen[0];
        ctx.updateData({ mediaId: Number(m.id), url: m.url || '', title: ctx.data.title || m.title || m.original_filename || '' }, { rerender: true, recordHistory: true });
      });
      row.appendChild(pickBtn);
      wrap.appendChild(row);

      if (ctx.data.url) {
        const audio = document.createElement('audio');
        audio.controls = true;
        audio.preload = 'metadata';
        audio.src = ctx.data.url;
        audio.style.width = '100%';
        audio.style.marginBottom = '8px';
        wrap.appendChild(audio);
      }

      const grid = document.createElement('div');
      grid.className = 'pc-ed-form-grid';

      const titleInput = document.createElement('input');
      titleInput.type = 'text';
      titleInput.className = 'pc-input';
      titleInput.value = ctx.data.title || '';
      titleInput.placeholder = 'Titre de l\'enregistrement audio…';
      titleInput.addEventListener('input', () => ctx.updateData({ title: titleInput.value }));

      const capInput = document.createElement('input');
      capInput.type = 'text';
      capInput.className = 'pc-input';
      capInput.value = stripHtml(ctx.data.caption || '');
      capInput.placeholder = 'Légende optionnelle…';
      capInput.addEventListener('input', () => ctx.updateData({ caption: escapeHtml(capInput.value) }));

      grid.append(titleInput, capInput);
      wrap.appendChild(grid);
      return wrap;
    },
  });

  // 15. File (downloadable document / archive card)
  registry.registerBlock({
    type: 'file',
    title: 'Fichier à télécharger',
    description: 'Carte de téléchargement pour un PDF, document ou archive.',
    icon: 'file',
    category: 'media',
    keywords: ['fichier', 'file', 'pdf', 'document', 'telechargement', 'zip'],
    defaultData: () => ({ mediaId: null, url: '', label: '', filename: '', extension: '', sizeBytes: 0 }),
    render: (ctx) => {
      const wrap = document.createElement('div');
      wrap.className = 'pc-ed-block-body pc-ed-file';

      const row = document.createElement('div');
      row.className = 'pc-ed-media-picker-row';
      const pickBtn = document.createElement('button');
      pickBtn.type = 'button';
      pickBtn.className = 'pc-btn pc-btn--secondary pc-btn--sm';
      pickBtn.append(createIconSvg('file', 14), document.createTextNode(' Choisir un fichier'));
      pickBtn.addEventListener('click', async () => {
        if (!window.Prime?.media?.openPicker) return;
        const chosen = await window.Prime.media.openPicker({
          multiple: false,
          kinds: ['document', 'archive'],
          selected: ctx.data.mediaId ? [Number(ctx.data.mediaId)] : [],
          title: 'Choisir un fichier à télécharger',
        });
        if (!chosen || chosen.length === 0) return;
        const m = chosen[0];
        ctx.updateData(
          {
            mediaId: Number(m.id),
            url: m.url || '',
            label: m.title || m.original_filename || 'Télécharger',
            filename: m.original_filename || '',
            extension: m.extension || '',
            sizeBytes: Number(m.size_bytes || 0),
          },
          { rerender: true, recordHistory: true },
        );
      });
      row.appendChild(pickBtn);
      if (ctx.data.filename) {
        const badge = document.createElement('span');
        badge.className = 'pc-badge pc-badge--neutral pc-mono';
        badge.textContent = ctx.data.filename;
        row.appendChild(badge);
      }
      wrap.appendChild(row);

      const labelInput = document.createElement('input');
      labelInput.type = 'text';
      labelInput.className = 'pc-input';
      labelInput.value = ctx.data.label || '';
      labelInput.placeholder = 'Libellé du bouton de téléchargement (ex. Télécharger la brochure PDF)';
      labelInput.addEventListener('input', () => ctx.updateData({ label: labelInput.value }));
      wrap.appendChild(labelInput);

      return wrap;
    },
  });
}
