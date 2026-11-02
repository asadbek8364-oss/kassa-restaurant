/*
 * «Касса и склад» — касса и складской учёт для магазина аксессуаров для телефонов.
 * Данные и все расчёты — на сервере (Laravel, /api/kassa): остатки, суммы чеков, сдача,
 * возвраты, отчёты и журнал. Сотрудник входит по PIN. На устройстве хранятся только
 * черновики: корзина, незаконченный приход и пересчёт инвентаризации.
 */
'use strict';
(function () {
  const API = String(window.KASSA_API || location.origin + '/api/kassa').replace(/\/+$/, '');
  const TOKEN_KEY = 'kassa-sklad:token';
  const LOCAL_KEY = 'kassa-sklad:drafts:v2';
  const DAY = 864e5;
  const ZXING_URL = 'https://cdn.jsdelivr.net/npm/@zxing/library@0.21.3/umd/index.min.js';

  const CATS = [
    { id: 'chargers', name: 'Зарядки', icon: 'bolt' },
    { id: 'cables', name: 'Кабели', icon: 'plug' },
    { id: 'cases', name: 'Чехлы', icon: 'phone' },
    { id: 'screen', name: 'Защита экрана', icon: 'shield' },
    { id: 'audio', name: 'Аудио', icon: 'headphones' },
    { id: 'accessories', name: 'Аксессуары', icon: 'star' },
  ];
  const CAT = Object.fromEntries(CATS.map((c) => [c.id, c]));
  const catOf = (p) => CAT[p.category] || CAT.accessories;
  const PAY = {
    cash: { name: 'Наличные', icon: 'cash' },
    card: { name: 'Карта', icon: 'card' },
    qr: { name: 'QR', icon: 'qr' },
  };
  const ROLES = { owner: 'Владелец', seller: 'Продавец' };
  const REASONS = ['Брак', 'Не подошёл', 'Передумал', 'Другое'];
  const PERIODS = { today: 'Сегодня', week: 'Неделя', month: 'Месяц', all: 'Всё время' };
  const SORTS = [
    ['name:1', 'Название: А–Я'],
    ['name:-1', 'Название: Я–А'],
    ['price:1', 'Цена: сначала дешевле'],
    ['price:-1', 'Цена: сначала дороже'],
    ['stock:1', 'Остаток: сначала меньше'],
    ['stock:-1', 'Остаток: сначала больше'],
  ];
  const TYPE_ICON = { sale: 'cart', ret: 'refund', inc: 'income', aud: 'audit' };
  const TABS = [
    ['home', 'Главная', 'home'],
    ['sale', 'Продажа', 'cart'],
    ['products', 'Товары', 'box'],
    ['reports', 'Отчёты', 'chart'],
    ['more', 'Ещё', 'grid'],
  ];
  const QUICK = [
    ['sale', 'Продажа', 'cart'],
    ['income', 'Приход', 'income'],
    ['scanner', 'Сканер', 'scan'],
    ['products', 'Товары', 'box'],
    ['audit', 'Инвентари­зация', 'audit'],
    ['reports', 'Отчёты', 'chart'],
    ['refund', 'Возврат', 'refund'],
    ['journal', 'Журнал', 'journal'],
    ['staff', 'Сотрудники', 'users'],
  ];
  const MORE = [
    ['income', 'Приход', 'income'],
    ['refund', 'Возврат', 'refund'],
    ['audit', 'Инвентаризация', 'audit'],
    ['journal', 'Журнал', 'journal'],
    ['staff', 'Сотрудники', 'users'],
    ['scanner', 'Сканер', 'scan'],
  ];

  // ---------------------------------------------------------------- Иконки
  const ICONS = {
    home: '<path d="M5 12H3l9-9 9 9h-2"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/><path d="M9 21v-6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v6"/>',
    cart: '<circle cx="6" cy="19" r="2"/><circle cx="17" cy="19" r="2"/><path d="M17 17H6V3H4"/><path d="m6 5 14 1-1 7H6"/>',
    box: '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3"/><path d="m12 12 8-4.5M12 12v9M12 12 4 7.5"/>',
    chart: '<path d="M3 13a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1zM15 9a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1zM9 5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1z"/>',
    grid: '<rect x="4" y="4" width="6" height="6" rx="1.5"/><rect x="14" y="4" width="6" height="6" rx="1.5"/><rect x="4" y="14" width="6" height="6" rx="1.5"/><rect x="14" y="14" width="6" height="6" rx="1.5"/>',
    scan: '<path d="M4 7V6a2 2 0 0 1 2-2h2M4 17v1a2 2 0 0 0 2 2h2M16 4h2a2 2 0 0 1 2 2v1M16 20h2a2 2 0 0 0 2-2v-1"/><path d="M7 9v6M10 9v6M13 9v6M17 9v6"/>',
    income: '<path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/><path d="m7 11 5 5 5-5M12 4v12"/>',
    refund: '<path d="m9 14-4-4 4-4"/><path d="M5 10h11a4 4 0 1 1 0 8h-1"/>',
    audit: '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/><path d="m9 14 2 2 4-4"/>',
    journal: '<path d="M12 8v4l2 2"/><path d="M3.05 11a9 9 0 1 1 .5 4M3 20v-5h5"/>',
    users: '<circle cx="9" cy="7" r="4"/><path d="M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2M16 3.13a4 4 0 0 1 0 7.75M21 21v-2a4 4 0 0 0-3-3.85"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    minus: '<path d="M5 12h14"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2l1-12M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/>',
    search: '<circle cx="10" cy="10" r="7"/><path d="m21 21-6-6"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    camera: '<path d="M5 7h1a2 2 0 0 0 2-2 1 1 0 0 1 1-1h6a1 1 0 0 1 1 1 2 2 0 0 0 2 2h1a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2"/><circle cx="12" cy="13" r="3"/>',
    cash: '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6.5 9.5h.01M17.5 14.5h.01"/>',
    card: '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18M7 15h.01M11 15h2"/>',
    qr: '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><path d="M14 14h2v2h-2zM18 18h2v2h-2zM14 20v-2M20 14v2"/>',
    alert: '<path d="M12 9v4M12 16h.01"/><path d="M10.36 3.59 2.26 17.13A1.91 1.91 0 0 0 3.9 20h16.2a1.91 1.91 0 0 0 1.64-2.87L13.64 3.59a1.91 1.91 0 0 0-3.28 0z"/>',
    right: '<path d="m9 6 6 6-6 6"/>',
    left: '<path d="m15 6-6 6 6 6"/>',
    receipt: '<path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16l-3-2-2 2-2-2-2 2-2-2-3 2"/><path d="M9 7h6M9 11h6M13 15h2"/>',
    edit: '<path d="M4 20h4L18.5 9.5a2.83 2.83 0 1 0-4-4L4 16v4"/><path d="m13.5 6.5 4 4"/>',
    check: '<path d="m5 12 5 5L20 7"/>',
    swap: '<path d="M7 10h14l-4-4M17 14H3l4 4"/>',
    image: '<path d="M15 8h.01"/><rect x="3" y="3" width="18" height="18" rx="3"/><path d="m3 16 5-5c.93-.9 2.07-.9 3 0l5 5"/><path d="m14 14 1-1c.93-.9 2.07-.9 3 0l3 3"/>',
    db: '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6a8 3 0 0 0 16 0V6M4 12v6a8 3 0 0 0 16 0v-6"/>',
    store: '<path d="M3 21h18M5 21V7l1-4h12l1 4v14"/><path d="M3 7h18M9 21v-6h6v6"/>',
    logout: '<path d="M14 8V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2-2v-2"/><path d="M9 12h12l-3-3M18 15l3-3"/>',
    backspace: '<path d="M20 6a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H9l-5-6 5-6z"/><path d="m12 10 4 4m0-4-4 4"/>',
    bolt: '<path d="M13 3v7h6l-8 11v-7H5l8-11"/>',
    plug: '<path d="M9.78 6 18 14.22l-2.05 2.05a5.81 5.81 0 1 1-8.22-8.22L9.78 6zM4 20l3.5-3.5M15 4l-3.5 3.5M20 9l-3.5 3.5"/>',
    phone: '<rect x="6" y="3" width="12" height="18" rx="2"/><path d="M11 4h2M12 17v.01"/>',
    shield: '<path d="M12 3a12 12 0 0 0 8.5 3A12 12 0 0 1 12 21 12 12 0 0 1 3.5 6 12 12 0 0 0 12 3"/>',
    headphones: '<path d="M4 15a2 2 0 0 1 2-2h1a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2zM15 15a2 2 0 0 1 2-2h1a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2z"/><path d="M4 15v-3a8 8 0 0 1 16 0v3"/>',
    star: '<path d="m12 17.75-6.17 3.25 1.18-6.88-5-4.87 6.9-1L12 2l3.09 6.25 6.9 1-5 4.87 1.18 6.88z"/>',
  };

  // ---------------------------------------------------------------- HTML-шаблоны с экранированием
  const RAW = Symbol('raw');
  const raw = (s) => ({ [RAW]: String(s) });
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  function out(v) {
    if (v == null || v === false || v === true) return '';
    if (Array.isArray(v)) return v.map(out).join('');
    if (typeof v === 'object' && RAW in v) return v[RAW];
    return esc(v);
  }
  function html(strs, ...vals) {
    let s = strs[0];
    for (let i = 0; i < vals.length; i++) s += out(vals[i]) + strs[i + 1];
    return raw(s);
  }
  const ic = (n, cls) => raw(`<svg class="i${cls ? ' ' + cls : ''}" viewBox="0 0 24 24" aria-hidden="true">${ICONS[n] || ''}</svg>`);
  const $ = (sel, root) => (root || document).querySelector(sel);
  const setHTML = (el, h) => { if (el) el.innerHTML = out(h); };

  // ---------------------------------------------------------------- Форматирование
  const nf = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });
  const num = (n) => nf.format(Math.round(n || 0));
  const money = (n) => num(n) + ' сум';
  const pcs = (n) => num(n) + ' шт';
  const signed = (n) => (n > 0 ? '+' : n < 0 ? '−' : '') + num(Math.abs(n));
  const pct = (a, b) => (b ? Math.round((a / b) * 100) : 0);
  const sum = (arr, f) => arr.reduce((s, x) => s + f(x), 0);
  const pad4 = (n) => String(n).padStart(4, '0');
  const retNo = (r) => 'В-' + pad4(r.number);
  const incNo = (d) => 'П-' + pad4(d.number);
  const audNo = (a) => 'И-' + pad4(a.number);
  const parseMoney = (v) => { const n = Number(String(v == null ? '' : v).replace(/[^\d]/g, '')); return Number.isFinite(n) ? n : 0; };
  const parseQty = (v) => { const s = String(v == null ? '' : v).replace(/[^\d]/g, ''); return s === '' ? null : Number(s); };
  function compact(n) {
    const a = Math.abs(n);
    const f = (x, d) => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: d }).format(x);
    if (a >= 1e9) return f(n / 1e9, 1) + ' млрд';
    if (a >= 1e6) return f(n / 1e6, a >= 1e8 ? 0 : 1) + ' млн';
    if (a >= 1e3) return f(n / 1e3, 0) + ' тыс';
    return f(n, 0);
  }
  function plural(n, forms) {
    const a = Math.abs(n) % 100, b = a % 10;
    if (a > 10 && a < 20) return forms[2];
    if (b > 1 && b < 5) return forms[1];
    if (b === 1) return forms[0];
    return forms[2];
  }
  const initials = (name) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();

  const sod = (t) => { const d = new Date(t); d.setHours(0, 0, 0, 0); return d.getTime(); };
  const daysAgo = (n) => sod(sod(Date.now()) - n * DAY + DAY / 2);
  const fmtTime = (t) => new Date(t).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  const fmtDate = (t, year) => new Date(t).toLocaleDateString('ru-RU', year ? { day: 'numeric', month: 'long', year: 'numeric' } : { day: 'numeric', month: 'long' });
  const fmtDT = (t) => new Date(t).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  const weekday = (t) => new Date(t).toLocaleDateString('ru-RU', { weekday: 'short' });
  function dayLabel(t) {
    const d = sod(t);
    if (d === daysAgo(0)) return 'Сегодня, ' + fmtDate(t);
    if (d === daysAgo(1)) return 'Вчера, ' + fmtDate(t);
    return fmtDate(t, new Date(t).getFullYear() !== new Date().getFullYear()) + ', ' + weekday(t);
  }
  function whenShort(t) {
    const d = sod(t);
    if (d === daysAgo(0)) return fmtTime(t);
    if (d === daysAgo(1)) return 'вчера ' + fmtTime(t);
    return new Date(t).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }) + ' ' + fmtTime(t);
  }

  // ---------------------------------------------------------------- EAN-13
  function eanCheck(d12) {
    let s = 0;
    for (let i = 0; i < 12; i++) s += Number(d12[i]) * (i % 2 ? 3 : 1);
    return (10 - (s % 10)) % 10;
  }
  const validEan = (c) => /^\d{13}$/.test(c) && eanCheck(c) === Number(c[12]);
  function normCode(v) {
    let c = String(v == null ? '' : v).trim().replace(/\s+/g, '');
    if (/^\d{12}$/.test(c)) c = '0' + c; // UPC-A → EAN-13
    return c;
  }
  const EAN_L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
  const EAN_R = EAN_L.map((p) => p.replace(/./g, (b) => (b === '1' ? '0' : '1')));
  const EAN_G = EAN_R.map((p) => p.split('').reverse().join(''));
  const EAN_PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];
  function barcodeSVG(code) {
    if (!validEan(code)) return html`<div class="mono">${code}</div>`;
    const parity = EAN_PARITY[Number(code[0])];
    let bits = '101';
    for (let i = 1; i <= 6; i++) bits += (parity[i - 1] === 'L' ? EAN_L : EAN_G)[Number(code[i])];
    bits += '01010';
    for (let i = 7; i <= 12; i++) bits += EAN_R[Number(code[i])];
    bits += '101';
    const q = 11;
    let bars = '';
    for (let i = 0; i < bits.length;) {
      if (bits[i] !== '1') { i++; continue; }
      let j = i;
      while (j < bits.length && bits[j] === '1') j++;
      const guard = i < 3 || (i >= 45 && i < 50) || i >= 92;
      bars += `<rect x="${q + i}" y="2" width="${j - i}" height="${guard ? 54 : 48}"/>`;
      i = j;
    }
    let digits = `<text x="${q - 5}" y="59">${code[0]}</text>`;
    for (let k = 0; k < 6; k++) digits += `<text x="${q + 3 + 7 * k + 3.5}" y="59">${code[1 + k]}</text>`;
    for (let k = 0; k < 6; k++) digits += `<text x="${q + 50 + 7 * k + 3.5}" y="59">${code[7 + k]}</text>`;
    return raw(`<svg viewBox="0 0 ${q + 95 + 7} 62" role="img" aria-label="Штрих-код ${code}">${bars}<g font-family="JetBrains Mono, ui-monospace, monospace" font-size="8" text-anchor="middle">${digits}</g></svg>`);
  }

  // ---------------------------------------------------------------- Сервер
  const lsGet = (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } };
  const lsSet = (k, v) => { try { if (v == null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) { /* хранилище недоступно */ } };

  class ApiError extends Error {
    constructor(message, status, data) {
      super(message);
      this.status = status;
      this.data = data;
    }
  }

  let TOKEN = lsGet(TOKEN_KEY);
  function setToken(t) {
    TOKEN = t || null;
    lsSet(TOKEN_KEY, TOKEN);
  }

  async function api(method, path, body) {
    const headers = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (TOKEN) headers.Authorization = 'Bearer ' + TOKEN;
    let res;
    try {
      res = await fetch(API + path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
    } catch (e) {
      throw new ApiError('Нет связи с сервером — проверьте интернет и попробуйте ещё раз', 0);
    }
    let data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    if (res.status === 401 && TOKEN && path !== '/login') {
      signOut('Сессия закончилась — войдите снова');
      throw new ApiError('Войдите в кассу заново', 401, data);
    }
    if (!res.ok) {
      const errs = data && data.errors ? Object.values(data.errors) : [];
      const msg = (errs[0] && errs[0][0]) || (data && data.message) || 'Сервер ответил ошибкой ' + res.status;
      throw new ApiError(msg, res.status, data);
    }
    return data;
  }

  // ---------------------------------------------------------------- Черновики на этом устройстве
  // Корзина, незаконченный приход и пересчёт живут здесь; документы, остатки и отчёты — на сервере.
  const newUuid = () => (window.crypto && crypto.randomUUID
    ? crypto.randomUUID()
    : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const r = (Math.random() * 16) | 0;
      return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    }));
  const freshCart = (payment) => ({ items: [], customer: '', payment: payment || 'cash', received: '', uuid: newUuid() });
  const freshDrafts = () => ({ cart: freshCart(), incomeDraft: { supplier: '', lines: [] }, auditDraft: { counts: {} } });
  let L = (() => {
    try {
      const d = JSON.parse(lsGet(LOCAL_KEY) || 'null');
      if (d && d.cart && d.incomeDraft && d.auditDraft) return d;
    } catch (e) { /* испорченный черновик — начинаем заново */ }
    return freshDrafts();
  })();
  const saveLocal = () => lsSet(LOCAL_KEY, JSON.stringify(L));

  // ---------------------------------------------------------------- Данные с сервера
  const D = { me: null, store: { name: 'Касса и склад', is_demo: false }, features: { demo: false }, employees: [], products: [], popular: [], suppliers: [], catalogAt: 0 };
  let byId = new Map();
  function setProducts(list) {
    D.products = list.slice().sort((a, b) => a.name.localeCompare(b.name, 'ru'));
    byId = new Map(D.products.map((p) => [p.id, p]));
  }
  function patchProducts(list) {
    if (!list || !list.length) return;
    const map = new Map(D.products.map((p) => [p.id, p]));
    list.forEach((p) => map.set(p.id, p));
    setProducts([...map.values()]);
  }
  const P = (id) => byId.get(Number(id));
  const byCode = (code) => D.products.find((p) => p.barcode === code);
  const isLow = (p) => p.stock <= p.min_stock;
  const lowStock = () => D.products.filter(isLow).sort((a, b) => a.stock - b.stock || a.name.localeCompare(b.name, 'ru'));
  const isOwner = () => !!(D.me && D.me.role === 'owner');
  const nameOf = (who) => (who && who.name) || 'Сотрудник удалён';
  const shortName = (who) => {
    const parts = nameOf(who).split(/\s+/);
    return parts.length > 1 ? parts[0] + ' ' + parts[1][0] + '.' : parts[0];
  };
  function applyBootstrap(b) {
    D.me = b.employee;
    D.store = b.store;
    D.employees = b.employees;
    D.popular = b.popular;
    D.suppliers = b.suppliers;
    D.features = b.features || { demo: false };
    D.catalogAt = Date.now();
    setProducts(b.products);
    // в черновиках оставляем только товары, которые ещё есть в каталоге
    L.cart.items = L.cart.items.filter((l) => P(l.pid));
    L.incomeDraft.lines = L.incomeDraft.lines.filter((l) => P(l.pid));
    Object.keys(L.auditDraft.counts).forEach((id) => { if (!P(id)) delete L.auditDraft.counts[id]; });
    saveLocal();
  }

  // Кэш ответов: показываем то, что есть, и тихо обновляем при заходе на экран.
  const cache = new Map();
  function get(key, path) {
    let c = cache.get(key);
    if (!c) {
      c = { data: undefined, stale: true, error: null, promise: null };
      cache.set(key, c);
    }
    if ((c.stale || (c.data === undefined && !c.error)) && !c.promise) {
      const first = c.data === undefined;
      c.stale = false;
      let changed = true;
      c.promise = api('GET', path)
        .then((d) => {
          // перерисовываем экран, только если с сервера пришло что-то новое: не мешаем вводу
          changed = first || JSON.stringify(d) !== JSON.stringify(c.data);
          c.data = d;
          c.error = null;
          if (first) U.enter = true;
        })
        .catch((e) => { c.error = e; if (e.status !== 401 && !first) toast(e.message, 'bad'); })
        .finally(() => { c.promise = null; if (D.me && changed) render(); });
    }
    return c.data;
  }
  const failed = (key) => { const c = cache.get(key); return c && c.data === undefined && c.error ? c.error : null; };
  function invalidate(prefix) {
    for (const [k, c] of cache) if (!prefix || k.startsWith(prefix)) { c.stale = true; c.error = null; }
  }
  let catalogLoading = null;
  function refreshCatalog(force) {
    if (!D.me || catalogLoading || (!force && Date.now() - D.catalogAt < 20000)) return;
    catalogLoading = api('GET', '/products')
      .then((d) => {
        const changed = JSON.stringify(d.products) !== JSON.stringify(D.products) || JSON.stringify(d.popular) !== JSON.stringify(D.popular);
        D.catalogAt = Date.now();
        if (!changed) return;
        setProducts(d.products);
        D.popular = d.popular;
        render();
      })
      .catch(() => { /* каталог обновится при следующем заходе */ })
      .finally(() => { catalogLoading = null; });
  }

  // ---------------------------------------------------------------- Касса и черновики
  const cartLines = () => L.cart.items.filter((l) => P(l.pid));
  const cartTotal = () => sum(cartLines(), (l) => l.qty * P(l.pid).sell_price);
  const cartUnits = () => sum(cartLines(), (l) => l.qty);

  function searchProducts(q, limit) {
    const words = q.trim().toLowerCase().split(/\s+/).filter(Boolean);
    if (!words.length) return [];
    const res = D.products.filter((p) => {
      const hay = (p.name + ' ' + p.barcode + ' ' + catOf(p).name).toLowerCase();
      return words.every((w) => hay.includes(w));
    });
    return limit ? res.slice(0, limit) : res;
  }

  function addToCart(pid) {
    const p = P(pid);
    if (!p) return { ok: false, msg: 'Товар не найден' };
    const line = L.cart.items.find((l) => l.pid === p.id);
    const inCart = line ? line.qty : 0;
    if (inCart + 1 > p.stock) {
      return { ok: false, msg: p.stock ? `На складе только ${pcs(p.stock)}: ${p.name}` : `Нет на складе: ${p.name}` };
    }
    if (line) line.qty++;
    else L.cart.items.unshift({ pid: p.id, qty: 1 });
    U.flash = p.id;
    U.flashNew = !line;
    saveLocal();
    return { ok: true, msg: '+1 ' + p.name, p };
  }

  function setCartQty(pid, n) {
    const p = P(pid);
    const line = L.cart.items.find((l) => l.pid === Number(pid));
    if (!line || !p) return;
    if (!(n > 0)) L.cart.items = L.cart.items.filter((l) => l !== line);
    else if (n > p.stock) {
      line.qty = p.stock;
      toast(`На складе только ${pcs(p.stock)}`, 'bad');
    } else line.qty = n;
    saveLocal();
    render();
  }

  function addIncomeLine(pid) {
    const p = P(pid);
    if (!p) return null;
    const line = L.incomeDraft.lines.find((l) => l.pid === p.id);
    if (line) line.qty++;
    else L.incomeDraft.lines.unshift({ pid: p.id, qty: 1, cost: p.buy_price });
    saveLocal();
    return p;
  }

  function auditStats() {
    let checked = 0, short = 0, shortSum = 0, extra = 0, extraSum = 0;
    for (const [id, actual] of Object.entries(L.auditDraft.counts)) {
      const p = P(id);
      if (!p) continue;
      checked++;
      const d = actual - p.stock;
      if (d < 0) { short -= d; shortSum -= d * p.buy_price; }
      if (d > 0) { extra += d; extraSum += d * p.buy_price; }
    }
    return { checked, total: D.products.length, short, shortSum, extra, extraSum };
  }

  // Одна операция с сервером за раз: кнопка показывает ожидание, повторные нажатия игнорируются.
  async function run(el, fn) {
    if (U.busy) return;
    U.busy = true;
    if (el) { el.disabled = true; el.classList.add('busy'); }
    try {
      await fn();
    } catch (e) {
      if (e.status !== 401) toast(e.message || 'Что-то пошло не так', 'bad');
      if (e.status === 422) refreshCatalog(true);
    } finally {
      U.busy = false;
      if (el && el.isConnected) { el.disabled = false; el.classList.remove('busy'); }
    }
  }

  // ---------------------------------------------------------------- Операции (считает и сохраняет сервер)
  function checkout(el) {
    const c = L.cart;
    const lines = cartLines();
    if (!lines.length) return;
    for (const l of lines) {
      const p = P(l.pid);
      if (l.qty > p.stock) { toast(`Недостаточно «${p.name}»: на складе ${pcs(p.stock)}`, 'bad'); return; }
    }
    const received = parseMoney(c.received);
    if (c.payment === 'cash' && received && received < cartTotal()) { toast('Получено меньше суммы чека', 'bad'); return; }
    return run(el, async () => {
      const res = await api('POST', '/sales', {
        client_uuid: c.uuid,
        items: lines.map((l) => ({ product_id: l.pid, qty: l.qty })),
        payment: c.payment,
        customer: c.customer.trim() || null,
        received: c.payment === 'cash' && received ? received : null,
      });
      patchProducts(res.products);
      L.cart = freshCart(c.payment);
      saveLocal();
      invalidate();
      render();
      openReceipt(res.sale, true);
      chime();
      setTimeout(() => {
        const ok = $('.ok-check');
        if (!ok) return;
        const b = ok.getBoundingClientRect();
        burst(b.left + b.width / 2, b.top + b.height / 2);
      }, 520);
    });
  }

  function postIncome(el) {
    const d = L.incomeDraft;
    const lines = d.lines.filter((l) => P(l.pid));
    if (!lines.length) { toast('Добавьте товары в приход', 'bad'); return; }
    const bad = lines.find((l) => !(l.qty > 0));
    if (bad) { toast(`Укажите количество: ${P(bad.pid).name}`, 'bad'); return; }
    return run(el, async () => {
      const res = await api('POST', '/incomes', {
        supplier: d.supplier.trim() || null,
        items: lines.map((l) => ({ product_id: l.pid, qty: l.qty, cost: l.cost || 0 })),
      });
      patchProducts(res.products);
      if (res.income.supplier && !D.suppliers.includes(res.income.supplier)) D.suppliers.push(res.income.supplier);
      L.incomeDraft = { supplier: '', lines: [] };
      saveLocal();
      invalidate();
      render();
      openIncomeDoc(res.income);
      toast(`Приход ${incNo(res.income)} проведён: +${pcs(res.income.units)}`, 'ok');
    });
  }

  function postRefund(el) {
    const sale = U.refSale;
    if (!sale) return;
    const items = sale.items.filter((i) => (U.refQty[i.id] || 0) > 0).map((i) => ({ sale_item_id: i.id, qty: U.refQty[i.id] }));
    if (!items.length) { toast('Выберите товары для возврата', 'bad'); return; }
    return run(el, async () => {
      const res = await api('POST', '/returns', { sale_id: sale.id, items, reason: U.refReason });
      patchProducts(res.products);
      U.refSale = null;
      U.refQty = {};
      U.enter = true;
      invalidate();
      render();
      openReturnDoc(res.return);
      toast(`Возврат ${retNo(res.return)} оформлен: −${money(res.return.total)}`, 'ok');
    });
  }

  function postAudit(el) {
    const counts = Object.entries(L.auditDraft.counts).filter(([id]) => P(id)).map(([id, actual]) => ({ product_id: Number(id), actual }));
    if (!counts.length) return;
    return run(el, async () => {
      const res = await api('POST', '/audits', { counts });
      patchProducts(res.products);
      L.auditDraft = { counts: {} };
      saveLocal();
      invalidate();
      render();
      openAuditDoc(res.audit);
      toast(`Инвентаризация ${audNo(res.audit)} проведена`, 'ok');
    });
  }

  function deleteProduct(id) {
    const p = P(id);
    if (!p) return;
    return run(null, async () => {
      await api('DELETE', '/products/' + p.id);
      setProducts(D.products.filter((x) => x.id !== p.id));
      L.cart.items = L.cart.items.filter((l) => l.pid !== p.id);
      L.incomeDraft.lines = L.incomeDraft.lines.filter((l) => l.pid !== p.id);
      delete L.auditDraft.counts[p.id];
      saveLocal();
      invalidate();
      closeSheet();
      render();
      toast(`Товар удалён: ${p.name}`, 'ok');
    });
  }

  // ---------------------------------------------------------------- Тост, лист, диалог
  let toastTimer = 0;
  function toast(msg, kind) {
    kind = kind || 'ok';
    const el = $('#toast');
    if (!el) return;
    el.className = 'toast show ' + kind;
    setHTML(el, html`${ic(kind === 'bad' ? 'alert' : 'check')}<span>${msg}</span>`);
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), kind === 'bad' ? 3800 : 2400);
  }

  function lockScroll() {
    document.body.style.overflow = !$('#sheet').hidden || !$('#scan').hidden ? 'hidden' : '';
  }
  let sheetTimer = 0;
  function openSheet(content) {
    const layer = $('#sheet');
    sheetSeq++;
    clearTimeout(sheetTimer);
    layer.classList.remove('closing');
    setHTML($('#sheet-body'), content);
    layer.hidden = false;
    $('.sheet', layer).scrollTop = 0;
    lockScroll();
    enterAnim($('#sheet-body .sheet-body'), 8);
  }
  function closeSheet() {
    const layer = $('#sheet');
    if (layer.hidden || layer.classList.contains('closing')) return;
    const done = () => {
      clearTimeout(sheetTimer);
      layer.classList.remove('closing');
      layer.hidden = true;
      setHTML($('#sheet-body'), '');
      lockScroll();
    };
    if (reduceMotion()) { done(); return; }
    layer.classList.add('closing');
    sheetTimer = setTimeout(done, 240);
  }
  const sheetHead = (title, sub) => html`<div class="sheet-h"><div><h2>${title}</h2>${sub ? html`<div class="sec-sub">${sub}</div>` : ''}</div><button class="icon-btn" data-act="closeSheet" aria-label="Закрыть">${ic('x')}</button></div>`;

  let dialogOk = null;
  function confirmBox(o) {
    dialogOk = o.onOk;
    setHTML($('#dialog-box'), html`<h3>${o.title}</h3>${o.text ? html`<p>${o.text}</p>` : ''}
      <div class="btn-row"><button class="btn" data-act="dialogCancel">Отмена</button><button class="btn ${o.danger ? 'btn-danger' : 'btn-primary'}" data-act="dialogOk">${o.ok || 'Подтвердить'}</button></div>`);
    $('#dialog').hidden = false;
    const okBtn = $('#dialog [data-act="dialogOk"]');
    if (okBtn) okBtn.focus();
  }
  function closeDialog() {
    $('#dialog').hidden = true;
    dialogOk = null;
  }

  // ---------------------------------------------------------------- Сканер (камера / фото / вручную)
  let decoderPromise = null;
  function getDecoder() {
    if (decoderPromise) return decoderPromise;
    decoderPromise = (async () => {
      if ('BarcodeDetector' in window) {
        try {
          const supported = await window.BarcodeDetector.getSupportedFormats();
          const formats = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128'].filter((f) => supported.includes(f));
          if (formats.length) return { kind: 'native', det: new window.BarcodeDetector({ formats }) };
        } catch (e) { /* переходим к ZXing */ }
      }
      const Z = window.ZXing || (await new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = ZXING_URL;
        s.async = true;
        s.onload = () => (window.ZXing ? resolve(window.ZXing) : reject(new Error('zxing')));
        s.onerror = () => reject(new Error('zxing'));
        document.head.appendChild(s);
      }));
      const formats = [Z.BarcodeFormat.EAN_13, Z.BarcodeFormat.EAN_8, Z.BarcodeFormat.UPC_A, Z.BarcodeFormat.UPC_E, Z.BarcodeFormat.CODE_128];
      const hints = new Map([[Z.DecodeHintType.POSSIBLE_FORMATS, formats]]);
      const photoHints = new Map([[Z.DecodeHintType.POSSIBLE_FORMATS, formats], [Z.DecodeHintType.TRY_HARDER, true]]);
      return { kind: 'zxing', Z, hints, photoHints };
    })();
    decoderPromise.catch(() => { decoderPromise = null; });
    return decoderPromise;
  }

  async function decodeImage(file) {
    const dec = await getDecoder();
    if (dec.kind === 'native') {
      const bmp = await createImageBitmap(file);
      try {
        const r = await dec.det.detect(bmp);
        return r && r[0] ? r[0].rawValue : null;
      } finally {
        if (bmp.close) bmp.close();
      }
    }
    const url = URL.createObjectURL(file);
    try {
      const reader = new dec.Z.BrowserMultiFormatReader(dec.photoHints);
      const r = await reader.decodeFromImageUrl(url);
      return r ? r.getText() : null;
    } catch (e) {
      return null;
    } finally {
      URL.revokeObjectURL(url);
    }
  }

  function camError(e) {
    const n = e && e.name;
    if (n === 'NotAllowedError' || n === 'SecurityError') return 'Нет доступа к камере. Разрешите его в настройках браузера, сфотографируйте штрих-код или введите цифры вручную.';
    if (n === 'NotFoundError' || n === 'OverconstrainedError' || n === 'NoCamera') return 'Камера не найдена. Сфотографируйте штрих-код или введите цифры вручную.';
    return 'Камера здесь недоступна. Сфотографируйте штрих-код или введите цифры вручную.';
  }

  let audioCtx = null;
  function beep() {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      const o = audioCtx.createOscillator();
      const g = audioCtx.createGain();
      o.type = 'square';
      o.frequency.value = 1320;
      g.gain.value = 0.05;
      o.connect(g);
      g.connect(audioCtx.destination);
      o.start();
      o.stop(audioCtx.currentTime + 0.08);
    } catch (e) { /* без звука */ }
    try { if (navigator.vibrate) navigator.vibrate(40); } catch (e) { /* без вибрации */ }
  }

  function chime() {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      const t = audioCtx.currentTime;
      [[880, 0], [1318.5, 0.09]].forEach(([f, d]) => {
        const o = audioCtx.createOscillator();
        const g = audioCtx.createGain();
        o.type = 'sine';
        o.frequency.value = f;
        g.gain.setValueAtTime(0.0001, t + d);
        g.gain.exponentialRampToValueAtTime(0.09, t + d + 0.015);
        g.gain.exponentialRampToValueAtTime(0.0001, t + d + 0.38);
        o.connect(g);
        g.connect(audioCtx.destination);
        o.start(t + d);
        o.stop(t + d + 0.42);
      });
    } catch (e) { /* без звука */ }
    try { if (navigator.vibrate) navigator.vibrate([30, 50, 30]); } catch (e) { /* без вибрации */ }
  }

  // ---------------------------------------------------------------- Анимации
  // Все эффекты выключаются, если в системе включено «уменьшить движение».
  const reduceMotion = () => !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  const easeOut = (t) => 1 - Math.pow(1 - t, 3);
  const memo = new Map(); // последние показанные значения: числа, длины полос, положения переключателей
  const FMT = { num, compact };
  const restartAnim = (el, cls) => { if (!el) return; el.classList.remove(cls); void el.offsetWidth; el.classList.add(cls); };

  // Числа плавно досчитываются, полосы и столбики растут от прошлого значения, плашка переключателя скользит.
  function fx(root) {
    if (!root) return;
    const calm = reduceMotion();
    root.querySelectorAll('[data-count]').forEach((el) => {
      const to = Number(el.dataset.count);
      const key = el.dataset.key;
      const fmt = FMT[el.dataset.fmt] || num;
      const from = memo.has(key) ? memo.get(key) : 0;
      memo.set(key, to);
      if (calm || from === to) return;
      const dur = Math.min(1150, 480 + Math.log10(Math.abs(to - from) + 1) * 75);
      const t0 = performance.now();
      el.textContent = fmt(from);
      const step = (now) => {
        if (!el.isConnected) return;
        const k = Math.min(1, (now - t0) / dur);
        el.textContent = fmt(from + (to - from) * easeOut(k));
        if (k < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    });
    const plan = [];
    root.querySelectorAll('[data-grow]').forEach((el) => {
      const key = el.dataset.grow;
      const prop = el.dataset.prop || 'width';
      const to = Number(el.dataset.to);
      const from = memo.has(key) ? memo.get(key) : 0;
      memo.set(key, to);
      if (calm || from === to) return;
      el.style.transition = 'none';
      el.style[prop] = from + '%';
      plan.push(() => { el.style.transition = ''; el.style[prop] = to + '%'; });
    });
    root.querySelectorAll('[data-seg]').forEach((el) => {
      const key = 'seg-' + el.dataset.seg;
      const to = Number(el.dataset.i);
      const from = memo.has(key) ? memo.get(key) : to;
      memo.set(key, to);
      const pill = el.querySelector('.seg-pill');
      if (calm || !pill || from === to) return;
      pill.style.transition = 'none';
      el.style.setProperty('--i', from);
      plan.push(() => { pill.style.transition = ''; el.style.setProperty('--i', to); });
    });
    if (plan.length) {
      void root.offsetWidth;
      plan.forEach((f) => f());
    }
  }

  // Каскадное появление блоков экрана и строк внутри них.
  function enterAnim(root, max) {
    if (!root || reduceMotion()) return;
    const rise = (el, delay) => { el.style.setProperty('--d', delay + 'ms'); el.classList.add('rise'); };
    Array.from(root.children).slice(0, max || 10).forEach((el, i) => {
      const base = i * 55;
      rise(el, base);
      el.querySelectorAll('.rows > *, .qa > *, .tiles > *, .menu-grid > *, .kpis > *').forEach((c, j) => {
        if (j < 10) rise(c, base + 80 + j * 34);
      });
    });
  }

  // «+1» перелетает по дуге из нажатого товара к итогу чека.
  function flyTo(from, toEl) {
    if (!from || !toEl || reduceMotion() || !document.body.animate) return;
    const to = toEl.getBoundingClientRect();
    const x0 = from.left + from.width / 2, y0 = from.top + from.height / 2;
    const x1 = to.left + to.width / 2, y1 = to.top + to.height / 2;
    const cx = (x0 + x1) / 2, cy = Math.min(y0, y1) - 110;
    const frames = [];
    for (let k = 0; k <= 12; k++) {
      const t = k / 12;
      const x = (1 - t) * (1 - t) * x0 + 2 * (1 - t) * t * cx + t * t * x1;
      const y = (1 - t) * (1 - t) * y0 + 2 * (1 - t) * t * cy + t * t * y1;
      frames.push({ transform: `translate(${x - x0}px, ${y - y0}px) scale(${1 - 0.5 * t})`, opacity: t < 0.85 ? 1 : 0.3 });
    }
    const dot = document.createElement('div');
    dot.className = 'fly';
    dot.textContent = '+1';
    dot.style.left = x0 + 'px';
    dot.style.top = y0 + 'px';
    document.body.appendChild(dot);
    dot.animate(frames, { duration: 640, easing: 'cubic-bezier(.45,0,.55,1)' }).onfinish = () => {
      dot.remove();
      if (toEl.isConnected) toEl.animate([{ transform: 'scale(1)' }, { transform: 'scale(1.14)' }, { transform: 'scale(1)' }], { duration: 340, easing: 'cubic-bezier(.3,1.6,.5,1)' });
    };
  }

  // Конфетти из галочки после пробитого чека.
  function burst(x, y) {
    if (reduceMotion() || !document.body.animate) return;
    const colors = ['var(--accent)', 'var(--teal)', 'var(--warn)', 'var(--good)'];
    const n = 28;
    for (let i = 0; i < n; i++) {
      const p = document.createElement('i');
      p.className = 'confetti' + (i % 3 === 0 ? ' round' : '');
      p.style.left = x + 'px';
      p.style.top = y + 'px';
      p.style.background = colors[i % colors.length];
      document.body.appendChild(p);
      const ang = (Math.PI * 2 * i) / n + Math.random() * 0.35;
      const dist = 55 + Math.random() * 75;
      const dx = Math.cos(ang) * dist, dy = Math.sin(ang) * dist - 24;
      const rot = (Math.random() - 0.5) * 600;
      p.animate([
        { transform: 'translate(-50%, -50%) scale(.3) rotate(0deg)', opacity: 1 },
        { transform: `translate(calc(-50% + ${dx}px), calc(-50% + ${dy}px)) scale(1) rotate(${rot / 2}deg)`, opacity: 1, offset: 0.5 },
        { transform: `translate(calc(-50% + ${dx * 1.2}px), calc(-50% + ${dy + 80}px)) scale(.7) rotate(${rot}deg)`, opacity: 0 },
      ], { duration: 950 + Math.random() * 450, easing: 'cubic-bezier(.2,.7,.3,1)' }).onfinish = () => p.remove();
    }
  }

  function shake(el) {
    if (!el || reduceMotion() || !el.animate) return;
    el.animate([{ transform: 'translateX(0)' }, { transform: 'translateX(-7px)' }, { transform: 'translateX(6px)' }, { transform: 'translateX(-3px)' }, { transform: 'translateX(0)' }], { duration: 340, easing: 'ease-out' });
  }

  // Плавная перестановка карточек (View Transitions API), где браузер это умеет.
  function withVT(fn) {
    if (!document.startViewTransition || reduceMotion()) { fn(); return; }
    let vt;
    try { vt = document.startViewTransition(fn); } catch (e) { fn(); return; }
    // пропущенный или прерванный переход — не ошибка: DOM всё равно обновлён
    ['ready', 'finished', 'updateCallbackDone'].forEach((k) => { if (vt && vt[k]) vt[k].catch(() => {}); });
  }

  const Scan = {
    opts: null, stream: null, timer: 0, reader: null, last: '', lastAt: 0, live: false,
    async open(opts) {
      this.opts = opts;
      this.last = '';
      this.live = true;
      $('#scan-title').textContent = opts.title || 'Сканер';
      $('#scan-done').hidden = !opts.continuous;
      setHTML($('#scan-last'), opts.continuous ? html`<span class="faint">Сканируйте товары один за другим</span>` : '');
      $('#scan-manual').value = '';
      $('#scan').hidden = false;
      lockScroll();
      this.status('Запускаем камеру…');
      try {
        await this.start();
      } catch (e) {
        if (this.live) this.status(camError(e));
      }
    },
    async start() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        const err = new Error('no camera');
        err.name = 'NoCamera';
        throw err;
      }
      const video = $('#scan-video');
      let dec = null;
      try { dec = await getDecoder(); } catch (e) { dec = null; }
      if (!this.live) return;
      const constraints = { audio: false, video: { facingMode: { ideal: 'environment' } } };
      if (dec && dec.kind === 'zxing') {
        this.reader = new dec.Z.BrowserMultiFormatReader(dec.hints, 250);
        this.status('Наведите камеру на штрих-код');
        this.reader.decodeFromConstraints(constraints, video, (res) => { if (res) this.hit(res.getText()); })
          .catch((e) => { if (this.live) this.status(camError(e)); });
        return;
      }
      this.stream = await navigator.mediaDevices.getUserMedia(constraints);
      if (!this.live) { this.stopStream(); return; }
      video.srcObject = this.stream;
      await video.play().catch(() => {});
      if (!dec) { this.status('Не удалось загрузить распознавание — введите цифры вручную'); return; }
      this.status('Наведите камеру на штрих-код');
      const tick = async () => {
        if (!this.live) return;
        try {
          if (video.readyState >= 2) {
            const r = await dec.det.detect(video);
            if (r && r.length) this.hit(r[0].rawValue);
          }
        } catch (e) { /* кадр не распознан */ }
        if (this.live) this.timer = setTimeout(tick, 180);
      };
      tick();
    },
    hit(value, manual) {
      const code = normCode(value);
      if (!code || !this.opts) return;
      const now = Date.now();
      if (!manual && code === this.last && now - this.lastAt < 2000) return;
      this.last = code;
      this.lastAt = now;
      beep();
      if (!reduceMotion()) restartAnim($('.scan-frame'), 'hit');
      const o = this.opts;
      if (!o.continuous) {
        this.close();
        o.onCode(code);
        return;
      }
      const r = o.onCode(code) || {};
      setHTML($('#scan-last'), html`<span class="${r.ok ? 'scan-ok' : 'scan-bad'}">${r.msg || code}</span>`);
    },
    status(t) {
      const el = $('#scan-status');
      el.textContent = t || '';
      el.hidden = !t;
    },
    stopStream() {
      if (this.stream) {
        this.stream.getTracks().forEach((t) => t.stop());
        this.stream = null;
      }
    },
    close() {
      this.live = false;
      clearTimeout(this.timer);
      try { if (this.reader) this.reader.reset(); } catch (e) { /* уже остановлен */ }
      this.reader = null;
      this.stopStream();
      const v = $('#scan-video');
      try { v.pause(); } catch (e) { /* нет видео */ }
      v.srcObject = null;
      $('#scan').hidden = true;
      lockScroll();
      const o = this.opts;
      this.opts = null;
      if (o && o.continuous) render();
    },
  };

  // ---------------------------------------------------------------- Состояние интерфейса
  const U = {
    saleQ: '', prodQ: '', prodCat: 'all', prodSort: 'name:1', prodLow: false,
    incQ: '', refSale: null, refQty: {}, refReason: 'Не подошёл',
    audQ: '', audCat: 'all', audDiff: false,
    period: 'today', jType: 'all', jQ: '', jQText: '', jLimit: 80, jLast: null,
    scanCode: '', backTo: 'home', flash: null, flashNew: false, focus: null, enter: false, pop: null, busy: false,
    seenSale: undefined,
    auth: null,
    pin: { pick: null, pin: '', err: '' },
  };

  // ---------------------------------------------------------------- Общие фрагменты
  const stockPill = (p) => (p.stock <= 0
    ? html`<span class="pill bad">нет в наличии</span>`
    : isLow(p) ? html`<span class="pill warn">${ic('alert')}${pcs(p.stock)}</span>` : html`<span class="pill">${pcs(p.stock)}</span>`);
  const cnt = (v, key, fmt) => html`<span data-count="${Math.round(v)}" data-key="${key}"${raw(fmt ? ` data-fmt="${fmt}"` : '')}>${fmt === 'compact' ? compact(v) : num(v)}</span>`;
  const sumVal = (v, key) => html`${key ? cnt(v, key) : num(v)}<em>сум</em>`;
  const empty = (icon, text) => html`<div class="empty">${ic(icon)}<span>${text}</span></div>`;
  const skeleton = (rows) => html`<div class="sk-list" aria-busy="true" aria-label="Загрузка">${Array.from({ length: rows || 4 }, () => html`<div class="sk-row"><span class="sk sk-ico"></span><span class="sk-lines"><span class="sk sk-line"></span><span class="sk sk-line short"></span></span></div>`)}</div>`;
  function loadBox(key, rows) {
    const err = failed(key);
    return err
      ? html`<div class="empty">${ic('alert')}<span>${err.message}</span><button class="btn btn-sm" data-act="retry" data-key="${key}">Повторить</button></div>`
      : skeleton(rows);
  }
  const docLabel = (type, number) => ({ sale: 'Продажа №' + number, ret: 'Возврат В-' + pad4(number), inc: 'Приход П-' + pad4(number), aud: 'Инвентаризация И-' + pad4(number) })[type];
  const DOC_ACT = { sale: 'openSale', ret: 'openReturn', inc: 'openIncome', aud: 'openAudit' };

  function saleRow(s, act, isNew) {
    return html`<button class="row${isNew ? ' is-new' : ''}" data-act="${act || 'openSale'}" data-id="${s.id}">
      <span class="row-ico sale">${ic(PAY[s.payment].icon)}</span>
      <span class="row-main">
        <span class="row-title">Чек №${s.number}${s.customer ? html` <span class="faint">· ${s.customer}</span>` : ''}</span>
        <span class="row-sub"><span>${whenShort(s.created_at)}</span><span>${shortName(s.employee)}</span><span>${PAY[s.payment].name}</span>${s.has_refund ? html`<span class="pill bad">возврат</span>` : ''}</span>
      </span>
      <span class="row-side"><span class="amt">${money(s.total)}</span><span class="faint">${pcs(s.units)}</span></span>
    </button>`;
  }
  const returnRow = (r) => html`<button class="row" data-act="openReturn" data-id="${r.id}">
      <span class="row-ico ret">${ic('refund')}</span>
      <span class="row-main"><span class="row-title">Возврат ${retNo(r)}</span>
      <span class="row-sub"><span>${whenShort(r.created_at)}</span><span>чек №${r.sale_number}</span><span>${r.reason}</span></span></span>
      <span class="row-side"><span class="amt minus">−${money(r.total)}</span><span class="faint">${shortName(r.employee)}</span></span>
    </button>`;
  const incomeRow = (d) => html`<button class="row" data-act="openIncome" data-id="${d.id}">
      <span class="row-ico inc">${ic('income')}</span>
      <span class="row-main"><span class="row-title">Приход ${incNo(d)}</span>
      <span class="row-sub"><span>${whenShort(d.created_at)}</span><span>${d.supplier || 'без поставщика'}</span></span></span>
      <span class="row-side"><span class="amt">${money(d.total)}</span><span class="faint">+${pcs(d.units)}</span></span>
    </button>`;
  const auditDiffText = (a) => (a.shortage_qty || a.surplus_qty ? `−${a.shortage_qty} / +${a.surplus_qty} шт` : 'без расхождений');
  const auditRow = (a) => html`<button class="row" data-act="openAudit" data-id="${a.id}">
      <span class="row-ico aud">${ic('audit')}</span>
      <span class="row-main"><span class="row-title">Инвентаризация ${audNo(a)}</span>
      <span class="row-sub"><span>${whenShort(a.created_at)}</span><span>${shortName(a.employee)}</span><span>проверено ${a.checked}</span></span></span>
      <span class="row-side"><span class="amt">${auditDiffText(a)}</span></span>
    </button>`;

  function suggestList(q, act) {
    if (!q || !q.trim()) return '';
    const res = searchProducts(q, 6);
    if (!res.length) return html`<div class="sug"><span class="sug-main"><span class="sug-name">Ничего не найдено</span><span class="sug-sub">Проверьте штрих-код или название</span></span></div>`;
    return res.map((p) => html`<button class="sug" data-act="${act}" data-id="${p.id}">
      <span class="cat-ico sm">${ic(catOf(p).icon, 'i-sm')}</span>
      <span class="sug-main"><span class="sug-name">${p.name}</span><span class="sug-sub"><span class="mono">${p.barcode}</span> · ${pcs(p.stock)}</span></span>
      <b class="num nowrap">${num(p.sell_price)}</b></button>`);
  }

  // ---------------------------------------------------------------- Экраны
  const SCREENS = {};

  // Главная
  SCREENS.home = {
    title: 'Главная', tab: true, keys: ['dashboard'],
    view() {
      const d = get('dashboard', '/dashboard');
      const todayRaw = new Date().toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' });
      const today = todayRaw.charAt(0).toUpperCase() + todayRaw.slice(1);
      const quick = html`<nav class="qa" aria-label="Быстрые действия">${QUICK.map(([to, label, icon], i) => html`<button class="qa-btn${i === 0 ? ' primary' : ''}" data-act="nav" data-to="${to}"><span class="qa-ico">${ic(icon)}</span><span>${label}</span></button>`)}</nav>`;
      if (!d) {
        return html`<section class="hero" aria-busy="true"><div class="hero-top"><span class="hero-label">Выручка за сегодня</span><span class="hero-date">${today}</span></div><div class="hero-value"><span class="sk on-hero"></span></div><div class="hero-stats"><div><b>…</b><span>чеков</span></div><div><b>…</b><span>продано, шт</span></div><div><b>…</b><span>средний чек</span></div></div></section>
          ${quick}<section class="card flush"><div class="card-h"><h2 class="sec-title">Последние продажи</h2></div>${loadBox('dashboard', 5)}</section>`;
      }
      const t = d.today;
      const low = d.low_stock;
      const recent = d.recent_sales;
      const seen = U.seenSale;
      U.seenSale = recent.length ? recent[0].number : 0;
      return html`
      <section class="hero" aria-label="Итоги дня">
        <div class="hero-top"><span class="hero-label">Выручка за сегодня</span><span class="hero-date">${today}</span></div>
        <div class="hero-value">${cnt(t.revenue, 'hero-rev')}<small>сум</small></div>
        ${t.refunds ? html`<div class="hero-label">продажи ${money(t.gross)} · возвраты −${money(t.refunds)}</div>` : ''}
        <div class="hero-stats">
          <div><b>${cnt(t.checks, 'hero-checks')}</b><span>${plural(t.checks, ['чек', 'чека', 'чеков'])}</span></div>
          <div><b>${cnt(t.units, 'hero-units')}</b><span>продано, шт</span></div>
          <div><b>${t.checks ? cnt(t.avg, 'hero-avg', 'compact') : '—'}</b><span>средний чек</span></div>
        </div>
      </section>
      ${low.length ? html`<button class="alert" data-act="lowStock">${ic('alert')}
        <span class="alert-body"><span class="alert-title">Заканчиваются ${low.length} ${plural(low.length, ['товар', 'товара', 'товаров'])}</span>
        <span class="alert-text">${low.slice(0, 3).map((p) => `${p.name} — ${p.stock} шт`).join(' · ')}${low.length > 3 ? ' …' : ''}</span></span>${ic('right')}</button>` : ''}
      ${quick}
      <section class="card flush">
        <div class="card-h"><h2 class="sec-title">Последние продажи</h2><button class="link" data-act="nav" data-to="journal">Журнал ${ic('right', 'i-sm')}</button></div>
        <div class="rows">${recent.length ? recent.map((s) => saleRow(s, null, seen != null && s.number > seen)) : empty('receipt', 'Продаж пока нет')}</div>
      </section>`;
    },
  };

  // Продажа (касса)
  function cartLine(l) {
    const p = P(l.pid);
    return html`<div class="cart-line${U.flash === p.id ? (U.flashNew ? ' flash new' : ' flash') : ''}">
      <div class="cart-name">${p.name}<div class="cart-price">${num(p.sell_price)} × ${l.qty} · на складе ${p.stock}</div></div>
      <button class="rm" data-act="rmCart" data-id="${p.id}" aria-label="Убрать из корзины">${ic('trash', 'i-sm')}</button>
      <div class="cart-ctrl">
        <div class="stepper"><button data-act="cartQty" data-id="${p.id}" data-d="-1" aria-label="Меньше">${ic('minus', 'i-sm')}</button><input class="input qty" inputmode="numeric" value="${l.qty}" data-ch="cartQtySet" data-id="${p.id}" aria-label="Количество"><button data-act="cartQty" data-id="${p.id}" data-d="1" aria-label="Больше">${ic('plus', 'i-sm')}</button></div>
        <span class="line-total">${money(p.sell_price * l.qty)}</span>
      </div>
    </div>`;
  }
  function changeText() {
    const t = cartTotal();
    const r = parseMoney(L.cart.received);
    if (!r) return '—';
    if (r < t) return html`<span class="bad">не хватает ${num(t - r)}</span>`;
    return money(r - t);
  }
  SCREENS.sale = {
    title: 'Продажа', tab: true, catalog: true,
    view() {
      const c = L.cart;
      const lines = cartLines();
      const total = cartTotal();
      const pop = D.popular.map(P).filter((p) => p && p.stock > 0).slice(0, 12);
      const payIdx = Object.keys(PAY).indexOf(c.payment);
      return html`
      <div class="search-row">
        <label class="search"><span class="sr-only">Штрих-код или название</span>${ic('search')}<input class="input" id="sale-q" type="search" enterkeyhint="done" autocomplete="off" placeholder="Штрих-код или название" value="${U.saleQ}" data-in="saleQ" data-enter="saleEnter"></label>
        <button class="icon-btn accent" data-act="scanSale" aria-label="Сканировать камерой">${ic('scan')}</button>
      </div>
      <div class="suggest" id="sale-suggest">${suggestList(U.saleQ, 'addCart')}</div>
      <section class="stack">
        <div class="card-h"><h2 class="sec-title">Популярные товары</h2><span class="sec-sub">нажмите, чтобы добавить</span></div>
        <div class="tiles">${pop.length ? pop.map((p) => html`<button class="tile" data-act="addCart" data-id="${p.id}"><span class="tile-name">${p.name}</span><span class="tile-foot"><span class="tile-price">${num(p.sell_price)}</span><span class="tile-stock">${p.stock} шт</span></span></button>`) : html`<div class="empty">${ic('box')}<span>Добавьте товары в каталог</span></div>`}</div>
      </section>
      <section class="card flush">
        <div class="card-h"><h2 class="sec-title">Корзина${lines.length ? html` <span class="faint">· ${pcs(cartUnits())}</span>` : ''}</h2>${lines.length ? html`<button class="link" data-act="clearCart">Очистить</button>` : ''}</div>
        <div class="rows">${lines.length ? lines.map(cartLine) : empty('cart', 'Корзина пуста. Отсканируйте штрих-код или выберите товар выше.')}</div>
      </section>
      ${lines.length ? html`
      <section class="card">
        <div class="field"><label for="cust">Имя покупателя</label><input class="input" id="cust" autocomplete="off" maxlength="60" placeholder="Необязательно" value="${c.customer}" data-in="customer"></div>
        <div class="field"><span class="label" id="pay-label">Способ оплаты</span>
          <div class="seg pay" role="radiogroup" aria-labelledby="pay-label" data-seg="pay" data-i="${payIdx}" style="--n:3;--i:${payIdx}"><span class="seg-pill" aria-hidden="true"></span>${Object.entries(PAY).map(([k, v]) => html`<button role="radio" aria-checked="${String(c.payment === k)}" class="${c.payment === k ? 'on' : ''}" data-act="pay" data-v="${k}">${ic(v.icon)}<span>${v.name}</span></button>`)}</div>
        </div>
        ${c.payment === 'cash' ? html`<div class="field-grid">
          <div class="field"><label for="recv">Получено, сум</label><input class="input money" id="recv" inputmode="numeric" autocomplete="off" placeholder="${num(total)}" value="${c.received}" data-in="received"></div>
          <div class="field"><span class="label">Сдача</span><div class="input out" id="change">${changeText()}</div></div>
        </div>` : ''}
        ${c.payment === 'qr' ? html`<p class="hint">Покажите покупателю QR-код терминала (Click, Payme, Uzum) и пробейте чек после подтверждения оплаты.</p>` : ''}
        ${c.payment === 'card' ? html`<p class="hint">Проведите оплату на терминале (Uzcard, Humo, Visa) и пробейте чек.</p>` : ''}
        <div class="totals">
          <div class="totals-row"><span>Позиций</span><b>${lines.length}</b></div>
          <div class="totals-row"><span>Товаров</span><b>${pcs(cartUnits())}</b></div>
          <div class="totals-row big"><span>Итого</span><span class="num">${money(total)}</span></div>
        </div>
        <p class="hint">Окончательную сумму, сдачу и списание со склада считает сервер при пробитии чека.</p>
      </section>` : ''}`;
    },
    bar() {
      if (!cartLines().length) return '';
      return html`<div class="checkout-inner"><div class="checkout-sum"><span>Итого</span><b>${cnt(cartTotal(), 'cart-total')}&nbsp;сум</b></div><button class="btn btn-primary btn-lg${U.busy ? ' busy' : ''}" data-act="checkout"${raw(U.busy ? ' disabled' : '')}>${ic('receipt')}Пробить чек</button></div>`;
    },
  };

  // Товары
  function filteredProducts() {
    let list = U.prodQ.trim() ? searchProducts(U.prodQ) : D.products.slice();
    if (U.prodCat !== 'all') list = list.filter((p) => p.category === U.prodCat);
    if (U.prodLow) list = list.filter(isLow);
    const [k, d] = U.prodSort.split(':');
    const dir = Number(d) || 1;
    const key = { name: (p) => p.name, price: (p) => p.sell_price, stock: (p) => p.stock }[k] || ((p) => p.name);
    return list.sort((a, b) => {
      const x = key(a), y = key(b);
      return (typeof x === 'string' ? x.localeCompare(y, 'ru') : x - y) * dir || a.name.localeCompare(b.name, 'ru');
    });
  }
  const pcard = (p) => html`<button class="pcard" data-act="openProduct" data-id="${p.id}" style="view-transition-name:pc-${p.id}">
      <span class="cat-ico">${ic(catOf(p).icon)}</span>
      <span class="pcard-name">${p.name}</span>
      <span class="pcard-code">${p.barcode} · ${catOf(p).name}</span>
      <span class="pcard-meta">
        <span class="kv"><span>Остаток</span>${stockPill(p)}</span>
        <span class="kv right"><span>Закупка</span><b>${num(p.buy_price)}</b></span>
        <span class="kv right"><span>Розница</span><b>${num(p.sell_price)}</b></span>
      </span>
    </button>`;
  function productList() {
    const list = filteredProducts();
    return html`<section class="card flush">
      <div class="card-h"><span class="summary-line">${list.length} ${plural(list.length, ['товар', 'товара', 'товаров'])} · ${pcs(sum(list, (p) => p.stock))}</span><button class="btn btn-primary btn-sm" data-act="newProduct">${ic('plus', 'i-sm')}Добавить товар</button></div>
      <div class="rows">${list.length ? list.map(pcard) : empty('search', D.products.length ? 'Ничего не найдено — измените поиск или фильтр' : 'Каталог пуст. Добавьте первый товар.')}</div>
    </section>`;
  }
  SCREENS.products = {
    title: 'Товары', tab: true, catalog: true,
    view() {
      const counts = {};
      D.products.forEach((p) => { counts[p.category] = (counts[p.category] || 0) + 1; });
      return html`
      <div class="search-row">
        <label class="search"><span class="sr-only">Поиск товара</span>${ic('search')}<input class="input" id="prod-q" type="search" enterkeyhint="search" autocomplete="off" placeholder="Название или штрих-код" value="${U.prodQ}" data-in="prodQ"></label>
        <button class="icon-btn" data-act="scanProducts" aria-label="Найти по штрих-коду камерой">${ic('scan')}</button>
      </div>
      <div class="chips" aria-label="Категории">
        <button class="chip${U.prodCat === 'all' ? ' on' : ''}" data-act="prodCat" data-v="all">Все <span class="count">${D.products.length}</span></button>
        ${CATS.map((c) => html`<button class="chip${U.prodCat === c.id ? ' on' : ''}" data-act="prodCat" data-v="${c.id}">${c.name} <span class="count">${counts[c.id] || 0}</span></button>`)}
      </div>
      <div class="toolbar">
        <label class="sr-only" for="prod-sort">Сортировка</label>
        <select class="select" id="prod-sort" data-ch="prodSort">${SORTS.map(([v, l]) => html`<option value="${v}"${raw(U.prodSort === v ? ' selected' : '')}>${l}</option>`)}</select>
        <button class="chip${U.prodLow ? ' warn-on' : ''}" data-act="prodLow" aria-pressed="${String(U.prodLow)}">${ic('alert', 'i-sm')}Мало на складе <span class="count">${lowStock().length}</span></button>
      </div>
      <div id="plist">${productList()}</div>`;
    },
  };

  // Лист, который сначала показывает загрузку, затем данные с сервера.
  let sheetSeq = 0;
  async function openLoading(title, sub, load, show) {
    openSheet(html`${sheetHead(title, sub)}<div class="sheet-body">${skeleton(4)}</div>`);
    const seq = sheetSeq;
    try {
      const data = await load();
      if (seq === sheetSeq && !$('#sheet').hidden) show(data);
    } catch (e) {
      if (seq === sheetSeq && !$('#sheet').hidden && e.status !== 401) {
        openSheet(html`${sheetHead(title, sub)}<div class="sheet-body">${empty('alert', e.message)}</div>`);
      }
    }
  }

  function openProduct(id) {
    const p = P(id);
    openLoading(p ? p.name : 'Товар', p ? catOf(p).name : '', () => api('GET', '/products/' + id), (d) => {
      patchProducts([d.product]);
      const x = d.product;
      openSheet(html`${sheetHead(x.name, catOf(x).name)}
        <div class="sheet-body">
          <div class="barcode">${barcodeSVG(x.barcode)}</div>
          <div class="kpis">
            <div class="kpi"><span>Остаток на складе</span><b class="${x.stock <= 0 ? 'bad' : x.is_low ? 'warn' : ''}">${pcs(x.stock)}</b><small>${x.is_low ? 'не выше минимума — ' + x.min_stock + ' шт' : 'минимум ' + x.min_stock + ' шт'}</small></div>
            <div class="kpi"><span>Продано за 30 дней</span><b>${pcs(d.sold_30d.qty)}</b><small>${money(d.sold_30d.sum)}</small></div>
            <div class="kpi"><span>Закупочная цена</span><b>${sumVal(x.buy_price)}</b></div>
            <div class="kpi"><span>Розничная цена</span><b>${sumVal(x.sell_price)}</b><small>${x.margin_pct != null ? 'наценка ' + x.margin_pct + '%' : ''}</small></div>
          </div>
          <div class="btn-row">
            <button class="btn btn-primary" data-act="productToCart" data-id="${x.id}"${raw(x.stock <= 0 ? ' disabled' : '')}>${ic('cart')}В корзину</button>
            <button class="btn" data-act="incomeFor" data-id="${x.id}">${ic('income')}Приход</button>
          </div>
          <section class="card flush"><div class="card-h"><h3 class="sec-title">Движение товара</h3></div>
            <div class="rows">${d.moves.length ? d.moves.map((m) => html`<button class="row" data-act="${DOC_ACT[m.type]}" data-id="${m.id}"><span class="row-ico ${m.type}">${ic(TYPE_ICON[m.type])}</span><span class="row-main"><span class="row-title">${docLabel(m.type, m.number)}</span><span class="row-sub"><span>${whenShort(m.created_at)}</span><span>${shortName({ name: m.employee })}</span></span></span><span class="amt ${m.qty > 0 ? 'plus' : 'minus'}">${signed(m.qty)} шт</span></button>`) : empty('journal', 'Операций с товаром ещё не было')}</div>
          </section>
          <div class="btn-row">
            <button class="btn" data-act="editProduct" data-id="${x.id}">${ic('edit')}Изменить</button>
            ${isOwner() ? html`<button class="btn btn-danger" data-act="deleteProduct" data-id="${x.id}">${ic('trash')}Удалить</button>` : ''}
          </div>
        </div>`);
    });
  }

  function marginText(buy, sell) {
    const b = parseMoney(buy), s = parseMoney(sell);
    if (!b || !s) return '';
    if (s < b) return html`<span class="bad">Розничная цена ниже закупочной на ${money(b - s)}</span>`;
    return `Наценка ${money(s - b)} · ${pct(s - b, b)}%`;
  }
  function codeHint() {
    const inp = $('#pf-code');
    const hint = $('#pf-code-hint');
    if (!inp || !hint) return;
    const v = normCode(inp.value);
    const form = $('#pform');
    const dup = v && D.products.find((p) => p.barcode === v && String(p.id) !== (form && form.dataset.id));
    let h;
    if (!v) h = 'Пусто — сервер создаст внутренний код EAN-13 (префикс 200).';
    else if (!/^\d+$/.test(v)) h = html`<span class="bad">Только цифры</span>`;
    else if (v.length < 13) h = `Ещё ${13 - v.length} ${plural(13 - v.length, ['цифра', 'цифры', 'цифр'])}`;
    else if (v.length > 13) h = html`<span class="bad">Слишком длинный код — нужно 13 цифр</span>`;
    else if (!validEan(v)) h = html`<span class="bad">Неверная контрольная цифра: последняя должна быть ${eanCheck(v)}</span>`;
    else if (dup) h = html`<span class="bad">Этот код уже у товара «${dup.name}»</span>`;
    else h = html`<span class="good">Код EAN-13 верный</span>`;
    setHTML(hint, h);
  }
  function productForm(p, opts) {
    opts = opts || {};
    const isNew = !p;
    const typed = String(opts.name || '').trim();
    const typedCode = /^\d{12,13}$/.test(typed) ? normCode(typed) : '';
    const v = p || { name: typedCode ? '' : typed, category: U.prodCat !== 'all' ? U.prodCat : 'cases', barcode: opts.barcode || typedCode, buy_price: '', sell_price: '', stock: '', min_stock: 3 };
    openSheet(html`${sheetHead(isNew ? 'Новый товар' : 'Изменить товар', isNew ? 'Карточка товара на складе' : v.name)}
      <form class="sheet-body" id="pform" data-submit="saveProduct" data-id="${isNew ? '' : v.id}" data-after="${opts.after || ''}" novalidate>
        <div class="field"><label for="pf-name">Название</label><input class="input" id="pf-name" name="name" maxlength="80" autocomplete="off" value="${v.name}" placeholder="Например, Чехол Silicone Case iPhone 16"></div>
        <div class="field"><label for="pf-cat">Категория</label><select class="select" id="pf-cat" name="category">${CATS.map((c) => html`<option value="${c.id}"${raw(c.id === v.category ? ' selected' : '')}>${c.name}</option>`)}</select></div>
        <div class="field"><label for="pf-code">Штрих-код (EAN-13)</label>
          <div class="search-row"><input class="input mono" id="pf-code" name="barcode" inputmode="numeric" maxlength="14" autocomplete="off" value="${v.barcode}" placeholder="13 цифр" data-in="pfCode" data-enter="pfCodeEnter"><button type="button" class="icon-btn" data-act="scanField" aria-label="Сканировать штрих-код">${ic('scan')}</button><button type="button" class="btn btn-sm" data-act="genCode">Создать</button></div>
          <span class="hint" id="pf-code-hint"></span></div>
        <div class="field-grid">
          <div class="field"><label for="pf-buy">Закупочная цена, сум</label><input class="input money" id="pf-buy" name="buy" inputmode="numeric" autocomplete="off" value="${v.buy_price}" data-in="pfCalc"></div>
          <div class="field"><label for="pf-sell">Розничная цена, сум</label><input class="input money" id="pf-sell" name="sell" inputmode="numeric" autocomplete="off" value="${v.sell_price}" data-in="pfCalc"></div>
          ${isNew ? html`<div class="field"><label for="pf-stock">Начальный остаток, шт</label><input class="input" id="pf-stock" name="stock" inputmode="numeric" autocomplete="off" value="${opts.after === 'income' ? 0 : v.stock}" placeholder="0"></div>` : ''}
          <div class="field${isNew ? '' : ' wide'}"><label for="pf-min">Мин. остаток, шт</label><input class="input" id="pf-min" name="min" inputmode="numeric" autocomplete="off" value="${v.min_stock}"></div>
        </div>
        <p class="hint" id="pf-margin">${marginText(v.buy_price, v.sell_price)}</p>
        ${isNew ? (opts.after === 'income' ? html`<p class="hint">Товар появится в приходе — количество укажете там.</p>` : '') : html`<p class="hint">Остаток меняется только через продажу, приход, возврат и инвентаризацию.</p>`}
        <p class="err" id="pf-err" role="alert"></p>
        <button class="btn btn-primary btn-lg" type="submit">${isNew ? 'Добавить товар' : 'Сохранить'}</button>
      </form>`);
    codeHint();
  }
  function saveProduct(form) {
    const f = Object.fromEntries(new FormData(form));
    const id = form.dataset.id;
    const err = (m) => { const e = $('#pf-err'); if (e) e.textContent = m; };
    const name = String(f.name || '').trim();
    if (!name) return err('Введите название товара');
    if (!parseMoney(f.sell)) return err('Укажите розничную цену');
    const payload = {
      name,
      category: f.category,
      barcode: normCode(f.barcode) || null,
      buy_price: parseMoney(f.buy),
      sell_price: parseMoney(f.sell),
      min_stock: parseQty(f.min) || 0,
    };
    if (!id) payload.stock = parseQty(f.stock) || 0;
    return run(form.querySelector('[type="submit"]'), async () => {
      try {
        const res = id ? await api('PUT', '/products/' + id, payload) : await api('POST', '/products', payload);
        patchProducts([res.product]);
        invalidate();
        if (form.dataset.after === 'income') {
          addIncomeLine(res.product.id);
          closeSheet();
          render();
        } else {
          render();
          openProduct(res.product.id);
        }
        toast(id ? 'Изменения сохранены' : `Товар добавлен: ${res.product.name}`, 'ok');
      } catch (e) {
        if (e.status === 422) { err(e.message); return; }
        throw e;
      }
    });
  }

  // Приход
  function incLine(l) {
    const p = P(l.pid);
    return html`<div class="cart-line">
      <div class="cart-name">${p.name}<div class="cart-price"><span class="mono">${p.barcode}</span> · сейчас ${pcs(p.stock)}</div></div>
      <button class="rm" data-act="incRm" data-id="${p.id}" aria-label="Убрать из прихода">${ic('trash', 'i-sm')}</button>
      <div class="cart-ctrl inc-ctrl">
        <label class="field"><span class="label">Кол-во, шт</span><input class="input sm" inputmode="numeric" autocomplete="off" value="${l.qty}" data-in="incQty" data-id="${p.id}"></label>
        <label class="field"><span class="label">Закупка, сум</span><input class="input sm money" inputmode="numeric" autocomplete="off" value="${l.cost}" data-in="incCost" data-id="${p.id}"></label>
        <span class="line-total" data-sum="${p.id}">${money(l.qty * l.cost)}</span>
      </div>
    </div>`;
  }
  function incTotals() {
    const lines = L.incomeDraft.lines.filter((l) => P(l.pid));
    return { units: sum(lines, (l) => l.qty || 0), total: sum(lines, (l) => (l.qty || 0) * (l.cost || 0)), n: lines.length };
  }
  SCREENS.income = {
    title: 'Приход', keys: ['incomes'], catalog: true,
    view() {
      const d = L.incomeDraft;
      const lines = d.lines.filter((l) => P(l.pid));
      const t = incTotals();
      const recent = get('incomes', '/incomes?limit=10');
      return html`
      <section class="card">
        <div class="card-h"><h2 class="sec-title">Новый приход</h2><span class="pill accent">черновик</span></div>
        <div class="field"><label for="inc-sup">Поставщик</label><input class="input" id="inc-sup" list="sup-list" autocomplete="off" maxlength="60" placeholder="Например, Baseus Uzbekistan" value="${d.supplier}" data-in="incSup"><datalist id="sup-list">${D.suppliers.map((s) => html`<option value="${s}"></option>`)}</datalist></div>
        <div class="field"><label for="inc-q">Добавить товар</label>
          <div class="search-row"><label class="search">${ic('search')}<input class="input" id="inc-q" type="search" enterkeyhint="done" autocomplete="off" placeholder="Штрих-код или название" value="${U.incQ}" data-in="incQ" data-enter="incEnter"></label><button class="icon-btn accent" data-act="scanIncome" aria-label="Сканировать камерой">${ic('scan')}</button></div>
        </div>
        <div class="suggest" id="inc-suggest">${suggestList(U.incQ, 'incAdd')}</div>
        <button class="btn btn-ghost btn-sm" data-act="newProductIncome">${ic('plus', 'i-sm')}Новый товар, которого нет в каталоге</button>
      </section>
      <section class="card flush">
        <div class="card-h"><h2 class="sec-title">Товары в приходе</h2>${lines.length ? html`<button class="link" data-act="incClear">Очистить</button>` : ''}</div>
        <div class="rows">${lines.length ? lines.map(incLine) : empty('income', 'Найдите или отсканируйте товары, которые привёз поставщик')}</div>
        ${lines.length ? html`<div class="row"><span class="row-main"><span class="row-title">Итого по приходу</span><span class="row-sub" id="inc-units">${t.n} ${plural(t.n, ['позиция', 'позиции', 'позиций'])} · ${pcs(t.units)}</span></span><span class="amt" id="inc-total">${money(t.total)}</span></div>
        <div class="row"><button class="btn btn-primary btn-lg btn-block" data-act="postIncome">${ic('check')}Провести приход</button></div>` : ''}
      </section>
      <section class="card flush">
        <div class="card-h"><h2 class="sec-title">Последние приходы</h2></div>
        <div class="rows">${recent ? (recent.incomes.length ? recent.incomes.map(incomeRow) : empty('income', 'Приходов ещё не было')) : loadBox('incomes', 3)}</div>
      </section>`;
    },
  };

  // Возврат
  function refundForm(sale) {
    const total = sum(sale.items, (i) => (U.refQty[i.id] || 0) * i.price);
    const anyLeft = sale.items.some((i) => i.returnable > 0);
    return html`<section class="card">
      <div class="card-h"><div><h2 class="sec-title">Чек №${sale.number}</h2><div class="sec-sub">${fmtDT(sale.created_at)} · ${shortName(sale.employee)} · ${PAY[sale.payment].name}${sale.customer ? ' · ' + sale.customer : ''}</div></div><button class="btn btn-sm" data-act="refClear">Другой чек</button></div>
      <div class="rows inset">${sale.items.map((i) => {
        const q = U.refQty[i.id] || 0;
        return html`<div class="cart-line">
          <div class="cart-name">${i.name}<div class="cart-price">${money(i.price)} · куплено ${i.qty}${i.returned ? `, уже возвращено ${i.returned}` : ''}</div></div><span></span>
          <div class="cart-ctrl">${i.returnable > 0
            ? html`<div class="stepper"><button data-act="refQty" data-id="${i.id}" data-d="-1" aria-label="Меньше"${raw(q <= 0 ? ' disabled' : '')}>${ic('minus', 'i-sm')}</button><span class="qty-val">${q}</span><button data-act="refQty" data-id="${i.id}" data-d="1" aria-label="Больше"${raw(q >= i.returnable ? ' disabled' : '')}>${ic('plus', 'i-sm')}</button></div><span class="line-total minus">${q ? '−' + money(q * i.price) : ''}</span>`
            : html`<span class="pill">возвращено полностью</span><span></span>`}</div>
        </div>`;
      })}</div>
      ${anyLeft ? html`
      <div class="field"><span class="label">Причина возврата</span><div class="chips wrap">${REASONS.map((r) => html`<button class="chip${U.refReason === r ? ' on' : ''}" data-act="refReason" data-v="${r}">${r}</button>`)}</div></div>
      <div class="totals">
        <div class="totals-row"><span>Вернуть деньги</span><b>${PAY[sale.payment].name}</b></div>
        <div class="totals-row big"><span>К возврату</span><span class="num">${money(total)}</span></div>
      </div>
      <button class="btn btn-danger btn-lg" data-act="postRefund"${raw(total ? '' : ' disabled')}>${ic('refund')}Оформить возврат</button>
      <p class="hint">Товар вернётся на склад, сумма вычтется из выручки. Сервер проверит, что по чеку ещё можно вернуть.</p>` : html`<p class="hint">По этому чеку всё уже возвращено.</p>`}
    </section>`;
  }
  async function loadRefundSale(id) {
    const r = await api('GET', '/sales/' + id);
    U.refSale = r.sale;
    U.refQty = {};
    U.enter = true;
    render();
    window.scrollTo(0, 0);
  }
  SCREENS.refund = {
    title: 'Возврат', keys: ['sales', 'returns'],
    view() {
      const sale = U.refSale;
      const sales = sale ? null : get('sales', '/sales?limit=12');
      const rets = get('returns', '/returns?limit=8');
      return html`
      ${sale ? refundForm(sale) : html`
      <section class="card">
        <div class="card-h"><h2 class="sec-title">Найти чек</h2></div>
        <div class="search-row"><label class="search"><span class="sr-only">Номер чека</span>${ic('receipt')}<input class="input" id="ref-no" inputmode="numeric" enterkeyhint="search" autocomplete="off" placeholder="Номер чека${sales && sales.sales[0] ? ', например ' + sales.sales[0].number : ''}" data-enter="refFind" data-keep></label><button class="btn btn-primary" data-act="refFind">Найти</button></div>
        <p class="hint">Или выберите чек из последних продаж.</p>
      </section>
      <section class="card flush"><div class="card-h"><h2 class="sec-title">Последние чеки</h2></div>
        <div class="rows">${sales ? (sales.sales.length ? sales.sales.map((s) => saleRow(s, 'refPick')) : empty('receipt', 'Продаж ещё не было')) : loadBox('sales', 5)}</div>
      </section>`}
      <section class="card flush"><div class="card-h"><h2 class="sec-title">Последние возвраты</h2></div>
        <div class="rows">${rets ? (rets.returns.length ? rets.returns.map(returnRow) : empty('refund', 'Возвратов не было')) : loadBox('returns', 3)}</div>
      </section>`;
    },
  };

  // Инвентаризация (пересчёт — черновик на устройстве, проведение — на сервере)
  function auditList() {
    const q = U.audQ.trim();
    let list = q && !/^\d{8,}$/.test(q) ? searchProducts(q) : D.products.slice();
    if (U.audCat !== 'all') list = list.filter((p) => p.category === U.audCat);
    if (U.audDiff) list = list.filter((p) => L.auditDraft.counts[p.id] !== undefined && L.auditDraft.counts[p.id] !== p.stock);
    return list.sort((a, b) => a.name.localeCompare(b.name, 'ru'));
  }
  function audDiffCell(p) {
    const a = L.auditDraft.counts[p.id];
    if (a === undefined) return { cls: '', text: '' };
    const d = a - p.stock;
    return { cls: d < 0 ? 'bad' : d > 0 ? 'good' : 'ok', text: d ? signed(d) : '✓' };
  }
  function audRow(p) {
    const a = L.auditDraft.counts[p.id];
    const dc = audDiffCell(p);
    return html`<div class="aud-row${a !== undefined ? ' counted' : ''}" data-row="${p.id}">
      <div><div class="aud-name">${p.name}</div><div class="aud-sub"><span class="mono">${p.barcode}</span><span>учёт: <b>${p.stock}</b></span></div></div>
      <div class="aud-ctrl">
        <button class="icon-btn sm" data-act="audFill" data-id="${p.id}" aria-label="Факт совпадает с учётом" title="Факт = учёт">${ic('check', 'i-sm')}</button>
        <input class="input sm qty" inputmode="numeric" autocomplete="off" placeholder="факт" value="${a !== undefined ? a : ''}" data-in="audFact" data-id="${p.id}" aria-label="Фактическое количество: ${p.name}">
        <span class="aud-diff ${dc.cls}" data-diff="${p.id}">${dc.text}</span>
      </div>
    </div>`;
  }
  function auditSummary() {
    const s = auditStats();
    return html`
      <div class="card-h"><h2 class="sec-title">Сверка остатков</h2><span class="sec-sub">проверено ${s.checked} из ${s.total}</span></div>
      <div class="progress" aria-hidden="true"><i data-grow="aud-progress" data-to="${pct(s.checked, s.total)}" style="width:${pct(s.checked, s.total)}%"></i></div>
      <div class="stat-table">
        <span>Недостача</span><b class="${s.short ? 'bad' : ''}">${s.short ? `−${pcs(s.short)} · −${money(s.shortSum)}` : '—'}</b>
        <span>Излишек</span><b class="${s.extra ? 'good' : ''}">${s.extra ? `+${pcs(s.extra)} · +${money(s.extraSum)}` : '—'}</b>
      </div>
      ${s.checked ? '' : html`<p class="hint">Пересчитайте товар на полке и введите факт. Каждое сканирование штрих-кода добавляет +1 к факту. При проведении сервер выставит остаток по факту.</p>`}`;
  }
  function auditListCard() {
    const list = auditList();
    return html`<div class="card-h"><span class="summary-line">${list.length} ${plural(list.length, ['позиция', 'позиции', 'позиций'])}</span>${list.length ? html`<button class="link" data-act="audFillAll">Факт = учёт для остальных</button>` : ''}</div>
      <div class="rows">${list.length ? list.map(audRow) : empty('audit', U.audDiff ? 'Расхождений нет' : 'Ничего не найдено')}</div>`;
  }
  function refreshAudit() {
    setHTML($('#aud-sum'), auditSummary());
    fx($('#aud-sum'));
    refreshBar();
  }
  SCREENS.audit = {
    title: 'Инвентаризация', keys: ['audits'], catalog: true,
    view() {
      const hist = get('audits', '/audits?limit=6');
      return html`
      <section class="card" id="aud-sum">${auditSummary()}</section>
      <div class="search-row">
        <label class="search"><span class="sr-only">Скан или поиск</span>${ic('scan')}<input class="input" id="aud-q" type="search" enterkeyhint="done" autocomplete="off" placeholder="Скан: факт +1 · или поиск" value="${U.audQ}" data-in="audQ" data-enter="audEnter"></label>
        <button class="icon-btn accent" data-act="scanAudit" aria-label="Сканировать камерой">${ic('camera')}</button>
      </div>
      <div class="chips">
        <button class="chip${U.audCat === 'all' ? ' on' : ''}" data-act="audCat" data-v="all">Все</button>
        ${CATS.map((c) => html`<button class="chip${U.audCat === c.id ? ' on' : ''}" data-act="audCat" data-v="${c.id}">${c.name}</button>`)}
        <button class="chip${U.audDiff ? ' warn-on' : ''}" data-act="audDiff" aria-pressed="${String(U.audDiff)}">Только расхождения</button>
      </div>
      <section class="card flush" id="aud-list">${auditListCard()}</section>
      <section class="card flush"><div class="card-h"><h2 class="sec-title">Проведённые инвентаризации</h2></div>
        <div class="rows">${hist ? (hist.audits.length ? hist.audits.map(auditRow) : empty('audit', 'Инвентаризаций ещё не было')) : loadBox('audits', 2)}</div>
      </section>`;
    },
    bar() {
      const n = Object.keys(L.auditDraft.counts).filter((id) => P(id)).length;
      if (!n) return '';
      return html`<div class="checkout-inner"><button class="btn" data-act="audReset">Сбросить</button><button class="btn btn-primary btn-lg" data-act="audPost">${ic('check')}Провести · ${n}</button></div>`;
    },
  };

  // Отчёты (всё считает сервер)
  function niceStep(x) {
    const p = Math.pow(10, Math.floor(Math.log10(x)));
    const f = x / p;
    return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * p;
  }
  const dayDate = (day) => new Date(day + 'T12:00:00');
  function seriesData(s) {
    const n = s.points.length;
    return s.points.map((pt) => {
      if (s.unit === 'hour') {
        const h = pt.key.slice(11, 13);
        return { v: pt.value, x: h, tip: `${h}:00–${h}:59` };
      }
      if (s.unit === 'day') {
        const d = dayDate(pt.key);
        return { v: pt.value, x: n <= 8 ? weekday(d) : String(d.getDate()), tip: `${fmtDate(d)}, ${weekday(d)}` };
      }
      const [y, m] = pt.key.split('-').map(Number);
      const d = new Date(y, m - 1, 1);
      return { v: pt.value, x: d.toLocaleDateString('ru-RU', { month: 'short' }).replace('.', ''), tip: d.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' }) };
    });
  }
  function chart(data) {
    const max = Math.max(0, ...data.map((d) => d.v));
    if (max <= 0) return empty('chart', 'За этот период продаж нет');
    const step = niceStep(max / 3);
    const top = Math.ceil(max / step) * step;
    const ticks = [];
    for (let v = 0; v <= top + step / 2; v += step) ticks.push(v);
    const maxI = data.findIndex((d) => d.v === max);
    const n = data.length;
    const every = Math.max(1, Math.ceil(n / 7));
    return html`<div class="chart">
      <div class="chart-plot">
        <div class="chart-grid" aria-hidden="true">${ticks.map((v) => html`<div style="bottom:${(v / top) * 100}%"><span>${v ? compact(v) : '0'}</span></div>`)}</div>
        <div class="chart-cols" role="list">${data.map((d, i) => {
          const h = (Math.max(0, d.v) / top) * 100;
          const pos = i < n * 0.2 ? ' l' : i > n * 0.8 ? ' r' : '';
          return html`<div class="col${d.v <= 0 ? ' zero' : ''}" role="listitem" tabindex="0" style="--h:${h}%" data-tip="${d.tip}: ${money(d.v)}" aria-label="${d.tip}: ${money(d.v)}"><i data-grow="col-${i}" data-prop="height" data-to="${h}" style="height:${h}%;--n:${i}"></i>${i === maxI ? html`<span class="col-label${pos}">${compact(d.v)}</span>` : ''}</div>`;
        })}</div>
        <div class="chart-tip" hidden></div>
      </div>
      <div class="chart-x" aria-hidden="true">${data.map((d, i) => html`<span>${(n - 1 - i) % every === 0 ? d.x : ''}</span>`)}</div>
    </div>`;
  }
  function hbars(rows, group) {
    const max = Math.max(1, ...rows.map((r) => r.v));
    return html`<div class="hbars">${rows.map((r, i) => html`<div class="hbar">
      <div class="hbar-top"><span class="hbar-name">${r.lead || ''}<span class="txt">${r.name}</span></span><b>${money(r.v)}${r.sub ? html` <span class="faint">· ${r.sub}</span>` : ''}</b></div>
      <div class="hbar-track"><i data-grow="${group}-${r.key}" data-to="${(Math.max(0, r.v) / max) * 100}" style="width:${(Math.max(0, r.v) / max) * 100}%;--n:${i}"></i></div>
    </div>`)}</div>`;
  }
  SCREENS.reports = {
    title: 'Отчёты', tab: true, keys: ['report:'],
    view() {
      const pi = Object.keys(PERIODS).indexOf(U.period);
      const seg = html`<div class="seg" role="tablist" aria-label="Период" data-seg="period" data-i="${pi}" style="--n:4;--i:${pi}"><span class="seg-pill" aria-hidden="true"></span>${Object.entries(PERIODS).map(([k, v]) => html`<button role="tab" aria-selected="${String(U.period === k)}" class="${U.period === k ? 'on' : ''}" data-act="period" data-v="${k}">${v}</button>`)}</div>`;
      const key = 'report:' + U.period;
      const r = get(key, '/reports?period=' + U.period);
      if (!r) return html`${seg}<section class="card">${loadBox(key, 4)}</section>`;
      const s = r.summary;
      const range = r.period === 'today' ? fmtDate(dayDate(r.to), true) : `${fmtDate(dayDate(r.from), r.from.slice(0, 4) !== r.to.slice(0, 4))} — ${fmtDate(dayDate(r.to), true)}`;
      const pays = r.by_payment.map((x) => ({ key: x.payment, name: PAY[x.payment].name, lead: ic(PAY[x.payment].icon, 'i-sm'), v: x.value, sub: x.share_pct + '%' }));
      const sellers = r.by_seller.map((x) => ({ key: x.employee_id || 0, name: x.name, v: x.value, sub: `${x.checks} ${plural(x.checks, ['чек', 'чека', 'чеков'])}` }));
      const top = r.top.map((x, i) => ({ key: x.product_id || x.name, name: x.name, lead: html`<span class="rank">${i + 1}</span>`, v: x.value, sub: pcs(x.qty) }));
      const w = r.warehouse;
      return html`
      ${seg}
      <p class="summary-line">${range}</p>
      <div class="kpis">
        <div class="kpi main"><span>Выручка</span><b>${sumVal(s.revenue, 'k-rev')}</b><small>продажи ${money(s.gross)}${s.refunds ? ` · возвраты −${money(s.refunds)} (${s.refunds_count})` : ' · возвратов нет'}</small></div>
        <div class="kpi"><span>Чеков</span><b>${cnt(s.checks, 'k-checks')}</b></div>
        <div class="kpi"><span>Продано единиц</span><b>${cnt(s.units, 'k-units')}</b></div>
        <div class="kpi"><span>Средний чек</span><b>${sumVal(s.avg, 'k-avg')}</b></div>
        <div class="kpi"><span>Валовая прибыль</span><b>${sumVal(s.profit, 'k-profit')}</b><small>${s.margin_pct != null ? 'маржа ' + s.margin_pct + '%' : ''}</small></div>
      </div>
      <section class="card"><div class="card-h"><h2 class="sec-title">${r.series.unit === 'hour' ? 'Выручка по часам' : r.series.unit === 'month' ? 'Выручка по месяцам' : 'Выручка по дням'}</h2><span class="sec-sub">с учётом возвратов</span></div>${chart(seriesData(r.series))}</section>
      <section class="card"><h2 class="sec-title">Способы оплаты</h2>${s.checks || s.refunds ? hbars(pays, 'pay') : empty('cash', 'Нет оплат за период')}</section>
      <section class="card"><h2 class="sec-title">Продавцы</h2>${sellers.length ? hbars(sellers, 'sel') : empty('users', 'Нет продаж за период')}</section>
      <section class="card"><div class="card-h"><h2 class="sec-title">Топ товаров</h2><span class="sec-sub">по выручке</span></div>${top.length ? hbars(top, 'top') : empty('box', 'Нет продаж за период')}</section>
      <section class="card flush"><div class="card-h"><h2 class="sec-title">Мало на складе</h2><span class="pill ${r.low_stock.length ? 'warn' : 'good'}">${r.low_stock.length}</span></div>
        <div class="rows">${r.low_stock.length ? r.low_stock.map((p) => html`<div class="row"><span class="row-main"><span class="row-title">${p.name}</span><span class="row-sub"><span>остаток ${p.stock} · минимум ${p.min_stock}</span></span></span><button class="btn btn-sm" data-act="incomeFor" data-id="${p.id}">${ic('income', 'i-sm')}Приход</button></div>`) : empty('check', 'Всех товаров достаточно')}</div>
      </section>
      <section class="card"><h2 class="sec-title">Склад сейчас</h2>
        <div class="stat-table">
          <span>Позиций в каталоге</span><b>${w.positions}</b>
          <span>Единиц на складе</span><b>${pcs(w.units)}</b>
          <span>Стоимость по закупке</span><b>${money(w.cost)}</b>
          <span>Стоимость в рознице</span><b>${money(w.retail)}</b>
          <span>Будущая наценка</span><b>${money(w.markup)}</b>
        </div>
      </section>`;
    },
  };

  // Журнал (выборку, поиск и итоги дней делает сервер)
  function jRow(e) {
    const who = shortName({ name: e.employee });
    let title, sub, side;
    if (e.type === 'sale') {
      title = 'Продажа №' + e.number;
      sub = [who, PAY[e.payment].name, e.customer];
      side = html`<span class="amt plus">+${num(e.total)}</span>`;
    } else if (e.type === 'ret') {
      title = 'Возврат ' + retNo(e);
      sub = ['чек №' + e.sale_number, who, e.reason];
      side = html`<span class="amt minus">−${num(e.total)}</span>`;
    } else if (e.type === 'inc') {
      title = 'Приход ' + incNo(e);
      sub = [e.supplier || 'без поставщика', who];
      side = html`<span class="amt">+${pcs(e.units)}</span><span class="faint">${money(e.total)}</span>`;
    } else {
      title = 'Инвентаризация ' + audNo(e);
      sub = [who, 'проверено ' + e.checked];
      side = html`<span class="amt">${auditDiffText(e)}</span>`;
    }
    return html`<button class="row" data-act="openDoc" data-type="${e.type}" data-id="${e.id}"><span class="row-ico ${e.type}">${ic(TYPE_ICON[e.type])}</span><span class="row-main"><span class="row-title">${title}</span><span class="row-sub">${[fmtTime(e.created_at), ...sub.filter(Boolean)].map((x) => html`<span>${x}</span>`)}</span></span><span class="row-side">${side}</span></button>`;
  }
  function journalData() {
    const key = `journal:${U.jType}:${U.jQ}:${U.jLimit}`;
    const fresh = get(key, `/journal?type=${U.jType}&limit=${U.jLimit}&q=${encodeURIComponent(U.jQ)}`);
    if (fresh) U.jLast = fresh;
    return { d: fresh || U.jLast, key, loading: !fresh };
  }
  function journalList() {
    const { d, key } = journalData();
    if (!d) return loadBox(key, 6);
    if (!d.entries.length) return empty('journal', 'Операций не найдено');
    const groups = [];
    for (const e of d.entries) {
      if (!groups.length || groups[groups.length - 1].day !== e.day) groups.push({ day: e.day, items: [] });
      groups[groups.length - 1].items.push(e);
    }
    return html`${groups.map((g) => {
      const withMoney = g.items.some((e) => e.type === 'sale' || e.type === 'ret');
      const net = d.days[g.day] ? d.days[g.day].net : 0;
      return html`<div class="day-h"><span>${dayLabel(dayDate(g.day))}</span>${withMoney ? html`<span>${signed(net)} сум</span>` : ''}</div>${g.items.map(jRow)}`;
    })}
    ${d.total > d.entries.length ? html`<div class="row"><button class="btn btn-block" data-act="jMore">Показать ещё · ${d.total - d.entries.length}</button></div>` : ''}`;
  }
  SCREENS.journal = {
    title: 'Журнал', keys: ['journal'],
    view() {
      const { d, loading } = journalData();
      const c = d ? d.counts : {};
      const types = [['all', 'Все'], ['sale', 'Продажи'], ['inc', 'Приходы'], ['ret', 'Возвраты'], ['aud', 'Инвентаризации']];
      return html`
      <label class="search"><span class="sr-only">Поиск по журналу</span>${ic('search')}<input class="input" id="j-q" type="search" enterkeyhint="search" autocomplete="off" placeholder="№ чека, покупатель, товар, сотрудник" value="${U.jQText}" data-in="jQ"></label>
      <div class="chips">${types.map(([k, l]) => html`<button class="chip${U.jType === k ? ' on' : ''}" data-act="jType" data-v="${k}">${l}${c[k] != null ? html` <span class="count">${c[k]}</span>` : ''}</button>`)}</div>
      <section class="card flush${loading && d ? ' is-loading' : ''}"><div class="rows" id="jlist">${journalList()}</div></section>`;
    },
  };

  // Сотрудники
  function empRow(e) {
    return html`<button class="row" data-act="openEmp" data-id="${e.id}">
      <span class="avatar lg ${e.role}">${initials(e.name)}</span>
      <span class="row-main"><span class="row-title">${e.name}</span>
        <span class="row-sub"><span class="pill ${e.role === 'owner' ? 'teal' : 'accent'}">${ROLES[e.role]}</span>${D.me && e.id === D.me.id ? html`<span class="pill good">на кассе</span>` : ''}${e.active ? '' : html`<span class="pill">отключён</span>`}</span>
        <span class="row-sub">Сегодня: ${e.today.checks} ${plural(e.today.checks, ['чек', 'чека', 'чеков'])} · ${money(e.today.sum)}</span></span>
      <span class="row-side"><span class="amt">${compact(e.month.sum)}</span><span class="faint">за 30 дней</span></span>
    </button>`;
  }
  SCREENS.staff = {
    title: 'Сотрудники', keys: ['staff'],
    view() {
      const d = get('staff', '/employees');
      const cur = D.me;
      return html`
      <section class="card">
        <div class="card-h"><span class="avatar lg ${cur.role}">${initials(cur.name)}</span><div class="row-main"><span class="label">Сейчас на кассе</span><span class="row-title">${cur.name}</span></div><button class="btn btn-sm" data-act="switchUser">${ic('swap', 'i-sm')}Сменить</button></div>
        <p class="hint">Сервер записывает каждую продажу, приход, возврат и инвентаризацию на сотрудника, который вошёл по PIN.</p>
      </section>
      <section class="card flush">
        <div class="card-h"><h2 class="sec-title">Команда</h2>${isOwner() ? html`<button class="btn btn-primary btn-sm" data-act="newEmp">${ic('plus', 'i-sm')}Добавить</button>` : ''}</div>
        <div class="rows">${d ? d.employees.map(empRow) : loadBox('staff', 4)}</div>
      </section>`;
    },
  };
  function openEmp(id) {
    const e = D.employees.find((x) => x.id === Number(id));
    openLoading(e ? e.name : 'Сотрудник', e ? ROLES[e.role] : '', () => api('GET', '/employees/' + id), (d) => {
      const x = d.employee;
      const stats = [['Сегодня', d.stats.today], ['7 дней', d.stats.week], ['30 дней', d.stats.month]];
      openSheet(html`${sheetHead(x.name, ROLES[x.role] + (x.active ? '' : ' · отключён'))}
        <div class="sheet-body">
          <div class="stat-table"><span>Телефон</span><b class="selectable">${x.phone || '—'}</b></div>
          <div class="kpis three">${stats.map(([l, c]) => html`<div class="kpi"><span>${l}</span><b>${compact(c.sum)}</b><small>${c.checks} ${plural(c.checks, ['чек', 'чека', 'чеков'])}</small></div>`)}</div>
          <div class="btn-row">
            ${x.active && D.me && x.id !== D.me.id ? html`<button class="btn btn-primary" data-act="switchTo" data-id="${x.id}">${ic('swap')}Встать на кассу</button>` : ''}
            ${isOwner() ? html`<button class="btn" data-act="editEmp" data-id="${x.id}">${ic('edit')}Изменить</button>` : ''}
          </div>
          <section class="card flush"><div class="card-h"><h3 class="sec-title">Последние продажи</h3></div>
            <div class="rows">${d.recent_sales.length ? d.recent_sales.map((s) => saleRow(s)) : empty('receipt', 'Продаж пока нет')}</div>
          </section>
          ${isOwner() && x.role !== 'owner' && D.me && x.id !== D.me.id ? html`<button class="btn${x.active ? ' btn-danger' : ''}" data-act="toggleEmp" data-id="${x.id}" data-active="${x.active ? 1 : 0}" data-name="${x.name}">${x.active ? 'Отключить сотрудника' : 'Вернуть в команду'}</button>` : ''}
        </div>`);
    });
  }
  function empForm(e) {
    const v = e || { name: '', role: 'seller', phone: '+998 ' };
    openSheet(html`${sheetHead(e ? 'Изменить сотрудника' : 'Новый сотрудник', e ? '' : 'Сотрудник входит в кассу по своему PIN')}
      <form class="sheet-body" data-submit="saveEmp" data-id="${e ? e.id : ''}" novalidate>
        <div class="field"><label for="ef-name">Имя и фамилия</label><input class="input" id="ef-name" name="name" maxlength="60" autocomplete="off" value="${v.name}" placeholder="Например, Мадина Алиева"></div>
        <div class="field"><label for="ef-role">Роль</label><select class="select" id="ef-role" name="role">${Object.entries(ROLES).map(([k, l]) => html`<option value="${k}"${raw(v.role === k ? ' selected' : '')}>${l}</option>`)}</select></div>
        <div class="field"><label for="ef-phone">Телефон</label><input class="input" id="ef-phone" name="phone" type="tel" maxlength="20" autocomplete="off" value="${v.phone || ''}"></div>
        <div class="field"><label for="ef-pin">${e ? 'Новый PIN (если нужно сменить)' : 'PIN для входа, 4–6 цифр'}</label><input class="input" id="ef-pin" name="pin" type="password" inputmode="numeric" maxlength="6" autocomplete="new-password"></div>
        <p class="err" id="ef-err" role="alert"></p>
        <button class="btn btn-primary btn-lg" type="submit">${e ? 'Сохранить' : 'Добавить сотрудника'}</button>
      </form>`);
  }

  // Ещё
  SCREENS.more = {
    title: 'Ещё', tab: true,
    view() {
      return html`
      <div class="menu-grid">${MORE.map(([to, label, icon]) => html`<button class="menu-btn" data-act="nav" data-to="${to}"><span class="qa-ico">${ic(icon)}</span><span>${label}</span></button>`)}</div>
      <section class="card">
        <div class="card-h"><span class="avatar lg ${D.me.role}">${initials(D.me.name)}</span><div class="row-main"><span class="label">Вы вошли как</span><span class="row-title">${D.me.name} · ${ROLES[D.me.role]}</span></div></div>
        <div class="btn-row"><button class="btn" data-act="switchUser">${ic('swap')}Сменить сотрудника</button><button class="btn" data-act="logout">${ic('logout')}Выйти</button></div>
      </section>
      ${isOwner() ? html`<section class="card">
        <div class="card-h"><h2 class="sec-title">Магазин</h2><span class="sec-sub">печатается в чеке</span></div>
        <form class="stack" data-submit="saveStore">
          <div class="field"><label for="st-name">Название</label><input class="input" id="st-name" name="name" maxlength="60" autocomplete="off" value="${D.store.name}" data-keep></div>
          <div class="field"><label for="st-addr">Адрес</label><input class="input" id="st-addr" name="address" maxlength="120" autocomplete="off" value="${D.store.address || ''}" data-keep></div>
          <button class="btn" type="submit">Сохранить</button>
        </form>
      </section>` : ''}
      <section class="card">
        <div class="card-h"><h2 class="sec-title">Данные</h2>${D.store.is_demo ? html`<span class="pill warn">демо-данные</span>` : html`<span class="pill good">${ic('db')}на сервере</span>`}</div>
        <p class="sec-sub">Товары, чеки, приходы, возвраты и отчёты хранятся на сервере магазина и считаются там же — их видно с любого телефона после входа. На этом устройстве хранятся только черновики: корзина, незаконченный приход и пересчёт.</p>
        ${isOwner() && D.features.demo ? html`${D.store.is_demo ? html`<p class="sec-sub">Сейчас загружен пример магазина. Чтобы вести свой учёт, начните с чистого листа — сотрудники и название магазина сохранятся.</p>` : ''}
        <div class="btn-row"><button class="btn" data-act="resetDemo">Загрузить демо заново</button><button class="btn btn-danger" data-act="wipeData">Начать с чистого листа</button></div>` : ''}
      </section>`;
    },
  };

  // Сканер
  function scanResult(p) {
    return html`<section class="card">
      <div class="card-h"><div><h2 class="sec-title">${p.name}</h2><div class="sec-sub">${catOf(p).name}</div></div>${stockPill(p)}</div>
      <div class="barcode">${barcodeSVG(p.barcode)}</div>
      <div class="stat-table"><span>Остаток</span><b>${pcs(p.stock)}</b><span>Розничная цена</span><b>${money(p.sell_price)}</b><span>Закупочная цена</span><b>${money(p.buy_price)}</b></div>
      <div class="btn-row">
        <button class="btn btn-primary" data-act="sellNow" data-id="${p.id}"${raw(p.stock <= 0 ? ' disabled' : '')}>${ic('cart')}Продать</button>
        <button class="btn" data-act="incomeFor" data-id="${p.id}">${ic('income')}Приход</button>
        <button class="btn" data-act="openProduct" data-id="${p.id}">${ic('box')}Карточка</button>
      </div>
    </section>`;
  }
  SCREENS.scanner = {
    title: 'Сканер', catalog: true,
    view() {
      const code = U.scanCode;
      const p = code && byCode(code);
      return html`
      <button class="scan-hero" data-act="scanLookupCam"><span class="qa-ico">${ic('scan')}</span><b>Сканировать камерой</b><span class="sec-sub">Наведите камеру телефона на штрих-код товара</span></button>
      <section class="card">
        <div class="field"><label for="scan-code">Или введите код вручную</label>
          <div class="search-row"><input class="input mono" id="scan-code" inputmode="numeric" enterkeyhint="search" autocomplete="off" placeholder="${D.products[0] ? D.products[0].barcode : '4780000000000'}" value="${code}" data-enter="scanLookup" data-keep><button class="btn btn-primary" data-act="scanLookup">Найти</button></div>
          <span class="hint">USB- и Bluetooth-сканеры работают как клавиатура: сканируйте прямо в это поле.</span>
        </div>
      </section>
      ${code ? (p ? scanResult(p) : html`<section class="card">
        <div class="card-h"><h2 class="sec-title">Товар не найден</h2><span class="mono sec-sub">${code}</span></div>
        <p class="sec-sub">Такого штрих-кода нет в каталоге.</p>
        ${validEan(code) ? html`<button class="btn btn-primary" data-act="newProductCode" data-code="${code}">${ic('plus')}Создать товар с этим кодом</button>` : html`<p class="hint">Код не похож на EAN-13 — проверьте цифры.</p>`}
      </section>`) : ''}`;
    },
  };

  // ---------------------------------------------------------------- Документы (листы)
  function openReceipt(saleOrId, fresh) {
    if (typeof saleOrId !== 'object') {
      openLoading('Чек', '', () => api('GET', '/sales/' + saleOrId).then((r) => r.sale), (s) => openReceipt(s, false));
      return;
    }
    const s = saleOrId;
    const canReturn = s.items.some((i) => i.returnable > 0);
    const head = fresh
      ? html`<div class="sheet-h"><div class="done"><svg class="ok-check" viewBox="0 0 52 52" aria-hidden="true"><circle cx="26" cy="26" r="23"/><path d="m15 27 7 7 15-15"/></svg><div><h2>Чек пробит</h2><div class="sec-sub">№${s.number} · ${money(s.total)} · ${PAY[s.payment].name}</div></div></div><button class="icon-btn" data-act="closeSheet" aria-label="Закрыть">${ic('x')}</button></div>`
      : sheetHead('Чек №' + s.number, fmtDT(s.created_at));
    const paper = html`
          <div class="rc-center"><div class="rc-store">${D.store.name}</div>${D.store.address ? html`<div class="rc-muted">${D.store.address}</div>` : ''}</div>
          <hr class="rc-hr">
          <div class="rc-row"><span>ЧЕК №${s.number}</span><span>${fmtDT(s.created_at)}</span></div>
          <div class="rc-row"><span>Кассир</span><span>${nameOf(s.employee)}</span></div>
          ${s.customer ? html`<div class="rc-row"><span>Покупатель</span><span>${s.customer}</span></div>` : ''}
          <hr class="rc-hr">
          ${s.items.map((i) => html`<div class="rc-item"><div>${i.name}</div><div class="rc-row"><span class="rc-muted">${i.qty} × ${num(i.price)}</span><span>${num(i.sum)}</span></div></div>`)}
          <hr class="rc-hr">
          <div class="rc-row rc-total"><span>ИТОГО</span><span>${num(s.total)}</span></div>
          <div class="rc-row"><span>Оплата</span><span>${PAY[s.payment].name}</span></div>
          ${s.payment === 'cash' && s.change > 0 ? html`<div class="rc-row"><span>Получено</span><span>${num(s.received)}</span></div><div class="rc-row"><span>Сдача</span><span>${num(s.change)}</span></div>` : ''}
          ${s.refunds.length ? html`<hr class="rc-hr">${s.refunds.map((r) => html`<div class="rc-row rc-ret"><span>Возврат ${retNo(r)}, ${fmtDate(r.created_at)}</span><span>−${num(r.total)}</span></div>`)}` : ''}
          <hr class="rc-hr">
          <div class="rc-center rc-muted">Суммы в узбекских сумах (UZS)<br>Спасибо за покупку!</div>`;
    openSheet(html`${head}
      <div class="sheet-body">
        ${fresh
          ? html`<div class="printer"><div class="printer-slot" aria-hidden="true"></div><div class="paper-feed"><div class="receipt printing">${paper}</div></div></div>`
          : html`<div class="receipt">${paper}</div>`}
        <div class="btn-row">${fresh
          ? html`<button class="btn btn-primary btn-lg" data-act="closeSheet">${ic('plus')}Новая продажа</button>`
          : html`${canReturn ? html`<button class="btn" data-act="refundFor" data-id="${s.id}">${ic('refund')}Оформить возврат</button>` : ''}<button class="btn btn-primary" data-act="closeSheet">Закрыть</button>`}</div>
      </div>`);
  }
  function openReturnDoc(rOrId) {
    if (typeof rOrId !== 'object') {
      openLoading('Возврат', '', () => api('GET', '/returns/' + rOrId).then((x) => x.return), openReturnDoc);
      return;
    }
    const r = rOrId;
    openSheet(html`${sheetHead('Возврат ' + retNo(r), fmtDT(r.created_at))}
      <div class="sheet-body">
        <div class="stat-table">
          <span>По чеку</span><b><button class="link" data-act="openSale" data-id="${r.sale_id}">№${r.sale_number}</button></b>
          <span>Оформил</span><b>${nameOf(r.employee)}</b>
          <span>Причина</span><b>${r.reason}</b>
          <span>Деньги вернули</span><b>${PAY[r.payment].name}</b>
        </div>
        <section class="card flush"><div class="rows">${r.items.map((i) => html`<div class="row"><span class="row-main"><span class="row-title">${i.name}</span><span class="row-sub"><span>${i.qty} × ${num(i.price)}</span><span>вернулся на склад</span></span></span><span class="amt minus">−${num(i.sum)}</span></div>`)}</div></section>
        <div class="totals-row big"><span>Сумма возврата</span><span class="num bad">−${money(r.total)}</span></div>
        <button class="btn" data-act="closeSheet">Закрыть</button>
      </div>`);
  }
  function openIncomeDoc(dOrId) {
    if (typeof dOrId !== 'object') {
      openLoading('Приход', '', () => api('GET', '/incomes/' + dOrId).then((x) => x.income), openIncomeDoc);
      return;
    }
    const d = dOrId;
    openSheet(html`${sheetHead('Приход ' + incNo(d), fmtDT(d.created_at))}
      <div class="sheet-body">
        <div class="stat-table"><span>Поставщик</span><b>${d.supplier || '—'}</b><span>Принял</span><b>${nameOf(d.employee)}</b><span>Товаров</span><b>${pcs(d.units)}</b></div>
        <section class="card flush"><div class="rows">${d.items.map((i) => html`<button class="row" data-act="openProduct" data-id="${i.product_id || ''}"${raw(i.product_id ? '' : ' disabled')}><span class="row-main"><span class="row-title">${i.name}</span><span class="row-sub"><span>${i.qty} шт × ${num(i.cost)}</span></span></span><span class="amt">${num(i.sum)}</span></button>`)}</div></section>
        <div class="totals-row big"><span>Сумма закупки</span><span class="num">${money(d.total)}</span></div>
        <button class="btn" data-act="closeSheet">Закрыть</button>
      </div>`);
  }
  function openAuditDoc(aOrId) {
    if (typeof aOrId !== 'object') {
      openLoading('Инвентаризация', '', () => api('GET', '/audits/' + aOrId).then((x) => x.audit), openAuditDoc);
      return;
    }
    const a = aOrId;
    const diffs = a.items.filter((i) => i.diff !== 0);
    openSheet(html`${sheetHead('Инвентаризация ' + audNo(a), fmtDT(a.created_at))}
      <div class="sheet-body">
        <div class="stat-table">
          <span>Провёл</span><b>${nameOf(a.employee)}</b>
          <span>Проверено позиций</span><b>${a.checked}</b>
          <span>Совпало</span><b>${a.matched}</b>
          <span>Недостача</span><b class="${a.shortage_sum ? 'bad' : ''}">${a.shortage_sum ? '−' + money(a.shortage_sum) : '—'}</b>
          <span>Излишек</span><b class="${a.surplus_sum ? 'good' : ''}">${a.surplus_sum ? '+' + money(a.surplus_sum) : '—'}</b>
        </div>
        <section class="card flush"><div class="card-h"><h3 class="sec-title">Расхождения</h3></div>
          <div class="rows">${diffs.length ? diffs.map((i) => html`<div class="row"><span class="row-main"><span class="row-title">${i.name}</span><span class="row-sub"><span>учёт ${i.expected} → факт ${i.actual}</span></span></span><span class="amt ${i.diff > 0 ? 'plus' : 'minus'}">${signed(i.diff)} шт</span></div>`) : empty('check', 'Все остатки совпали с учётом')}</div>
        </section>
        <button class="btn" data-act="closeSheet">Закрыть</button>
      </div>`);
  }

  // ---------------------------------------------------------------- Вход по PIN и первая настройка
  function empTile(e) {
    return html`<button class="emp-tile${U.pin.pick === e.id ? ' on' : ''}" data-act="pinPick" data-id="${e.id}"><span class="avatar lg ${e.role}">${initials(e.name)}</span><span class="emp-name">${e.name}</span><span class="emp-role">${ROLES[e.role]}</span></button>`;
  }
  function pinPad() {
    const st = U.pin;
    const n = Math.max(4, st.pin.length);
    return html`<div class="pin">
      <div class="pin-dots" id="pin-dots" aria-label="Введено цифр: ${st.pin.length}">${Array.from({ length: n }, (_, i) => html`<i class="${i < st.pin.length ? 'on' : ''}"></i>`)}</div>
      <p class="err center" role="alert">${st.err || ''}</p>
      <div class="pin-pad">${['1', '2', '3', '4', '5', '6', '7', '8', '9'].map((d) => html`<button data-act="pinKey" data-v="${d}">${d}</button>`)}<button data-act="pinKey" data-v="back" aria-label="Стереть">${ic('backspace')}</button><button data-act="pinKey" data-v="0">0</button><button class="pin-ok${U.busy ? ' busy' : ''}" data-act="pinSubmit" aria-label="Войти"${raw(st.pin.length < 4 || U.busy ? ' disabled' : '')}>${ic('check')}</button></div>
    </div>`;
  }
  function refreshPin() {
    document.querySelectorAll('.emp-tile').forEach((t) => t.classList.toggle('on', Number(t.dataset.id) === U.pin.pick));
    setHTML($('#pin-area'), U.pin.pick ? pinPad() : '');
  }
  async function pinSubmit() {
    const st = U.pin;
    if (!st.pick || st.pin.length < 4 || U.busy) return;
    U.busy = true;
    refreshPin();
    try {
      const r = await api('POST', '/login', { employee_id: st.pick, pin: st.pin });
      const old = TOKEN;
      setToken(r.token);
      if (old && old !== r.token) {
        fetch(API + '/logout', { method: 'POST', headers: { Accept: 'application/json', Authorization: 'Bearer ' + old } }).catch(() => {});
      }
      U.busy = false;
      await startSession();
      toast(`На кассе: ${D.me.name}`, 'ok');
    } catch (e) {
      U.busy = false;
      st.pin = '';
      st.err = e.message;
      refreshPin();
      shake($('#pin-dots'));
    }
  }
  function openSwitch(pick) {
    U.pin = { pick: pick || null, pin: '', err: '' };
    openSheet(html`${sheetHead('Кто на кассе?', 'Выберите сотрудника и введите его PIN')}
      <div class="sheet-body"><div class="emp-grid">${D.employees.filter((e) => e.active).map(empTile)}</div><div id="pin-area">${U.pin.pick ? pinPad() : ''}</div></div>`);
  }
  async function startSession() {
    cache.clear();
    const b = await api('GET', '/bootstrap');
    applyBootstrap(b);
    U.auth = null;
    U.pin = { pick: null, pin: '', err: '' };
    U.seenSale = undefined;
    U.refSale = null;
    U.jLast = null;
    closeSheet();
    lastRoute = null;
    render();
  }
  function signOut(msg) {
    setToken(null);
    D.me = null;
    cache.clear();
    U.pin = { pick: null, pin: '', err: '' };
    if (!$('#scan').hidden) Scan.close();
    closeSheet();
    showAuth(msg);
  }
  async function showAuth(msg) {
    U.auth = { msg };
    render();
    try {
      const o = await api('GET', '/options');
      if (D.me) return;
      U.auth = { options: o, msg };
    } catch (e) {
      U.auth = { error: e.message, msg };
    }
    U.enter = true;
    render();
  }
  function loginView(o) {
    return html`<section class="auth">
      <div class="auth-head"><span class="auth-logo">${ic('store', 'i-lg')}</span><div><h2>${o.store.name}</h2><p class="sec-sub">Выберите себя и введите PIN</p></div></div>
      ${U.auth.msg ? html`<p class="auth-msg">${U.auth.msg}</p>` : ''}
      <div class="emp-grid">${o.employees.map(empTile)}</div>
      <div id="pin-area">${U.pin.pick ? pinPad() : ''}</div>
      ${o.demo_hint ? html`<p class="hint center">${o.demo_hint}</p>` : ''}
    </section>`;
  }
  // Простые PIN отклоняет сервер; здесь та же проверка, чтобы подсказать сразу.
  const COMMON_PINS = ['1212', '1122', '2580', '0852', '6969', '1004', '2000', '1010', '2001', '1313', '7777777', '121212', '112233', '123123', '654321', '696969'];
  function pinProblem(pin) {
    if (!/^\d{4,6}$/.test(pin)) return 'PIN — от 4 до 6 цифр';
    const d = pin.split('').map(Number);
    const same = d.every((x) => x === d[0]);
    const up = d.every((x, i) => i === 0 || x - d[i - 1] === 1);
    const down = d.every((x, i) => i === 0 || x - d[i - 1] === -1);
    if (same || up || down || COMMON_PINS.includes(pin)) return 'PIN слишком простой: не используйте одинаковые цифры, цифры подряд (1234, 4321) и частые комбинации';
    return '';
  }
  function setupView(o) {
    if (!o.setup_ready) {
      return html`<section class="auth">
        <div class="auth-head"><span class="auth-logo">${ic('store', 'i-lg')}</span><div><h2>Установка закрыта</h2><p class="sec-sub">Касса ещё не настроена, но мастер первого запуска на этом сервере выключен.</p></div></div>
        <section class="card"><p class="sec-sub">Администратору: задайте код установки в файле <span class="mono">.env</span> на сервере — строку <span class="mono">KASSA_SETUP_CODE=…</span> — и обновите эту страницу. Код понадобится один раз, при создании магазина.</p><button class="btn" data-act="authRetry">Обновить</button></section>
      </section>`;
    }
    return html`<section class="auth">
      <div class="auth-head"><span class="auth-logo">${ic('store', 'i-lg')}</span><div><h2>Первая настройка</h2><p class="sec-sub">Создайте магазин и владельца. Продавцов владелец добавит потом.</p></div></div>
      <form class="card" data-submit="setup" novalidate>
        ${o.setup_code_required ? html`<div class="field"><label for="su-code">Код установки</label><input class="input mono" id="su-code" name="setup_code" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="KASSA_SETUP_CODE из .env"><span class="hint">Его задаёт администратор в файле .env на сервере. Нужен один раз.</span></div>` : ''}
        <div class="field"><label for="su-store">Название магазина</label><input class="input" id="su-store" name="store_name" maxlength="60" autocomplete="off" placeholder="Например, Mobi Aksessuar"></div>
        <div class="field"><label for="su-owner">Имя владельца</label><input class="input" id="su-owner" name="owner_name" maxlength="60" autocomplete="off" placeholder="Имя и фамилия"></div>
        <div class="field-grid">
          <div class="field"><label for="su-pin">Ваш PIN, 4–6 цифр</label><input class="input" id="su-pin" name="pin" type="password" inputmode="numeric" maxlength="6" autocomplete="new-password"></div>
          <div class="field"><label for="su-pin2">PIN ещё раз</label><input class="input" id="su-pin2" name="pin2" type="password" inputmode="numeric" maxlength="6" autocomplete="new-password"></div>
        </div>
        <p class="hint">Придумайте свой PIN: не 1111, не 1234 и не год рождения. Им вы будете входить в кассу и подтверждать действия владельца.</p>
        <p class="err" id="su-err" role="alert"></p>
        <button class="btn btn-primary btn-lg" type="submit">Создать магазин</button>
      </form>
      ${o.demo_available ? html`<section class="card"><p class="sec-sub">Тестовый сервер: можно загрузить пример магазина с товарами и продажами за месяц.</p><button class="btn" data-act="setupDemo">${ic('db')}Загрузить демо-магазин</button></section>` : ''}
    </section>`;
  }
  function renderAuth() {
    const a = U.auth || {};
    document.body.classList.add('auth');
    $('#who').hidden = true;
    $('#back').hidden = true;
    setHTML($('#tabs'), '');
    setHTML($('#bar'), '');
    $('#bar').hidden = true;
    $('.app').classList.remove('has-bar');
    document.body.classList.remove('with-bar');
    $('#eyebrow').textContent = 'Касса и склад';
    let view;
    if (a.error) {
      $('#title').textContent = 'Нет связи';
      view = html`<section class="auth"><div class="card">${empty('alert', a.error)}<button class="btn btn-primary" data-act="authRetry">Попробовать снова</button></div></section>`;
    } else if (!a.options) {
      $('#title').textContent = 'Касса и склад';
      view = html`<section class="auth"><div class="card">${skeleton(3)}<p class="hint center">Подключаемся к серверу…</p></div></section>`;
    } else if (a.options.needs_setup) {
      $('#title').textContent = 'Первая настройка';
      view = setupView(a.options);
    } else {
      $('#title').textContent = 'Вход';
      view = loginView(a.options);
    }
    setHTML($('#view'), view);
    if (U.enter) enterAnim($('#view'));
    U.enter = false;
  }

  // ---------------------------------------------------------------- Навигация и отрисовка
  const route = () => {
    const h = location.hash.replace(/^#/, '');
    return SCREENS[h] ? h : 'home';
  };
  function go(r) {
    if (route() === r && location.hash) render();
    else location.hash = r;
  }
  function refreshBar() {
    const scr = SCREENS[route()];
    const bar = D.me && scr.bar ? scr.bar() : '';
    const el = $('#bar');
    const wasHidden = el.hidden;
    setHTML(el, bar);
    el.hidden = !bar;
    document.body.classList.toggle('with-bar', !!bar);
    $('.app').classList.toggle('has-bar', !!bar);
    if (!bar) memo.delete('cart-total');
    else if (wasHidden && !reduceMotion()) restartAnim(el, 'bar-in');
    fx(el);
  }
  let lastRoute = null;
  let lastTab = null;
  let lastBadge = 0;
  function render() {
    if (!D.me) { renderAuth(); return; }
    document.body.classList.remove('auth');
    $('#who').hidden = false;
    const r = route();
    const scr = SCREENS[r];
    const entering = r !== lastRoute;
    const first = lastRoute === null;
    if (entering) {
      if (lastRoute === 'scanner') U.scanCode = '';
      lastRoute = r;
      window.scrollTo(0, 0);
      (scr.keys || []).forEach((k) => invalidate(k));
      if (scr.catalog) refreshCatalog();
    }
    // при обновлении данных с сервера сохраняем фокус и курсор в поле ввода
    const active = document.activeElement;
    // поле в фокусе находим заново по id или по data-in + data-id (строки корзины, прихода, пересчёта)
    let keepSel = null;
    if (active && active !== document.body && $('#view').contains(active)) {
      if (active.id) keepSel = '#' + active.id;
      else if (active.dataset && active.dataset.in) keepSel = `[data-in="${active.dataset.in}"]` + (active.dataset.id ? `[data-id="${active.dataset.id}"]` : '');
      else if (active.dataset && active.dataset.ch) keepSel = `[data-ch="${active.dataset.ch}"]` + (active.dataset.id ? `[data-id="${active.dataset.id}"]` : '');
    }
    const keepId = keepSel;
    let caret = null;
    try { if (keepId && active.selectionStart != null) caret = [active.selectionStart, active.selectionEnd]; } catch (e) { caret = null; }
    // поля, не связанные с состоянием (номер чека, код, настройки магазина), переживают фоновое обновление
    const kept = entering ? [] : Array.from(document.querySelectorAll('#view [data-keep][id]')).map((el) => [el.id, el.value]);

    $('#title').textContent = scr.title;
    if (entering && !first && !reduceMotion()) restartAnim($('#title'), 'title-in');
    $('#eyebrow').textContent = D.store.name + (D.store.is_demo ? ' · демо' : '');
    $('#back').hidden = !!scr.tab;
    const e = D.me;
    setHTML($('#who'), html`<span class="avatar ${e.role}">${initials(e.name)}</span><span class="who-text"><span class="who-name">${e.name.split(/\s+/)[0]}</span><span class="who-role">${ROLES[e.role]}</span></span>`);
    const curTab = scr.tab ? r : 'more';
    const n = cartUnits();
    const badgePop = !first && n > 0 && n !== lastBadge;
    const bounce = !first && curTab !== lastTab;
    setHTML($('#tabs'), TABS.map(([id, label, icon]) => html`<button class="tab${curTab === id ? ' on' : ''}${curTab === id && bounce ? ' bounce' : ''}" data-act="nav" data-to="${id}" aria-current="${curTab === id ? 'page' : 'false'}">${ic(icon)}<span>${label}</span>${id === 'sale' && n ? html`<span class="tab-badge${badgePop ? ' pop' : ''}">${n}</span>` : ''}</button>`));
    $('#tab-ind').style.setProperty('--i', TABS.findIndex(([id]) => id === curTab));
    lastBadge = n;
    lastTab = curTab;
    setHTML($('#view'), scr.view());
    kept.forEach(([id, value]) => { const el = document.getElementById(id); if (el) el.value = value; });
    U.flash = null;
    U.flashNew = false;
    if (entering || U.enter) enterAnim($('#view'));
    U.enter = false;
    fx($('#view'));
    refreshBar();
    if (U.pop) {
      const c = $(U.pop);
      U.pop = null;
      if (c && !reduceMotion()) restartAnim(c, 'pop');
    }
    if (U.focus) {
      const f = $('#' + U.focus);
      U.focus = null;
      if (f) f.focus();
    } else if (keepId && !entering) {
      const f = $('#view').querySelector(keepId);
      if (f) {
        f.focus();
        try { if (caret) f.setSelectionRange(caret[0], caret[1]); } catch (err) { /* поле без курсора */ }
      }
    }
  }

  function showTip(col) {
    const plot = col.closest('.chart-plot');
    const tip = plot && $('.chart-tip', plot);
    if (!tip) return;
    plot.querySelectorAll('.col.on').forEach((c) => c.classList.remove('on'));
    col.classList.add('on');
    tip.textContent = col.dataset.tip;
    tip.hidden = false;
    const pr = plot.getBoundingClientRect(), cr = col.getBoundingClientRect();
    const w = tip.offsetWidth;
    let x = cr.left + cr.width / 2 - pr.left;
    x = Math.max(w / 2 - 44, Math.min(pr.width - w / 2, x));
    tip.style.left = x + 'px';
  }
  function hideTip(col) {
    const plot = col.closest('.chart-plot');
    const tip = plot && $('.chart-tip', plot);
    col.classList.remove('on');
    if (tip) tip.hidden = true;
  }

  // ---------------------------------------------------------------- Обработчики
  const lookupPrompt = (code, after) => confirmBox({
    title: 'Товар не найден',
    text: `Штрих-кода ${code} нет в каталоге. Создать новый товар с этим кодом?`,
    ok: 'Создать товар',
    onOk: () => productForm(null, { barcode: code, after }),
  });

  const ACT = {
    nav(el) {
      const to = el.dataset.to;
      if (!SCREENS[to].tab) U.backTo = route();
      closeSheet();
      go(to);
    },
    back() { go(U.backTo && U.backTo !== route() ? U.backTo : 'home'); },
    closeSheet,
    dialogCancel: closeDialog,
    dialogOk() { const f = dialogOk; closeDialog(); if (f) f(); },
    retry(el) {
      const c = cache.get(el.dataset.key);
      if (c) { c.error = null; c.stale = true; }
      render();
    },
    lowStock() {
      U.prodLow = true;
      U.prodCat = 'all';
      U.prodQ = '';
      go('products');
    },

    // вход и сотрудники
    authRetry() { if (TOKEN) { U.auth = { msg: '' }; render(); startSession().catch((e) => { if (e.status !== 401) { U.auth = { error: e.message }; render(); } }); } else showAuth(); },
    pinPick(el) {
      U.pin = { pick: Number(el.dataset.id), pin: '', err: '' };
      refreshPin();
      const area = $('#pin-area');
      if (area && area.scrollIntoView) area.scrollIntoView({ block: 'nearest', behavior: reduceMotion() ? 'auto' : 'smooth' });
    },
    pinKey(el) {
      const st = U.pin;
      if (U.busy) return;
      const v = el.dataset.v;
      if (v === 'back') st.pin = st.pin.slice(0, -1);
      else if (st.pin.length < 6) st.pin += v;
      st.err = '';
      refreshPin();
    },
    pinSubmit,
    setupDemo(el) {
      run(el, async () => {
        const code = $('#su-code');
        const r = await api('POST', '/setup', { demo: true, setup_code: code ? code.value.trim() : null });
        setToken(r.token);
        await startSession();
        toast('Демо-магазин загружен. PIN владельца — 1111', 'ok');
      });
    },
    switchUser() { openSwitch(); },
    switchTo(el) { openSwitch(Number(el.dataset.id)); },
    logout() {
      confirmBox({
        title: 'Выйти из кассы?', text: 'Корзина и черновики на этом устройстве сохранятся.', ok: 'Выйти',
        onOk() {
          api('POST', '/logout').catch(() => {});
          signOut('');
        },
      });
    },
    openSale(el) { openReceipt(el.dataset.id); },
    openReturn(el) { openReturnDoc(el.dataset.id); },
    openIncome(el) { openIncomeDoc(el.dataset.id); },
    openAudit(el) { openAuditDoc(el.dataset.id); },
    openProduct(el) { if (el.dataset.id) openProduct(el.dataset.id); },
    openEmp(el) { openEmp(el.dataset.id); },
    openDoc(el) {
      ({ sale: openReceipt, ret: openReturnDoc, inc: openIncomeDoc, aud: openAuditDoc })[el.dataset.type](el.dataset.id);
    },

    // касса
    addCart(el) {
      const from = el.getBoundingClientRect();
      const r = addToCart(el.dataset.id);
      if (!r.ok) { toast(r.msg, 'bad'); shake(el); return; }
      if (el.classList.contains('sug')) U.saleQ = '';
      render();
      flyTo(from, $('#bar .checkout-sum') || $('.tab[data-to="sale"] .i'));
    },
    cartQty(el) {
      const line = L.cart.items.find((l) => l.pid === Number(el.dataset.id));
      if (line) setCartQty(line.pid, line.qty + Number(el.dataset.d));
    },
    rmCart(el) { setCartQty(el.dataset.id, 0); },
    clearCart() {
      confirmBox({ title: 'Очистить корзину?', text: 'Все товары будут убраны из текущего чека.', ok: 'Очистить', danger: true, onOk() { L.cart = freshCart(L.cart.payment); saveLocal(); render(); } });
    },
    pay(el) { L.cart.payment = el.dataset.v; saveLocal(); render(); },
    checkout(el) { checkout(el); },
    scanSale() {
      Scan.open({
        title: 'Сканирование в чек', continuous: true,
        onCode(code) {
          const p = byCode(code);
          if (!p) return { ok: false, msg: `Не найден: ${code}` };
          const r = addToCart(p.id);
          if (r.ok) render();
          return r;
        },
      });
    },

    // товары
    prodCat(el) { U.prodCat = el.dataset.v; withVT(render); },
    prodLow() { U.prodLow = !U.prodLow; withVT(render); },
    newProduct() { productForm(null); },
    editProduct(el) { const p = P(el.dataset.id); if (p) productForm(p); },
    deleteProduct(el) {
      const p = P(el.dataset.id);
      if (!p) return;
      confirmBox({ title: 'Удалить товар?', text: `«${p.name}» исчезнет из каталога. Чеки и документы с ним останутся в журнале.`, ok: 'Удалить', danger: true, onOk: () => deleteProduct(p.id) });
    },
    productToCart(el) {
      const r = addToCart(el.dataset.id);
      if (!r.ok) { toast(r.msg, 'bad'); return; }
      closeSheet();
      go('sale');
      toast(`В корзине: ${r.p.name}`, 'ok');
    },
    sellNow(el) { ACT.productToCart(el); },
    incomeFor(el) {
      const p = addIncomeLine(el.dataset.id);
      if (!p) return;
      closeSheet();
      if (route() !== 'income') U.backTo = route();
      go('income');
      toast(`В приходе: ${p.name}`, 'ok');
    },
    scanProducts() {
      Scan.open({
        title: 'Найти товар',
        onCode(code) {
          const p = byCode(code);
          if (p) openProduct(p.id);
          else lookupPrompt(code);
        },
      });
    },
    scanField() {
      Scan.open({
        title: 'Штрих-код товара',
        onCode(code) {
          const inp = $('#pf-code');
          if (inp) { inp.value = code; codeHint(); }
        },
      });
    },
    genCode(el) {
      run(el, async () => {
        const r = await api('GET', '/products/barcode');
        const inp = $('#pf-code');
        if (inp) { inp.value = r.barcode; codeHint(); }
      });
    },

    // приход
    incAdd(el) {
      addIncomeLine(el.dataset.id);
      U.incQ = '';
      render();
    },
    incRm(el) {
      L.incomeDraft.lines = L.incomeDraft.lines.filter((l) => l.pid !== Number(el.dataset.id));
      saveLocal();
      render();
    },
    incClear() {
      confirmBox({ title: 'Очистить приход?', text: 'Все строки текущего прихода будут удалены.', ok: 'Очистить', danger: true, onOk() { L.incomeDraft.lines = []; saveLocal(); render(); } });
    },
    newProductIncome() { productForm(null, { after: 'income', name: U.incQ }); },
    postIncome(el) { postIncome(el); },
    scanIncome() {
      Scan.open({
        title: 'Сканирование прихода', continuous: true,
        onCode(code) {
          const p = byCode(code);
          if (!p) return { ok: false, msg: `Не найден: ${code}. Создайте товар в приходе.` };
          addIncomeLine(p.id);
          render();
          const l = L.incomeDraft.lines.find((x) => x.pid === p.id);
          return { ok: true, msg: `${p.name}: ${l.qty} шт` };
        },
      });
    },

    // возврат
    refFind(el) {
      const inp = $('#ref-no');
      const n = parseQty(inp && inp.value);
      if (n == null) { toast('Введите номер чека', 'bad'); return; }
      run(el && el.tagName === 'BUTTON' ? el : null, async () => {
        const r = await api('GET', '/sales?limit=1&number=' + n);
        if (!r.sales.length) { toast(`Чек №${n} не найден`, 'bad'); return; }
        await loadRefundSale(r.sales[0].id);
      });
    },
    refPick(el) { run(null, () => loadRefundSale(el.dataset.id)); },
    refClear() { U.refSale = null; U.refQty = {}; U.enter = true; render(); },
    refQty(el) {
      const it = U.refSale && U.refSale.items.find((i) => i.id === Number(el.dataset.id));
      if (!it) return;
      U.refQty[it.id] = Math.max(0, Math.min(it.returnable, (U.refQty[it.id] || 0) + Number(el.dataset.d)));
      render();
    },
    refReason(el) { U.refReason = el.dataset.v; render(); },
    postRefund(el) {
      const sale = U.refSale;
      if (!sale) return;
      const total = sum(sale.items, (i) => (U.refQty[i.id] || 0) * i.price);
      confirmBox({ title: `Вернуть ${money(total)}?`, text: `Деньги вернуть: ${PAY[sale.payment].name.toLowerCase()}. Товар вернётся на склад.`, ok: 'Оформить возврат', danger: true, onOk: () => postRefund(el) });
    },
    refundFor(el) {
      closeSheet();
      if (route() !== 'refund') U.backTo = route();
      run(null, async () => {
        await loadRefundSale(el.dataset.id);
        go('refund');
      });
    },

    // инвентаризация
    audCat(el) { U.audCat = el.dataset.v; U.enter = true; render(); },
    audDiff() { U.audDiff = !U.audDiff; render(); },
    audFill(el) {
      const p = P(el.dataset.id);
      if (!p) return;
      L.auditDraft.counts[p.id] = p.stock;
      saveLocal();
      const row = $(`[data-row="${p.id}"]`);
      if (row) row.outerHTML = out(audRow(p));
      refreshAudit();
    },
    audFillAll() {
      let n = 0;
      for (const p of auditList()) if (L.auditDraft.counts[p.id] === undefined) { L.auditDraft.counts[p.id] = p.stock; n++; }
      saveLocal();
      render();
      toast(n ? `Факт = учёт для ${n} ${plural(n, ['позиции', 'позиций', 'позиций'])}` : 'Все позиции уже проверены', 'ok');
    },
    audReset() {
      confirmBox({ title: 'Сбросить пересчёт?', text: 'Все введённые фактические количества будут удалены.', ok: 'Сбросить', danger: true, onOk() { L.auditDraft = { counts: {} }; saveLocal(); render(); } });
    },
    audPost(el) {
      const s = auditStats();
      confirmBox({
        title: 'Провести инвентаризацию?',
        text: `Остатки ${s.checked} ${plural(s.checked, ['позиции', 'позиций', 'позиций'])} станут равны факту. Недостача: ${s.short} шт, излишек: ${s.extra} шт.`,
        ok: 'Провести', onOk: () => postAudit(el),
      });
    },
    scanAudit() {
      Scan.open({
        title: 'Пересчёт товара', continuous: true,
        onCode(code) {
          const p = byCode(code);
          if (!p) return { ok: false, msg: `Не найден: ${code}` };
          const c = L.auditDraft.counts;
          c[p.id] = (c[p.id] || 0) + 1;
          saveLocal();
          render();
          return { ok: true, msg: `${p.name}: факт ${c[p.id]} · учёт ${p.stock}` };
        },
      });
    },

    // отчёты и журнал
    period(el) { U.period = el.dataset.v; render(); },
    jType(el) { U.jType = el.dataset.v; U.jLimit = 80; U.enter = true; render(); },
    jMore() { U.jLimit += 80; render(); },

    // сотрудники (владелец)
    newEmp() { empForm(null); },
    editEmp(el) { const e = D.employees.find((x) => x.id === Number(el.dataset.id)); if (e) empForm(e); },
    toggleEmp(el) {
      const id = el.dataset.id;
      const active = el.dataset.active === '1';
      const name = el.dataset.name;
      const apply = () => run(null, async () => {
        const r = await api('POST', `/employees/${id}/toggle`);
        D.employees = D.employees.map((x) => (x.id === r.employee.id ? r.employee : x));
        invalidate('staff');
        closeSheet();
        render();
        toast(r.employee.active ? `${name} снова в команде` : `${name} отключён — его входы закрыты`, 'ok');
      });
      if (active) confirmBox({ title: 'Отключить сотрудника?', text: `${name} больше не сможет войти в кассу. Его продажи останутся в отчётах.`, ok: 'Отключить', danger: true, onOk: apply });
      else apply();
    },

    // данные (владелец)
    resetDemo() {
      confirmBox({
        title: 'Загрузить демо заново?', text: 'Все товары, чеки, документы и сотрудники на сервере будут заменены примером магазина. Войти можно будет по демо-PIN: владелец 1111.', ok: 'Загрузить демо', danger: true,
        onOk: () => run(null, async () => {
          await api('POST', '/data/demo');
          L = freshDrafts();
          saveLocal();
          signOut('Загружен демо-магазин. PIN владельца — 1111, продавцов — 2222, 3333, 4444.');
        }),
      });
    },
    wipeData() {
      confirmBox({
        title: 'Начать с чистого листа?', text: 'На сервере удалятся все товары, чеки, приходы, возвраты и инвентаризации. Сотрудники и название магазина останутся.', ok: 'Удалить всё', danger: true,
        onOk: () => run(null, async () => {
          await api('POST', '/data/wipe');
          L = freshDrafts();
          saveLocal();
          D.store.is_demo = false;
          setProducts([]);
          D.popular = [];
          invalidate();
          go('home');
          toast('Учёт очищен — добавьте свои товары', 'ok');
        }),
      });
    },

    // сканер
    scanLookupCam() {
      Scan.open({ title: 'Найти товар', onCode(code) { U.scanCode = code; render(); } });
    },
    scanLookup() {
      const inp = $('#scan-code');
      U.scanCode = normCode(inp ? inp.value : '');
      if (!U.scanCode) { toast('Введите штрих-код', 'bad'); return; }
      render();
    },
    newProductCode(el) { productForm(null, { barcode: el.dataset.code }); },
    scanClose() { Scan.close(); },
  };

  let jTimer = 0;
  const IN = {
    saleQ(el) { U.saleQ = el.value; setHTML($('#sale-suggest'), suggestList(U.saleQ, 'addCart')); },
    customer(el) { L.cart.customer = el.value; saveLocal(); },
    received(el) {
      L.cart.received = el.value.replace(/[^\d]/g, '');
      saveLocal();
      setHTML($('#change'), changeText());
    },
    prodQ(el) { U.prodQ = el.value; setHTML($('#plist'), productList()); },
    pfCalc() { setHTML($('#pf-margin'), marginText($('#pf-buy').value, $('#pf-sell').value)); },
    pfCode: codeHint,
    incSup(el) { L.incomeDraft.supplier = el.value; saveLocal(); },
    incQ(el) { U.incQ = el.value; setHTML($('#inc-suggest'), suggestList(U.incQ, 'incAdd')); },
    incQty(el) { IN.incLine(el, 'qty'); },
    incCost(el) { IN.incLine(el, 'cost'); },
    incLine(el, field) {
      const l = L.incomeDraft.lines.find((x) => x.pid === Number(el.dataset.id));
      if (!l) return;
      l[field] = field === 'qty' ? parseQty(el.value) || 0 : parseMoney(el.value);
      saveLocal();
      setHTML($(`[data-sum="${l.pid}"]`), money(l.qty * l.cost));
      const t = incTotals();
      setHTML($('#inc-total'), money(t.total));
      setHTML($('#inc-units'), `${t.n} ${plural(t.n, ['позиция', 'позиции', 'позиций'])} · ${pcs(t.units)}`);
    },
    audQ(el) { U.audQ = el.value; setHTML($('#aud-list'), auditListCard()); },
    audFact(el) {
      const p = P(el.dataset.id);
      if (!p) return;
      const n = parseQty(el.value);
      if (n == null) delete L.auditDraft.counts[p.id];
      else L.auditDraft.counts[p.id] = n;
      saveLocal();
      const row = el.closest('.aud-row');
      if (row) row.classList.toggle('counted', n != null);
      const dc = audDiffCell(p);
      const cell = $(`[data-diff="${p.id}"]`);
      if (cell) {
        cell.className = 'aud-diff ' + dc.cls;
        cell.textContent = dc.text;
        if (dc.text && !reduceMotion()) restartAnim(cell, 'pop');
      }
      refreshAudit();
    },
    jQ(el) {
      U.jQText = el.value;
      clearTimeout(jTimer);
      jTimer = setTimeout(() => {
        U.jQ = U.jQText.trim();
        U.jLimit = 80;
        render();
      }, 300);
    },
  };

  const CH = {
    cartQtySet(el) { setCartQty(el.dataset.id, parseQty(el.value) || 0); },
    prodSort(el) { U.prodSort = el.value; withVT(() => setHTML($('#plist'), productList())); },
    async scanPhoto(el) {
      const file = el.files && el.files[0];
      el.value = '';
      if (!file) return;
      Scan.status('Распознаём фото…');
      try {
        const code = await decodeImage(file);
        if (code) Scan.hit(code, true);
        else Scan.status('На фото не найден штрих-код. Снимите ближе и ровнее или введите цифры вручную.');
      } catch (e) {
        Scan.status('Не удалось распознать фото — введите цифры вручную.');
      }
    },
  };

  function enterCode(el, onFound, onMiss) {
    const q = el.value.trim();
    if (!q) return;
    const code = normCode(q);
    let p = byCode(code);
    if (!p && !/^\d+$/.test(code)) {
      const res = searchProducts(q, 2);
      if (res.length === 1) p = res[0];
      else { toast(res.length ? 'Найдено несколько товаров — выберите из списка' : 'Ничего не найдено', 'bad'); return; }
    }
    if (p) onFound(p);
    else onMiss(code);
  }
  const ENTER = {
    saleEnter(el) {
      enterCode(el, (p) => {
        const r = addToCart(p.id);
        if (!r.ok) { toast(r.msg, 'bad'); return; }
        beep();
        U.saleQ = '';
        U.focus = 'sale-q';
        render();
      }, (code) => toast(`Товар со штрих-кодом ${code} не найден`, 'bad'));
    },
    incEnter(el) {
      enterCode(el, (p) => {
        addIncomeLine(p.id);
        beep();
        U.incQ = '';
        U.focus = 'inc-q';
        render();
      }, (code) => (validEan(code) ? lookupPrompt(code, 'income') : toast(`Товар со штрих-кодом ${code} не найден`, 'bad')));
    },
    audEnter(el) {
      const code = normCode(el.value);
      const p = byCode(code);
      if (!p) { if (/^\d{8,}$/.test(code)) toast(`Штрих-код ${code} не найден`, 'bad'); return; }
      const c = L.auditDraft.counts;
      c[p.id] = (c[p.id] || 0) + 1;
      saveLocal();
      beep();
      U.audQ = '';
      U.focus = 'aud-q';
      render();
      toast(`${p.name}: факт ${c[p.id]} · учёт ${p.stock}`, 'ok');
    },
    refFind() { ACT.refFind(null); },
    scanLookup() { ACT.scanLookup(); },
    scanManual(el) { const v = el.value; el.value = ''; Scan.hit(v, true); },
    pfCodeEnter() { codeHint(); const n = $('#pf-buy'); if (n) n.focus(); },
  };

  const SUBMIT = {
    saveProduct,
    saveEmp(form) {
      const f = Object.fromEntries(new FormData(form));
      const id = form.dataset.id;
      const errEl = $('#ef-err');
      const name = String(f.name || '').trim();
      if (!name) { errEl.textContent = 'Введите имя сотрудника'; return; }
      const pin = String(f.pin || '').trim();
      if ((!id || pin) && pinProblem(pin)) { errEl.textContent = pinProblem(pin); return; }
      const payload = { name, role: f.role, phone: String(f.phone || '').trim(), pin: pin || null };
      run(form.querySelector('[type="submit"]'), async () => {
        try {
          const r = id ? await api('PUT', '/employees/' + id, payload) : await api('POST', '/employees', payload);
          const others = D.employees.filter((x) => x.id !== r.employee.id);
          D.employees = [...others, r.employee].sort((a, b) => a.name.localeCompare(b.name, 'ru'));
          if (D.me && D.me.id === r.employee.id) D.me = r.employee;
          invalidate('staff');
          closeSheet();
          render();
          toast(id ? 'Изменения сохранены' : `Сотрудник добавлен: ${name}`, 'ok');
        } catch (e) {
          if (e.status === 422) { errEl.textContent = e.message; return; }
          throw e;
        }
      });
    },
    saveStore(form) {
      const f = Object.fromEntries(new FormData(form));
      run(form.querySelector('[type="submit"]'), async () => {
        const r = await api('PUT', '/store', { name: String(f.name || '').trim(), address: String(f.address || '').trim() || null });
        D.store = r.store;
        render();
        toast('Данные магазина сохранены', 'ok');
      });
    },
    setup(form) {
      const f = Object.fromEntries(new FormData(form));
      const errEl = $('#su-err');
      const pin = String(f.pin || '');
      if (!String(f.store_name || '').trim() || !String(f.owner_name || '').trim()) { errEl.textContent = 'Заполните название магазина и имя владельца'; return; }
      if (form.querySelector('#su-code') && !String(f.setup_code || '').trim()) { errEl.textContent = 'Введите код установки'; return; }
      const weak = pinProblem(pin);
      if (weak) { errEl.textContent = weak; return; }
      if (pin !== f.pin2) { errEl.textContent = 'PIN не совпадает — введите ещё раз'; return; }
      errEl.textContent = '';
      run(form.querySelector('[type="submit"]'), async () => {
        try {
          const r = await api('POST', '/setup', { store_name: f.store_name, owner_name: f.owner_name, pin, setup_code: String(f.setup_code || '').trim() || null });
          setToken(r.token);
          await startSession();
          toast('Магазин создан. Добавьте товары и продавцов', 'ok');
        } catch (e) {
          if (e.status === 422 || e.status === 403) { errEl.textContent = e.message; return; }
          throw e;
        }
      });
    },
  };

  // ---------------------------------------------------------------- Запуск
  function bind() {
    document.addEventListener('click', (e) => {
      const el = e.target.closest('[data-act]');
      if (!el || el.disabled) return;
      const fn = ACT[el.dataset.act];
      if (!fn) return;
      e.preventDefault();
      if (el.classList.contains('chip')) U.pop = `.chip[data-act="${el.dataset.act}"]` + (el.dataset.v ? `[data-v="${el.dataset.v}"]` : '');
      fn(el, e);
    });
    document.addEventListener('animationend', (e) => {
      if (e.animationName === 'rise') {
        e.target.classList.remove('rise');
        e.target.style.removeProperty('--d');
      }
    });
    document.addEventListener('input', (e) => {
      const el = e.target.closest('[data-in]');
      if (el && IN[el.dataset.in]) IN[el.dataset.in](el, e);
    });
    document.addEventListener('change', (e) => {
      const el = e.target.closest('[data-ch]');
      if (el && CH[el.dataset.ch]) CH[el.dataset.ch](el, e);
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        if (!$('#dialog').hidden) closeDialog();
        else if (!$('#scan').hidden) Scan.close();
        else closeSheet();
        return;
      }
      // PIN с клавиатуры, когда открыта панель ввода
      const typing = e.target.closest && e.target.closest('input, textarea, select');
      if (!typing && $('#pin-area .pin') && $('#dialog').hidden) {
        if (/^\d$/.test(e.key)) { e.preventDefault(); ACT.pinKey({ dataset: { v: e.key } }); return; }
        if (e.key === 'Backspace') { e.preventDefault(); ACT.pinKey({ dataset: { v: 'back' } }); return; }
        if (e.key === 'Enter') { e.preventDefault(); pinSubmit(); return; }
      }
      if (e.key === 'Enter' && !e.isComposing) {
        const el = e.target.closest && e.target.closest('[data-enter]');
        if (el && ENTER[el.dataset.enter]) {
          e.preventDefault();
          ENTER[el.dataset.enter](el, e);
        }
      }
    });
    document.addEventListener('submit', (e) => {
      const f = e.target.closest('[data-submit]');
      if (!f) return;
      e.preventDefault();
      if (SUBMIT[f.dataset.submit]) SUBMIT[f.dataset.submit](f);
    });
    document.addEventListener('pointerover', (e) => { const c = e.target.closest && e.target.closest('.col[data-tip]'); if (c) showTip(c); });
    document.addEventListener('pointerout', (e) => { const c = e.target.closest && e.target.closest('.col[data-tip]'); if (c && !c.contains(e.relatedTarget)) hideTip(c); });
    document.addEventListener('focusin', (e) => { const c = e.target.closest && e.target.closest('.col[data-tip]'); if (c) showTip(c); });
    document.addEventListener('focusout', (e) => { const c = e.target.closest && e.target.closest('.col[data-tip]'); if (c) hideTip(c); });
    window.addEventListener('hashchange', () => {
      closeSheet();
      render();
    });
    // вернулись в приложение — подтягиваем свежие данные (продажи с других телефонов)
    document.addEventListener('visibilitychange', () => {
      if (document.hidden || !D.me) return;
      invalidate();
      refreshCatalog(true);
      render();
    });
    window.addEventListener('storage', (e) => {
      if (e.key !== LOCAL_KEY || !e.newValue) return;
      try { L = JSON.parse(e.newValue); if (D.me) render(); } catch (err) { /* чужой формат — игнорируем */ }
    });
  }

  async function init() {
    document.documentElement.lang = 'ru';
    setHTML($('#back'), ic('left'));
    setHTML($('#scan-x'), ic('x'));
    setHTML($('#scan-photo-ico'), ic('camera'));
    bind();
    if (!TOKEN) showAuth();
    else {
      U.auth = { msg: '' };
      render();
      try {
        await startSession();
      } catch (e) {
        if (e.status !== 401) { U.auth = { error: e.message }; render(); }
      }
    }
    if ('serviceWorker' in navigator && /^https?:$/.test(location.protocol) && document.querySelector('link[rel="manifest"]')) {
      navigator.serviceWorker.register('sw.js').catch(() => { /* офлайн-оболочка недоступна */ });
    }
  }

  init();
})();
