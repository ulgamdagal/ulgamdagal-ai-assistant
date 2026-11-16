(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const select = document.getElementById('ulgamdagal-avatar-select');
    const reset = document.getElementById('ulgamdagal-avatar-default');
    const field = document.getElementById('ulgamdagal-avatar-id');
    const preview = document.getElementById('ulgamdagal-avatar-preview');
    if (!select || !reset || !field || !preview || !window.wp || !wp.media) return;
    let frame;
    select.addEventListener('click', () => {
      if (!frame) {
        frame = wp.media({ title: 'Выберите аватар ассистента', button: { text: 'Использовать аватар' }, library: { type: 'image' }, multiple: false });
        frame.on('select', () => {
          const image = frame.state().get('selection').first().toJSON();
          field.value = image.id;
          preview.src = image.sizes && image.sizes.thumbnail ? image.sizes.thumbnail.url : image.url;
        });
      }
      frame.open();
    });
    reset.addEventListener('click', () => {
      field.value = '0';
      preview.src = preview.dataset.default;
    });
  });
})();
