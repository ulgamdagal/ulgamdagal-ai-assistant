(() => {
  'use strict';
  document.querySelectorAll('.ulgamdagal-chat').forEach((root) => {
    const shell = root.closest('.ulgamdagal-chat-shell');
    const launcher = shell && shell.querySelector('.ulgamdagal-launcher');
    const close = root.querySelector('.ulgamdagal-close');
    const clear = root.querySelector('.ulgamdagal-clear');
    const history = [];
    let conversationToken = null;
    let pendingRequest = null;
    let operatorVisitorName = '';
    const form = root.querySelector('form');
    const input = form.querySelector('textarea');
    const button = form.querySelector('button');
    const status = root.querySelector('.ulgamdagal-status');
    const modeStatus = root.querySelector('.ulgamdagal-mode-status');
    const log = root.querySelector('.ulgamdagal-messages');
    const setOpen = (open) => {
      if (!shell || !launcher) return;
      shell.classList.toggle('is-closed', !open);
      launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
      launcher.setAttribute('aria-label', open ? 'Закрыть чат' : 'Открыть чат');
      if (open) setTimeout(() => input.focus(), 50);
    };
    if (launcher) launcher.addEventListener('click', () => setOpen(shell.classList.contains('is-closed')));
    if (close) close.addEventListener('click', () => setOpen(false));
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else button.click();
      }
    });
    let linkHosts = [];
    try { linkHosts = JSON.parse(root.dataset.linkHosts || '[]'); } catch (_) {}
    const appendInline = (element, text) => {
      const pattern = /(\*\*[^*\n]+\*\*|\[[^\]\n]+\]\(https?:\/\/[^\s)]+\)|https?:\/\/[^\s<]+)/g;
      let cursor = 0;
      let match;
      while ((match = pattern.exec(text)) !== null) {
        element.append(document.createTextNode(text.slice(cursor, match.index)));
        const token = match[0];
        if (token.startsWith('**') && token.endsWith('**')) {
          const strong = document.createElement('strong');
          strong.textContent = token.slice(2, -2);
          element.append(strong);
        } else {
          const markdown = token.match(/^\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)$/);
          const href = markdown ? markdown[2] : token.replace(/[.,;:!?]+$/, '');
          const trailing = markdown ? '' : token.slice(href.length);
          try {
            const url = new URL(href);
            if (!window.UlgamdagalLinkAllowed || !window.UlgamdagalLinkAllowed(href, linkHosts)) throw new Error('domain');
            const link = document.createElement('a');
            link.href = url.href;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = markdown ? markdown[1] : href;
            element.append(link);
            if (trailing) element.append(document.createTextNode(trailing));
          } catch (_) {
            element.append(document.createTextNode(markdown ? markdown[1] + ' (ссылка скрыта)' : '[ссылка скрыта]'));
          }
        }
        cursor = match.index + token.length;
      }
      element.append(document.createTextNode(text.slice(cursor)));
    };
    const renderMessage = (element, text) => {
      let list = null;
      text.split(/\r?\n/).forEach((rawLine) => {
        const line = rawLine.trim();
        if (!line) { list = null; return; }
        if (line === '---') {
          element.append(document.createElement('hr'));
          list = null;
          return;
        }
        const bullet = line.match(/^[-*]\s+(.+)$/);
        if (bullet) {
          if (!list || list.tagName !== 'UL') {
            list = document.createElement('ul');
            element.append(list);
          }
          const item = document.createElement('li');
          appendInline(item, bullet[1]);
          list.append(item);
          return;
        }
        const numbered = line.match(/^\d+[.)]\s+(.+)$/);
        if (numbered) {
          if (!list || list.tagName !== 'OL') {
            list = document.createElement('ol');
            element.append(list);
          }
          const item = document.createElement('li');
          appendInline(item, numbered[1]);
          list.append(item);
          return;
        }
        list = null;
        const heading = line.match(/^#{1,3}\s+(.+)$/);
        const block = document.createElement(heading ? 'h3' : 'p');
        appendInline(block, heading ? heading[1] : line);
        element.append(block);
      });
    };
    const append = (label, text, role) => {
      const article = document.createElement('article');
      article.className = `ulgamdagal-message ulgamdagal-message--${role}`;
      const name = document.createElement('strong');
      const body = document.createElement('div');
      name.textContent = label;
      renderMessage(body, text);
      article.append(name, body);
      log.appendChild(article);
      article.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    };
    const secureId = () => {
      const bytes = new Uint8Array(16);
      self.crypto.getRandomValues(bytes);
      bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
      const hex = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
      return hex.slice(0,8)+'-'+hex.slice(8,12)+'-'+hex.slice(12,16)+'-'+hex.slice(16,20)+'-'+hex.slice(20);
    };
    if (!self.crypto || typeof self.crypto.getRandomValues !== 'function') {
      status.textContent = 'Для безопасного чата откройте HTTPS-версию сайта в современном браузере.';
      input.disabled = true; button.disabled = true;
      return;
    }
    const storageKey = 'ulgamdagal_ai_chat_history_v2_' + root.dataset.endpoint;
    const remember = root.dataset.remember === '1';
    const sessionKey = 'ulgamdagal_ai_chat_session_v2_' + root.dataset.endpoint;
    const sessionStore = remember ? localStorage : sessionStorage;
    let sessionId = '';
    try {
      sessionId = sessionStore.getItem(sessionKey) || '';
      if (!/^[a-zA-Z0-9-]{16,80}$/.test(sessionId)) {
        sessionId = secureId();
        sessionStore.setItem(sessionKey, sessionId);
      }
    } catch (_) {
      sessionId = secureId();
    }
    const pendingKey = sessionKey + '_pending';
    const persistPending = () => {
      try {
        if (pendingRequest) sessionStore.setItem(pendingKey, JSON.stringify(pendingRequest));
        else sessionStore.removeItem(pendingKey);
      } catch (_) {}
    };
    try {
      const savedPending = JSON.parse(sessionStore.getItem(pendingKey) || 'null');
      if (savedPending && savedPending.session_id === sessionId && typeof savedPending.message === 'string' && /^[0-9a-f-]{36}$/i.test(savedPending.request_id)) {
        pendingRequest = savedPending;
        input.value = savedPending.message;
        status.textContent = savedPending.blocked ? 'Автоматический повтор остановлен. При необходимости нажмите «Новый диалог».' : savedPending.retryable ? 'Сообщение не отправлено. Можно повторить отправку или изменить текст.' : 'Проверяем состояние предыдущего сообщения. Повторно оно не отправляется.';
      }
    } catch (_) {}
    const saveHistory = () => {
      if (!remember) return;
      const hours = Math.max(1, Math.min(168, Number(root.dataset.historyHours) || 24));
      try {
        localStorage.setItem(storageKey, JSON.stringify({ expires: Date.now() + hours * 3600000, messages: history.slice(-20), conversationToken, sessionId, operatorVisitorName }));
      } catch (_) {}
    };
    if (remember) {
      try {
        const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
        if (saved && Number(saved.expires) > Date.now() && Array.isArray(saved.messages)) {
          if (saved.sessionId === sessionId && typeof saved.conversationToken === 'string' && /^[0-9a-f-]{36}\.[0-9a-f]{64}$/.test(saved.conversationToken)) {
            conversationToken = saved.conversationToken;
            if (typeof saved.operatorVisitorName === 'string' && saved.operatorVisitorName.trim() && saved.operatorVisitorName.length <= 60) {
              operatorVisitorName = saved.operatorVisitorName.trim();
            }
          }
          saved.messages.slice(-20).forEach((item) => {
            if (!item || !['user', 'assistant'].includes(item.role) || typeof item.content !== 'string') return;
            history.push({ role: item.role, content: item.content.slice(0, 4000) });
            append(item.role === 'user' ? 'Вы' : (root.dataset.assistantName || 'Ulgamdagal AI'), item.content.slice(0, 4000), item.role);
          });
        } else {
          localStorage.removeItem(storageKey);
        }
      } catch (_) {
        try { localStorage.removeItem(storageKey); } catch (_) {}
      }
    }
    let resetting = false;
    if (clear) clear.addEventListener('click', async () => {
      if (button.disabled || resetting || (operatorButton && operatorButton.disabled)) return;
      const draft = input.value;
      const abandoning = pendingRequest && pendingRequest.blocked;
      if (pendingRequest && !pendingRequest.retryable && !abandoning) {
        status.textContent = 'Сначала дождитесь результата проверки предыдущего сообщения.';
        return;
      }
      if (abandoning && !window.confirm('Начать новый диалог? Связь с прежним оператором не переносится. Старый диалог может остаться открытым. Если сообщение уже было принято, повторная отправка в новом диалоге может создать дубль.')) return;
      if (conversationToken && !abandoning) {
        resetting = true;
        clear.disabled = true;
        button.disabled = true;
        input.disabled = true;
        operatorRequestEpoch++;
        status.textContent = 'Завершаем предыдущий диалог…';
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 15000);
        try {
          const headers = { 'Content-Type': 'application/json' };
          if (root.dataset.nonce) headers['X-WP-Nonce'] = root.dataset.nonce;
          const response = await fetch(root.dataset.endpoint.replace(/\/chat\/?$/, '/conversation-close'), {
            method: 'POST', credentials: 'same-origin', headers, signal: controller.signal,
            body: JSON.stringify({session_id: sessionId, conversation_token: conversationToken})
          });
          const data = await response.json();
          if (!response.ok || data.closed !== true || data.conversation_id !== conversationToken.split('.')[0]) {
            status.textContent = response.status === 409 ? 'Предыдущее сообщение ещё обрабатывается. Попробуйте начать новый диалог немного позже.' : 'Не удалось завершить диалог. Переписка сохранена. Нажмите «Новый диалог» ещё раз немного позже.';
            return;
          }
        } catch (_) {
          status.textContent = 'Не удалось подтвердить завершение диалога. Переписка сохранена. Попробуйте ещё раз.';
          return;
        } finally {
          clearTimeout(timer);
          resetting = false;
          clear.disabled = false;
          button.disabled = false;
          input.disabled = false;
        }
      }
      input.value = draft;
      input.readOnly = false;
      conversationToken = null;
      operatorVisitorName = '';
      pendingRequest = null;
      persistPending();
      sessionId = secureId();
      try { sessionStore.setItem(sessionKey, sessionId); } catch (_) {}
      operatorLast = 0;
      operatorKey = (root.dataset.telegram === '1' ? 'ulgamdagal_ai_telegram_last_v1_' : 'ulgamdagal_ai_operator_last_v2_') + sessionId;
      if (modeStatus) modeStatus.textContent = 'Отвечает ИИ-агент';
      history.splice(0, history.length);
      log.replaceChildren();
      try { localStorage.removeItem(storageKey); } catch (_) {}
      const namePanel = root.querySelector('.ulgamdagal-operator-name');
      const nameField = root.querySelector('.ulgamdagal-visitor-name');
      const requestButton = root.querySelector('.ulgamdagal-request-operator');
      if (namePanel) namePanel.hidden = true;
      if (nameField) nameField.value = '';
      if (requestButton) requestButton.textContent = 'Позвать оператора';
      clearWaitingNotice();
      operatorRequestEpoch++;
      status.textContent = 'Начат новый диалог. Предыдущая переписка остаётся на сервере.';
      setTimeout(() => { if (status.textContent === 'Начат новый диалог. Предыдущая переписка остаётся на сервере.') status.textContent = ''; }, 1800);
    });
    let operatorKey = (root.dataset.telegram === '1' ? 'ulgamdagal_ai_telegram_last_v1_' : 'ulgamdagal_ai_operator_last_v2_') + sessionId;
    let operatorLast = 0;
    try { operatorLast = Number(localStorage.getItem(operatorKey)) || 0; } catch (_) {}
    let operatorPolling = false;
    let operatorRequestEpoch = 0;
    let waitingDeadline = 0;
    let waitingTimer = null;
    const unavailableNotice = 'Сейчас не удаётся связаться с ИИ-агентом и операторами. Попробуйте немного позже.';
    const publicError = (data) => {
      const messages = {
        service_paused: 'Приём сообщений временно приостановлен. Попробуйте позже.',
        chat_busy: 'Чат сейчас занят. Повторите сообщение через несколько секунд.',
        chat_visitor_busy: 'С вашего адреса уже обрабатывается сообщение. Дождитесь ответа и повторите попытку.',
        service_rate: 'Слишком много запросов. Подождите несколько секунд.',
        service_busy: 'Операция ещё выполняется. Подождите несколько секунд.',
        service_lock_unavailable: 'Защита запросов временно недоступна. Сообщите владельцу сайта.',
        service_counter_unavailable: 'Защита запросов временно недоступна. Сообщите владельцу сайта.',
        rate_limit: 'Пожалуйста, подождите немного перед следующим сообщением.',
        server_rate_limit: 'Чат временно занят. Попробуйте немного позже.',
        daily_limit: 'Сегодня чат больше не принимает сообщения. Попробуйте завтра.',
        request_pending: 'Ответ пока не получен. Попробуйте отправить то же сообщение немного позже.',
        invalid_message: 'Проверьте сообщение: отправьте обычный текст и при необходимости сократите его.',
        invalid_conversation: 'Не удалось подтвердить диалог. Нажмите «Новый диалог»; связь с прежним оператором не переносится.',
        conversation_token_required: 'Потерян доступ к диалогу. Нажмите «Новый диалог»; связь с прежним оператором не переносится.',
        rest_cookie_invalid_nonce: 'Обновите страницу для восстановления доступа, затем повторите отправку.',
        chat_private: 'Владелец временно отключил публичный чат.',
        rest_no_route: 'Адрес чата недоступен. Сообщите владельцу сайта.',
        session_unavailable: 'Не удалось проверить диалог. Повторите попытку позже.',
        invalid_session: 'Некорректный диалог. Нажмите «Новый диалог».',
        invalid_request_id: 'Некорректный номер запроса. Нажмите «Новый диалог».',
        invalid_request: 'Проверьте данные сообщения.',
        json_required: 'Неверный формат запроса. Обновите страницу.',
        files_forbidden: 'Прикрепление файлов не поддерживается.',
        request_too_large: 'Запрос слишком большой. Сократите сообщение.',
        not_configured: 'Чат пока не настроен. Сообщите владельцу сайта.',
        sync_storage: 'Хранилище сообщений временно недоступно. Повторите попытку позже.',
        sync_config: 'Синхронизация не настроена. Сообщите владельцу сайта.',
        sync_unmapped: 'История ожидает привязки. Сообщите владельцу сайта.',
        sync_pending: 'Предыдущая история ещё доставляется. Повторите попытку позже.',
        sync_mapping_conflict: 'Есть сообщения другого диалога. Автоматический повтор остановлен.',
        sync_event_conflict: 'Этот номер уже относится к другому сообщению. Автоматический повтор остановлен.',
        request_conflict: 'Этот номер уже относится к другому сообщению. Автоматический повтор остановлен.',
        status_conflict: 'Сохранённый запрос не совпадает с сообщением. Автоматический повтор остановлен.',
        blocked: 'Доступ к чату ограничен.',
        access_denied: 'Доступ к чату ограничен.'
      };
      const error = new Error(unavailableNotice);
      error.publicNotice = data && Object.prototype.hasOwnProperty.call(messages, data.code) ? messages[data.code] : unavailableNotice;
      return error;
    };
    const conflictCodes = ['sync_event_conflict', 'sync_mapping_conflict', 'request_conflict', 'status_conflict'];
    const resetCodes = ['invalid_conversation', 'conversation_token_required', 'invalid_session', 'invalid_request_id'];
    const earlyWordPressCodes = ['rest_cookie_invalid_nonce', 'chat_private', 'rest_no_route'];
    const safeCodes = ['chat_busy', 'chat_visitor_busy', 'rate_limit', 'service_rate', 'service_busy',
      'service_lock_unavailable', 'service_counter_unavailable', 'invalid_message', 'invalid_request',
      'json_required', 'files_forbidden', 'request_too_large', 'access_denied', 'blocked', 'session_unavailable', 'not_configured', 'sync_storage', 'sync_config', 'sync_pending', 'sync_unmapped'];
    const handleRejection = (data, fromStatus) => {
      if (!pendingRequest || !data) return;
      if (conflictCodes.includes(data.code) || resetCodes.includes(data.code)) {
        pendingRequest.blocked = data.code;
        pendingRequest.retryable = false;
      } else if (!fromStatus && (earlyWordPressCodes.includes(data.code) ||
          (data.data && data.data.request_not_sent === true && safeCodes.includes(data.code)))) {
        pendingRequest.retryable = true;
        delete pendingRequest.blocked;
      } else return;
      persistPending();
      input.readOnly = !pendingRequest.retryable;
      status.textContent = publicError(data).publicNotice;
    };
    const waitingNotice = 'Заявка передана оператору. Пока он не примет диалог, ИИ продолжает отвечать. Заявка действует 10 минут.';
    const clearWaitingNotice = () => {
      waitingDeadline = 0;
      clearTimeout(waitingTimer);
      if (status.textContent === waitingNotice) status.textContent = '';
    };
    const expireWaitingNotice = () => {
      if (waitingDeadline && Date.now() >= waitingDeadline) clearWaitingNotice();
    };
    const pollOperator = async () => {
      expireWaitingNotice();
      if (document.hidden || operatorPolling || resetting || !conversationToken) return;
      operatorPolling = true;
      const pollController = new AbortController();
      const pollTimer = setTimeout(() => pollController.abort(), 15000);
      const pollingSession = sessionId;
      const pollingEpoch = operatorRequestEpoch;
      try {
        const endpoint = root.dataset.endpoint.replace(/\/chat\/?$/, '/operator-messages');
        const pollHeaders = { 'Content-Type': 'application/json' };
        if (root.dataset.nonce) pollHeaders['X-WP-Nonce'] = root.dataset.nonce;
        const response = await fetch(endpoint, { signal: pollController.signal, method: 'POST', credentials: 'same-origin', headers: pollHeaders, body: JSON.stringify({ session_id: sessionId, conversation_token: conversationToken, after_id: operatorLast }) });
        if (!response.ok) return;
        const data = await response.json();
        if (pollingSession !== sessionId || resetting) return;
        if (Array.isArray(data.messages)) data.messages.forEach((item) => {
          if (!item || typeof item.message !== 'string') return;
          const itemId = Number(item.id);
          if (!Number.isSafeInteger(itemId) || itemId <= operatorLast) return;
          operatorLast = itemId;
          const systemMessage = item.system === true || item.message === 'Оператор сейчас недоступен. Диалог продолжит ИИ-агент.';
          append(systemMessage ? 'Система' : 'Оператор', item.message, 'assistant');
          history.push({ role: 'assistant', content: item.message });
        });
        if (history.length > 20) history.splice(0, history.length - 20);
        if (data.messages && data.messages.length) {
          saveHistory();
          try { localStorage.setItem(operatorKey, String(operatorLast)); } catch (_) {}
        }
        if (modeStatus) modeStatus.textContent = data.waiting ? 'ИИ отвечает · ожидаем оператора' : (data.active ? 'Подключён оператор' : 'Отвечает ИИ-агент');
        if (pollingEpoch === operatorRequestEpoch && (data.active === true || data.waiting === false)) {
          clearWaitingNotice();
          if (status.textContent === 'К диалогу подключён оператор.') status.textContent = '';
        }
      } catch (_) {}
      finally { clearTimeout(pollTimer); operatorPolling = false; }
    };
    const operatorButton = root.querySelector('.ulgamdagal-request-operator');
    if (operatorButton) operatorButton.addEventListener('click', async () => {
      if (operatorButton.disabled || resetting) return;
      if (!conversationToken || pendingRequest) { status.textContent = 'Сначала отправьте вопрос и дождитесь ответа ИИ.'; return; }
      const panel = root.querySelector('.ulgamdagal-operator-name');
      const nameInput = root.querySelector('.ulgamdagal-visitor-name');
      if (!panel || !nameInput) { status.textContent = 'Обновите страницу сайта.'; return; }
      if (!operatorVisitorName && panel.hidden) {
        panel.hidden = false;
        operatorButton.textContent = 'Отправить заявку';
        nameInput.focus();
        return;
      }
      const visitorName = operatorVisitorName || nameInput.value.trim();
      if (!visitorName || visitorName.length > 60) { status.textContent = 'Введите имя до 60 символов.'; nameInput.focus(); return; }
      const requestedSession = sessionId;
      const requestedConversation = conversationToken;
      operatorRequestEpoch++;
      operatorButton.disabled = true;
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 15000);
      try {
        const headers = { 'Content-Type': 'application/json' };
        if (root.dataset.nonce) headers['X-WP-Nonce'] = root.dataset.nonce;
        const response = await fetch(root.dataset.endpoint.replace(/\/chat\/?$/, '/operator-request'), {
          method: 'POST', credentials: 'same-origin', headers, signal: controller.signal,
          body: JSON.stringify({session_id: sessionId, conversation_token: conversationToken, visitor_name: visitorName})
        });
        const data = await response.json();
        if (requestedSession !== sessionId || requestedConversation !== conversationToken) return;
        if (!response.ok) throw publicError(data);
        // Remember only an accepted request, within this signed conversation.
        operatorVisitorName = visitorName;
        saveHistory();
        panel.hidden = true;
        operatorButton.textContent = 'Позвать оператора';
        operatorRequestEpoch++;
        if (data.state === 'active') {
          clearWaitingNotice();
        } else {
          if (!waitingDeadline || Date.now() >= waitingDeadline) waitingDeadline = Date.now() + 10 * 60 * 1000;
          status.textContent = waitingNotice;
          clearTimeout(waitingTimer);
          waitingTimer = setTimeout(expireWaitingNotice, Math.max(0, waitingDeadline - Date.now()));
        }
        pollOperator();
      } catch (error) {
        if (requestedSession === sessionId) status.textContent = error.publicNotice || unavailableNotice;
      } finally { clearTimeout(timer); operatorButton.disabled = false; }
    });
    pollOperator();
    setInterval(pollOperator, 7000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) pollOperator(); });
    window.addEventListener('focus', pollOperator);
    let sending = false, checking = false;
    input.readOnly = !!pendingRequest && !pendingRequest.retryable;
    const acceptReply = (data, message) => {
        if (data.operator_mode !== true) {
          if (typeof data.reply !== 'string' || typeof data.conversation_token !== 'string' || !/^[0-9a-f-]{36}\.[0-9a-f]{64}$/.test(data.conversation_token)) throw new Error('Некорректный ответ сервера истории.');
          conversationToken = data.conversation_token;
        }
        append('Вы', message, 'user');
        history.push({ role: 'user', content: message });
        if (data.operator_mode === true) {
          if (modeStatus) modeStatus.textContent = 'Подключён оператор';
          status.textContent = 'Сообщение передано оператору.';
          setTimeout(() => {
            if (status.textContent === 'Сообщение передано оператору.') status.textContent = '';
          }, 2200);
        } else {
          if (typeof data.reply !== 'string') throw new Error('Некорректный ответ сервера.');
          append(root.dataset.assistantName || 'Ulgamdagal AI', data.reply, 'assistant');
          history.push({ role: 'assistant', content: data.reply });
          status.textContent = '';
        }
        if (history.length > 20) history.splice(0, history.length - 20);
        saveHistory();
        pendingRequest = null;
        persistPending();
        input.value = '';
        input.readOnly = false;

    };
    const checkPending = async () => {
      if (!pendingRequest || pendingRequest.retryable || pendingRequest.blocked || sending || checking || resetting || document.hidden) return;
      const pending = pendingRequest;
      checking = true; button.disabled = true;
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 15000);
      try {
        const headers = {'Content-Type':'application/json'};
        if (root.dataset.nonce) headers['X-WP-Nonce'] = root.dataset.nonce;
        const response = await fetch(root.dataset.endpoint.replace(new RegExp('/chat/?$'), '/request-status'), {
          method:'POST', credentials:'same-origin', signal:controller.signal, headers,
          body:JSON.stringify({...pending, website:form.elements.website.value})
        });
        const data = await response.json();
        if (!pendingRequest || pendingRequest.request_id !== pending.request_id) return;
        if (!response.ok) { handleRejection(data, true); status.textContent = publicError(data).publicNotice; return; }
        if (data.request_id !== pending.request_id) return;
        if (data.state === 'completed') {
          acceptReply(data.result, pending.message);
        } else if (data.state === 'recovered' || data.state === 'failed') {
          pendingRequest = null; persistPending(); input.readOnly = false;
          status.textContent = 'Не удалось получить ответ. Ожидание завершено, переписка сохранена. Можно отправить вопрос снова — это будет новый запрос.';
        } else if (data.state === 'pending') {
          status.textContent = 'Ответ задерживается. Проверяем состояние автоматически; повторно вопрос не отправляется.';
        }
      } catch (_) {
        // A network error is not proof of completion. Retain the exact pending ID.
      } finally {
        clearTimeout(timer); checking = false;
        if (!sending && !resetting) button.disabled = false;
      }
    };
    setInterval(checkPending, 15000);
    window.addEventListener('focus', checkPending);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) checkPending(); });
    checkPending();

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const message = input.value.trim();
      if (!message || button.disabled) return;
      if (pendingRequest && pendingRequest.blocked) { status.textContent = publicError({code: pendingRequest.blocked}).publicNotice; return; }
      if (pendingRequest && !pendingRequest.retryable) { await checkPending(); return; }
      sending = true;
      button.disabled = true;
      if (clear) clear.disabled = true;
      input.disabled = true;
      if (!pendingRequest || pendingRequest.message !== message || pendingRequest.session_id !== sessionId || pendingRequest.product_id !== (Number(root.dataset.productId) || 0)) {
        const bytes = new Uint8Array(16);
        self.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const hex = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
        const requestId = hex.slice(0,8)+'-'+hex.slice(8,12)+'-'+hex.slice(12,16)+'-'+hex.slice(16,20)+'-'+hex.slice(20);
        pendingRequest = { message, product_id: Number(root.dataset.productId) || 0,
          conversation_token: conversationToken, session_id: sessionId, request_id: requestId };
        persistPending();
      }
      pendingRequest.retryable = false;
      delete pendingRequest.blocked;
      persistPending();
      status.textContent = 'Ожидаем ответ…';
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 65000);
      try {
        const headers = { 'Content-Type': 'application/json' };
        if (root.dataset.nonce) headers['X-WP-Nonce'] = root.dataset.nonce;
        const response = await fetch(root.dataset.endpoint, {
          method: 'POST', credentials: 'same-origin', signal: controller.signal,
          headers,
          body: JSON.stringify({ ...pendingRequest, history, website: form.elements.website.value }),
        });
        const data = await response.json();
        if (!response.ok) {
          handleRejection(data, false);
          throw publicError(data);
        }
        acceptReply(data, message);
      } catch (error) {
        status.textContent = error.publicNotice || unavailableNotice;
      } finally {
        sending = false;
        clearTimeout(timer);
        button.disabled = false;
        if (clear) clear.disabled = false;
        input.disabled = false;
        input.readOnly = !!pendingRequest && !pendingRequest.retryable;
        input.focus();
        if (pendingRequest) checkPending();
      }
    });
  });
})();
