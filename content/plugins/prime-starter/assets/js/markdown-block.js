/**
 * Prime Starter — Prime Editor block `prime-starter/markdown`.
 * The server converts and sanitises the Markdown; the editor only edits the source.
 */

if (window.Prime?.editor?.registerBlock) {
  window.Prime.editor.registerBlock({
    type: 'prime-starter/markdown',
    title: 'Markdown',
    description: 'Texte écrit en Markdown, converti en HTML propre à l’affichage.',
    icon: 'file-text',
    keywords: ['markdown', 'md', 'texte', 'text', 'note'],
    defaultData: () => ({ markdown: '' }),
    render(block, helpers) {
      const textarea = document.createElement('textarea');
      textarea.className = 'pc-textarea ps-ed-markdown';
      textarea.rows = 8;
      textarea.placeholder = '# Titre\n\nUn paragraphe avec du **gras**.';
      textarea.setAttribute('aria-label', 'Markdown');
      textarea.value = block.data.markdown || '';
      textarea.addEventListener('input', () => helpers.updateData({ markdown: textarea.value }));

      return { element: textarea, focusTarget: textarea };
    },
  });
}
