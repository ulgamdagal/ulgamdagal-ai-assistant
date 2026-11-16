(() => {
  'use strict';
  const root = document.querySelector('.ulg-admin--settings');
  if (!root) return;
  const form = root.querySelector('#ulgamdagal-settings');
  const original = form && form.querySelector('.form-table');
  if (!original) return;
  const groups = [
    ['launch', 'Запуск чата', 'Доступ посетителей и способы размещения.', ['public_chat', 'floating_chat']],
    ['appearance', 'Виджет и оформление', 'Имя, аватар, цвета и расположение на экране.', ['assistant_name', 'avatar_id', 'colors', 'widget_side']],
    ['knowledge', 'Знания ассистента', 'Информация о компании, страницы и каталог товаров.', ['store_info', 'knowledge_pages', 'woocommerce']],
    ['operators', 'Операторы', 'Передача диалогов и доступ сотрудников.', ['operator_users', 'telegram_enabled']],
    ['history', 'История и журнал', 'Хранение переписки и удобное время сообщений.', ['remember_chat', 'log_chats', 'journal_timezone']],
    ['security', 'Защита и доступ', 'Частота запросов, блокировки и разрешённые ссылки.', ['rate_limit', 'blocked_networks', 'allow_remote_edits', 'link_domains']]
  ];
  const rows = Array.from(original.tBodies).reduce((all, body) => all.concat(Array.from(body.rows)), []);
  const nav = root.querySelector('.ulg-section-nav');
  groups.forEach(([id, title, description, fields]) => {
    const section = document.createElement('section');
    section.className = 'ulg-card'; section.id = 'ulg-' + id;
    const heading = document.createElement('h2'); heading.textContent = title;
    const note = document.createElement('p'); note.className = 'ulg-card-intro'; note.textContent = description;
    const table = document.createElement('table'); table.className = 'form-table';
    const body = document.createElement('tbody'); table.appendChild(body);
    fields.forEach(field => rows.forEach(row => {
      if (row.parentNode !== original.tBodies[0]) return;
      const match = Array.from(row.querySelectorAll('[name]')).some(el => el.name.indexOf('ulgamdagal_ai_settings[' + field + ']') === 0);
      if (match) body.appendChild(row);
    }));
    if (!body.children.length) return;
    section.append(heading, note, table); form.insertBefore(section, original);
    if (id === 'launch') {
      const placement = root.querySelector('.ulg-placement');
      if (placement) section.insertBefore(placement, table);
    }
    const link = document.createElement('a'); link.href = '#' + section.id; link.textContent = title; nav.appendChild(link);
  });
  if (!original.querySelector('tr')) original.remove(); // Unknown future fields remain visible.
  const save = form.querySelector('p.submit');
  if (save) {
    save.classList.add('ulg-savebar');
    const note = document.createElement('span'); note.textContent = 'Все разделы сохраняются одной кнопкой.'; save.appendChild(note);
    const button = save.querySelector('[type=submit]'); if (button) button.value = 'Сохранить настройки';
  }
})();
