(() => {
  'use strict';
  const boot = () => {
    const root = document.getElementById('ulgamdagal-colors');
    if (!root) return;
    const preview = root.querySelector('#ulgamdagal-color-preview');
    const inputs = Array.from(root.querySelectorAll('[data-color]'));
    const ink = hex => {
      const rgb = [1, 3, 5].map(i => {
        const v = parseInt(hex.slice(i, i + 2), 16) / 255;
        return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
      });
      const l = rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722;
      return (l + .05) / .05 >= 1.05 / (l + .05) ? '#000000' : '#ffffff';
    };
    const mode = root.querySelector('#ulgamdagal-text-mode');
    const update = () => {
      root.querySelector('#ulgamdagal-text-colors').hidden = mode.value !== 'manual';
      const values = Object.fromEntries(inputs.map(input => [input.dataset.color, input.value]));
      ['accent', 'background', 'assistant', 'visitor'].forEach(key => {
        preview.style.setProperty('--ua-' + key, values[key]);
        preview.style.setProperty('--ua-' + key + '-ink', mode.value === 'manual' && values[key + '_text'] ? values[key + '_text'] : ink(values[key]));
      });
    };
    mode.addEventListener('change', update);
    inputs.forEach(input => input.addEventListener('input', update));
    root.querySelector('#ulgamdagal-colors-reset').addEventListener('click', () => {
      inputs.forEach(input => { input.value = input.dataset.default; });
      mode.value = 'auto';
      update();
    });
    update();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
