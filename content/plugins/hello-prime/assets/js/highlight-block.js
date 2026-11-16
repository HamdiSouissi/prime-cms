/**
 * Hello Prime — Client-side Prime Editor extension block (`hello-prime/highlight`).
 * Registers automatically via `window.Prime.editor.registerBlock(...)`.
 */

if (window.Prime?.editor?.registerBlock) {
  window.Prime.editor.registerBlock({
    type: 'hello-prime/highlight',
    title: 'Mise en avant Hello Prime',
    description: 'Encart violet mettant en lumière un message clé ou un témoignage.',
    icon: 'sparkles',
    keywords: ['highlight', 'spotlight', 'hello', 'encart', 'violet'],
    defaultData: () => ({ badge: 'À retenir', text: '' }),
    render(block, helpers) {
      const wrap = document.createElement('div');
      wrap.className = 'hp-ed-highlight';

      const badgeInput = document.createElement('input');
      badgeInput.type = 'text';
      badgeInput.className = 'pc-input hp-ed-highlight__badge-input';
      badgeInput.placeholder = 'Badge (ex. À retenir)';
      badgeInput.value = block.data.badge || 'À retenir';
      badgeInput.addEventListener('input', () => {
        helpers.updateData({ badge: badgeInput.value });
      });

      const body = document.createElement('div');
      body.className = 'pc-ed-richtext hp-ed-highlight__body';
      body.contentEditable = 'true';
      body.dataset.placeholder = 'Texte de mise en avant…';
      body.innerHTML = block.data.text || '';
      body.addEventListener('input', () => {
        helpers.updateData({ text: body.innerHTML });
      });

      wrap.append(badgeInput, body);
      return { element: wrap, focusTarget: body };
    },
  });
}
