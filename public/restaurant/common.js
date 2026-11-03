/*
 * Общее для приложений ресторана (/admin, /waiter, /kitchen): запросы к /api/restaurant с токеном,
 * вход по PIN (роль определяет приложение), диалоги, уведомления, чек (экран и печать из браузера),
 * звук (WebAudio), service worker. Без библиотек и внешних файлов — работает в локальной сети без интернета.
 *
 * Демо-версия (demo/): если перед этим файлом подключён эмулятор сервера (window.RESTAURANT_DEMO),
 * запросы идут в него, а не на сервер, адреса приложений и ресурсов — относительные.
 */
'use strict';
(function () {
  const R = (window.R = {});
  R.app = document.body.dataset.app;
  const API = '/api/restaurant';
  const KEY = 'restoran:';
  R.me = null;
  R.demo = window.RESTAURANT_DEMO || null;
  R.urls = Object.assign({ admin: '/admin', waiter: '/waiter', kitchen: '/kitchen', kassa: '/kassa/' }, R.demo ? R.demo.urls : {});
  R.assets = R.demo ? R.demo.assets : '/restaurant/';

  // ---------------------------------------------------------------- хранилище устройства
  R.local = {
    get(key, fallback = null) {
      try { const v = localStorage.getItem(KEY + key); return v === null ? fallback : JSON.parse(v); } catch (e) { return fallback; }
    },
    set(key, value) { try { localStorage.setItem(KEY + key, JSON.stringify(value)); } catch (e) { /* хранилище недоступно */ } },
    del(key) { try { localStorage.removeItem(KEY + key); } catch (e) { /* */ } },
  };
  const tokenKey = (app) => (app || R.app) + ':token';
  R.token = () => R.local.get(tokenKey());

  // ---------------------------------------------------------------- HTML с экранированием
  class Raw { constructor(s) { this.s = s; } toString() { return this.s; } }
  R.raw = (s) => new Raw(String(s));
  R.esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const val = (v) => (v instanceof Raw ? v.s : Array.isArray(v) ? v.map(val).join('') : v === null || v === undefined || v === false ? '' : R.esc(v));
  R.html = (strings, ...values) => new Raw(strings.reduce((out, s, i) => out + s + (i < values.length ? val(values[i]) : ''), ''));
  R.$ = (sel, root = document) => root.querySelector(sel);
  R.$$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  R.set = (el, content) => { if (el) el.innerHTML = val(content); };

  // ---------------------------------------------------------------- форматы
  R.money = (n) => Math.round(Number(n) || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  R.sum = (n) => R.money(n) + ' сум';
  const pad = (n) => String(n).padStart(2, '0');
  // Время приходит с сервера в часовом поясе ресторана (ISO со смещением) — показываем его как есть,
  // независимо от настроек часового пояса устройства.
  R.tz = null;
  R.time = (iso) => (iso ? String(iso).slice(11, 16) : '');
  R.date = (iso) => (iso ? String(iso).slice(8, 10) + '.' + String(iso).slice(5, 7) + '.' + String(iso).slice(0, 4) : '');
  R.dateTime = (iso) => (iso ? R.date(iso) + ' ' + R.time(iso) : '');
  R.ymd = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  const zoned = (opts, locale) => { try { return new Intl.DateTimeFormat(locale, Object.assign({ timeZone: R.tz || undefined }, opts)); } catch (e) { return new Intl.DateTimeFormat(locale, opts); } };
  // «сегодня» ресторана (YYYY-MM-DD), со сдвигом в днях
  R.today = (shiftDays = 0) => zoned({ year: 'numeric', month: '2-digit', day: '2-digit' }, 'en-CA').format(new Date(Date.now() + R.skew + shiftDays * 864e5));
  R.clock = () => zoned({ hour: '2-digit', minute: '2-digit', hour12: false }, 'ru-RU').format(new Date(Date.now() + R.skew));
  R.initials = (name) => String(name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
  R.firstName = (name) => String(name || '').trim().split(/\s+/)[0] || '';
  R.plural = (n, one, few, many) => {
    const a = Math.abs(n) % 100; const b = a % 10;
    return a > 10 && a < 20 ? many : b > 1 && b < 5 ? few : b === 1 ? one : many;
  };
  R.uuid = () => (window.crypto && crypto.randomUUID ? crypto.randomUUID()
    : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = (Math.random() * 16) | 0; return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16); }));
  // разница часов устройства и сервера — таймеры кухни считаются по серверному времени
  R.skew = 0;
  R.syncClock = (serverIso) => { if (serverIso) R.skew = new Date(serverIso).getTime() - Date.now(); };
  R.now = () => Date.now() + R.skew;
  R.elapsed = (iso) => (iso ? Math.max(0, Math.floor((R.now() - new Date(iso).getTime()) / 1000)) : 0);
  R.mmss = (sec) => { const m = Math.floor(sec / 60); return m >= 60 ? Math.floor(m / 60) + ':' + pad(m % 60) + ':' + pad(sec % 60) : m + ':' + pad(sec % 60); };
  R.ago = (iso) => { const m = Math.floor(R.elapsed(iso) / 60); return m < 1 ? 'только что' : m < 60 ? m + ' мин' : Math.floor(m / 60) + ' ч ' + (m % 60) + ' мин'; };
  R.STATUS = { new: 'Новый', cooking: 'Готовится', ready: 'Готово', served: 'Подано', paid: 'Оплачен', cancelled: 'Отменён' };
  R.ROLE = { owner: 'Владелец', admin: 'Администратор', waiter: 'Официант', cook: 'Повар', seller: 'Продавец' };

  // ---------------------------------------------------------------- API
  class ApiError extends Error {
    constructor(message, status, data) { super(message); this.status = status; this.data = data; }
  }
  R.ApiError = ApiError;
  R.online = true;
  R.onNetwork = null;
  const setOnline = (ok) => { if (R.online !== ok) { R.online = ok; if (R.onNetwork) R.onNetwork(ok); } };

  // запрос к серверу (или к эмулятору демо-версии) → { status, data }
  async function send(method, url, body, token, timeout) {
    if (R.demo) return R.demo.request(method, url, body, token);
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), timeout || 15000);
    const headers = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (token) headers.Authorization = 'Bearer ' + token;
    try {
      const res = await fetch(url, { method, headers, body: body !== undefined ? JSON.stringify(body) : undefined, signal: ctrl.signal, cache: 'no-store' });
      let data = null;
      try { data = await res.json(); } catch (e) { /* пустой ответ */ }
      return { status: res.status, data };
    } finally {
      clearTimeout(timer);
    }
  }

  R.api = async (method, path, body, opts = {}) => {
    const token = opts.token !== undefined ? opts.token : R.token();
    let status;
    let data;
    try {
      ({ status, data } = await send(method, (opts.base || API) + path, body, token, opts.timeout));
    } catch (e) {
      setOnline(false);
      throw new ApiError('Нет связи с сервером ресторана — проверьте Wi-Fi', 0);
    }
    setOnline(true);
    if (status === 401 && !opts.public) {
      R.local.del(tokenKey());
      R.showLogin('Войдите заново');
      throw new ApiError((data && data.message) || 'Войдите заново', 401, data);
    }
    if (status >= 400) {
      const first = data && data.errors ? Object.values(data.errors).flat()[0] : null;
      throw new ApiError(first || (data && data.message) || 'Ошибка сервера (' + status + ')', status, data);
    }
    return data;
  };
  // демо: данные общие для вкладок — изменение в соседней вкладке сразу обновляет экран (R.live задаёт приложение)
  R.live = null;
  if (R.demo) {
    window.addEventListener('storage', (ev) => { if (ev.key === R.demo.key && R.live && R.me) R.live(); });
  }

  // ---------------------------------------------------------------- уведомления и диалоги
  let toastTimer = null;
  R.toast = (message, kind = '') => {
    const el = R.$('#toast');
    if (!el) return;
    el.textContent = message;
    el.className = 'toast show' + (kind ? ' ' + kind : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.className = 'toast'; }, kind === 'bad' ? 5200 : 2800);
  };
  R.fail = (e) => { if (e && e.status !== 401) R.toast((e && e.message) || 'Ошибка', 'bad'); };

  let modalClose = null;
  R.modal = (content, opts = {}) => {
    const layer = R.$('#modal');
    const box = R.$('#modal-box');
    box.className = 'modal' + (opts.wide ? ' wide' : '');
    R.set(box, R.html`<button class="modal-x" data-act="modalClose" aria-label="Закрыть">×</button>${content}`);
    layer.hidden = false;
    modalClose = opts.onClose || null;
    const focus = box.querySelector('[autofocus]') || box.querySelector('input, select, textarea');
    if (focus && opts.focus !== false) setTimeout(() => focus.focus(), 30);
    return box;
  };
  R.closeModal = () => {
    const layer = R.$('#modal');
    if (!layer || layer.hidden) return;
    layer.hidden = true;
    R.set(R.$('#modal-box'), '');
    const cb = modalClose;
    modalClose = null;
    if (cb) cb();
  };
  R.confirm = (title, text, okLabel = 'Да', danger = false) => new Promise((resolve) => {
    let answered = false;
    const box = R.modal(R.html`<h2>${title}</h2>${text ? R.html`<p class="sub">${text}</p>` : ''}
      <div class="form-actions"><button class="btn" data-confirm="0">Отмена</button><button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-confirm="1" autofocus>${okLabel}</button></div>`,
    { onClose: () => { if (!answered) resolve(false); } });
    box.addEventListener('click', (ev) => {
      const b = ev.target.closest('[data-confirm]');
      if (!b) return;
      answered = true;
      R.closeModal();
      resolve(b.dataset.confirm === '1');
    });
  });
  // кнопка «занята», пока идёт запрос
  R.busy = async (btn, fn) => {
    if (btn && btn.disabled) return undefined;
    if (btn) { btn.disabled = true; btn.classList.add('busy'); }
    try { return await fn(); } finally { if (btn) { btn.disabled = false; btn.classList.remove('busy'); } }
  };
  R.formData = (form) => {
    const out = {};
    new FormData(form).forEach((v, k) => { out[k] = typeof v === 'string' ? v.trim() : v; });
    R.$$('input[type=checkbox]', form).forEach((c) => { if (c.name) out[c.name] = c.checked; });
    return out;
  };

  // делегирование действий: data-act="имя" → R.actions[имя](элемент, событие)
  R.actions = {
    modalClose: () => R.closeModal(),
  };
  document.addEventListener('click', (ev) => {
    const el = ev.target.closest('[data-act]');
    if (!el || el.disabled) return;
    const fn = R.actions[el.dataset.act];
    if (fn) { ev.preventDefault(); fn(el, ev); }
  });
  document.addEventListener('submit', (ev) => {
    const form = ev.target.closest('form[data-submit]');
    if (!form) return;
    ev.preventDefault();
    const fn = R.actions[form.dataset.submit];
    if (fn) fn(form, ev);
  });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') R.closeModal(); });

  // ---------------------------------------------------------------- чек: тот же документ, что печатает ESC/POS
  R.receiptHtml = (doc) => {
    const width = (doc && doc.width) || 48;
    const lines = (doc && doc.lines) || [];
    const out = lines.map((l) => {
      if (l.t === 'rule') return R.html`<div class="rl rule">${(l.char || '-').repeat(width)}</div>`;
      if (l.t === 'feed') return R.html`<div class="feed"></div>`;
      const cls = ['rl', l.size === 'l' ? 'l' : l.size === 't' ? 't' : '', l.bold ? 'b' : '', l.invert ? 'inv' : '',
        l.align === 'center' ? 'c' : l.align === 'right' ? 'r' : ''].filter(Boolean).join(' ');
      if (l.t === 'row') return R.html`<div class="${cls} rrow"><span>${l.left}</span><span>${l.right}</span></div>`;
      return R.html`<div class="${cls}">${l.text}</div>`;
    });
    return R.html`<div class="receipt">${out}</div>`;
  };
  // печать из браузера: чек в скрытом фрейме + window.print() (в Chrome с --kiosk-printing — без диалога)
  R.printDoc = (doc) => new Promise((resolve) => {
    const frame = R.$('#print-frame');
    const css = `@page{size:80mm auto;margin:2mm}body{margin:0;background:#fff}
      .receipt{font-family:ui-monospace,Menlo,Consolas,"Liberation Mono",monospace;font-size:12px;line-height:1.3;color:#000;width:76mm}
      .rl{white-space:pre-wrap;word-break:break-word}.c{text-align:center}.r{text-align:right}.b{font-weight:800}
      .t{font-size:1.45em}.l{font-size:1.9em;line-height:1.1}.inv{background:#000;color:#fff;padding:0 3px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
      .rrow{display:flex;justify-content:space-between;gap:8px}.rrow span:last-child{white-space:nowrap}.rule{overflow:hidden;white-space:nowrap}.feed{height:1em}`;
    frame.srcdoc = '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Чек</title><style>' + css + '</style></head><body>' + R.receiptHtml(doc) + '</body></html>';
    frame.onload = () => {
      try {
        frame.contentWindow.focus();
        frame.contentWindow.print();
      } catch (e) { R.toast('Браузер не дал напечатать чек', 'bad'); }
      setTimeout(resolve, 500);
    };
  });

  // ---------------------------------------------------------------- звук (WebAudio, без файлов)
  let audio = null;
  R.unlockAudio = () => {
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      if (audio.state === 'suspended') audio.resume();
      return true;
    } catch (e) { return false; }
  };
  R.audioReady = () => !!audio && audio.state === 'running';
  R.beep = (kind = 'new') => {
    if (!audio) return;
    const notes = kind === 'new' ? [[880, 0], [1175, 0.16], [1568, 0.32]] : kind === 'ready' ? [[1046, 0], [1318, 0.14]] : [[440, 0], [330, 0.2]];
    const t0 = audio.currentTime + 0.02;
    notes.forEach(([freq, at]) => {
      const osc = audio.createOscillator();
      const gain = audio.createGain();
      osc.type = 'sine';
      osc.frequency.value = freq;
      gain.gain.setValueAtTime(0.0001, t0 + at);
      gain.gain.exponentialRampToValueAtTime(0.45, t0 + at + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, t0 + at + 0.3);
      osc.connect(gain).connect(audio.destination);
      osc.start(t0 + at);
      osc.stop(t0 + at + 0.32);
    });
  };

  // экран не гаснет (кухня, официант) — только в защищённом контексте
  let wake = null;
  R.keepAwake = async () => {
    try { if ('wakeLock' in navigator && document.visibilityState === 'visible') wake = await navigator.wakeLock.request('screen'); } catch (e) { /* не поддерживается */ }
  };
  document.addEventListener('visibilitychange', () => { if (wake !== null && document.visibilityState === 'visible') R.keepAwake(); });

  // ---------------------------------------------------------------- PWA
  R.registerSW = () => {
    if (!R.demo && 'serviceWorker' in navigator && window.isSecureContext) {
      navigator.serviceWorker.register('/' + R.app + '/sw.js', { scope: '/' + R.app }).catch(() => { /* без офлайн-оболочки */ });
    }
  };

  // ---------------------------------------------------------------- вход по PIN
  const L = { options: null, pick: null, pin: '', error: '', storeId: R.local.get('store'), onReady: null, setup: false };
  const APP_TITLE = { admin: 'Админка ресторана', waiter: 'Официант', kitchen: 'Кухня' };

  R.showLogin = (message) => {
    R.me = null;
    const shell = R.$('#shell');
    if (shell) shell.hidden = true;
    R.closeModal();
    L.pin = '';
    L.pick = null;
    L.error = message || '';
    R.$('#login').hidden = false;
    loadOptions();
  };

  async function loadOptions() {
    try {
      L.options = await R.api('GET', '/options?app=' + R.app + (L.storeId ? '&store_id=' + L.storeId : ''), undefined, { public: true, token: null });
      L.storeId = L.options.store.id;
      R.local.set('store', L.storeId);
    } catch (e) {
      L.options = null;
      L.error = e.message;
    }
    renderLogin();
  }

  function renderLogin() {
    const o = L.options;
    const box = R.$('#login');
    if (!o) {
      R.set(box, R.html`<div class="login-box card"><div class="login-head"><h1>${APP_TITLE[R.app]}</h1><p class="sub">${L.error || 'Загрузка…'}</p></div>
        <div class="form-actions" style="justify-content:center"><button class="btn btn-primary" data-act="loginRetry">Повторить</button></div></div>`);
      return;
    }
    if (o.needs_setup) { renderSetup(box, o); return; }
    const staff = o.employees || [];
    const picked = staff.find((e) => e.id === L.pick);
    R.set(box, R.html`<div class="login-box">
      <div class="login-head"><img src="${R.assets}icons/${R.app}.svg" alt=""><h1>${APP_TITLE[R.app]}</h1>
        <p class="sub">${o.store.name}${o.mode === 'online' ? ' · онлайн-сервер' : ''}</p></div>
      ${o.stores.length > 1 ? R.html`<div class="field" style="max-width:360px;margin:0 auto 14px"><label for="login-store">Ресторан</label>
        <select class="select" id="login-store" data-change="loginStore">${o.stores.map((s) => R.html`<option value="${s.id}"${R.raw(s.id === o.store.id ? ' selected' : '')}>${s.name}</option>`)}</select></div>` : ''}
      ${staff.length ? R.html`<div class="staff-grid">${staff.map((e) => R.html`<button class="staff-tile${e.id === L.pick ? ' on' : ''}" data-act="loginPick" data-id="${e.id}">
          <span class="avatar ${e.role}">${R.initials(e.name)}</span><span>${e.name}</span><span class="role">${e.role_name}</span></button>`)}</div>`
        : R.html`<div class="card empty">В этом ресторане нет сотрудников с доступом к этому приложению.<br>Администратор добавляет их в админке: /admin → Сотрудники.</div>`}
      ${picked ? R.html`<div class="pin-box card"><div><b>${picked.name}</b> — введите PIN</div>
        <div class="pin-dots">${[0, 1, 2, 3, 4, 5].slice(0, Math.max(4, L.pin.length)).map((i) => R.html`<i class="${i < L.pin.length ? 'on' : ''}"></i>`)}</div>
        <div class="pin-pad">${['1', '2', '3', '4', '5', '6', '7', '8', '9', 'C', '0', '⌫'].map((d) => R.html`<button data-act="pinKey" data-key="${d}" aria-label="${d === '⌫' ? 'Стереть' : d === 'C' ? 'Очистить' : d}">${d}</button>`)}</div>
        <button class="btn btn-primary btn-block btn-lg" style="margin-top:12px" data-act="pinSubmit"${R.raw(L.pin.length < 4 ? ' disabled' : '')}>Войти</button>
        <div class="pin-error">${L.error}</div></div>` : (L.error ? R.html`<div class="pin-error" style="text-align:center">${L.error}</div>` : '')}
      ${o.demo_hint ? R.html`<div class="demo-hint">${o.demo_hint}</div>` : ''}
      <div class="login-foot">Приложения ресторана: <a href="${R.urls.admin}">админка</a> · <a href="${R.urls.waiter}">официант</a> · <a href="${R.urls.kitchen}">кухня</a>${R.demo ? R.html` · <a href="${R.demo.urls.launcher}" target="_top">лаунчер демо-версии</a>` : ''}</div>
    </div>`);
  }

  function renderSetup(box, o) {
    if (R.app !== 'admin') {
      R.set(box, R.html`<div class="login-box card"><div class="login-head"><h1>${APP_TITLE[R.app]}</h1>
        <p class="sub">Ресторан ещё не настроен. Откройте админку и создайте ресторан и администратора.</p></div>
        <div class="form-actions" style="justify-content:center"><a class="btn btn-primary" href="${R.urls.admin}">Открыть админку</a></div></div>`);
      return;
    }
    R.set(box, R.html`<div class="login-box card" style="max-width:520px">
      <div class="login-head"><img src="${R.assets}icons/admin.svg" alt=""><h1>Первая настройка</h1>
        <p class="sub">Создайте ресторан и владельца. Владелец входит по PIN и добавляет администраторов, официантов и поваров.</p></div>
      ${o.setup_ready ? R.html`<form data-submit="setup">
        ${o.setup_code_required ? R.html`<div class="field"><label for="su-code">Код установки</label><input class="input" id="su-code" name="setup_code" required autocomplete="off">
          <span class="hint">Задан в KASSA_SETUP_CODE в файле .env на сервере</span></div>` : ''}
        <div class="field"><label for="su-name">Название ресторана</label><input class="input" id="su-name" name="store_name" required maxlength="60" placeholder="Ресторан «Навруз»"></div>
        <div class="field"><label for="su-owner">Ваше имя</label><input class="input" id="su-owner" name="owner_name" required maxlength="60"></div>
        <div class="field"><label for="su-pin">PIN (4–6 цифр)</label><input class="input" id="su-pin" name="pin" required inputmode="numeric" pattern="\\d{4,6}" maxlength="6" autocomplete="off">
          <span class="hint">Простые PIN (1111, 1234, 2580…) не принимаются</span></div>
        <button class="btn btn-primary btn-block btn-lg">Создать ресторан</button>
        <div class="pin-error">${L.error}</div></form>`
        : R.html`<p class="pin-error">Мастер установки закрыт: задайте KASSA_SETUP_CODE в файле .env на сервере.</p>`}
    </div>`);
  }

  async function finishLogin(res) {
    if (res.redirect && res.app !== R.app) {
      // роль сотрудника открывает другой интерфейс — передаём ему вход и переходим
      if (res.app === 'kassa') {
        try { localStorage.setItem('kassa-sklad:token', res.token); } catch (e) { /* войдёт в кассе заново */ }
      } else {
        R.local.set(tokenKey(res.app), res.token);
      }
      location.href = res.home;
      return;
    }
    R.local.set(tokenKey(), res.token);
    R.local.set('store', res.store.id);
    await R.boot();
  }

  Object.assign(R.actions, {
    loginRetry: () => loadOptions(),
    loginPick(el) { L.pick = Number(el.dataset.id); L.pin = ''; L.error = ''; renderLogin(); },
    pinKey(el) {
      const k = el.dataset.key;
      if (k === 'C') L.pin = '';
      else if (k === '⌫') L.pin = L.pin.slice(0, -1);
      else if (L.pin.length < 6) L.pin += k;
      L.error = '';
      renderLogin();
    },
    async pinSubmit(el) {
      await R.busy(el, async () => {
        try {
          const res = await R.api('POST', '/login', { employee_id: L.pick, pin: L.pin, app: R.app, store_id: L.storeId }, { public: true, token: null });
          L.pin = '';
          await finishLogin(res);
        } catch (e) {
          L.pin = '';
          L.error = e.message;
          renderLogin();
        }
      });
    },
    async setup(form) {
      const data = R.formData(form);
      const btn = R.$('button', form);
      await R.busy(btn, async () => {
        try {
          const res = await R.api('POST', '/setup', data, { base: '/api/kassa', public: true, token: null });
          R.local.set(tokenKey(), res.token);
          R.local.set('store', res.store.id);
          await R.boot();
        } catch (e) { L.error = e.message; renderLogin(); }
      });
    },
    async logout() {
      try { await R.api('POST', '/logout'); } catch (e) { /* выходим в любом случае */ }
      R.local.del(tokenKey());
      R.showLogin();
    },
  });
  document.addEventListener('change', (ev) => {
    const el = ev.target.closest('[data-change]');
    if (!el) return;
    if (el.dataset.change === 'loginStore') { L.storeId = Number(el.value); L.pick = null; loadOptions(); return; }
    const fn = R.actions[el.dataset.change];
    if (fn) fn(el, ev);
  });
  // PIN с физической клавиатуры
  document.addEventListener('keydown', (ev) => {
    if (R.$('#login').hidden || !L.pick || ev.target.closest('input, select, textarea')) return;
    if (/^\d$/.test(ev.key) && L.pin.length < 6) { L.pin += ev.key; L.error = ''; renderLogin(); }
    else if (ev.key === 'Backspace') { L.pin = L.pin.slice(0, -1); renderLogin(); }
    else if (ev.key === 'Enter' && L.pin.length >= 4) { const b = R.$('[data-act=pinSubmit]'); if (b) b.click(); }
  });

  // ---------------------------------------------------------------- запуск приложения
  R.start = (onReady) => {
    L.onReady = onReady;
    R.registerSW();
    R.boot();
  };
  R.boot = async () => {
    if (!R.token()) { R.showLogin(); return; }
    try {
      R.me = await R.api('GET', '/me');
      R.tz = R.me.timezone || null;
    } catch (e) {
      if (e.status === 401) return;
      R.$('#login').hidden = false;
      R.set(R.$('#login'), R.html`<div class="login-box card"><div class="login-head"><h1>Нет связи</h1><p class="sub">${e.message}</p></div>
        <div class="form-actions" style="justify-content:center"><button class="btn btn-primary" data-act="reboot">Повторить</button></div></div>`);
      return;
    }
    if (!R.me.apps.some((a) => a.app === R.app)) {
      // приложение не для этой роли — открываем «своё»
      const home = R.me.apps[0];
      if (home) { R.local.set(tokenKey(home.app), R.token()); location.href = home.url; return; }
    }
    R.$('#login').hidden = true;
    R.$('#shell').hidden = false;
    if (L.onReady) L.onReady(R.me);
  };
  R.actions.reboot = () => R.boot();
})();
