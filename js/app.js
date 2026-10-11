/* Общий фронтенд-модуль: window.App */
(function () {
  'use strict';

  function csrfToken() {
    var m = document.querySelector('meta[name="csrf"]');
    return m ? m.getAttribute('content') : '';
  }

  /* fetch -> JSON, с CSRF-заголовком. body: объект -> JSON, FormData -> как есть */
  async function api(url, opts) {
    opts = opts || {};
    var headers = { 'X-CSRF-Token': csrfToken() };
    var init = {
      method: opts.method || 'GET',
      headers: headers,
      credentials: 'same-origin'
    };
    if (opts.body !== undefined && opts.body !== null) {
      if (opts.body instanceof FormData) {
        init.body = opts.body;
      } else {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(opts.body);
      }
    }
    var resp;
    try {
      resp = await fetch(url, init);
    } catch (e) {
      throw new Error('Ошибка сети: ' + e.message);
    }
    var data = null;
    var text = await resp.text();
    try {
      data = text ? JSON.parse(text) : {};
    } catch (e) {
      throw new Error('Некорректный ответ сервера (' + resp.status + ')');
    }
    if (!resp.ok || (data && data.ok === false)) {
      throw new Error((data && data.error) ? data.error : ('Ошибка ' + resp.status));
    }
    return data;
  }

  function debounce(fn, ms) {
    var t = null;
    return function () {
      var self = this, args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(self, args); }, ms);
    };
  }

  var toastBox = null;
  function toast(message, type) {
    type = type || 'info';
    if (!toastBox) {
      toastBox = document.createElement('div');
      toastBox.className = 'fixed left-0 right-0 bottom-20 sm:bottom-6 z-[100] flex flex-col items-center gap-2 px-4 pointer-events-none';
      document.body.appendChild(toastBox);
    }
    var colors = {
      info: 'bg-[#1e293b] text-white',
      success: 'bg-gradient-to-br from-[#10b981] to-[#059669] text-white',
      error: 'bg-[#dc2626] text-white',
      warning: 'bg-amber-500 text-white'
    };
    var el = document.createElement('div');
    el.className = 'pointer-events-auto max-w-md w-full sm:w-auto px-4 py-3 rounded-2xl shadow-lg text-sm ' + (colors[type] || colors.info);
    el.textContent = message;
    toastBox.appendChild(el);
    setTimeout(function () {
      el.style.transition = 'opacity .3s';
      el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 350);
    }, 3500);
  }

  /* Короткий beep через WebAudio */
  var audioCtx = null;
  function beep(freq, ms) {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      var osc = audioCtx.createOscillator();
      var gain = audioCtx.createGain();
      osc.type = 'sine';
      osc.frequency.value = freq || 880;
      gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + (ms || 120) / 1000);
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.start();
      osc.stop(audioCtx.currentTime + (ms || 120) / 1000);
    } catch (e) { /* игнорируем */ }
  }

  function vibrate(ms) {
    if (navigator.vibrate) {
      try { navigator.vibrate(ms || 100); } catch (e) { /* игнорируем */ }
    }
  }

  function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  window.App = {
    api: api,
    debounce: debounce,
    toast: toast,
    beep: beep,
    vibrate: vibrate,
    esc: esc
  };

  /* Регистрация Service Worker (только по HTTPS / localhost) */
  if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function () { /* молча */ });
    });
  }
})();
