/*
 * «Касса и склад» — касса и складской учёт для магазина аксессуаров для телефонов.
 * Одностраничное приложение без сборки: все данные лежат в localStorage этого устройства.
 * Остаток товара меняют только операции: продажа, приход, возврат, инвентаризация.
 */
'use strict';
(function () {
  const STORE_KEY = 'kassa-sklad:v1';
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
  const retNo = (r) => 'В-' + pad4(r.no);
  const incNo = (d) => 'П-' + pad4(d.no);
  const audNo = (a) => 'И-' + pad4(a.no);
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
  function genBarcode() {
    for (;;) {
      const d12 = '200' + String(Math.floor(Math.random() * 1e9)).padStart(9, '0');
      const code = d12 + eanCheck(d12);
      if (!S.products.some((p) => p.barcode === code)) return code;
    }
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

  // ---------------------------------------------------------------- Хранение
  let S = null;
  let saveWarned = false;
  function load() {
    try {
      const t = localStorage.getItem(STORE_KEY);
      if (t) {
        const d = JSON.parse(t);
        if (d && d.v === 1 && Array.isArray(d.products)) return d;
      }
    } catch (e) { /* хранилище недоступно — работаем в памяти */ }
    return null;
  }
  function save() {
    try {
      localStorage.setItem(STORE_KEY, JSON.stringify(S));
    } catch (e) {
      if (!saveWarned) {
        saveWarned = true;
        toast('Браузер не даёт сохранить данные — после закрытия вкладки они пропадут', 'bad');
      }
    }
  }
  function normalize(d) {
    d.meta = d.meta || {};
    d.store = d.store || { name: 'Мой магазин', address: '' };
    ['products', 'employees', 'sales', 'returns', 'incomes', 'audits'].forEach((k) => { if (!Array.isArray(d[k])) d[k] = []; });
    if (!d.employees.length) d.employees.push({ id: 'e1', name: 'Владелец', role: 'owner', phone: '', active: true });
    d.seq = d.seq || {};
    d.seq.sale = d.seq.sale || 1001 + d.sales.length;
    d.seq.ret = d.seq.ret || d.returns.length + 1;
    d.seq.inc = d.seq.inc || d.incomes.length + 1;
    d.seq.aud = d.seq.aud || d.audits.length + 1;
    d.cart = d.cart || { items: [], customer: '', payment: 'cash', received: '' };
    d.incomeDraft = d.incomeDraft || { supplier: '', lines: [] };
    d.auditDraft = d.auditDraft || { counts: {} };
    if (!d.employees.some((e) => e.id === d.currentUserId && e.active)) d.currentUserId = (d.employees.find((e) => e.active) || d.employees[0]).id;
    return d;
  }
  const uid = (prefix) => prefix + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);

  // ---------------------------------------------------------------- Данные и расчёты
  const P = (id) => S.products.find((p) => p.id === id);
  const E = (id) => S.employees.find((e) => e.id === id);
  const byCode = (code) => S.products.find((p) => p.barcode === code);
  const empName = (id) => (E(id) ? E(id).name : 'Сотрудник удалён');
  const shortName = (id) => {
    const parts = empName(id).split(/\s+/);
    return parts.length > 1 ? parts[0] + ' ' + parts[1][0] + '.' : parts[0];
  };
  const me = () => E(S.currentUserId) || S.employees[0];
  const isLow = (p) => p.stock <= p.min;
  const lowStock = () => S.products.filter(isLow).sort((a, b) => a.stock - b.stock || a.name.localeCompare(b.name, 'ru'));
  const saleById = (id) => S.sales.find((s) => s.id === id);
  const saleReturns = (saleId) => S.returns.filter((r) => r.saleId === saleId);
  function returnedQty(saleId, pid) {
    let q = 0;
    for (const r of S.returns) if (r.saleId === saleId) for (const i of r.items) if (i.pid === pid) q += i.qty;
    return q;
  }
  const cartLines = () => S.cart.items.filter((l) => P(l.pid));
  const cartTotal = () => sum(cartLines(), (l) => l.qty * P(l.pid).sellPrice);
  const cartUnits = () => sum(cartLines(), (l) => l.qty);

  function searchProducts(q, limit) {
    const words = q.trim().toLowerCase().split(/\s+/).filter(Boolean);
    if (!words.length) return [];
    const res = S.products.filter((p) => {
      const hay = (p.name + ' ' + p.barcode + ' ' + catOf(p).name).toLowerCase();
      return words.every((w) => hay.includes(w));
    });
    return limit ? res.slice(0, limit) : res;
  }

  function popular(limit) {
    const from = daysAgo(29);
    const q = {};
    for (const s of S.sales) if (s.ts >= from) for (const i of s.items) q[i.pid] = (q[i.pid] || 0) + i.qty;
    return S.products
      .filter((p) => p.stock > 0)
      .sort((a, b) => (q[b.id] || 0) - (q[a.id] || 0) || a.name.localeCompare(b.name, 'ru'))
      .slice(0, limit);
  }

  function periodStart(p) {
    if (p === 'today') return daysAgo(0);
    if (p === 'week') return daysAgo(6);
    if (p === 'month') return daysAgo(29);
    let first = Date.now();
    for (const s of S.sales) if (s.ts < first) first = s.ts;
    for (const r of S.returns) if (r.ts < first) first = r.ts;
    return sod(first);
  }

  // Выручка = продажи − возвраты (по дате возврата). Прибыль = выручка − себестоимость проданного.
  function calc(from, to) {
    to = to || Infinity;
    const sales = S.sales.filter((s) => s.ts >= from && s.ts < to);
    const rets = S.returns.filter((r) => r.ts >= from && r.ts < to);
    const byPay = { cash: 0, card: 0, qr: 0 };
    const bySeller = {};
    const byProd = {};
    let gross = 0, cost = 0, units = 0;
    const seller = (id) => (bySeller[id] = bySeller[id] || { checks: 0, sum: 0 });
    const prod = (i) => (byProd[i.pid] = byProd[i.pid] || { pid: i.pid, name: i.name, qty: 0, sum: 0 });
    for (const s of sales) {
      gross += s.total;
      byPay[s.payment] = (byPay[s.payment] || 0) + s.total;
      const b = seller(s.userId);
      b.checks++;
      b.sum += s.total;
      for (const i of s.items) {
        units += i.qty;
        cost += i.qty * i.cost;
        const p = prod(i);
        p.qty += i.qty;
        p.sum += i.qty * i.price;
      }
    }
    let refunds = 0;
    for (const r of rets) {
      refunds += r.total;
      byPay[r.payment] = (byPay[r.payment] || 0) - r.total;
      const sale = saleById(r.saleId);
      seller(sale ? sale.userId : r.userId).sum -= r.total;
      for (const i of r.items) {
        units -= i.qty;
        cost -= i.qty * i.cost;
        const p = prod(i);
        p.qty -= i.qty;
        p.sum -= i.qty * i.price;
      }
    }
    const revenue = gross - refunds;
    return {
      sales, rets, gross, refunds, revenue, units, byPay, bySeller, byProd,
      checks: sales.length,
      avg: sales.length ? gross / sales.length : 0,
      profit: revenue - cost,
    };
  }

  function productMoves(pid) {
    const m = [];
    for (const s of S.sales) for (const i of s.items) if (i.pid === pid) m.push({ ts: s.ts, type: 'sale', label: 'Продажа №' + s.no, qty: -i.qty, who: s.userId, act: 'openSale', id: s.id });
    for (const r of S.returns) for (const i of r.items) if (i.pid === pid) m.push({ ts: r.ts, type: 'ret', label: 'Возврат ' + retNo(r), qty: i.qty, who: r.userId, act: 'openReturn', id: r.id });
    for (const d of S.incomes) for (const i of d.items) if (i.pid === pid) m.push({ ts: d.ts, type: 'inc', label: 'Приход ' + incNo(d), qty: i.qty, who: d.userId, act: 'openIncome', id: d.id });
    for (const a of S.audits) for (const i of a.items) if (i.pid === pid && i.actual !== i.expected) m.push({ ts: a.ts, type: 'aud', label: 'Инвентаризация ' + audNo(a), qty: i.actual - i.expected, who: a.userId, act: 'openAudit', id: a.id });
    return m.sort((a, b) => b.ts - a.ts);
  }

  // ---------------------------------------------------------------- Операции
  function addToCart(pid) {
    const p = P(pid);
    if (!p) return { ok: false, msg: 'Товар не найден' };
    const line = S.cart.items.find((l) => l.pid === pid);
    const inCart = line ? line.qty : 0;
    if (inCart + 1 > p.stock) {
      return { ok: false, msg: p.stock ? `На складе только ${pcs(p.stock)}: ${p.name}` : `Нет на складе: ${p.name}` };
    }
    if (line) line.qty++;
    else S.cart.items.unshift({ pid, qty: 1 });
    U.flash = pid;
    U.flashNew = !line;
    save();
    return { ok: true, msg: '+1 ' + p.name, p };
  }

  function setCartQty(pid, n) {
    const p = P(pid);
    const line = S.cart.items.find((l) => l.pid === pid);
    if (!line || !p) return;
    if (!(n > 0)) S.cart.items = S.cart.items.filter((l) => l !== line);
    else if (n > p.stock) {
      line.qty = p.stock;
      toast(`На складе только ${pcs(p.stock)}`, 'bad');
    } else line.qty = n;
    save();
    render();
  }

  function checkout() {
    const c = S.cart;
    const lines = cartLines();
    if (!lines.length) return;
    for (const l of lines) {
      const p = P(l.pid);
      if (l.qty > p.stock) { toast(`Недостаточно «${p.name}»: на складе ${pcs(p.stock)}`, 'bad'); return; }
    }
    const items = lines.map((l) => {
      const p = P(l.pid);
      return { pid: p.id, name: p.name, qty: l.qty, price: p.sellPrice, cost: p.buyPrice };
    });
    const total = sum(items, (i) => i.qty * i.price);
    let received = null;
    if (c.payment === 'cash') {
      const r = parseMoney(c.received);
      if (r && r < total) { toast('Получено меньше суммы чека', 'bad'); return; }
      received = r || total;
    }
    items.forEach((i) => { P(i.pid).stock -= i.qty; });
    const no = S.seq.sale++;
    const sale = { id: 's' + no, no, ts: Date.now(), userId: me().id, customer: c.customer.trim(), payment: c.payment, items, total, received };
    S.sales.push(sale);
    S.cart = { items: [], customer: '', payment: c.payment, received: '' };
    save();
    render();
    openReceipt(sale.id, true);
    chime();
    setTimeout(() => {
      const c = $('.ok-check');
      if (!c) return;
      const b = c.getBoundingClientRect();
      burst(b.left + b.width / 2, b.top + b.height / 2);
    }, 520);
  }

  function addIncomeLine(pid) {
    const p = P(pid);
    if (!p) return null;
    const lines = S.incomeDraft.lines;
    const line = lines.find((l) => l.pid === pid);
    if (line) line.qty++;
    else lines.unshift({ pid, qty: 1, cost: p.buyPrice });
    save();
    return p;
  }

  function postIncome() {
    const d = S.incomeDraft;
    const lines = d.lines.filter((l) => P(l.pid));
    if (!lines.length) { toast('Добавьте товары в приход', 'bad'); return; }
    const bad = lines.find((l) => !(l.qty > 0));
    if (bad) { toast(`Укажите количество: ${P(bad.pid).name}`, 'bad'); return; }
    const items = lines.map((l) => ({ pid: l.pid, name: P(l.pid).name, qty: l.qty, cost: l.cost || 0 }));
    items.forEach((i) => {
      const p = P(i.pid);
      p.stock += i.qty;
      if (i.cost > 0) p.buyPrice = i.cost;
    });
    const no = S.seq.inc++;
    const doc = { id: 'i' + no, no, ts: Date.now(), userId: me().id, supplier: d.supplier.trim(), items, total: sum(items, (i) => i.qty * i.cost) };
    S.incomes.push(doc);
    S.incomeDraft = { supplier: '', lines: [] };
    save();
    render();
    openIncomeDoc(doc.id);
    toast(`Приход ${incNo(doc)} проведён: +${pcs(sum(items, (i) => i.qty))}`, 'ok');
  }

  function postRefund() {
    const sale = saleById(U.refSale);
    if (!sale) return;
    const items = [];
    for (const it of sale.items) {
      const q = U.refQty[it.pid] || 0;
      if (q <= 0) continue;
      if (q > it.qty - returnedQty(sale.id, it.pid)) { toast('Нельзя вернуть больше, чем куплено', 'bad'); return; }
      items.push({ pid: it.pid, name: it.name, qty: q, price: it.price, cost: it.cost });
    }
    if (!items.length) { toast('Выберите товары для возврата', 'bad'); return; }
    items.forEach((i) => { const p = P(i.pid); if (p) p.stock += i.qty; });
    const no = S.seq.ret++;
    const r = { id: 'r' + no, no, ts: Date.now(), saleId: sale.id, saleNo: sale.no, userId: me().id, payment: sale.payment, reason: U.refReason, items, total: sum(items, (i) => i.qty * i.price) };
    S.returns.push(r);
    U.refSale = null;
    U.refQty = {};
    save();
    render();
    openReturnDoc(r.id);
    toast(`Возврат ${retNo(r)} оформлен: −${money(r.total)}`, 'ok');
  }

  function auditStats() {
    let checked = 0, short = 0, shortSum = 0, extra = 0, extraSum = 0;
    for (const [id, actual] of Object.entries(S.auditDraft.counts)) {
      const p = P(id);
      if (!p) continue;
      checked++;
      const d = actual - p.stock;
      if (d < 0) { short -= d; shortSum -= d * p.buyPrice; }
      if (d > 0) { extra += d; extraSum += d * p.buyPrice; }
    }
    return { checked, total: S.products.length, short, shortSum, extra, extraSum };
  }

  function postAudit() {
    const counts = S.auditDraft.counts;
    const items = Object.keys(counts).filter((id) => P(id)).map((id) => {
      const p = P(id);
      return { pid: id, name: p.name, expected: p.stock, actual: counts[id], cost: p.buyPrice };
    });
    if (!items.length) return;
    items.forEach((i) => { P(i.pid).stock = i.actual; });
    const no = S.seq.aud++;
    const doc = { id: 'a' + no, no, ts: Date.now(), userId: me().id, items };
    S.audits.push(doc);
    S.auditDraft = { counts: {} };
    save();
    render();
    openAuditDoc(doc.id);
    toast(`Инвентаризация ${audNo(doc)} проведена`, 'ok');
  }

  function deleteProduct(id) {
    const p = P(id);
    if (!p) return;
    S.products = S.products.filter((x) => x.id !== id);
    S.cart.items = S.cart.items.filter((l) => l.pid !== id);
    S.incomeDraft.lines = S.incomeDraft.lines.filter((l) => l.pid !== id);
    delete S.auditDraft.counts[id];
    save();
    closeSheet();
    render();
    toast(`Товар удалён: ${p.name}`, 'ok');
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
    period: 'today', jType: 'all', jQ: '', jLimit: 80,
    scanCode: '', backTo: 'home', flash: null, focus: null,
  };

  // ---------------------------------------------------------------- Общие фрагменты
  const stockPill = (p) => (p.stock <= 0
    ? html`<span class="pill bad">нет в наличии</span>`
    : isLow(p) ? html`<span class="pill warn">${ic('alert')}${pcs(p.stock)}</span>` : html`<span class="pill">${pcs(p.stock)}</span>`);
  const cnt = (v, key, fmt) => html`<span data-count="${Math.round(v)}" data-key="${key}"${raw(fmt ? ` data-fmt="${fmt}"` : '')}>${fmt === 'compact' ? compact(v) : num(v)}</span>`;
  const sumVal = (v, key) => html`${key ? cnt(v, key) : num(v)}<em>сум</em>`;
  const empty = (icon, text) => html`<div class="empty">${ic(icon)}<span>${text}</span></div>`;

  function saleRow(s, act, isNew) {
    const hasRet = S.returns.some((r) => r.saleId === s.id);
    return html`<button class="row${isNew ? ' is-new' : ''}" data-act="${act || 'openSale'}" data-id="${s.id}">
      <span class="row-ico sale">${ic(PAY[s.payment].icon)}</span>
      <span class="row-main">
        <span class="row-title">Чек №${s.no}${s.customer ? html` <span class="faint">· ${s.customer}</span>` : ''}</span>
        <span class="row-sub"><span>${whenShort(s.ts)}</span><span>${shortName(s.userId)}</span><span>${PAY[s.payment].name}</span>${hasRet ? html`<span class="pill bad">возврат</span>` : ''}</span>
      </span>
      <span class="row-side"><span class="amt">${money(s.total)}</span><span class="faint">${pcs(sum(s.items, (i) => i.qty))}</span></span>
    </button>`;
  }
  const returnRow = (r) => html`<button class="row" data-act="openReturn" data-id="${r.id}">
      <span class="row-ico ret">${ic('refund')}</span>
      <span class="row-main"><span class="row-title">Возврат ${retNo(r)}</span>
      <span class="row-sub"><span>${whenShort(r.ts)}</span><span>чек №${r.saleNo}</span><span>${r.reason}</span></span></span>
      <span class="row-side"><span class="amt minus">−${money(r.total)}</span><span class="faint">${shortName(r.userId)}</span></span>
    </button>`;
  const incomeRow = (d) => html`<button class="row" data-act="openIncome" data-id="${d.id}">
      <span class="row-ico inc">${ic('income')}</span>
      <span class="row-main"><span class="row-title">Приход ${incNo(d)}</span>
      <span class="row-sub"><span>${whenShort(d.ts)}</span><span>${d.supplier || 'без поставщика'}</span></span></span>
      <span class="row-side"><span class="amt">${money(d.total)}</span><span class="faint">+${pcs(sum(d.items, (i) => i.qty))}</span></span>
    </button>`;
  function auditDiffText(a) {
    const minus = sum(a.items, (i) => Math.max(0, i.expected - i.actual));
    const plus = sum(a.items, (i) => Math.max(0, i.actual - i.expected));
    return minus || plus ? `−${minus} / +${plus} шт` : 'без расхождений';
  }
  const auditRow = (a) => html`<button class="row" data-act="openAudit" data-id="${a.id}">
      <span class="row-ico aud">${ic('audit')}</span>
      <span class="row-main"><span class="row-title">Инвентаризация ${audNo(a)}</span>
      <span class="row-sub"><span>${whenShort(a.ts)}</span><span>${shortName(a.userId)}</span><span>проверено ${a.items.length}</span></span></span>
      <span class="row-side"><span class="amt">${auditDiffText(a)}</span></span>
    </button>`;

  function suggestList(q, act) {
    if (!q || !q.trim()) return '';
    const res = searchProducts(q, 6);
    if (!res.length) return html`<div class="sug"><span class="sug-main"><span class="sug-name">Ничего не найдено</span><span class="sug-sub">Проверьте штрих-код или название</span></span></div>`;
    return res.map((p) => html`<button class="sug" data-act="${act}" data-id="${p.id}">
      <span class="cat-ico sm">${ic(catOf(p).icon, 'i-sm')}</span>
      <span class="sug-main"><span class="sug-name">${p.name}</span><span class="sug-sub"><span class="mono">${p.barcode}</span> · ${pcs(p.stock)}</span></span>
      <b class="num nowrap">${num(p.sellPrice)}</b></button>`);
  }

  // ---------------------------------------------------------------- Экраны
  const SCREENS = {};

  // Главная
  SCREENS.home = {
    title: 'Главная', tab: true,
    view() {
      const t = calc(daysAgo(0));
      const low = lowStock();
      const recent = S.sales.slice(-8).reverse();
      const seen = U.seenSale;
      U.seenSale = S.sales.length ? S.sales[S.sales.length - 1].no : 0;
      const todayRaw = new Date().toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' });
      const today = todayRaw.charAt(0).toUpperCase() + todayRaw.slice(1);
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
      <nav class="qa" aria-label="Быстрые действия">${QUICK.map(([to, label, icon], i) => html`<button class="qa-btn${i === 0 ? ' primary' : ''}" data-act="nav" data-to="${to}"><span class="qa-ico">${ic(icon)}</span><span>${label}</span></button>`)}</nav>
      <section class="card flush">
        <div class="card-h"><h2 class="sec-title">Последние продажи</h2><button class="link" data-act="nav" data-to="journal">Журнал ${ic('right', 'i-sm')}</button></div>
        <div class="rows">${recent.length ? recent.map((s) => saleRow(s, null, seen != null && s.no > seen)) : empty('receipt', 'Продаж пока нет')}</div>
      </section>`;
    },
  };

  // Продажа (касса)
  function cartLine(l) {
    const p = P(l.pid);
    return html`<div class="cart-line${U.flash === p.id ? (U.flashNew ? ' flash new' : ' flash') : ''}">
      <div class="cart-name">${p.name}<div class="cart-price">${num(p.sellPrice)} × ${l.qty} · на складе ${p.stock}</div></div>
      <button class="rm" data-act="rmCart" data-id="${p.id}" aria-label="Убрать из корзины">${ic('trash', 'i-sm')}</button>
      <div class="cart-ctrl">
        <div class="stepper"><button data-act="cartQty" data-id="${p.id}" data-d="-1" aria-label="Меньше">${ic('minus', 'i-sm')}</button><input class="input qty" inputmode="numeric" value="${l.qty}" data-ch="cartQtySet" data-id="${p.id}" aria-label="Количество"><button data-act="cartQty" data-id="${p.id}" data-d="1" aria-label="Больше">${ic('plus', 'i-sm')}</button></div>
        <span class="line-total">${money(p.sellPrice * l.qty)}</span>
      </div>
    </div>`;
  }
  function changeText() {
    const t = cartTotal();
    const r = parseMoney(S.cart.received);
    if (!r) return '—';
    if (r < t) return html`<span class="bad">не хватает ${num(t - r)}</span>`;
    return money(r - t);
  }
  SCREENS.sale = {
    title: 'Продажа', tab: true,
    view() {
      const c = S.cart;
      const lines = cartLines();
      const total = cartTotal();
      return html`
      <div class="search-row">
        <label class="search"><span class="sr-only">Штрих-код или название</span>${ic('search')}<input class="input" id="sale-q" type="search" enterkeyhint="done" autocomplete="off" placeholder="Штрих-код или название" value="${U.saleQ}" data-in="saleQ" data-enter="saleEnter"></label>
        <button class="icon-btn accent" data-act="scanSale" aria-label="Сканировать камерой">${ic('scan')}</button>
      </div>
      <div class="suggest" id="sale-suggest">${suggestList(U.saleQ, 'addCart')}</div>
      <section class="stack">
        <div class="card-h"><h2 class="sec-title">Популярные товары</h2><span class="sec-sub">нажмите, чтобы добавить</span></div>
        <div class="tiles">${popular(12).map((p) => html`<button class="tile" data-act="addCart" data-id="${p.id}"><span class="tile-name">${p.name}</span><span class="tile-foot"><span class="tile-price">${num(p.sellPrice)}</span><span class="tile-stock">${p.stock} шт</span></span></button>`)}</div>
      </section>
      <section class="card flush">
        <div class="card-h"><h2 class="sec-title">Корзина${lines.length ? html` <span class="faint">· ${pcs(cartUnits())}</span>` : ''}</h2>${lines.length ? html`<button class="link" data-act="clearCart">Очистить</button>` : ''}</div>
        <div class="rows">${lines.length ? lines.map(cartLine) : empty('cart', 'Корзина пуста. Отсканируйте штрих-код или выберите товар выше.')}</div>
      </section>
      ${lines.length ? html`
      <section class="card">
        <div class="field"><label for="cust">Имя покупателя</label><input class="input" id="cust" autocomplete="off" maxlength="60" placeholder="Необязательно" value="${c.customer}" data-in="customer"></div>
        <div class="field"><span class="label" id="pay-label">Способ оплаты</span>
          <div class="seg pay" role="radiogroup" aria-labelledby="pay-label" data-seg="pay" data-i="${Object.keys(PAY).indexOf(c.payment)}" style="--n:3;--i:${Object.keys(PAY).indexOf(c.payment)}"><span class="seg-pill" aria-hidden="true"></span>${Object.entries(PAY).map(([k, v]) => html`<button role="radio" aria-checked="${String(c.payment === k)}" class="${c.payment === k ? 'on' : ''}" data-act="pay" data-v="${k}">${ic(v.icon)}<span>${v.name}</span></button>`)}</div>
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
      </section>` : ''}`;
    },
    bar() {
      if (!cartLines().length) return '';
      return html`<div class="checkout-inner"><div class="checkout-sum"><span>Итого</span><b>${cnt(cartTotal(), 'cart-total')}&nbsp;сум</b></div><button class="btn btn-primary btn-lg" data-act="checkout">${ic('receipt')}Пробить чек</button></div>`;
    },
  };

  // Товары
  function filteredProducts() {
    let list = U.prodQ.trim() ? searchProducts(U.prodQ) : S.products.slice();
    if (U.prodCat !== 'all') list = list.filter((p) => p.category === U.prodCat);
    if (U.prodLow) list = list.filter(isLow);
    const [k, d] = U.prodSort.split(':');
    const dir = Number(d) || 1;
    const key = { name: (p) => p.name, price: (p) => p.sellPrice, stock: (p) => p.stock }[k] || ((p) => p.name);
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
        <span class="kv right"><span>Закупка</span><b>${num(p.buyPrice)}</b></span>
        <span class="kv right"><span>Розница</span><b>${num(p.sellPrice)}</b></span>
      </span>
    </button>`;
  function productList() {
    const list = filteredProducts();
    const units = sum(list, (p) => p.stock);
    return html`<section class="card flush">
      <div class="card-h"><span class="summary-line">${list.length} ${plural(list.length, ['товар', 'товара', 'товаров'])} · ${pcs(units)}</span><button class="btn btn-primary btn-sm" data-act="newProduct">${ic('plus', 'i-sm')}Добавить товар</button></div>
      <div class="rows">${list.length ? list.map(pcard) : empty('search', S.products.length ? 'Ничего не найдено — измените поиск или фильтр' : 'Каталог пуст. Добавьте первый товар.')}</div>
    </section>`;
  }
  SCREENS.products = {
    title: 'Товары', tab: true,
    view() {
      const counts = {};
      S.products.forEach((p) => { counts[p.category] = (counts[p.category] || 0) + 1; });
      const lowN = lowStock().length;
      return html`
      <div class="search-row">
        <label class="search"><span class="sr-only">Поиск товара</span>${ic('search')}<input class="input" id="prod-q" type="search" enterkeyhint="search" autocomplete="off" placeholder="Название или штрих-код" value="${U.prodQ}" data-in="prodQ"></label>
        <button class="icon-btn" data-act="scanProducts" aria-label="Найти по штрих-коду камерой">${ic('scan')}</button>
      </div>
      <div class="chips" aria-label="Категории">
        <button class="chip${U.prodCat === 'all' ? ' on' : ''}" data-act="prodCat" data-v="all">Все <span class="count">${S.products.length}</span></button>
        ${CATS.map((c) => html`<button class="chip${U.prodCat === c.id ? ' on' : ''}" data-act="prodCat" data-v="${c.id}">${c.name} <span class="count">${counts[c.id] || 0}</span></button>`)}
      </div>
      <div class="toolbar">
        <label class="sr-only" for="prod-sort">Сортировка</label>
        <select class="select" id="prod-sort" data-ch="prodSort">${SORTS.map(([v, l]) => html`<option value="${v}"${raw(U.prodSort === v ? ' selected' : '')}>${l}</option>`)}</select>
        <button class="chip${U.prodLow ? ' warn-on' : ''}" data-act="prodLow" aria-pressed="${String(U.prodLow)}">${ic('alert', 'i-sm')}Мало на складе <span class="count">${lowN}</span></button>
      </div>
      <div id="plist">${productList()}</div>`;
    },
  };

  function openProduct(id) {
    const p = P(id);
    if (!p) return;
    const from = daysAgo(29);
    let sold = 0, soldSum = 0;
    for (const s of S.sales) if (s.ts >= from) for (const i of s.items) if (i.pid === id) { sold += i.qty; soldSum += i.qty * i.price; }
    const moves = productMoves(id).slice(0, 8);
    const margin = p.buyPrice ? Math.round(((p.sellPrice - p.buyPrice) / p.buyPrice) * 100) : null;
    openSheet(html`${sheetHead(p.name, catOf(p).name)}
      <div class="sheet-body">
        <div class="barcode">${barcodeSVG(p.barcode)}</div>
        <div class="kpis">
          <div class="kpi"><span>Остаток на складе</span><b class="${p.stock <= 0 ? 'bad' : isLow(p) ? 'warn' : ''}">${pcs(p.stock)}</b><small>${isLow(p) ? 'не выше минимума — ' + p.min + ' шт' : 'минимум ' + p.min + ' шт'}</small></div>
          <div class="kpi"><span>Продано за 30 дней</span><b>${pcs(sold)}</b><small>${money(soldSum)}</small></div>
          <div class="kpi"><span>Закупочная цена</span><b>${sumVal(p.buyPrice)}</b></div>
          <div class="kpi"><span>Розничная цена</span><b>${sumVal(p.sellPrice)}</b><small>${margin != null ? 'наценка ' + margin + '%' : ''}</small></div>
        </div>
        <div class="btn-row">
          <button class="btn btn-primary" data-act="productToCart" data-id="${p.id}"${raw(p.stock <= 0 ? ' disabled' : '')}>${ic('cart')}В корзину</button>
          <button class="btn" data-act="incomeFor" data-id="${p.id}">${ic('income')}Приход</button>
        </div>
        <section class="card flush"><div class="card-h"><h3 class="sec-title">Движение товара</h3></div>
          <div class="rows">${moves.length ? moves.map((m) => html`<button class="row" data-act="${m.act}" data-id="${m.id}"><span class="row-ico ${m.type}">${ic(TYPE_ICON[m.type])}</span><span class="row-main"><span class="row-title">${m.label}</span><span class="row-sub"><span>${whenShort(m.ts)}</span><span>${shortName(m.who)}</span></span></span><span class="amt ${m.qty > 0 ? 'plus' : 'minus'}">${signed(m.qty)} шт</span></button>`) : empty('journal', 'Операций с товаром ещё не было')}</div>
        </section>
        <div class="btn-row">
          <button class="btn" data-act="editProduct" data-id="${p.id}">${ic('edit')}Изменить</button>
          <button class="btn btn-danger" data-act="deleteProduct" data-id="${p.id}">${ic('trash')}Удалить</button>
        </div>
      </div>`);
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
    const dup = v && S.products.find((p) => p.barcode === v && p.id !== (form && form.dataset.id));
    let h;
    if (!v) h = 'Пусто — приложение создаст внутренний код EAN-13 (префикс 200).';
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
    const v = p || { name: typedCode ? '' : typed, category: U.prodCat !== 'all' ? U.prodCat : 'cases', barcode: opts.barcode || typedCode, buyPrice: '', sellPrice: '', stock: '', min: 3 };
    openSheet(html`${sheetHead(isNew ? 'Новый товар' : 'Изменить товар', isNew ? 'Карточка товара на складе' : v.name)}
      <form class="sheet-body" id="pform" data-submit="saveProduct" data-id="${isNew ? '' : v.id}" data-after="${opts.after || ''}" novalidate>
        <div class="field"><label for="pf-name">Название</label><input class="input" id="pf-name" name="name" maxlength="80" autocomplete="off" value="${v.name}" placeholder="Например, Чехол Silicone Case iPhone 16"></div>
        <div class="field"><label for="pf-cat">Категория</label><select class="select" id="pf-cat" name="category">${CATS.map((c) => html`<option value="${c.id}"${raw(c.id === v.category ? ' selected' : '')}>${c.name}</option>`)}</select></div>
        <div class="field"><label for="pf-code">Штрих-код (EAN-13)</label>
          <div class="search-row"><input class="input mono" id="pf-code" name="barcode" inputmode="numeric" maxlength="14" autocomplete="off" value="${v.barcode}" placeholder="13 цифр" data-in="pfCode" data-enter="pfCodeEnter"><button type="button" class="icon-btn" data-act="scanField" aria-label="Сканировать штрих-код">${ic('scan')}</button><button type="button" class="btn btn-sm" data-act="genCode">Создать</button></div>
          <span class="hint" id="pf-code-hint"></span></div>
        <div class="field-grid">
          <div class="field"><label for="pf-buy">Закупочная цена, сум</label><input class="input money" id="pf-buy" name="buy" inputmode="numeric" autocomplete="off" value="${v.buyPrice}" data-in="pfCalc"></div>
          <div class="field"><label for="pf-sell">Розничная цена, сум</label><input class="input money" id="pf-sell" name="sell" inputmode="numeric" autocomplete="off" value="${v.sellPrice}" data-in="pfCalc"></div>
          ${isNew ? html`<div class="field"><label for="pf-stock">Начальный остаток, шт</label><input class="input" id="pf-stock" name="stock" inputmode="numeric" autocomplete="off" value="${opts.after === 'income' ? 0 : v.stock}" placeholder="0"></div>` : ''}
          <div class="field${isNew ? '' : ' wide'}"><label for="pf-min">Мин. остаток, шт</label><input class="input" id="pf-min" name="min" inputmode="numeric" autocomplete="off" value="${v.min}"></div>
        </div>
        <p class="hint" id="pf-margin">${marginText(v.buyPrice, v.sellPrice)}</p>
        ${isNew ? (opts.after === 'income' ? html`<p class="hint">Товар появится в приходе — количество укажете там.</p>` : '') : html`<p class="hint">Остаток меняется только через продажу, приход, возврат и инвентаризацию.</p>`}
        <p class="err" id="pf-err" role="alert"></p>
        <button class="btn btn-primary btn-lg" type="submit">${isNew ? 'Добавить товар' : 'Сохранить'}</button>
      </form>`);
    codeHint();
  }
  function saveProduct(form) {
    const f = Object.fromEntries(new FormData(form));
    const id = form.dataset.id;
    const err = (m) => { $('#pf-err').textContent = m; };
    const name = String(f.name || '').trim();
    if (!name) return err('Введите название товара');
    let code = normCode(f.barcode);
    if (!code) code = genBarcode();
    if (!validEan(code)) return err('Штрих-код должен быть EAN-13: 13 цифр с верной контрольной цифрой');
    const dup = S.products.find((p) => p.barcode === code && p.id !== id);
    if (dup) return err(`Штрих-код уже занят товаром «${dup.name}»`);
    const buy = parseMoney(f.buy), sell = parseMoney(f.sell);
    if (!sell) return err('Укажите розничную цену');
    const min = parseQty(f.min) || 0;
    const category = CAT[f.category] ? f.category : 'accessories';
    if (id) {
      Object.assign(P(id), { name, category, barcode: code, buyPrice: buy, sellPrice: sell, min });
      save();
      render();
      openProduct(id);
      toast('Изменения сохранены', 'ok');
      return;
    }
    const p = { id: uid('p'), name, category, barcode: code, buyPrice: buy, sellPrice: sell, stock: 0, min };
    S.products.push(p);
    const stock = parseQty(f.stock) || 0;
    if (stock > 0) {
      p.stock = stock;
      const no = S.seq.inc++;
      S.incomes.push({ id: 'i' + no, no, ts: Date.now(), userId: me().id, supplier: 'Начальный остаток', items: [{ pid: p.id, name, qty: stock, cost: buy }], total: stock * buy });
    }
    if (form.dataset.after === 'income') {
      addIncomeLine(p.id);
      closeSheet();
    }
    save();
    render();
    if (form.dataset.after !== 'income') openProduct(p.id);
    toast(`Товар добавлен: ${name}`, 'ok');
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
    const lines = S.incomeDraft.lines.filter((l) => P(l.pid));
    return { units: sum(lines, (l) => l.qty || 0), total: sum(lines, (l) => (l.qty || 0) * (l.cost || 0)), n: lines.length };
  }
  SCREENS.income = {
    title: 'Приход',
    view() {
      const d = S.incomeDraft;
      const lines = d.lines.filter((l) => P(l.pid));
      const t = incTotals();
      const suppliers = [...new Set(S.incomes.map((i) => i.supplier).filter((s) => s && s !== 'Начальный остаток'))];
      const recent = S.incomes.slice(-10).reverse();
      return html`
      <section class="card">
        <div class="card-h"><h2 class="sec-title">Новый приход</h2><span class="pill accent">${incNo({ no: S.seq.inc })}</span></div>
        <div class="field"><label for="inc-sup">Поставщик</label><input class="input" id="inc-sup" list="sup-list" autocomplete="off" maxlength="60" placeholder="Например, Baseus Uzbekistan" value="${d.supplier}" data-in="incSup"><datalist id="sup-list">${suppliers.map((s) => html`<option value="${s}"></option>`)}</datalist></div>
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
        <div class="rows">${recent.length ? recent.map(incomeRow) : empty('income', 'Приходов ещё не было')}</div>
      </section>`;
    },
  };

  // Возврат
  function refundForm(sale) {
    const lines = sale.items.map((it) => ({ ...it, done: returnedQty(sale.id, it.pid) }));
    const total = sum(lines, (l) => (U.refQty[l.pid] || 0) * l.price);
    const anyLeft = lines.some((l) => l.qty - l.done > 0);
    return html`<section class="card">
      <div class="card-h"><div><h2 class="sec-title">Чек №${sale.no}</h2><div class="sec-sub">${fmtDT(sale.ts)} · ${shortName(sale.userId)} · ${PAY[sale.payment].name}${sale.customer ? ' · ' + sale.customer : ''}</div></div><button class="btn btn-sm" data-act="refClear">Другой чек</button></div>
      <div class="rows inset">${lines.map((l) => {
        const left = l.qty - l.done;
        const q = U.refQty[l.pid] || 0;
        return html`<div class="cart-line">
          <div class="cart-name">${l.name}<div class="cart-price">${money(l.price)} · куплено ${l.qty}${l.done ? `, уже возвращено ${l.done}` : ''}</div></div><span></span>
          <div class="cart-ctrl">${left > 0
            ? html`<div class="stepper"><button data-act="refQty" data-id="${l.pid}" data-d="-1" aria-label="Меньше"${raw(q <= 0 ? ' disabled' : '')}>${ic('minus', 'i-sm')}</button><span class="qty-val">${q}</span><button data-act="refQty" data-id="${l.pid}" data-d="1" aria-label="Больше"${raw(q >= left ? ' disabled' : '')}>${ic('plus', 'i-sm')}</button></div><span class="line-total minus">${q ? '−' + money(q * l.price) : ''}</span>`
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
      <p class="hint">Товар вернётся на склад, сумма вычтется из выручки.</p>` : html`<p class="hint">По этому чеку всё уже возвращено.</p>`}
    </section>`;
  }
  SCREENS.refund = {
    title: 'Возврат',
    view() {
      const sale = U.refSale && saleById(U.refSale);
      const lastNo = S.sales.length ? S.sales[S.sales.length - 1].no : 1001;
      return html`
      ${sale ? refundForm(sale) : html`
      <section class="card">
        <div class="card-h"><h2 class="sec-title">Найти чек</h2></div>
        <div class="search-row"><label class="search"><span class="sr-only">Номер чека</span>${ic('receipt')}<input class="input" id="ref-no" inputmode="numeric" enterkeyhint="search" autocomplete="off" placeholder="Номер чека, например ${lastNo}" data-enter="refFind"></label><button class="btn btn-primary" data-act="refFind">Найти</button></div>
        <p class="hint">Или выберите чек из последних продаж.</p>
      </section>
      <section class="card flush"><div class="card-h"><h2 class="sec-title">Последние чеки</h2></div>
        <div class="rows">${S.sales.length ? S.sales.slice(-12).reverse().map((s) => saleRow(s, 'refPick')) : empty('receipt', 'Продаж ещё не было')}</div>
      </section>`}
      <section class="card flush"><div class="card-h"><h2 class="sec-title">Последние возвраты</h2></div>
        <div class="rows">${S.returns.length ? S.returns.slice(-8).reverse().map(returnRow) : empty('refund', 'Возвратов не было')}</div>
      </section>`;
    },
  };

  // Инвентаризация
  function auditList() {
    const q = U.audQ.trim();
    let list = q && !/^\d{8,}$/.test(q) ? searchProducts(q) : S.products.slice();
    if (U.audCat !== 'all') list = list.filter((p) => p.category === U.audCat);
    if (U.audDiff) list = list.filter((p) => S.auditDraft.counts[p.id] !== undefined && S.auditDraft.counts[p.id] !== p.stock);
    return list.sort((a, b) => a.name.localeCompare(b.name, 'ru'));
  }
  function audDiffCell(p) {
    const a = S.auditDraft.counts[p.id];
    if (a === undefined) return { cls: '', text: '' };
    const d = a - p.stock;
    return { cls: d < 0 ? 'bad' : d > 0 ? 'good' : 'ok', text: d ? signed(d) : '✓' };
  }
  function audRow(p) {
    const a = S.auditDraft.counts[p.id];
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
      ${s.checked ? '' : html`<p class="hint">Пересчитайте товар на полке и введите факт. Каждое сканирование штрих-кода добавляет +1 к факту. При проведении остаток станет равен факту.</p>`}`;
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
    title: 'Инвентаризация',
    view() {
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
        <div class="rows">${S.audits.length ? S.audits.slice(-6).reverse().map(auditRow) : empty('audit', 'Инвентаризаций ещё не было')}</div>
      </section>`;
    },
    bar() {
      const n = Object.keys(S.auditDraft.counts).filter((id) => P(id)).length;
      if (!n) return '';
      return html`<div class="checkout-inner"><button class="btn" data-act="audReset">Сбросить</button><button class="btn btn-primary btn-lg" data-act="audPost">${ic('check')}Провести · ${n}</button></div>`;
    },
  };

  // Отчёты
  function niceStep(x) {
    const p = Math.pow(10, Math.floor(Math.log10(x)));
    const f = x / p;
    return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * p;
  }
  function series(from) {
    const now = Date.now();
    const add = (m, k, v) => m.set(k, (m.get(k) || 0) + v);
    const two = (h) => String(h).padStart(2, '0');
    if (U.period === 'today') {
      const m = new Map();
      for (const s of S.sales) if (s.ts >= from) add(m, new Date(s.ts).getHours(), s.total);
      for (const r of S.returns) if (r.ts >= from) add(m, new Date(r.ts).getHours(), -r.total);
      const hours = [...m.keys()];
      const a = Math.min(9, ...hours), b = Math.max(20, new Date(now).getHours(), ...hours);
      const res = [];
      for (let h = a; h <= b; h++) res.push({ v: m.get(h) || 0, x: two(h), tip: `${two(h)}:00–${two(h)}:59` });
      return res;
    }
    const days = Math.round((sod(now) - from) / DAY) + 1;
    if (days <= 62) {
      const m = new Map();
      for (const s of S.sales) if (s.ts >= from) add(m, sod(s.ts), s.total);
      for (const r of S.returns) if (r.ts >= from) add(m, sod(r.ts), -r.total);
      const res = [];
      for (let t = from; t <= now; t = sod(t + DAY * 1.5)) {
        res.push({ v: m.get(t) || 0, x: days <= 8 ? weekday(t) : String(new Date(t).getDate()), tip: `${fmtDate(t)}, ${weekday(t)}` });
      }
      return res;
    }
    const mk = (t) => { const d = new Date(t); return d.getFullYear() * 12 + d.getMonth(); };
    const m = new Map();
    for (const s of S.sales) if (s.ts >= from) add(m, mk(s.ts), s.total);
    for (const r of S.returns) if (r.ts >= from) add(m, mk(r.ts), -r.total);
    const res = [];
    for (let k = mk(from); k <= mk(now); k++) {
      const d = new Date(Math.floor(k / 12), k % 12, 1);
      res.push({ v: m.get(k) || 0, x: d.toLocaleDateString('ru-RU', { month: 'short' }).replace('.', ''), tip: d.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' }) });
    }
    return res;
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
  function hbars(rows, total, group) {
    const max = Math.max(1, ...rows.map((r) => r.v));
    return html`<div class="hbars">${rows.map((r, i) => html`<div class="hbar">
      <div class="hbar-top"><span class="hbar-name">${r.lead || ''}<span class="txt">${r.name}</span></span><b>${money(r.v)}${total ? html` <span class="faint">· ${pct(r.v, total)}%</span>` : ''}${r.sub ? html` <span class="faint">· ${r.sub}</span>` : ''}</b></div>
      <div class="hbar-track"><i data-grow="${group}-${r.key}" data-to="${(Math.max(0, r.v) / max) * 100}" style="width:${(Math.max(0, r.v) / max) * 100}%;--n:${i}"></i></div>
    </div>`)}</div>`;
  }
  SCREENS.reports = {
    title: 'Отчёты', tab: true,
    view() {
      const from = periodStart(U.period);
      const st = calc(from);
      const range = U.period === 'today' ? fmtDate(Date.now(), true) : `${fmtDate(from, new Date(from).getFullYear() !== new Date().getFullYear())} — ${fmtDate(Date.now(), true)}`;
      const pays = Object.entries(PAY).map(([k, v]) => ({ key: k, name: v.name, lead: ic(v.icon, 'i-sm'), v: st.byPay[k] || 0 }));
      const sellers = Object.entries(st.bySeller)
        .map(([id, b]) => ({ key: id, name: empName(id), v: b.sum, sub: `${b.checks} ${plural(b.checks, ['чек', 'чека', 'чеков'])}` }))
        .sort((a, b) => b.v - a.v);
      const top = Object.values(st.byProd).filter((p) => p.qty > 0).sort((a, b) => b.sum - a.sum).slice(0, 10)
        .map((p, i) => ({ key: p.pid, name: p.name, lead: html`<span class="rank">${i + 1}</span>`, v: p.sum, sub: pcs(p.qty) }));
      const low = lowStock();
      const units = sum(S.products, (p) => p.stock);
      const costVal = sum(S.products, (p) => p.stock * p.buyPrice);
      const retailVal = sum(S.products, (p) => p.stock * p.sellPrice);
      return html`
      <div class="seg" role="tablist" aria-label="Период" data-seg="period" data-i="${Object.keys(PERIODS).indexOf(U.period)}" style="--n:4;--i:${Object.keys(PERIODS).indexOf(U.period)}"><span class="seg-pill" aria-hidden="true"></span>${Object.entries(PERIODS).map(([k, v]) => html`<button role="tab" aria-selected="${String(U.period === k)}" class="${U.period === k ? 'on' : ''}" data-act="period" data-v="${k}">${v}</button>`)}</div>
      <p class="summary-line">${range}</p>
      <div class="kpis">
        <div class="kpi main"><span>Выручка</span><b>${sumVal(st.revenue, 'k-rev')}</b><small>продажи ${money(st.gross)}${st.refunds ? ` · возвраты −${money(st.refunds)} (${st.rets.length})` : ' · возвратов нет'}</small></div>
        <div class="kpi"><span>Чеков</span><b>${cnt(st.checks, 'k-checks')}</b></div>
        <div class="kpi"><span>Продано единиц</span><b>${cnt(st.units, 'k-units')}</b></div>
        <div class="kpi"><span>Средний чек</span><b>${sumVal(st.avg, 'k-avg')}</b></div>
        <div class="kpi"><span>Валовая прибыль</span><b>${sumVal(st.profit, 'k-profit')}</b><small>${st.revenue > 0 ? 'маржа ' + pct(st.profit, st.revenue) + '%' : ''}</small></div>
      </div>
      <section class="card"><div class="card-h"><h2 class="sec-title">${U.period === 'today' ? 'Выручка по часам' : 'Выручка по дням'}</h2><span class="sec-sub">с учётом возвратов</span></div>${chart(series(from))}</section>
      <section class="card"><h2 class="sec-title">Способы оплаты</h2>${st.checks || st.refunds ? hbars(pays, st.revenue, 'pay') : empty('cash', 'Нет оплат за период')}</section>
      <section class="card"><h2 class="sec-title">Продавцы</h2>${sellers.length ? hbars(sellers, 0, 'sel') : empty('users', 'Нет продаж за период')}</section>
      <section class="card"><div class="card-h"><h2 class="sec-title">Топ товаров</h2><span class="sec-sub">по выручке</span></div>${top.length ? hbars(top, 0, 'top') : empty('box', 'Нет продаж за период')}</section>
      <section class="card flush"><div class="card-h"><h2 class="sec-title">Мало на складе</h2><span class="pill ${low.length ? 'warn' : 'good'}">${low.length}</span></div>
        <div class="rows">${low.length ? low.map((p) => html`<div class="row"><span class="row-main"><span class="row-title">${p.name}</span><span class="row-sub"><span>остаток ${p.stock} · минимум ${p.min}</span></span></span><button class="btn btn-sm" data-act="incomeFor" data-id="${p.id}">${ic('income', 'i-sm')}Приход</button></div>`) : empty('check', 'Всех товаров достаточно')}</div>
      </section>
      <section class="card"><h2 class="sec-title">Склад сейчас</h2>
        <div class="stat-table">
          <span>Позиций в каталоге</span><b>${S.products.length}</b>
          <span>Единиц на складе</span><b>${pcs(units)}</b>
          <span>Стоимость по закупке</span><b>${money(costVal)}</b>
          <span>Стоимость в рознице</span><b>${money(retailVal)}</b>
          <span>Будущая наценка</span><b>${money(retailVal - costVal)}</b>
        </div>
      </section>`;
    },
  };

  // Журнал
  function journalEntries() {
    const e = [];
    for (const s of S.sales) {
      e.push({
        type: 'sale', ts: s.ts, id: s.id, title: 'Продажа №' + s.no,
        sub: [shortName(s.userId), PAY[s.payment].name, s.customer].filter(Boolean),
        side: html`<span class="amt plus">+${num(s.total)}</span>`, net: s.total,
        hay: [s.no, s.customer, empName(s.userId), PAY[s.payment].name, ...s.items.map((i) => i.name)].join(' ').toLowerCase(),
      });
    }
    for (const r of S.returns) {
      e.push({
        type: 'ret', ts: r.ts, id: r.id, title: `Возврат ${retNo(r)}`,
        sub: ['чек №' + r.saleNo, shortName(r.userId), r.reason],
        side: html`<span class="amt minus">−${num(r.total)}</span>`, net: -r.total,
        hay: [retNo(r), r.saleNo, r.reason, empName(r.userId), ...r.items.map((i) => i.name)].join(' ').toLowerCase(),
      });
    }
    for (const d of S.incomes) {
      e.push({
        type: 'inc', ts: d.ts, id: d.id, title: `Приход ${incNo(d)}`,
        sub: [d.supplier || 'без поставщика', shortName(d.userId)],
        side: html`<span class="amt">+${pcs(sum(d.items, (i) => i.qty))}</span><span class="faint">${money(d.total)}</span>`, net: 0,
        hay: [incNo(d), d.supplier, empName(d.userId), ...d.items.map((i) => i.name)].join(' ').toLowerCase(),
      });
    }
    for (const a of S.audits) {
      e.push({
        type: 'aud', ts: a.ts, id: a.id, title: `Инвентаризация ${audNo(a)}`,
        sub: [shortName(a.userId), 'проверено ' + a.items.length],
        side: html`<span class="amt">${auditDiffText(a)}</span>`, net: 0,
        hay: [audNo(a), empName(a.userId), ...a.items.map((i) => i.name)].join(' ').toLowerCase(),
      });
    }
    return e.sort((a, b) => b.ts - a.ts);
  }
  function journalList() {
    const q = U.jQ.trim().toLowerCase().replace(/^№/, '');
    let list = journalEntries();
    if (U.jType !== 'all') list = list.filter((e) => e.type === U.jType);
    if (q) list = list.filter((e) => e.hay.includes(q));
    if (!list.length) return empty('journal', 'Операций не найдено');
    const shown = list.slice(0, U.jLimit);
    const groups = [];
    for (const e of shown) {
      const d = sod(e.ts);
      if (!groups.length || groups[groups.length - 1].d !== d) groups.push({ d, items: [] });
      groups[groups.length - 1].items.push(e);
    }
    return html`${groups.map((g) => {
      const money_ = g.items.some((e) => e.type === 'sale' || e.type === 'ret');
      return html`<div class="day-h"><span>${dayLabel(g.d)}</span>${money_ ? html`<span>${signed(sum(g.items, (e) => e.net))} сум</span>` : ''}</div>
      ${g.items.map((e) => html`<button class="row" data-act="openDoc" data-type="${e.type}" data-id="${e.id}"><span class="row-ico ${e.type}">${ic(TYPE_ICON[e.type])}</span><span class="row-main"><span class="row-title">${e.title}</span><span class="row-sub">${[fmtTime(e.ts), ...e.sub].map((s) => html`<span>${s}</span>`)}</span></span><span class="row-side">${e.side}</span></button>`)}`;
    })}
    ${list.length > shown.length ? html`<div class="row"><button class="btn btn-block" data-act="jMore">Показать ещё · ${list.length - shown.length}</button></div>` : ''}`;
  }
  SCREENS.journal = {
    title: 'Журнал',
    view() {
      const types = [['all', 'Все', S.sales.length + S.returns.length + S.incomes.length + S.audits.length], ['sale', 'Продажи', S.sales.length], ['inc', 'Приходы', S.incomes.length], ['ret', 'Возвраты', S.returns.length], ['aud', 'Инвентаризации', S.audits.length]];
      return html`
      <label class="search"><span class="sr-only">Поиск по журналу</span>${ic('search')}<input class="input" id="j-q" type="search" enterkeyhint="search" autocomplete="off" placeholder="№ чека, покупатель, товар, сотрудник" value="${U.jQ}" data-in="jQ"></label>
      <div class="chips">${types.map(([k, l, n]) => html`<button class="chip${U.jType === k ? ' on' : ''}" data-act="jType" data-v="${k}">${l} <span class="count">${n}</span></button>`)}</div>
      <section class="card flush"><div class="rows" id="jlist">${journalList()}</div></section>`;
    },
  };

  // Сотрудники
  function empRow(e, today, month) {
    const t = today.bySeller[e.id] || { checks: 0, sum: 0 };
    const m = month.bySeller[e.id] || { checks: 0, sum: 0 };
    return html`<button class="row" data-act="openEmp" data-id="${e.id}">
      <span class="avatar lg ${e.role}">${initials(e.name)}</span>
      <span class="row-main"><span class="row-title">${e.name}</span>
        <span class="row-sub"><span class="pill ${e.role === 'owner' ? 'teal' : 'accent'}">${ROLES[e.role]}</span>${e.id === S.currentUserId ? html`<span class="pill good">на кассе</span>` : ''}${e.active ? '' : html`<span class="pill">неактивен</span>`}</span>
        <span class="row-sub">Сегодня: ${t.checks} ${plural(t.checks, ['чек', 'чека', 'чеков'])} · ${money(t.sum)}</span></span>
      <span class="row-side"><span class="amt">${compact(m.sum)}</span><span class="faint">за 30 дней</span></span>
    </button>`;
  }
  SCREENS.staff = {
    title: 'Сотрудники',
    view() {
      const today = calc(daysAgo(0));
      const month = calc(daysAgo(29));
      const cur = me();
      const list = S.employees.slice().sort((a, b) => (b.active ? 1 : 0) - (a.active ? 1 : 0) || (a.role === 'owner' ? -1 : 0) - (b.role === 'owner' ? -1 : 0) || a.name.localeCompare(b.name, 'ru'));
      return html`
      <section class="card">
        <div class="card-h"><span class="avatar lg ${cur.role}">${initials(cur.name)}</span><div class="row-main"><span class="label">Сейчас на кассе</span><span class="row-title">${cur.name}</span></div><button class="btn btn-sm" data-act="switchUser">${ic('swap', 'i-sm')}Сменить</button></div>
        <p class="hint">Продажи, приходы, возвраты и инвентаризации записываются на сотрудника, который стоит на кассе.</p>
      </section>
      <section class="card flush">
        <div class="card-h"><h2 class="sec-title">Команда</h2><button class="btn btn-primary btn-sm" data-act="newEmp">${ic('plus', 'i-sm')}Добавить</button></div>
        <div class="rows">${list.map((e) => empRow(e, today, month))}</div>
      </section>`;
    },
  };
  function openEmp(id) {
    const e = E(id);
    if (!e) return;
    const stats = [['Сегодня', daysAgo(0)], ['7 дней', daysAgo(6)], ['30 дней', daysAgo(29)]]
      .map(([l, f]) => [l, calc(f).bySeller[id] || { checks: 0, sum: 0 }]);
    const sales = S.sales.filter((s) => s.userId === id).slice(-10).reverse();
    openSheet(html`${sheetHead(e.name, ROLES[e.role] + (e.active ? '' : ' · неактивен'))}
      <div class="sheet-body">
        <div class="stat-table"><span>Телефон</span><b class="selectable">${e.phone || '—'}</b></div>
        <div class="kpis three">${stats.map(([l, c]) => html`<div class="kpi"><span>${l}</span><b>${compact(c.sum)}</b><small>${c.checks} ${plural(c.checks, ['чек', 'чека', 'чеков'])}</small></div>`)}</div>
        <div class="btn-row">
          ${e.active && e.id !== S.currentUserId ? html`<button class="btn btn-primary" data-act="setCurrent" data-id="${e.id}">${ic('swap')}Встать на кассу</button>` : ''}
          <button class="btn" data-act="editEmp" data-id="${e.id}">${ic('edit')}Изменить</button>
        </div>
        <section class="card flush"><div class="card-h"><h3 class="sec-title">Последние продажи</h3></div>
          <div class="rows">${sales.length ? sales.map((s) => saleRow(s)) : empty('receipt', 'Продаж пока нет')}</div>
        </section>
        ${e.role !== 'owner' ? html`<button class="btn${e.active ? ' btn-danger' : ''}" data-act="toggleEmp" data-id="${e.id}">${e.active ? 'Отключить сотрудника' : 'Вернуть в команду'}</button>` : ''}
      </div>`);
  }
  function empForm(e) {
    const v = e || { name: '', role: 'seller', phone: '+998 ' };
    openSheet(html`${sheetHead(e ? 'Изменить сотрудника' : 'Новый сотрудник')}
      <form class="sheet-body" data-submit="saveEmp" data-id="${e ? e.id : ''}" novalidate>
        <div class="field"><label for="ef-name">Имя и фамилия</label><input class="input" id="ef-name" name="name" maxlength="60" autocomplete="off" value="${v.name}" placeholder="Например, Мадина Алиева"></div>
        <div class="field"><label for="ef-role">Роль</label><select class="select" id="ef-role" name="role">${Object.entries(ROLES).map(([k, l]) => html`<option value="${k}"${raw(v.role === k ? ' selected' : '')}>${l}</option>`)}</select></div>
        <div class="field"><label for="ef-phone">Телефон</label><input class="input" id="ef-phone" name="phone" type="tel" maxlength="20" autocomplete="off" value="${v.phone}"></div>
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
        <div class="card-h"><h2 class="sec-title">Магазин</h2><span class="sec-sub">печатается в чеке</span></div>
        <form class="stack" data-submit="saveStore">
          <div class="field"><label for="st-name">Название</label><input class="input" id="st-name" name="name" maxlength="40" autocomplete="off" value="${S.store.name}"></div>
          <div class="field"><label for="st-addr">Адрес</label><input class="input" id="st-addr" name="address" maxlength="100" autocomplete="off" value="${S.store.address}"></div>
          <button class="btn" type="submit">Сохранить</button>
        </form>
      </section>
      <section class="card">
        <div class="card-h"><h2 class="sec-title">Данные</h2>${S.meta.demo ? html`<span class="pill warn">демо-данные</span>` : ''}</div>
        <p class="sec-sub">Товары, продажи и документы хранятся на этом устройстве, в памяти браузера: ${S.products.length} ${plural(S.products.length, ['товар', 'товара', 'товаров'])}, ${S.sales.length} ${plural(S.sales.length, ['чек', 'чека', 'чеков'])}, ${S.incomes.length} ${plural(S.incomes.length, ['приход', 'прихода', 'приходов'])}. Не очищайте данные сайта в браузере, иначе учёт пропадёт.</p>
        ${S.meta.demo ? html`<p class="sec-sub">Сейчас загружен пример магазина. Чтобы вести свой учёт, начните с чистого листа — сотрудники и название магазина сохранятся.</p>` : ''}
        <div class="btn-row"><button class="btn" data-act="resetDemo">Загрузить демо заново</button><button class="btn btn-danger" data-act="wipeData">Начать с чистого листа</button></div>
      </section>`;
    },
  };

  // Сканер
  function scanResult(p) {
    return html`<section class="card">
      <div class="card-h"><div><h2 class="sec-title">${p.name}</h2><div class="sec-sub">${catOf(p).name}</div></div>${stockPill(p)}</div>
      <div class="barcode">${barcodeSVG(p.barcode)}</div>
      <div class="stat-table"><span>Остаток</span><b>${pcs(p.stock)}</b><span>Розничная цена</span><b>${money(p.sellPrice)}</b><span>Закупочная цена</span><b>${money(p.buyPrice)}</b></div>
      <div class="btn-row">
        <button class="btn btn-primary" data-act="sellNow" data-id="${p.id}"${raw(p.stock <= 0 ? ' disabled' : '')}>${ic('cart')}Продать</button>
        <button class="btn" data-act="incomeFor" data-id="${p.id}">${ic('income')}Приход</button>
        <button class="btn" data-act="openProduct" data-id="${p.id}">${ic('box')}Карточка</button>
      </div>
    </section>`;
  }
  SCREENS.scanner = {
    title: 'Сканер',
    view() {
      const code = U.scanCode;
      const p = code && byCode(code);
      return html`
      <button class="scan-hero" data-act="scanLookupCam"><span class="qa-ico">${ic('scan')}</span><b>Сканировать камерой</b><span class="sec-sub">Наведите камеру телефона на штрих-код товара</span></button>
      <section class="card">
        <div class="field"><label for="scan-code">Или введите код вручную</label>
          <div class="search-row"><input class="input mono" id="scan-code" inputmode="numeric" enterkeyhint="search" autocomplete="off" placeholder="${S.products[0] ? S.products[0].barcode : '4780000000000'}" value="${code}" data-enter="scanLookup"><button class="btn btn-primary" data-act="scanLookup">Найти</button></div>
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
  function openReceipt(id, fresh) {
    const s = saleById(id);
    if (!s) return;
    const rets = saleReturns(id);
    const canReturn = s.items.some((i) => i.qty > returnedQty(s.id, i.pid));
    const head = fresh
      ? html`<div class="sheet-h"><div class="done"><svg class="ok-check" viewBox="0 0 52 52" aria-hidden="true"><circle cx="26" cy="26" r="23"/><path d="m15 27 7 7 15-15"/></svg><div><h2>Чек пробит</h2><div class="sec-sub">№${s.no} · ${money(s.total)} · ${PAY[s.payment].name}</div></div></div><button class="icon-btn" data-act="closeSheet" aria-label="Закрыть">${ic('x')}</button></div>`
      : sheetHead('Чек №' + s.no, fmtDT(s.ts));
    const paper = html`
          <div class="rc-center"><div class="rc-store">${S.store.name}</div>${S.store.address ? html`<div class="rc-muted">${S.store.address}</div>` : ''}</div>
          <hr class="rc-hr">
          <div class="rc-row"><span>ЧЕК №${s.no}</span><span>${fmtDT(s.ts)}</span></div>
          <div class="rc-row"><span>Кассир</span><span>${empName(s.userId)}</span></div>
          ${s.customer ? html`<div class="rc-row"><span>Покупатель</span><span>${s.customer}</span></div>` : ''}
          <hr class="rc-hr">
          ${s.items.map((i) => html`<div class="rc-item"><div>${i.name}</div><div class="rc-row"><span class="rc-muted">${i.qty} × ${num(i.price)}</span><span>${num(i.qty * i.price)}</span></div></div>`)}
          <hr class="rc-hr">
          <div class="rc-row rc-total"><span>ИТОГО</span><span>${num(s.total)}</span></div>
          <div class="rc-row"><span>Оплата</span><span>${PAY[s.payment].name}</span></div>
          ${s.payment === 'cash' && s.received > s.total ? html`<div class="rc-row"><span>Получено</span><span>${num(s.received)}</span></div><div class="rc-row"><span>Сдача</span><span>${num(s.received - s.total)}</span></div>` : ''}
          ${rets.length ? html`<hr class="rc-hr">${rets.map((r) => html`<div class="rc-row rc-ret"><span>Возврат ${retNo(r)}, ${fmtDate(r.ts)}</span><span>−${num(r.total)}</span></div>`)}` : ''}
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
  function openReturnDoc(id) {
    const r = S.returns.find((x) => x.id === id);
    if (!r) return;
    openSheet(html`${sheetHead('Возврат ' + retNo(r), fmtDT(r.ts))}
      <div class="sheet-body">
        <div class="stat-table">
          <span>По чеку</span><b><button class="link" data-act="openSale" data-id="${r.saleId}">№${r.saleNo}</button></b>
          <span>Оформил</span><b>${empName(r.userId)}</b>
          <span>Причина</span><b>${r.reason}</b>
          <span>Деньги вернули</span><b>${PAY[r.payment].name}</b>
        </div>
        <section class="card flush"><div class="rows">${r.items.map((i) => html`<div class="row"><span class="row-main"><span class="row-title">${i.name}</span><span class="row-sub"><span>${i.qty} × ${num(i.price)}</span><span>вернулся на склад</span></span></span><span class="amt minus">−${num(i.qty * i.price)}</span></div>`)}</div></section>
        <div class="totals-row big"><span>Сумма возврата</span><span class="num bad">−${money(r.total)}</span></div>
        <button class="btn" data-act="closeSheet">Закрыть</button>
      </div>`);
  }
  function openIncomeDoc(id) {
    const d = S.incomes.find((x) => x.id === id);
    if (!d) return;
    openSheet(html`${sheetHead('Приход ' + incNo(d), fmtDT(d.ts))}
      <div class="sheet-body">
        <div class="stat-table"><span>Поставщик</span><b>${d.supplier || '—'}</b><span>Принял</span><b>${empName(d.userId)}</b><span>Товаров</span><b>${pcs(sum(d.items, (i) => i.qty))}</b></div>
        <section class="card flush"><div class="rows">${d.items.map((i) => html`<button class="row" data-act="openProduct" data-id="${i.pid}"><span class="row-main"><span class="row-title">${i.name}</span><span class="row-sub"><span>${i.qty} шт × ${num(i.cost)}</span></span></span><span class="amt">${num(i.qty * i.cost)}</span></button>`)}</div></section>
        <div class="totals-row big"><span>Сумма закупки</span><span class="num">${money(d.total)}</span></div>
        <button class="btn" data-act="closeSheet">Закрыть</button>
      </div>`);
  }
  function openAuditDoc(id) {
    const a = S.audits.find((x) => x.id === id);
    if (!a) return;
    const diffs = a.items.filter((i) => i.actual !== i.expected);
    const short = sum(diffs, (i) => Math.max(0, i.expected - i.actual) * i.cost);
    const extra = sum(diffs, (i) => Math.max(0, i.actual - i.expected) * i.cost);
    openSheet(html`${sheetHead('Инвентаризация ' + audNo(a), fmtDT(a.ts))}
      <div class="sheet-body">
        <div class="stat-table">
          <span>Провёл</span><b>${empName(a.userId)}</b>
          <span>Проверено позиций</span><b>${a.items.length}</b>
          <span>Совпало</span><b>${a.items.length - diffs.length}</b>
          <span>Недостача</span><b class="${short ? 'bad' : ''}">${short ? '−' + money(short) : '—'}</b>
          <span>Излишек</span><b class="${extra ? 'good' : ''}">${extra ? '+' + money(extra) : '—'}</b>
        </div>
        <section class="card flush"><div class="card-h"><h3 class="sec-title">Расхождения</h3></div>
          <div class="rows">${diffs.length ? diffs.map((i) => html`<div class="row"><span class="row-main"><span class="row-title">${i.name}</span><span class="row-sub"><span>учёт ${i.expected} → факт ${i.actual}</span></span></span><span class="amt ${i.actual > i.expected ? 'plus' : 'minus'}">${signed(i.actual - i.expected)} шт</span></div>`) : empty('check', 'Все остатки совпали с учётом')}</div>
        </section>
        <button class="btn" data-act="closeSheet">Закрыть</button>
      </div>`);
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
    const bar = scr.bar ? scr.bar() : '';
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
    const r = route();
    const scr = SCREENS[r];
    const entering = r !== lastRoute;
    const first = lastRoute === null;
    if (entering) {
      if (lastRoute === 'scanner') U.scanCode = '';
      lastRoute = r;
      window.scrollTo(0, 0);
    }
    $('#title').textContent = scr.title;
    if (entering && !first && !reduceMotion()) restartAnim($('#title'), 'title-in');
    $('#eyebrow').textContent = S.store.name + (S.meta.demo ? ' · демо' : '');
    $('#back').hidden = !!scr.tab;
    const e = me();
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
    lowStock() {
      U.prodLow = true;
      U.prodCat = 'all';
      U.prodQ = '';
      go('products');
    },
    switchUser() {
      openSheet(html`${sheetHead('Кто на кассе?', 'Новые операции будут записаны на выбранного сотрудника')}
        <section class="card flush"><div class="rows">${S.employees.filter((e) => e.active).map((e) => html`<button class="row" data-act="setCurrent" data-id="${e.id}"><span class="avatar lg ${e.role}">${initials(e.name)}</span><span class="row-main"><span class="row-title">${e.name}</span><span class="row-sub">${ROLES[e.role]}</span></span>${e.id === S.currentUserId ? html`<span class="pill good">${ic('check')}на кассе</span>` : ''}</button>`)}</div></section>`);
    },
    setCurrent(el) {
      const e = E(el.dataset.id);
      if (!e || !e.active) return;
      S.currentUserId = e.id;
      save();
      closeSheet();
      render();
      toast(`На кассе: ${e.name}`, 'ok');
    },
    openSale(el) { openReceipt(el.dataset.id); },
    openReturn(el) { openReturnDoc(el.dataset.id); },
    openIncome(el) { openIncomeDoc(el.dataset.id); },
    openAudit(el) { openAuditDoc(el.dataset.id); },
    openProduct(el) { openProduct(el.dataset.id); },
    openEmp(el) { openEmp(el.dataset.id); },
    openDoc(el) {
      const id = el.dataset.id;
      ({ sale: openReceipt, ret: openReturnDoc, inc: openIncomeDoc, aud: openAuditDoc })[el.dataset.type](id);
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
      const line = S.cart.items.find((l) => l.pid === el.dataset.id);
      if (line) setCartQty(line.pid, line.qty + Number(el.dataset.d));
    },
    rmCart(el) { setCartQty(el.dataset.id, 0); },
    clearCart() {
      confirmBox({ title: 'Очистить корзину?', text: 'Все товары будут убраны из текущего чека.', ok: 'Очистить', danger: true, onOk() { S.cart.items = []; S.cart.received = ''; save(); render(); } });
    },
    pay(el) { S.cart.payment = el.dataset.v; save(); render(); },
    checkout,
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
    editProduct(el) { productForm(P(el.dataset.id)); },
    deleteProduct(el) {
      const p = P(el.dataset.id);
      if (!p) return;
      confirmBox({ title: 'Удалить товар?', text: `«${p.name}» исчезнет из каталога. Проданные чеки и документы останутся в журнале.`, ok: 'Удалить', danger: true, onOk: () => deleteProduct(p.id) });
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
    genCode() {
      const inp = $('#pf-code');
      if (inp) { inp.value = genBarcode(); codeHint(); }
    },

    // приход
    incAdd(el) {
      addIncomeLine(el.dataset.id);
      U.incQ = '';
      render();
    },
    incRm(el) {
      S.incomeDraft.lines = S.incomeDraft.lines.filter((l) => l.pid !== el.dataset.id);
      save();
      render();
    },
    incClear() {
      confirmBox({ title: 'Очистить приход?', text: 'Все строки текущего прихода будут удалены.', ok: 'Очистить', danger: true, onOk() { S.incomeDraft.lines = []; save(); render(); } });
    },
    newProductIncome() { productForm(null, { after: 'income', name: U.incQ }); },
    postIncome,
    scanIncome() {
      Scan.open({
        title: 'Сканирование прихода', continuous: true,
        onCode(code) {
          const p = byCode(code);
          if (!p) return { ok: false, msg: `Не найден: ${code}. Создайте товар в приходе.` };
          addIncomeLine(p.id);
          render();
          const l = S.incomeDraft.lines.find((x) => x.pid === p.id);
          return { ok: true, msg: `${p.name}: ${l.qty} шт` };
        },
      });
    },

    // возврат
    refFind() {
      const inp = $('#ref-no');
      const n = parseQty(inp && inp.value);
      const s = n != null && S.sales.find((x) => x.no === n);
      if (!s) { toast(n != null ? `Чек №${n} не найден` : 'Введите номер чека', 'bad'); return; }
      U.refSale = s.id;
      U.refQty = {};
      U.enter = true;
      render();
    },
    refPick(el) {
      U.refSale = el.dataset.id;
      U.refQty = {};
      U.enter = true;
      render();
      window.scrollTo(0, 0);
    },
    refClear() { U.refSale = null; U.refQty = {}; U.enter = true; render(); },
    refQty(el) {
      const sale = saleById(U.refSale);
      const it = sale && sale.items.find((i) => i.pid === el.dataset.id);
      if (!it) return;
      const left = it.qty - returnedQty(sale.id, it.pid);
      U.refQty[it.pid] = Math.max(0, Math.min(left, (U.refQty[it.pid] || 0) + Number(el.dataset.d)));
      render();
    },
    refReason(el) { U.refReason = el.dataset.v; render(); },
    postRefund() {
      const sale = saleById(U.refSale);
      if (!sale) return;
      const total = sum(sale.items, (i) => (U.refQty[i.pid] || 0) * i.price);
      confirmBox({ title: `Вернуть ${money(total)}?`, text: `Деньги вернуть: ${PAY[sale.payment].name.toLowerCase()}. Товар вернётся на склад.`, ok: 'Оформить возврат', danger: true, onOk: postRefund });
    },
    refundFor(el) {
      U.refSale = el.dataset.id;
      U.refQty = {};
      closeSheet();
      if (route() !== 'refund') U.backTo = route();
      go('refund');
    },

    // инвентаризация
    audCat(el) { U.audCat = el.dataset.v; U.enter = true; render(); },
    audDiff() { U.audDiff = !U.audDiff; render(); },
    audFill(el) {
      const p = P(el.dataset.id);
      if (!p) return;
      S.auditDraft.counts[p.id] = p.stock;
      save();
      const row = $(`[data-row="${p.id}"]`);
      if (row) row.outerHTML = out(audRow(p));
      refreshAudit();
    },
    audFillAll() {
      let n = 0;
      for (const p of auditList()) if (S.auditDraft.counts[p.id] === undefined) { S.auditDraft.counts[p.id] = p.stock; n++; }
      save();
      render();
      toast(n ? `Факт = учёт для ${n} ${plural(n, ['позиции', 'позиций', 'позиций'])}` : 'Все позиции уже проверены', 'ok');
    },
    audReset() {
      confirmBox({ title: 'Сбросить пересчёт?', text: 'Все введённые фактические количества будут удалены.', ok: 'Сбросить', danger: true, onOk() { S.auditDraft = { counts: {} }; save(); render(); } });
    },
    audPost() {
      const s = auditStats();
      confirmBox({
        title: 'Провести инвентаризацию?',
        text: `Остатки ${s.checked} ${plural(s.checked, ['позиции', 'позиций', 'позиций'])} станут равны факту. Недостача: ${s.short} шт, излишек: ${s.extra} шт.`,
        ok: 'Провести', onOk: postAudit,
      });
    },
    scanAudit() {
      Scan.open({
        title: 'Пересчёт товара', continuous: true,
        onCode(code) {
          const p = byCode(code);
          if (!p) return { ok: false, msg: `Не найден: ${code}` };
          const c = S.auditDraft.counts;
          c[p.id] = (c[p.id] || 0) + 1;
          save();
          render();
          return { ok: true, msg: `${p.name}: факт ${c[p.id]} · учёт ${p.stock}` };
        },
      });
    },

    // отчёты и журнал
    period(el) { U.period = el.dataset.v; render(); },
    jType(el) { U.jType = el.dataset.v; U.jLimit = 80; U.enter = true; render(); },
    jMore() { U.jLimit += 80; setHTML($('#jlist'), journalList()); },

    // сотрудники
    newEmp() { empForm(null); },
    editEmp(el) { empForm(E(el.dataset.id)); },
    toggleEmp(el) {
      const e = E(el.dataset.id);
      if (!e || e.role === 'owner') return;
      const apply = () => {
        e.active = !e.active;
        if (!e.active && S.currentUserId === e.id) S.currentUserId = (S.employees.find((x) => x.role === 'owner' && x.active) || S.employees.find((x) => x.active)).id;
        save();
        closeSheet();
        render();
        toast(e.active ? `${e.name} снова в команде` : `${e.name} отключён`, 'ok');
      };
      if (e.active) confirmBox({ title: 'Отключить сотрудника?', text: `${e.name} не сможет вставать на кассу. Его продажи останутся в отчётах.`, ok: 'Отключить', danger: true, onOk: apply });
      else apply();
    },

    // данные
    resetDemo() {
      confirmBox({
        title: 'Загрузить демо заново?', text: 'Текущие товары, продажи и документы будут заменены примером магазина.', ok: 'Загрузить демо', danger: true,
        onOk() {
          S = normalize(window.KassaDemo.create(Date.now()));
          Object.assign(U, { refSale: null, refQty: {}, scanCode: '' });
          save();
          go('home');
          toast('Демо-данные загружены', 'ok');
        },
      });
    },
    wipeData() {
      confirmBox({
        title: 'Начать с чистого листа?', text: 'Удалятся все товары, продажи, приходы, возвраты и инвентаризации. Сотрудники и название магазина останутся.', ok: 'Удалить всё', danger: true,
        onOk() {
          S = normalize({ v: 1, meta: { demo: false, createdAt: Date.now() }, store: S.store, employees: S.employees, currentUserId: S.currentUserId, products: [], sales: [], returns: [], incomes: [], audits: [], seq: { sale: 1001, ret: 1, inc: 1, aud: 1 } });
          Object.assign(U, { refSale: null, refQty: {}, scanCode: '' });
          save();
          go('home');
          toast('Учёт очищен — добавьте свои товары', 'ok');
        },
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

  const IN = {
    saleQ(el) { U.saleQ = el.value; setHTML($('#sale-suggest'), suggestList(U.saleQ, 'addCart')); },
    customer(el) { S.cart.customer = el.value; save(); },
    received(el) {
      S.cart.received = el.value.replace(/[^\d]/g, '');
      save();
      setHTML($('#change'), changeText());
    },
    prodQ(el) { U.prodQ = el.value; setHTML($('#plist'), productList()); },
    pfCalc() { setHTML($('#pf-margin'), marginText($('#pf-buy').value, $('#pf-sell').value)); },
    pfCode: codeHint,
    incSup(el) { S.incomeDraft.supplier = el.value; save(); },
    incQ(el) { U.incQ = el.value; setHTML($('#inc-suggest'), suggestList(U.incQ, 'incAdd')); },
    incQty(el) { IN.incLine(el, 'qty'); },
    incCost(el) { IN.incLine(el, 'cost'); },
    incLine(el, field) {
      const l = S.incomeDraft.lines.find((x) => x.pid === el.dataset.id);
      if (!l) return;
      l[field] = field === 'qty' ? parseQty(el.value) || 0 : parseMoney(el.value);
      save();
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
      if (n == null) delete S.auditDraft.counts[p.id];
      else S.auditDraft.counts[p.id] = n;
      save();
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
    jQ(el) { U.jQ = el.value; U.jLimit = 80; setHTML($('#jlist'), journalList()); },
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
      const c = S.auditDraft.counts;
      c[p.id] = (c[p.id] || 0) + 1;
      save();
      beep();
      U.audQ = '';
      U.focus = 'aud-q';
      render();
      toast(`${p.name}: факт ${c[p.id]} · учёт ${p.stock}`, 'ok');
    },
    refFind() { ACT.refFind(); },
    scanLookup() { ACT.scanLookup(); },
    scanManual(el) { const v = el.value; el.value = ''; Scan.hit(v, true); },
    pfCodeEnter() { codeHint(); const n = $('#pf-buy'); if (n) n.focus(); },
  };

  const SUBMIT = {
    saveProduct,
    saveEmp(form) {
      const f = Object.fromEntries(new FormData(form));
      const name = String(f.name || '').trim();
      if (!name) { $('#ef-err').textContent = 'Введите имя сотрудника'; return; }
      const role = ROLES[f.role] ? f.role : 'seller';
      const phone = String(f.phone || '').trim();
      const id = form.dataset.id;
      if (id) Object.assign(E(id), { name, role, phone });
      else S.employees.push({ id: uid('e'), name, role, phone: phone === '+998' ? '' : phone, active: true });
      save();
      closeSheet();
      render();
      toast(id ? 'Изменения сохранены' : `Сотрудник добавлен: ${name}`, 'ok');
    },
    saveStore(form) {
      const f = Object.fromEntries(new FormData(form));
      S.store.name = String(f.name || '').trim() || 'Мой магазин';
      S.store.address = String(f.address || '').trim();
      save();
      render();
      toast('Данные магазина сохранены', 'ok');
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
    window.addEventListener('storage', (e) => {
      if (e.key !== STORE_KEY || !e.newValue) return;
      try { S = normalize(JSON.parse(e.newValue)); render(); } catch (err) { /* чужие данные — игнорируем */ }
    });
  }

  function init() {
    document.documentElement.lang = 'ru';
    S = load();
    if (!S) {
      S = window.KassaDemo.create(Date.now());
      S = normalize(S);
      save();
    } else S = normalize(S);
    setHTML($('#back'), ic('left'));
    setHTML($('#scan-x'), ic('x'));
    setHTML($('#scan-photo-ico'), ic('camera'));
    bind();
    render();
    if ('serviceWorker' in navigator && /^https?:$/.test(location.protocol) && document.querySelector('link[rel="manifest"]')) {
      navigator.serviceWorker.register('sw.js').catch(() => { /* офлайн-режим недоступен */ });
    }
  }

  init();
})();
