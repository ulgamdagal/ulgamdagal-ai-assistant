(() => {
  'use strict';
  const body = document.getElementById('ulgamdagal-log-body');
  const status = document.getElementById('ulgamdagal-log-refresh-status');
  const soundButton = document.getElementById('ulgamdagal-sound-toggle');
  if (!body || !status) return;

  const soundKey = 'ulgamdagal_ai_log_sound';
  let running = false;
  let signature = body.innerHTML;
  let soundEnabled = false;
  try { soundEnabled = localStorage.getItem(soundKey) === '1'; } catch (_) {}

  const updateButton = () => {
    if (!soundButton) return;
    soundButton.textContent = soundEnabled ? 'Звук включён' : 'Включить звук';
    soundButton.setAttribute('aria-pressed', soundEnabled ? 'true' : 'false');
  };

  const beep = () => {
    if (!soundEnabled) return;
    try {
      const AudioContextClass = window.AudioContext || window.webkitAudioContext;
      if (!AudioContextClass) return;
      const context = new AudioContextClass();
      const oscillator = context.createOscillator();
      const gain = context.createGain();
      oscillator.frequency.value = 740;
      gain.gain.setValueAtTime(0.0001, context.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.15, context.currentTime + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.28);
      oscillator.connect(gain);
      gain.connect(context.destination);
      oscillator.start();
      oscillator.stop(context.currentTime + 0.3);
      oscillator.addEventListener('ended', () => context.close());
    } catch (_) {}
  };

  if (soundButton) {
    updateButton();
    soundButton.addEventListener('click', () => {
      soundEnabled = !soundEnabled;
      try { localStorage.setItem(soundKey, soundEnabled ? '1' : '0'); } catch (_) {}
      updateButton();
      if (soundEnabled) beep();
    });
  }

  const refresh = async () => {
    if (running || document.hidden) return;
    running = true;
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('_ulgamdagal_refresh', String(Date.now()));
      const response = await fetch(url.href, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const html = await response.text();
      const documentCopy = new DOMParser().parseFromString(html, 'text/html');
      const freshBody = documentCopy.getElementById('ulgamdagal-log-body');
      if (!freshBody) throw new Error('table');
      const freshSignature = freshBody.innerHTML;
      const changed = freshSignature !== signature;
      body.replaceChildren(...Array.from(freshBody.children).map((row) => row.cloneNode(true)));
      signature = freshSignature;
      if (changed) beep();
      status.textContent = 'Обновлено: ' + new Date().toLocaleTimeString();
    } catch (_) {
      status.textContent = 'Не удалось обновить журнал. Следующая попытка через 7 секунд.';
    } finally {
      running = false;
    }
  };

  window.setInterval(refresh, 7000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
