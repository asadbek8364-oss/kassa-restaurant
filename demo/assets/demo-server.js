/*
 * «Ресторан» — демо-версия без сервера: эмулятор API /api/restaurant прямо в браузере.
 *
 * Приложения демо-версии — те же файлы, что и на сервере (assets/common.js, floor.js, admin.js, waiter.js,
 * cashier.js, kitchen.js): common.js видит window.RESTAURANT_DEMO и отправляет запросы сюда. Логика повторяет
 * сервер (app/Services/Restaurant): статусы заказа (отметки ставят официант, кассир и админка — экран кухни только
 * показывает), дозаказ отдельным тикетом, заказы навынос с оплатой заранее и «Выдан», оплата картой через
 * эмулятор терминала, стоп-лист, роли, отчёты, очередь печати (виртуальный принтер) и синхронизация
 * (онлайн-сервер имитируется).
 *
 * Данные демо-ресторана хранятся в localStorage этого браузера и общие для всех вкладок и окон:
 * заказ, отправленный официантом, сразу видит экран кухни в соседней вкладке или в лаунчере.
 */
'use strict';
(function () {
  const KEY = 'restoran-demo:state:v2';
  const SEEN = 'restoran-demo:seen';
  const MIN = 60000;
  const HOUR = 3600000;
  const URLS = { admin: 'admin.html', waiter: 'waiter.html', cashier: 'cashier.html', kitchen: 'kitchen.html', kassa: 'https://asadbek8364-oss.github.io/kassa-demo/', launcher: 'index.html' };
  const PINS = { admin: '1111', waiter: '2222', cook: '3333', cashier: '4444' };
  const HINT = 'Демо: администратор — PIN 1111, официант — 2222, повар — 3333, кассир — 4444';
  const ROLE_NAMES = { owner: 'Владелец', admin: 'Администратор', waiter: 'Официант', cashier: 'Кассир', cook: 'Повар', seller: 'Продавец' };
  const ACCESS = { admin: ['owner', 'admin'], waiter: ['owner', 'admin', 'waiter'], cashier: ['owner', 'admin', 'cashier'], kitchen: ['owner', 'admin', 'cook'], kassa: ['owner', 'admin', 'seller'] };
  const APP_NAMES = { admin: 'админка ресторана', waiter: 'приложение официанта', cashier: 'приложение кассира', kitchen: 'экран кухни', kassa: 'касса и склад' };
  const APPS = ['admin', 'waiter', 'cashier', 'kitchen'];
  const HOME_ROLE = { admin: 'admin', waiter: 'waiter', cashier: 'cashier', kitchen: 'cook' };
  const STATUS_NAMES = { new: 'Новый', cooking: 'Готовится', ready: 'Готов', served: 'Подан', paid: 'Оплачен', cancelled: 'Отменён' };
  const ITEM_NAMES = { new: 'Новое', cooking: 'Готовится', ready: 'Готово', served: 'Подано', cancelled: 'Отменено' };
  const TERM_NAMES = { pending: 'Ожидает терминал', approved: 'Оплачено', declined: 'Отклонено', cancelled: 'Отменено', error: 'Ошибка терминала' };
  const KIND_NAMES = { kitchen: 'Кухня', cancel: 'Отмена', guest: 'Счёт гостю', test: 'Тест' };
  const OPEN = ['new', 'cooking', 'ready', 'served'];
  const FINAL = ['paid', 'cancelled'];
  const ON_BOARD = ['new', 'cooking', 'ready'];
  const STAFF_ROLES = ['admin', 'waiter', 'cashier', 'cook'];
  const home = (role) => (role === 'owner' || role === 'admin' ? 'admin' : role === 'waiter' ? 'waiter' : role === 'cashier' ? 'cashier' : role === 'cook' ? 'kitchen' : 'kassa');

  // ---------------------------------------------------------------- время и форматы
  const now = () => Date.now();
  const pad = (n) => String(n).padStart(2, '0');
  function iso(ms) {
    if (ms === null || ms === undefined) return null;
    const d = new Date(ms);
    const off = -d.getTimezoneOffset();
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds())
      + (off >= 0 ? '+' : '-') + pad(Math.floor(Math.abs(off) / 60)) + ':' + pad(Math.abs(off) % 60);
  }
  const ymd = (ms) => { const d = new Date(ms); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
  const dayStart = (ms) => { const d = new Date(ms); d.setHours(0, 0, 0, 0); return d.getTime(); };
  const addDays = (ms, n) => { const d = new Date(ms); d.setDate(d.getDate() + n); return d.getTime(); };
  const parseYmd = (v) => { const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(v || '')); return m ? new Date(+m[1], +m[2] - 1, +m[3]).getTime() : null; };
  const hm = (ms) => { const d = new Date(ms); return pad(d.getHours()) + ':' + pad(d.getMinutes()); };
  const dmyhm = (ms) => { const d = new Date(ms); return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear() + ' ' + hm(ms); };
  const money = (n) => String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  const lower = (v) => String(v).toLocaleLowerCase('ru');
  const timezone = () => { try { return Intl.DateTimeFormat().resolvedOptions().timeZone || null; } catch (e) { return null; } };
  const randomToken = () => Array.from({ length: 40 }, () => 'abcdefghijklmnopqrstuvwxyz0123456789'[Math.floor(Math.random() * 36)]).join('');
  const sum = (list, fn) => list.reduce((acc, x) => acc + fn(x), 0);
  const byId = (a, b) => a.id - b.id;
  const bySort = (a, b) => a.sort - b.sort || a.id - b.id;
  const byName = (a, b) => a.name.localeCompare(b.name, 'ru');

  // ---------------------------------------------------------------- хранилище (общее для вкладок)
  let memory = null; // если localStorage недоступен — данные живут, пока открыта страница
  const storage = {
    get(key) { try { return localStorage.getItem(key); } catch (e) { return key === KEY ? memory : null; } },
    set(key, value) { try { localStorage.setItem(key, value); } catch (e) { if (key === KEY) memory = value; } },
    del(key) { try { localStorage.removeItem(key); } catch (e) { if (key === KEY) memory = null; } },
  };

  function load() {
    let s = null;
    try { s = JSON.parse(storage.get(KEY) || 'null'); } catch (e) { s = null; }
    if (!s || s.v !== 2) {
      s = seed(now());
      persist(s);
      return s;
    }
    // демо долго не открывали — сдвигаем время вперёд: заказы и история «продолжаются» с того же места
    const seen = Number(storage.get(SEEN)) || s.created;
    const gap = now() - seen;
    if (gap > 30 * MIN) {
      shift(s, gap);
      persist(s);
    }
    return s;
  }
  const persist = (s) => storage.set(KEY, JSON.stringify(s));
  const touch = () => storage.set(SEEN, String(now()));

  function shift(s, delta) {
    const move = (obj, fields) => fields.forEach((f) => { if (typeof obj[f] === 'number') obj[f] += delta; });
    s.orders.forEach((o) => move(o, ['created', 'bill', 'paid', 'cancelled', 'synced']));
    s.items.forEach((i) => move(i, ['sent', 'cooking', 'ready', 'served', 'cancelled']));
    s.tickets.forEach((t) => move(t, ['sent']));
    s.jobs.forEach((j) => move(j, ['created', 'printed', 'next']));
    s.logs.forEach((l) => move(l, ['started', 'finished']));
    (s.payments || []).forEach((p) => move(p, ['created', 'finished']));
    s.created += delta;
  }

  // ---------------------------------------------------------------- демо-данные (как php artisan restaurant:demo)
  const MENU = [
    ['Салаты', 'Ачичук', 'Помидоры, репчатый лук, острый перец, базилик', 18000, 6],
    ['Салаты', 'Салат «Ташкент»', 'Зелёная редька, отварная говядина, яйцо, жареный лук', 35000, 4],
    ['Салаты', 'Цезарь с курицей', 'Романо, куриное филе, пармезан, сухарики, соус цезарь', 42000, 3],
    ['Салаты', 'Овощной салат', 'Огурцы, помидоры, перец, зелень, растительное масло', 25000, 3],
    ['Супы', 'Шурпа', 'Баранина, картофель, морковь, нут, зелень', 38000, 5],
    ['Супы', 'Лагман', 'Тянутая лапша, говядина, овощи, чеснок', 40000, 7],
    ['Супы', 'Мастава', 'Рис, говядина, овощи, катык', 32000, 3],
    ['Супы', 'Мошхурда', 'Маш, рис, говядина, овощи', 30000, 2],
    ['Горячие блюда', 'Плов чайханский', 'Рис девзира, баранина, жёлтая морковь, нут, изюм', 45000, 10],
    ['Горячие блюда', 'Манты (5 шт.)', 'Рубленая баранина, лук, курдючный жир; подаются со сметаной', 38000, 6],
    ['Горячие блюда', 'Шашлык из баранины', 'Каре ягнёнка на мангале, лук, зелень', 35000, 7],
    ['Горячие блюда', 'Шашлык из курицы', 'Куриное бедро в маринаде, на мангале', 28000, 5],
    ['Горячие блюда', 'Казан-кабоб', 'Баранина с картофелем в казане', 65000, 2],
    ['Горячие блюда', 'Долма', 'Виноградные листья, рубленое мясо, рис, катык', 42000, 2],
    ['Выпечка', 'Самса с мясом', 'Слоёное тесто, баранина, лук; из тандыра', 12000, 8],
    ['Выпечка', 'Самса с тыквой', 'Слоёное тесто, тыква, лук', 10000, 3],
    ['Выпечка', 'Лепёшка тандырная', 'Горячая лепёшка из тандыра', 5000, 9],
    ['Напитки', 'Чай зелёный (чайник)', 'Зелёный чай, 0,8 л', 8000, 9],
    ['Напитки', 'Чай чёрный с лимоном', 'Чайник 0,8 л, лимон', 10000, 5],
    ['Напитки', 'Компот домашний', 'Сухофрукты, 0,5 л', 9000, 5],
    ['Напитки', 'Айран', 'Кисломолочный напиток, 0,3 л', 9000, 4],
    ['Напитки', 'Вода минеральная 0,5 л', 'Без газа или с газом', 6000, 4],
  ];
  const TABLES = [['1', 4], ['2', 4], ['3', 4], ['4', 4], ['5', 6], ['6', 6], ['7', 8], ['VIP', 12]];
  const COMMENTS = ['без лука', 'острое', 'без зелени', 'поменьше соли', 'хорошо прожарить', 'соус отдельно'];

  function mulberry32(a) {
    return function () {
      a = (a + 0x6D2B79F5) | 0;
      let t = Math.imul(a ^ (a >>> 15), 1 | a);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  function seed(t) {
    const rnd = mulberry32(20261104);
    const int = (n) => Math.floor(rnd() * n);
    const s = {
      v: 2, created: t, printerOn: true, syncOffline: false,
      seq: { order: 0, sale: 1000, item: 0, ticket: 0, job: 0, dish: 0, cat: 0, table: 0, emp: 0, log: 0, payment: 0 },
      store: { id: 1, name: 'Демо-ресторан «Навруз»', address: 'г. Ташкент, ул. Навои, 30', is_demo: true },
      settings: {
        cook_minutes: 15, receipt_header: null, receipt_footer: 'Спасибо, что были у нас! Ждём снова',
        printer_mode: 'network', printer_host: '192.168.1.50', printer_port: 9100, printer_codepage: 'cp866', printer_width: 48,
        sync_url: 'https://online.example.uz', sync_token: true, unsynced: false,
        // терминал кассы — эмулятор: «Отправить на терминал» и имитация оплаты или отказа
        terminal_enabled: true, terminal_test: true, terminal_host: null, terminal_port: null,
      },
      staff: [], categories: [], dishes: [], tables: [], orders: [], items: [], tickets: [], jobs: [], logs: [], payments: [], tokens: {},
    };
    const emp = (name, role, phone) => { const e = { id: ++s.seq.emp, name, role, phone, pin: PINS[role], active: true, unsynced: false }; s.staff.push(e); return e; };
    const admin = emp('Дилшод Каримов', 'admin', '+998 90 123-45-67');
    const waiter = emp('Азиз Рахимов', 'waiter', '+998 93 541-20-18');
    emp('Шахзода Юсупова', 'cook', '+998 97 730-55-02');
    const cashier = emp('Малика Турсунова', 'cashier', '+998 99 812-33-40');

    const cats = {};
    const weights = {};
    MENU.forEach(([cat, name, description, price, w], i) => {
      if (!cats[cat]) {
        cats[cat] = { id: ++s.seq.cat, name: cat, sort: (Object.keys(cats).length + 1) * 10, deleted: false, unsynced: false };
        s.categories.push(cats[cat]);
      }
      const d = { id: ++s.seq.dish, category_id: cats[cat].id, name, description, price, active: true, stop: name === 'Казан-кабоб', sort: (i + 1) * 10, deleted: false, unsynced: false };
      s.dishes.push(d);
      weights[d.id] = w;
    });
    TABLES.forEach(([name, seats], i) => s.tables.push({ id: ++s.seq.table, name, seats, active: true, sort: (i + 1) * 10, deleted: false, unsynced: false }));

    const available = s.dishes.filter((d) => !d.stop);
    const totalW = sum(available, (d) => weights[d.id]);
    const pick = (n) => {
      const chosen = new Map();
      for (let g = 0; chosen.size < n && g < 30; g++) {
        let x = rnd() * totalW;
        for (const d of available) {
          x -= weights[d.id];
          if (x <= 0) {
            if (!chosen.has(d.id)) chosen.set(d.id, { dish: d, qty: 1 + (rnd() < 0.3 ? int(3) : 0), comment: rnd() < 0.12 ? COMMENTS[int(COMMENTS.length)] : null });
            break;
          }
        }
      }
      return [...chosen.values()];
    };

    // оплаченные заказы за 14 прошлых дней и сегодня до текущего часа; примерно каждый шестой — навынос
    const today = dayStart(t);
    const plans = [];
    for (let d = 14; d >= 0; d--) {
      const day = addDays(today, -d);
      const wd = new Date(day).getDay();
      const count = d === 0 ? 4 : 8 + int(6) + (wd === 0 || wd === 6 ? 4 : 0);
      for (let k = 0; k < count; k++) plans.push(d === 0 ? Math.max(today, t - (60 + int(240)) * MIN) : day + 11 * HOUR + int(10 * HOUR));
    }
    plans.sort((a, b) => a - b);
    for (const opened of plans) {
      const paid = Math.max(opened + MIN, Math.min(opened + (35 + int(50)) * MIN, t - 5 * MIN));
      const takeaway = rnd() < 0.17;
      const table = takeaway ? null : s.tables[int(s.tables.length)];
      const by = takeaway ? cashier : rnd() < 0.8 ? waiter : admin;
      const lines = pick(takeaway ? 1 + int(3) : 2 + int(4));
      const total = sum(lines, (l) => l.dish.price * l.qty);
      const payment = rnd() < 0.55 ? 'card' : 'cash';
      const received = payment === 'cash' ? Math.ceil(total / 50000) * 50000 : null;
      const o = {
        id: ++s.seq.order, number: s.seq.order, type: takeaway ? 'takeaway' : 'table', table_id: table ? table.id : null, table_name: table ? table.name : null,
        waiter_id: by.id, waiter_name: by.name,
        guests: table ? 1 + int(table.seats) : null, comment: null, status: 'paid', total, payment, received, change: received ? received - total : 0,
        sale_number: ++s.seq.sale, closed_by: by.id, created: opened, bill: paid - 3 * MIN, paid, cancelled: null, cancel_reason: null, synced: null,
      };
      s.orders.push(o);
      lines.forEach((l) => s.items.push({
        id: ++s.seq.item, order_id: o.id, ticket_id: null, batch: 1, dish_id: l.dish.id, name: l.dish.name, price: l.dish.price, qty: l.qty,
        comment: l.comment, status: 'served', sent: opened, cooking: opened + 2 * MIN, ready: opened + 14 * MIN, served: opened + 16 * MIN, cancelled: null,
      }));
    }

    // последняя синхронизация — сегодня в 03:40 (или вчера, если 03:40 ещё не было)
    let lastSync = today + 3 * HOUR + 40 * MIN;
    if (lastSync > t) lastSync = addDays(lastSync, -1);
    const sent = s.orders.filter((o) => o.paid < lastSync);
    sent.forEach((o) => { o.synced = lastSync; o.saleSynced = true; });
    s.logs.push({ id: ++s.seq.log, direction: 'sync', trigger: 'schedule', status: 'ok', started: lastSync, finished: lastSync + 4000,
      sent: { orders: sent.filter((o) => o.paid >= addDays(lastSync, -1)).length, sales: sent.filter((o) => o.paid >= addDays(lastSync, -1)).length }, received: {}, error: null });

    // открытые заказы в разных статусах: за столиками и навынос (один оплачен заранее картой, другой ждёт оплаты при выдаче)
    const dish = (name) => s.dishes.find((d) => d.name === name);
    [
      [1, 3, 2, [['Лагман', 2, 'без зелени', 'new'], ['Самса с мясом', 2, null, 'new'], ['Чай зелёный (чайник)', 1, null, 'new']], null, false, null],
      [2, 9, 4, [['Плов чайханский', 3, null, 'cooking'], ['Ачичук', 2, null, 'ready'], ['Лепёшка тандырная', 2, null, 'cooking'], ['Шашлык из баранины', 4, 'хорошо прожарить', 'new']], 'у гостя аллергия на орехи', false, null],
      [4, 18, 3, [['Манты (5 шт.)', 2, null, 'ready'], ['Шурпа', 1, 'острое', 'ready'], ['Компот домашний', 3, null, 'ready']], null, false, null],
      [6, 55, 5, [['Плов чайханский', 2, null, 'served'], ['Шашлык из курицы', 3, null, 'served'], ['Айран', 2, null, 'served'], ['Чай чёрный с лимоном', 1, null, 'served']], null, true, null],
      [null, 6, null, [['Плов чайханский', 2, null, 'cooking'], ['Самса с мясом', 4, null, 'cooking'], ['Лепёшка тандырная', 1, null, 'ready']], 'Анвар, заберёт в 13:30', false, 'card'],
      [null, 12, null, [['Шашлык из курицы', 2, 'соус отдельно', 'ready'], ['Компот домашний', 2, null, 'ready']], 'Гульнора', false, null],
    ].forEach(([ti, minutes, guests, lines, comment, bill, prepaid]) => {
      const table = ti === null ? null : s.tables[ti];
      const by = table ? waiter : cashier;
      const sentAt = t - minutes * MIN;
      const o = {
        id: ++s.seq.order, number: s.seq.order, type: table ? 'table' : 'takeaway', table_id: table ? table.id : null, table_name: table ? table.name : null,
        waiter_id: by.id, waiter_name: by.name,
        guests, comment, status: 'new', total: 0, payment: null, received: null, change: 0, sale_number: null, closed_by: null,
        created: sentAt, bill: bill ? t - 2 * MIN : null, paid: null, cancelled: null, cancel_reason: null, synced: null,
      };
      s.orders.push(o);
      const tk = { id: ++s.seq.ticket, order_id: o.id, batch: 1, employee_id: by.id, comment, client_uuid: null, sent: sentAt };
      s.tickets.push(tk);
      lines.forEach(([name, qty, c, status]) => {
        const d = dish(name);
        s.items.push({
          id: ++s.seq.item, order_id: o.id, ticket_id: tk.id, batch: 1, dish_id: d.id, name: d.name, price: d.price, qty, comment: c, status,
          sent: sentAt, cooking: status !== 'new' ? sentAt + MIN : null,
          ready: status === 'ready' || status === 'served' ? sentAt + Math.min(minutes - 1, 14) * MIN : null,
          served: status === 'served' ? sentAt + Math.min(minutes - 1, 16) * MIN : null, cancelled: null,
        });
      });
      refreshStatus(s, o);
      if (prepaid) Object.assign(o, { payment: prepaid, sale_number: ++s.seq.sale, paid: sentAt + MIN, bill: sentAt + MIN });
    });
    return s;
  }

  // ---------------------------------------------------------------- ответы и ошибки
  class Http {
    constructor(status, data) { this.status = status; this.data = data; }
  }
  const fail = (status, message, extra) => { throw new Http(status, Object.assign({ message }, extra || {})); };
  const invalid = (field, message) => { throw new Http(422, { message, errors: { [field]: [message] } }); };
  const str = (v) => (v === undefined || v === null ? '' : String(v).trim());
  const bool = (v, dflt) => (v === undefined || v === null || v === '' ? dflt : v === true || v === 1 || v === '1' || v === 'true' || v === 'on');
  function text(body, field, label, max, required) {
    const v = str(body[field]);
    if (!v && required) invalid(field, 'Заполните поле «' + label + '»');
    if (v.length > max) invalid(field, '«' + label + '»: не длиннее ' + max + ' символов');
    return v || null;
  }
  function number(body, field, label, min, max, required, dflt) {
    const raw = body[field];
    if (raw === undefined || raw === null || raw === '') {
      if (required) invalid(field, 'Заполните поле «' + label + '»');
      return dflt;
    }
    const n = Number(raw);
    if (!Number.isInteger(n)) invalid(field, 'Поле «' + label + '» должно быть целым числом');
    if (n < min) invalid(field, '«' + label + '» не может быть меньше ' + min);
    if (n > max) invalid(field, '«' + label + '» не может быть больше ' + max);
    return n;
  }
  const COMMON_PINS = ['1212', '1122', '2580', '0852', '6969', '1004', '2000', '1010', '2001', '1313', '7777777', '121212', '112233', '123123', '654321', '696969'];
  function weakPin(pin) {
    if (COMMON_PINS.includes(pin) || new Set(pin).size === 1) return true;
    let up = true;
    let down = true;
    for (let i = 1; i < pin.length; i++) {
      const d = pin.charCodeAt(i) - pin.charCodeAt(i - 1);
      up = up && d === 1;
      down = down && d === -1;
    }
    return up || down;
  }
  function pinRule(body, required) {
    const pin = str(body.pin);
    if (!pin) {
      if (required) invalid('pin', 'Заполните поле «PIN»');
      return null;
    }
    if (!/^\d{4,6}$/.test(pin)) invalid('pin', 'PIN — от 4 до 6 цифр');
    if (weakPin(pin)) invalid('pin', 'PIN слишком простой: не используйте одинаковые цифры, цифры подряд (1234, 4321) и частые комбинации');
    return pin;
  }

  // ---------------------------------------------------------------- представления (как app/Support/Restaurant/Presenter.php)
  const storeOut = (s) => ({ id: s.store.id, name: s.store.name, address: s.store.address, is_demo: true });
  const staffOut = (e) => ({ id: e.id, name: e.name, role: e.role, role_name: ROLE_NAMES[e.role] || e.role, phone: e.phone || null, active: e.active, global: e.role === 'owner', unsynced: !!e.unsynced });
  const catOut = (c) => ({ id: c.id, name: c.name, sort: c.sort, unsynced: !!c.unsynced });
  const dishOut = (d) => ({ id: d.id, category_id: d.category_id, name: d.name, description: d.description || null, price: d.price, active: d.active, stop: d.stop, sort: d.sort, unsynced: !!d.unsynced });
  const empName = (s, id) => { const e = s.staff.find((x) => x.id === id); return e ? e.name : null; };
  const itemsOf = (s, o) => s.items.filter((i) => i.order_id === o.id);
  const active = (items) => items.filter((i) => i.status !== 'cancelled');
  const placeOf = (o) => (o.type === 'takeaway' ? 'Навынос' : 'Стол ' + o.table_name);
  const isPaid = (o) => o.status === 'paid' || !!o.sale_number;

  function summaryOut(s, o, items) {
    const list = items || itemsOf(s, o);
    return {
      id: o.id, number: o.number, type: o.type || 'table', place: placeOf(o), table_id: o.table_id, table_name: o.table_name, waiter: { id: o.waiter_id, name: o.waiter_name },
      guests: o.guests, comment: o.comment, status: o.status, status_name: STATUS_NAMES[o.status], total: o.total,
      items_count: sum(active(list), (i) => i.qty), ready_count: sum(list.filter((i) => i.status === 'ready'), (i) => i.qty),
      payment: o.payment, paid: isPaid(o), created_at: iso(o.created), bill_at: iso(o.bill), paid_at: iso(o.paid), cancelled_at: iso(o.cancelled),
    };
  }
  const itemOut = (i) => ({
    id: i.id, dish_id: i.dish_id, name: i.name, price: i.price, qty: i.qty, sum: i.qty * i.price, comment: i.comment, status: i.status,
    batch: i.batch, ticket_id: i.ticket_id, sent_at: iso(i.sent), cooking_at: iso(i.cooking), ready_at: iso(i.ready), served_at: iso(i.served),
  });
  function orderOut(s, o) {
    const items = itemsOf(s, o).sort(byId);
    return Object.assign(summaryOut(s, o, items), {
      received: o.received, change: o.change, cancel_reason: o.cancel_reason, sale_number: o.sale_number,
      terminal: (s.payments || []).filter((p) => p.order_id === o.id).sort((a, b) => b.id - a.id).map(termOut),
      items: items.map(itemOut),
      tickets: s.tickets.filter((t) => t.order_id === o.id).sort((a, b) => a.batch - b.batch)
        .map((t) => ({ id: t.id, batch: t.batch, comment: t.comment, sent_at: iso(t.sent), employee: empName(s, t.employee_id) })),
    });
  }
  const tableOut = (s, t, open) => ({
    id: t.id, name: t.name, seats: t.seats, active: t.active, sort: t.sort,
    status: open ? (open.bill ? 'bill' : 'busy') : 'free', order: open ? summaryOut(s, open) : null, unsynced: !!t.unsynced,
  });
  function aggregate(list) {
    const pending = list.filter((i) => i.status !== 'served');
    if (!list.length) return 'new';
    if (!pending.length) return 'served';
    if (pending.every((i) => i.status === 'ready')) return 'ready';
    if (pending.every((i) => i.status === 'new')) return 'new';
    return 'cooking';
  }
  function refreshStatus(s, o) {
    if (FINAL.includes(o.status)) return;
    const list = active(itemsOf(s, o));
    o.total = sum(list, (i) => i.qty * i.price);
    o.status = aggregate(list);
  }
  function ticketCard(s, t) {
    const o = s.orders.find((x) => x.id === t.order_id);
    const items = s.items.filter((i) => i.ticket_id === t.id).sort(byId);
    const list = active(items);
    const cooking = list.map((i) => i.cooking).filter(Boolean);
    const allReady = list.every((i) => i.status === 'ready' || i.status === 'served');
    return {
      id: t.id, order_id: o.id, number: o.number, batch: t.batch, takeaway: o.type === 'takeaway', place: placeOf(o), table_name: o.table_name, guests: o.guests,
      waiter: empName(s, t.employee_id) || o.waiter_name, comment: t.comment, column: aggregate(list), sent_at: iso(t.sent),
      started_at: cooking.length ? iso(Math.min(...cooking)) : null,
      ready_at: allReady && list.length ? iso(Math.max(...list.map((i) => i.ready || 0))) : null,
      items: items.map((i) => ({ id: i.id, name: i.name, qty: i.qty, comment: i.comment, status: i.status })),
    };
  }
  const settingsOut = (s) => {
    const x = s.settings;
    return {
      cook_minutes: x.cook_minutes, receipt_header: x.receipt_header, receipt_footer: x.receipt_footer, printer_mode: x.printer_mode, printer_host: x.printer_host,
      printer_port: x.printer_port, printer_codepage: x.printer_codepage, printer_width: x.printer_width,
      terminal_enabled: !!x.terminal_enabled, terminal_host: x.terminal_host || null, terminal_port: x.terminal_port || null, terminal_test: !!x.terminal_test, unsynced: !!x.unsynced,
    };
  };
  const termOut = (p) => ({
    id: p.id, order_id: p.order_id, amount: p.amount, status: p.status, status_name: TERM_NAMES[p.status], message: p.message || null,
    reference: p.reference || null, auth_code: p.auth_code || null, card: p.card || null, test: p.driver === 'emulator',
    created_at: iso(p.created), finished_at: iso(p.finished),
  });
  const jobOut = (j, withDoc) => Object.assign({
    id: j.id, kind: j.kind, kind_name: KIND_NAMES[j.kind], title: j.title, status: j.status, attempts: j.attempts, last_error: j.last_error,
    next_attempt_at: iso(j.status === 'pending' ? j.next : null), printed_at: iso(j.printed), printed_via: j.via, created_at: iso(j.created),
  }, withDoc ? { document: j.document } : {});
  const logOut = (l) => (l ? { id: l.id, direction: l.direction, trigger: l.trigger, status: l.status, sent: l.sent || {}, received: l.received || {}, error: l.error, started_at: iso(l.started), finished_at: iso(l.finished) } : null);

  // ---------------------------------------------------------------- чеки (как ReceiptTemplates.php)
  function receipt(width) {
    const lines = [];
    const r = {
      text(value, align = 'left', size = 'n', bold = false, invert = false) { lines.push({ t: 'text', text: value, align, size, bold, invert }); return r; },
      row(left, right, size = 'n', bold = false) { lines.push({ t: 'row', left, right, size, bold }); return r; },
      rule(char = '-') { lines.push({ t: 'rule', char }); return r; },
      doc() { return { width, lines }; },
    };
    return r;
  }
  function kitchenDoc(s, o, t, items, reprint) {
    const r = receipt(s.settings.printer_width);
    r.text('КУХНЯ', 'center', 'l', true).text(t.batch > 1 ? 'ДОЗАКАЗ К №' + o.number : 'ЗАКАЗ №' + o.number, 'center', 'l', true).text(placeOf(o).toUpperCase(), 'center', 'l', true, o.type === 'takeaway');
    if (o.guests) r.text('Гостей: ' + o.guests, 'center');
    r.rule().row(o.type === 'takeaway' ? 'Кассир' : 'Официант', empName(s, t.employee_id) || o.waiter_name || '').row('Отправлен', dmyhm(t.sent));
    if (reprint) r.text('ПОВТОРНАЯ ПЕЧАТЬ · ' + hm(now()), 'center', 'n', true, true);
    r.rule('=');
    items.forEach((i) => {
      r.text(i.qty + ' × ' + i.name, 'left', 't', true);
      if (i.comment) r.text('>> ' + i.comment, 'left', 't', true, true);
    });
    r.rule('=');
    if (t.comment) r.text('К ЗАКАЗУ:', 'left', 'n', true).text(t.comment, 'left', 't', true, true);
    return r.doc();
  }
  function cancelDoc(s, o, items, by, reason) {
    const r = receipt(s.settings.printer_width);
    r.text('ОТМЕНА', 'center', 'l', true, true).text('ЗАКАЗ №' + o.number, 'center', 'l', true).text(placeOf(o).toUpperCase(), 'center', 'l', true)
      .rule().row('Отменил', by).row('Время', dmyhm(now())).rule('=').text('НЕ ГОТОВИТЬ:', 'left', 'n', true);
    items.forEach((i) => r.text(i.qty + ' × ' + i.name, 'left', 't', true));
    r.rule('=');
    if (reason) r.text('Причина: ' + reason);
    return r.doc();
  }
  function guestDoc(s, o) {
    const r = receipt(s.settings.printer_width);
    r.text(s.store.name, 'center', 'l', true);
    if (s.store.address) r.text(s.store.address, 'center');
    if (s.settings.receipt_header) r.text(s.settings.receipt_header, 'center');
    const first = (o.waiter_name || '').trim().split(/\s+/)[0] || '—';
    r.rule().row('Счёт № ' + o.number, dmyhm(o.paid || o.bill || now()))
      .row(placeOf(o) + (o.guests ? ' · гостей ' + o.guests : ''), (o.type === 'takeaway' ? 'Кассир: ' : 'Официант: ') + first).rule();
    const lines = new Map();
    active(itemsOf(s, o)).sort(byId).forEach((i) => {
      const k = i.name + '|' + i.price;
      if (!lines.has(k)) lines.set(k, { name: i.name, price: i.price, qty: 0 });
      lines.get(k).qty += i.qty;
    });
    let total = 0;
    lines.forEach((l) => {
      total += l.qty * l.price;
      r.text(l.name).row('   ' + l.qty + ' × ' + money(l.price), money(l.qty * l.price));
    });
    r.rule('=').row('ИТОГО', money(total), 't', true);
    if (isPaid(o)) {
      r.row('Оплата', o.payment === 'cash' ? 'наличные' : 'карта');
      if (o.payment === 'cash' && o.received) r.row('Получено', money(o.received)).row('Сдача', money(o.change));
    }
    r.rule().text(s.settings.receipt_footer || 'Спасибо, что были у нас!', 'center');
    return r.doc();
  }
  function testDoc(s) {
    return receipt(s.settings.printer_width).text('ТЕСТОВАЯ ПЕЧАТЬ', 'center', 'l', true).text(s.store.name, 'center').rule()
      .row('Кодовая страница', s.settings.printer_codepage).row('Ширина строки', s.settings.printer_width + ' симв.').row('Время', dmyhm(now())).rule()
      .text('АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ').text('абвгдеёжзийклмнопрстуфхцчшщъыьэюя № 0123456789')
      .text('2 × Плов чайханский', 'left', 't', true).text('Крупно: СТОЛ 5', 'left', 'l', true).text('>> без лука, острое', 'left', 'n', true, true)
      .rule('=').text('Видны русские буквы и линия во всю ширину — принтер настроен.', 'center').doc();
  }

  // ---------------------------------------------------------------- очередь печати (виртуальный принтер)
  const printing = (s) => s.settings.printer_mode === 'network' || s.settings.printer_mode === 'browser';
  const backoff = (attempts) => Math.min(60, 5 * 2 ** Math.max(0, attempts - 1)) * 1000;
  function enqueue(s, kind, title, document, orderId, ticketId) {
    if (!printing(s)) return null;
    const job = { id: ++s.seq.job, kind, title, document, status: 'pending', attempts: 0, last_error: null, next: null, printed: null, via: null, created: now(), order_id: orderId || null, ticket_id: ticketId || null };
    s.jobs.push(job);
    if (s.jobs.length > 300) s.jobs.splice(0, s.jobs.length - 300);
    processQueue(s);
    return job;
  }
  /** Сетевой принтер: по порядку; пока первый чек ждёт принтер, следующие ждут его. */
  function processQueue(s) {
    if (s.settings.printer_mode !== 'network') return false;
    let changed = false;
    for (const j of s.jobs.filter((x) => x.status === 'pending').sort(byId)) {
      if (j.next && j.next > now()) break;
      changed = true;
      j.attempts++;
      if (!s.printerOn) {
        j.last_error = 'Принтер ' + s.settings.printer_host + ':' + s.settings.printer_port + ' недоступен: Connection refused (в демо принтер выключен в лаунчере)';
        if (j.attempts >= 10) { j.status = 'failed'; j.next = null; } else j.next = now() + backoff(j.attempts);
        break;
      }
      Object.assign(j, { status: 'done', printed: now(), via: 'network', last_error: null, next: null });
    }
    return changed;
  }
  function printKitchen(s, o, t, reprint) {
    const items = s.items.filter((i) => i.ticket_id === t.id && i.status !== 'cancelled').sort(byId);
    if (!items.length) return null;
    const title = (t.batch > 1 ? 'Дозаказ к №' : 'Заказ №') + o.number + ' · ' + placeOf(o).toLowerCase() + (reprint ? ' (повторно)' : '');
    return enqueue(s, 'kitchen', title, kitchenDoc(s, o, t, items, reprint), o.id, t.id);
  }
  function printCancel(s, o, items, by, reason) {
    if (items.length) enqueue(s, 'cancel', 'Отмена · заказ №' + o.number + ' · ' + placeOf(o).toLowerCase(), cancelDoc(s, o, items, by.name, reason), o.id);
  }

  // ---------------------------------------------------------------- доступ
  function auth(s, token) {
    const e = token ? s.staff.find((x) => x.id === s.tokens[token]) : null;
    if (!e || !e.active) fail(401, 'Войдите заново');
    return e;
  }
  function allow(app, e) {
    if (!ACCESS[app].includes(e.role)) {
      const h = home(e.role);
      fail(403, 'Нет доступа: ' + APP_NAMES[app] + ' не для роли «' + (ROLE_NAMES[e.role] || '—') + '». Ваш интерфейс — ' + URLS[h], { home: URLS[h] });
    }
  }
  const manager = (e) => e.role === 'owner' || e.role === 'admin';
  const findOr404 = (list, id, message) => list.find((x) => x.id === Number(id) && !x.deleted) || fail(404, message || 'Не найдено');
  function openOrder(s, id) {
    const o = s.orders.find((x) => x.id === Number(id)) || fail(404, 'Заказ не найден');
    if (!OPEN.includes(o.status)) fail(409, 'Заказ №' + o.number + ' уже ' + (o.status === 'paid' ? 'оплачен' : 'отменён'), { order_id: o.id });
    return o;
  }
  const markUnsynced = (row) => { row.unsynced = true; };

  // ---------------------------------------------------------------- заказ (как OrderService.php)
  function lines(body) {
    const items = body.items;
    if (!Array.isArray(items) || !items.length) invalid('items', 'Добавьте хотя бы одну позицию');
    if (items.length > 100) invalid('items', 'Слишком много позиций: максимум 100');
    return items.map((l, i) => {
      const qty = Number(l && l.qty);
      if (!Number.isInteger(qty) || qty < 1 || qty > 99) invalid('items.' + i + '.qty', '«количество»: от 1 до 99');
      const comment = str(l.comment);
      if (comment.length > 120) invalid('items.' + i + '.comment', '«комментарий к блюду»: не длиннее 120 символов');
      return { dish_id: Number(l.dish_id), qty, comment: comment || null };
    });
  }
  function dishes(s, list) {
    list.forEach((l) => {
      const d = s.dishes.find((x) => x.id === l.dish_id);
      if (!d || d.deleted || !d.active) invalid('items', (d ? '«' + d.name + '»' : 'Блюдо') + ' больше нет в меню — уберите его из заказа');
      if (d.stop) invalid('items', '«' + d.name + '» в стоп-листе — уберите его из заказа');
    });
  }
  function send(s, o, by, batch, list, comment, clientUuid) {
    const at = now();
    const t = { id: ++s.seq.ticket, order_id: o.id, batch, employee_id: by.id, comment: comment || null, client_uuid: clientUuid || null, sent: at };
    s.tickets.push(t);
    list.forEach((l) => {
      const d = s.dishes.find((x) => x.id === l.dish_id);
      s.items.push({ id: ++s.seq.item, order_id: o.id, ticket_id: t.id, batch, dish_id: d.id, name: d.name, price: d.price, qty: l.qty, comment: l.comment, status: 'new', sent: at, cooking: null, ready: null, served: null, cancelled: null });
    });
    return t;
  }
  function move(i, to) {
    if (i.status === to) return;
    const at = now();
    i.status = to;
    if (to === 'cooking') { i.cooking = i.cooking || at; i.ready = null; }
    if (to === 'ready') i.ready = at;
    if (to === 'served') i.served = at;
    if (to === 'cancelled') i.cancelled = at;
  }
  function replay(s, clientUuid) {
    if (!clientUuid) return null;
    const t = s.tickets.find((x) => x.client_uuid === clientUuid);
    return t ? s.orders.find((o) => o.id === t.order_id) : null;
  }

  // ---------------------------------------------------------------- зал: официант и кассир (как WaiterController и CashierController)
  // навынос, оплаченный заранее, закрывается, когда всё выдано
  function closeIfDone(o, by) {
    if (o.type === 'takeaway' && o.sale_number && o.status === 'served') { o.status = 'paid'; o.closed_by = o.closed_by || (by && by.id) || null; }
  }
  const pendingTerm = (s, o, except) => (s.payments || []).find((p) => p.order_id === o.id && p.status === 'pending' && p !== except);
  function noTerminal(s, o, except) {
    if (pendingTerm(s, o, except)) invalid('terminal', 'Идёт оплата на терминале — дождитесь результата или отмените её на экране оплаты');
  }
  function changeable(s, o) {
    if (isPaid(o)) invalid('order', 'Заказ №' + o.number + ' уже оплачен — отменить нельзя. Возврат денег оформляет администратор в «Кассе и складе»');
    noTerminal(s, o);
  }
  // сумма к оплате; у заказа столика ничего не должно готовиться
  function payable(s, o) {
    if (!OPEN.includes(o.status) || isPaid(o)) fail(409, 'Заказ №' + o.number + ' уже ' + (o.status === 'cancelled' ? 'отменён' : 'оплачен'), { order_id: o.id });
    const list = active(itemsOf(s, o));
    if (!list.length) invalid('items', 'В заказе нет блюд к оплате');
    const cooking = list.filter((i) => i.status === 'new' || i.status === 'cooking');
    if (o.type !== 'takeaway' && cooking.length) invalid('items', 'Ещё готовятся: ' + [...new Set(cooking.map((i) => i.name))].join(', ') + '. Отметьте «Готово», когда кухня отдаст блюда, или отмените эти позиции');
    return sum(list, (i) => i.qty * i.price);
  }
  function createOrder(s, c, attrs, list, comment) {
    const o = Object.assign({
      id: ++s.seq.order, number: s.seq.order, type: 'table', table_id: null, table_name: null, waiter_id: c.me.id, waiter_name: c.me.name,
      guests: null, comment, status: 'new', total: 0, payment: null, received: null, change: 0, sale_number: null, closed_by: null,
      created: now(), bill: null, paid: null, cancelled: null, cancel_reason: null, synced: null,
    }, attrs);
    s.orders.push(o);
    const t = send(s, o, c.me, 1, list, comment, c.body.client_uuid);
    refreshStatus(s, o);
    printKitchen(s, o, t, false);
    c.status = 201;
    return { order: orderOut(s, o), replay: false };
  }
  function setStatus(s, o, by, to, ticketId, itemIds) {
    if (!['new', 'cooking', 'ready', 'served'].includes(to)) invalid('status', 'Недопустимое значение поля «статус»');
    if (!itemIds && to === 'new') invalid('status', 'Вернуть в «новые» можно только отдельные позиции');
    const from = { cooking: ['new'], ready: ['new', 'cooking'], served: ['ready'] }[to];
    const items = itemsOf(s, o).filter((i) => ON_BOARD.includes(i.status) && (!ticketId || i.ticket_id === ticketId)
      && (itemIds ? itemIds.includes(i.id) : from.includes(i.status)));
    if (!items.length) invalid('status', to === 'served' ? 'Нет готовых блюд — сначала отметьте «Готово»' : 'Нет блюд, которые можно отметить «' + ITEM_NAMES[to] + '»');
    items.forEach((i) => move(i, to));
    refreshStatus(s, o);
    closeIfDone(o, by);
  }
  function handOut(s, o, by) {
    if (o.type !== 'takeaway') invalid('order', 'Это заказ столика — отметьте «Подано»');
    const list = active(itemsOf(s, o));
    const cooking = list.filter((i) => i.status === 'new' || i.status === 'cooking');
    if (cooking.length) invalid('items', 'Ещё не готово: ' + [...new Set(cooking.map((i) => i.name))].join(', ') + '. Отметьте «Готово», когда кухня отдаст заказ');
    list.filter((i) => i.status === 'ready').forEach((i) => move(i, 'served'));
    refreshStatus(s, o);
    closeIfDone(o, by);
  }
  // оплата: продажа кассы; стол — закрывается, навынос — закрывается, когда всё выдано
  function payOrder(s, o, by, payment, received, terminal) {
    noTerminal(s, o, terminal);
    const total = payable(s, o);
    if (terminal && terminal.amount !== total) invalid('total', 'Сумма заказа изменилась, пока шла оплата на терминале');
    let change = 0;
    if (payment === 'cash') {
      received = received || total;
      if (received < total) invalid('received', 'Получено меньше суммы счёта');
      change = received - total;
    } else received = null;
    if (o.type !== 'takeaway') active(itemsOf(s, o)).filter((i) => i.status === 'ready').forEach((i) => move(i, 'served'));
    Object.assign(o, { total, payment, received, change, sale_number: ++s.seq.sale, paid: now(), bill: o.bill || now() });
    if (o.type === 'takeaway') { refreshStatus(s, o); closeIfDone(o, by); } else Object.assign(o, { status: 'paid', closed_by: by.id });
  }

  // ---------------------------------------------------------------- терминал кассы: эмулятор (тестовый режим)
  function termFinish(s, p, status, message, extra) {
    Object.assign(p, { status, message, finished: now() }, extra || {});
  }
  function termApply(s, p) {
    if (p.status !== 'pending') return;
    const o = s.orders.find((x) => x.id === p.order_id);
    if (p.outcome === 'approved') {
      try {
        payOrder(s, o, s.staff.find((e) => e.id === p.employee_id) || { id: null, name: '' }, 'card', null, p);
        termFinish(s, p, 'approved', 'Оплачено (эмулятор терминала)', { reference: String(100000000000 + p.id), auth_code: String((p.id * 7919) % 1000000).padStart(6, '0'), card: '•••• 4242' });
      } catch (e) {
        if (!(e instanceof Http)) throw e;
        termFinish(s, p, 'error', 'Терминал подтвердил оплату, но заказ не закрыт: ' + e.data.message + '. Обратитесь к администратору');
      }
    } else if (p.outcome === 'declined') {
      termFinish(s, p, 'declined', 'Отказ банка: недостаточно средств (эмулятор терминала)');
    }
  }
  const termResponse = (s, p) => ({ payment: termOut(p), order: orderOut(s, s.orders.find((o) => o.id === p.order_id)), poll_ms: 1500 });
  const paymentOf = (s, c) => (s.payments || []).find((p) => p.id === Number(c.params[0])) || fail(404, 'Не найдено');

  const floor = {
    state(s) {
      const all = s.orders.filter((o) => OPEN.includes(o.status)).sort(byId);
      const open = {};
      all.filter((o) => o.type !== 'takeaway').forEach((o) => { open[o.table_id] = o; });
      const tables = s.tables.filter((t) => t.active && !t.deleted).sort(bySort);
      const ids = new Set(tables.map((t) => t.id));
      return {
        server_time: iso(now()), mode: 'local', printer_mode: s.settings.printer_mode,
        terminal: { enabled: !!s.settings.terminal_enabled, test: !!s.settings.terminal_test },
        menu_version: menuVersion(s),
        tables: tables.map((t) => tableOut(s, t, open[t.id])),
        orphans: Object.values(open).filter((o) => !ids.has(o.table_id)).map((o) => summaryOut(s, o)),
        takeaway: all.filter((o) => o.type === 'takeaway').map((o) => Object.assign(summaryOut(s, o), {
          lines: active(itemsOf(s, o)).sort(byId).map((i) => ({ name: i.name, qty: i.qty, status: i.status })),
        })),
      };
    },
    menu(s) {
      return {
        menu_version: menuVersion(s),
        categories: s.categories.filter((c) => !c.deleted).sort((a, b) => a.sort - b.sort || byName(a, b)).map(catOut),
        dishes: s.dishes.filter((d) => d.active && !d.deleted).sort((a, b) => a.sort - b.sort || byName(a, b)).map(dishOut),
      };
    },
    show(s, c) { return { order: orderOut(s, s.orders.find((o) => o.id === Number(c.params[0])) || fail(404, 'Заказ не найден')) }; },
    open(s, c) {
      const list = lines(c.body);
      const tableId = number(c.body, 'table_id', 'столик', 1, 1e9, true);
      const guests = number(c.body, 'guests', 'гостей', 0, 99, false, null);
      const comment = text(c.body, 'comment', 'комментарий', 200, false);
      const again = replay(s, c.body.client_uuid);
      if (again) return { order: orderOut(s, again), replay: true };
      const table = s.tables.find((t) => t.id === tableId && !t.deleted) || fail(404, 'Столик не найден — обновите схему зала');
      if (!table.active) invalid('table_id', 'Столик недоступен — обновите схему зала');
      const busy = s.orders.find((o) => o.table_id === table.id && o.type !== 'takeaway' && OPEN.includes(o.status));
      if (busy) fail(409, 'На столике ' + table.name + ' уже открыт заказ №' + busy.number + ' — добавьте блюда в него', { order_id: busy.id });
      dishes(s, list);
      return createOrder(s, c, { type: 'table', table_id: table.id, table_name: table.name, guests: guests || null }, list, comment);
    },
    takeaway(s, c) {
      const list = lines(c.body);
      const comment = text(c.body, 'comment', 'комментарий', 200, false);
      const again = replay(s, c.body.client_uuid);
      if (again) return { order: orderOut(s, again), replay: true };
      dishes(s, list);
      return createOrder(s, c, { type: 'takeaway' }, list, comment);
    },
    add(s, c) {
      const list = lines(c.body);
      const comment = text(c.body, 'comment', 'комментарий', 200, false);
      const again = replay(s, c.body.client_uuid);
      if (again) return { order: orderOut(s, again), replay: true };
      const o = openOrder(s, c.params[0]);
      if (isPaid(o)) invalid('items', 'Заказ №' + o.number + ' уже оплачен — оформите новый заказ');
      noTerminal(s, o);
      dishes(s, list);
      const batch = Math.max(0, ...s.tickets.filter((t) => t.order_id === o.id).map((t) => t.batch)) + 1;
      const t = send(s, o, c.me, batch, list, comment, c.body.client_uuid);
      o.bill = null;
      refreshStatus(s, o);
      printKitchen(s, o, t, false);
      c.status = 201;
      return { order: orderOut(s, o), replay: false };
    },
    status(s, c) {
      const o = openOrder(s, c.params[0]);
      const ids = Array.isArray(c.body.item_ids) ? c.body.item_ids.map(Number) : null;
      setStatus(s, o, c.me, c.body.status, c.body.ticket_id ? Number(c.body.ticket_id) : null, ids);
      return { order: orderOut(s, o) };
    },
    serve(s, c) {
      const o = openOrder(s, c.params[0]);
      const only = Array.isArray(c.body.item_ids) ? c.body.item_ids.map(Number) : null;
      itemsOf(s, o).filter((i) => i.status === 'ready' && (!only || only.includes(i.id))).forEach((i) => move(i, 'served'));
      refreshStatus(s, o);
      closeIfDone(o, c.me);
      return { order: orderOut(s, o) };
    },
    handout(s, c) {
      const o = openOrder(s, c.params[0]);
      handOut(s, o, c.me);
      return { order: orderOut(s, o) };
    },
    reprint(s, c) {
      const o = s.orders.find((x) => x.id === Number(c.params[0])) || fail(404, 'Заказ не найден');
      const t = s.tickets.find((x) => x.id === Number(c.params[1]) && x.order_id === o.id) || fail(404, 'Отправка не из этого заказа');
      if (!printing(s)) invalid('printer', 'Печать выключена — включите принтер в админке: Настройки → Принтер');
      printKitchen(s, o, t, true);
      return { ok: true };
    },
    bill(s, c) {
      const o = openOrder(s, c.params[0]);
      if (!active(itemsOf(s, o)).length) invalid('items', 'В заказе нет блюд — счёт не нужен');
      o.bill = o.bill || now();
      const doc = guestDoc(s, o);
      const print = c.body.print !== false;
      if (print) enqueue(s, 'guest', 'Счёт №' + o.number + ' · ' + placeOf(o).toLowerCase(), doc, o.id);
      return { order: orderOut(s, o), receipt: doc, printer_mode: s.settings.printer_mode, printed: print && s.settings.printer_mode !== 'off' };
    },
    receipt(s, c) { return { receipt: guestDoc(s, s.orders.find((o) => o.id === Number(c.params[0])) || fail(404, 'Заказ не найден')) }; },
    pay(s, c) {
      const payment = c.body.payment;
      if (!['cash', 'card'].includes(payment)) invalid('payment', payment ? 'Недопустимое значение поля «способ оплаты»' : 'Заполните поле «способ оплаты»');
      if (c.app === 'cashier' && payment === 'card' && s.settings.terminal_enabled) invalid('payment', 'Карта принимается через терминал: нажмите «Отправить на терминал»');
      const received = number(c.body, 'received', 'получено', 0, 1e11, false, null);
      const o = s.orders.find((x) => x.id === Number(c.params[0])) || fail(404, 'Заказ не найден');
      if (isPaid(o)) return { order: orderOut(s, o), replay: true };
      if (o.status === 'cancelled') fail(409, 'Заказ №' + o.number + ' отменён', { order_id: o.id });
      payOrder(s, o, c.me, payment, received, null);
      return { order: orderOut(s, o), replay: false };
    },
    cancel(s, c) {
      const reason = text(c.body, 'reason', 'причина', 100, false);
      const o = openOrder(s, c.params[0]);
      changeable(s, o);
      const list = active(itemsOf(s, o));
      if (list.some((i) => i.status !== 'new') && !manager(c.me)) invalid('order', 'Часть блюд уже готовится или подана — отменить заказ может администратор');
      const toKitchen = list.filter((i) => ON_BOARD.includes(i.status)).map((i) => Object.assign({}, i));
      list.forEach((i) => { if (i.status !== 'served') move(i, 'cancelled'); });
      Object.assign(o, { status: 'cancelled', cancelled: now(), cancel_reason: reason, closed_by: c.me.id });
      printCancel(s, o, toKitchen, c.me, reason);
      return { order: orderOut(s, o) };
    },
    cancelItem(s, c) {
      const reason = text(c.body, 'reason', 'причина', 100, false);
      const item = s.items.find((i) => i.id === Number(c.params[1])) || fail(404, 'Позиция не найдена');
      if (item.order_id !== Number(c.params[0])) fail(404, 'Позиция не из этого заказа');
      const o = openOrder(s, c.params[0]);
      changeable(s, o);
      if (item.status === 'cancelled') invalid('item', 'Позиция уже отменена');
      if (item.status !== 'new' && !manager(c.me)) invalid('item', 'Кухня уже начала «' + item.name + '» — отменить может администратор');
      const toKitchen = ON_BOARD.includes(item.status) ? [Object.assign({}, item)] : [];
      move(item, 'cancelled');
      refreshStatus(s, o);
      printCancel(s, o, toKitchen, c.me, reason);
      return { order: orderOut(s, o) };
    },
    // терминал: «Отправить на терминал» → ожидание → оплачено / отклонено / отмена
    terminalStart(s, c) {
      if (!s.settings.terminal_enabled) invalid('terminal', 'Терминал выключен — включите его в админке: Настройки → Платёжный терминал');
      const o = s.orders.find((x) => x.id === Number(c.params[0])) || fail(404, 'Заказ не найден');
      const pending = pendingTerm(s, o);
      if (pending) return termResponse(s, pending);
      const amount = payable(s, o);
      s.payments = s.payments || [];
      const p = { id: ++s.seq.payment, order_id: o.id, employee_id: c.me.id, amount, driver: s.settings.terminal_test ? 'emulator' : 'json', status: 'pending', message: null, created: now(), finished: null, outcome: null };
      s.payments.push(p);
      if (p.driver !== 'emulator') termFinish(s, p, 'error', 'Терминал ' + (s.settings.terminal_host || '') + ':' + (s.settings.terminal_port || '') + ' недоступен (в демо настоящего терминала нет — включите тестовый режим)');
      c.status = 201;
      return termResponse(s, p);
    },
    terminalShow(s, c) { const p = paymentOf(s, c); termApply(s, p); return termResponse(s, p); },
    terminalCancel(s, c) {
      const p = paymentOf(s, c);
      termApply(s, p);
      if (p.status === 'pending') termFinish(s, p, 'cancelled', 'Оплата отменена на кассе');
      return termResponse(s, p);
    },
    terminalEmulate(s, c) {
      const p = paymentOf(s, c);
      if (p.driver !== 'emulator') invalid('terminal', 'Имитация доступна только в тестовом режиме терминала');
      if (!['approved', 'declined'].includes(c.body.result)) invalid('result', 'Недопустимое значение поля «исход»');
      if (p.status === 'pending') p.outcome = c.body.result;
      termApply(s, p);
      return termResponse(s, p);
    },
  };

  function menuVersion(s) {
    const src = JSON.stringify([s.categories, s.dishes]);
    let h = 5381;
    for (let i = 0; i < src.length; i++) h = ((h << 5) + h + src.charCodeAt(i)) | 0;
    return (h >>> 0).toString(16);
  }

  // ---------------------------------------------------------------- кухня: только доска и печать из браузера
  const kitchen = {
    board(s) {
      const onBoard = new Set(s.items.filter((i) => ON_BOARD.includes(i.status) && i.ticket_id).map((i) => i.ticket_id));
      const cards = s.tickets.filter((t) => onBoard.has(t.id))
        .filter((t) => { const o = s.orders.find((x) => x.id === t.order_id); return o && OPEN.includes(o.status); })
        .sort((a, b) => a.sent - b.sent || a.id - b.id)
        .map((t) => ticketCard(s, t))
        .filter((card) => ['new', 'cooking', 'ready'].includes(card.column));
      const cancelled = s.items.filter((i) => i.status === 'cancelled' && i.sent && i.cancelled >= now() - 10 * MIN)
        .sort((a, b) => b.cancelled - a.cancelled).slice(0, 20)
        .map((i) => { const o = s.orders.find((x) => x.id === i.order_id); return { id: i.id, number: o.number, place: placeOf(o).toLowerCase(), table_name: o.table_name, name: i.name, qty: i.qty, at: iso(i.cancelled) }; });
      const network = s.settings.printer_mode === 'network';
      const problem = network ? s.jobs.filter((j) => (j.status === 'pending' || j.status === 'failed') && j.last_error).sort(byId).pop() : null;
      return {
        server_time: iso(now()), cook_minutes: s.settings.cook_minutes, printer_mode: s.settings.printer_mode, cards, cancelled,
        stop_list: s.dishes.filter((d) => d.stop && d.active && !d.deleted).sort(byName).map((d) => d.name),
        print_jobs: s.settings.printer_mode === 'browser' ? s.jobs.filter((j) => j.status === 'pending').sort(byId).slice(0, 10).map((j) => jobOut(j, true)) : [],
        printer_problem: problem ? {
          message: problem.last_error,
          waiting: s.jobs.filter((j) => j.status === 'pending').length,
          failed: s.jobs.filter((j) => j.status === 'failed' && j.created >= now() - 24 * HOUR).length,
        } : null,
      };
    },
    claim(s, c) {
      const j = s.jobs.find((x) => x.id === Number(c.params[0])) || fail(404, 'Не найдено');
      if (j.status !== 'pending') fail(409, 'Этот чек уже напечатан другим экраном');
      Object.assign(j, { status: 'done', printed: now(), via: 'browser', attempts: j.attempts + 1 });
      return { job: jobOut(j, true) };
    },
    stop(s, c) {
      if (c.body.stop === undefined || c.body.stop === null) invalid('stop', 'Заполните поле «стоп-лист»');
      const d = findOr404(s.dishes, c.params[0]);
      d.stop = bool(c.body.stop, false);
      markUnsynced(d);
      return { dish: dishOut(d) };
    },
  };

  // ---------------------------------------------------------------- отчёты (как RestaurantReportService.php)
  function report(s, from, to) {
    const end = addDays(to, 1);
    // оплаченные (и оплаченные заранее навынос) — по дате оплаты
    const paid = s.orders.filter((o) => o.status !== 'cancelled' && o.paid && o.paid >= from && o.paid < end);
    const revenue = sum(paid, (o) => o.total);
    const days = new Map();
    for (let d = from; d <= to; d = addDays(d, 1)) days.set(ymd(d), { day: ymd(d), revenue: 0, orders: 0 });
    paid.forEach((o) => { const x = days.get(ymd(o.paid)); if (x) { x.revenue += o.total; x.orders++; } });
    const group = (key, nameKey, fallback) => {
      const groups = new Map();
      paid.forEach((o) => {
        const k = key === 'table_id' && o.type === 'takeaway' ? 'takeaway' : o[key] !== null && o[key] !== undefined ? o[key] : 'none:' + o[nameKey];
        if (!groups.has(k)) groups.set(k, []);
        groups.get(k).push(o);
      });
      return [...groups.values()].map((g) => {
        const rev = sum(g, (o) => o.total);
        const latest = g.slice().sort((a, b) => b.paid - a.paid)[0];
        const name = key === 'table_id' && latest.type === 'takeaway' ? 'Навынос' : latest[nameKey] || fallback;
        return { id: g[0][key], name, revenue: rev, orders: g.length, avg: Math.round(rev / g.length) };
      }).sort((a, b) => b.revenue - a.revenue);
    };
    const ids = new Set(paid.map((o) => o.id));
    const dishesMap = new Map();
    s.items.filter((i) => ids.has(i.order_id) && i.status !== 'cancelled').forEach((i) => {
      const k = (i.dish_id || 'x') + '|' + i.name;
      if (!dishesMap.has(k)) dishesMap.set(k, { dish_id: i.dish_id, name: i.name, qty: 0, revenue: 0 });
      const x = dishesMap.get(k);
      x.qty += i.qty;
      x.revenue += i.qty * i.price;
    });
    return {
      from: ymd(from), to: ymd(to),
      summary: {
        revenue, orders: paid.length, avg: paid.length ? Math.round(revenue / paid.length) : 0, guests: sum(paid, (o) => o.guests || 0),
        cancelled: s.orders.filter((o) => o.status === 'cancelled' && o.cancelled >= from && o.cancelled < end).length,
      },
      by_day: [...days.values()].map((d) => Object.assign(d, { avg: d.orders ? Math.round(d.revenue / d.orders) : 0 })),
      by_waiter: group('waiter_id', 'waiter_name', 'Без официанта'),
      by_table: group('table_id', 'table_name', 'Без столика'),
      by_payment: ['cash', 'card'].map((p) => ({ payment: p, revenue: sum(paid.filter((o) => o.payment === p), (o) => o.total), orders: paid.filter((o) => o.payment === p).length })),
      top_dishes: [...dishesMap.values()].sort((a, b) => b.qty - a.qty || b.revenue - a.revenue).slice(0, 10),
    };
  }

  // ---------------------------------------------------------------- синхронизация (онлайн-сервер имитируется)
  function pending(s) {
    const master = [...s.categories, ...s.dishes, ...s.tables, ...s.staff].filter((x) => x.unsynced).length + (s.settings.unsynced ? 1 : 0);
    return {
      orders: s.orders.filter((o) => FINAL.includes(o.status) && !o.synced).length,
      sales: s.orders.filter((o) => o.sale_number && !o.saleSynced).length,
      stock: 0,
      menu: master,
    };
  }
  function syncSummary(s) {
    const p = pending(s);
    const docs = p.orders + p.sales + p.stock;
    const runs = s.logs.filter((l) => l.direction === 'sync');
    const last = runs[runs.length - 1] || null;
    const lastOk = runs.filter((l) => l.status === 'ok').pop() || null;
    const stale = !lastOk || lastOk.finished < now() - 26 * HOUR;
    const configured = !!(s.settings.sync_url && s.settings.sync_token);
    return {
      mode: 'local', mode_name: 'Локальный сервер ресторана', schedule: '03:40', timezone: timezone(), server_time: iso(now()),
      configured, url: s.settings.sync_url, token_set: !!s.settings.sync_token, pending: p, pending_total: docs + p.menu,
      last: logOut(last), last_ok_at: iso(lastOk && lastOk.finished),
      attention: configured && docs + p.menu > 0 && ((last && last.status === 'failed') || stale),
    };
  }
  function runSync(s, trigger) {
    const started = now();
    const p = pending(s);
    if (s.syncOffline) {
      const log = { id: ++s.seq.log, direction: 'sync', trigger, status: 'failed', sent: {}, received: {}, started, finished: started + 1500,
        error: 'Нет связи с онлайн-сервером (' + s.settings.sync_url + '): Could not resolve host (в демо — «сбой связи» включён в лаунчере)' };
      s.logs.push(log);
      return log;
    }
    s.orders.filter((o) => FINAL.includes(o.status) && !o.synced).forEach((o) => { o.synced = started; });
    s.orders.filter((o) => o.sale_number).forEach((o) => { o.saleSynced = true; });
    [...s.categories, ...s.dishes, ...s.tables, ...s.staff].forEach((x) => { x.unsynced = false; });
    s.settings.unsynced = false;
    const log = { id: ++s.seq.log, direction: 'sync', trigger, status: 'ok', started, finished: started + 1200, error: null,
      sent: { orders: p.orders, sales: p.sales, returns: 0, incomes: 0, audits: 0, products: 0, menu: p.menu, conflicts: 0, rejected: 0 },
      received: { settings: 0, categories: 0, dishes: 0, tables: 0, staff: 0 } };
    s.logs.push(log);
    return log;
  }

  // ---------------------------------------------------------------- админка
  const today = () => dayStart(now());
  const admin = {
    dashboard(s) {
      const open = s.orders.filter((o) => OPEN.includes(o.status)).sort(byId);
      const qty = (st) => sum(s.items.filter((i) => i.status === st), (i) => i.qty);
      const problem = s.jobs.filter((j) => (j.status === 'pending' || j.status === 'failed') && j.last_error).sort(byId).pop();
      return {
        today: report(s, today(), today()).summary,
        tables: { total: s.tables.filter((t) => t.active && !t.deleted).length, busy: new Set(open.filter((o) => o.type !== 'takeaway').map((o) => o.table_id)).size, bill: open.filter((o) => o.type !== 'takeaway' && o.bill).length },
        takeaway: { open: open.filter((o) => o.type === 'takeaway').length, ready: open.filter((o) => o.type === 'takeaway' && o.status === 'ready').length },
        kitchen: { new: qty('new'), cooking: qty('cooking'), ready: qty('ready') },
        open_orders: open.map((o) => summaryOut(s, o)),
        printer: {
          mode: s.settings.printer_mode, pending: s.jobs.filter((j) => j.status === 'pending').length,
          failed: s.jobs.filter((j) => j.status === 'failed' && j.created >= now() - 24 * HOUR).length, last_error: problem ? problem.last_error : null,
        },
        sync: syncSummary(s),
      };
    },
    orders(s, c) {
      const filter = c.query.filter || 'day';
      let list = s.orders.slice().sort((a, b) => b.id - a.id);
      if (filter === 'open') list = list.filter((o) => OPEN.includes(o.status));
      else {
        const day = parseYmd(c.query.date) || today();
        list = list.filter((o) => o.created >= day && o.created < addDays(day, 1));
      }
      return { orders: list.slice(0, 300).map((o) => summaryOut(s, o)) };
    },
    order(s, c) { return { order: orderOut(s, s.orders.find((o) => o.id === Number(c.params[0])) || fail(404, 'Заказ не найден')) }; },
    status: (s, c) => floor.status(s, c),
    handout: (s, c) => floor.handout(s, c),
    reprint: (s, c) => floor.reprint(s, c),
    menu(s) {
      return {
        categories: s.categories.filter((x) => !x.deleted).sort((a, b) => a.sort - b.sort || byName(a, b))
          .map((x) => Object.assign(catOut(x), { dishes: s.dishes.filter((d) => d.category_id === x.id && !d.deleted).length })),
        dishes: s.dishes.filter((d) => !d.deleted).sort((a, b) => a.sort - b.sort || byName(a, b)).map(dishOut),
      };
    },
    saveCategory(s, c) {
      const current = c.params[0] ? findOr404(s.categories, c.params[0]) : null;
      const name = text(c.body, 'name', 'название', 40, true);
      const sort = number(c.body, 'sort', 'порядок', 0, 100000, false, current ? current.sort : Math.max(0, ...s.categories.filter((x) => !x.deleted).map((x) => x.sort)) + 10);
      if (s.categories.some((x) => !x.deleted && x !== current && lower(x.name) === lower(name))) invalid('name', 'Категория «' + name + '» уже есть');
      const row = current || { id: ++s.seq.cat, deleted: false };
      Object.assign(row, { name, sort });
      markUnsynced(row);
      if (!current) { s.categories.push(row); c.status = 201; }
      return { category: catOut(row) };
    },
    deleteCategory(s, c) {
      const row = findOr404(s.categories, c.params[0]);
      if (s.dishes.some((d) => d.category_id === row.id && !d.deleted)) invalid('category_id', 'В категории есть блюда — перенесите или удалите их');
      row.deleted = true;
      markUnsynced(row);
      return { ok: true };
    },
    saveDish(s, c) {
      const current = c.params[0] ? findOr404(s.dishes, c.params[0]) : null;
      const categoryId = number(c.body, 'category_id', 'категория', 1, 1e9, true);
      const name = text(c.body, 'name', 'название', 80, true);
      const description = text(c.body, 'description', 'состав', 300, false);
      const price = number(c.body, 'price', 'цена', 0, 100000000, true);
      const sort = number(c.body, 'sort', 'порядок', 0, 100000, false, 0);
      if (!s.categories.some((x) => x.id === categoryId && !x.deleted)) invalid('category_id', 'Выберите категорию из списка');
      const row = current || { id: ++s.seq.dish, deleted: false };
      Object.assign(row, { category_id: categoryId, name, description, price, active: bool(c.body.active, true), stop: bool(c.body.stop, false), sort });
      markUnsynced(row);
      if (!current) { s.dishes.push(row); c.status = 201; }
      return { dish: dishOut(row) };
    },
    deleteDish(s, c) {
      const row = findOr404(s.dishes, c.params[0]);
      row.deleted = true;
      markUnsynced(row);
      return { ok: true };
    },
    stop: (s, c) => kitchen.stop(s, c),
    tables(s) {
      const open = {};
      s.orders.filter((o) => OPEN.includes(o.status)).forEach((o) => { open[o.table_id] = o; });
      return { tables: s.tables.filter((t) => !t.deleted).sort(bySort).map((t) => tableOut(s, t, open[t.id])) };
    },
    saveTable(s, c) {
      const current = c.params[0] ? findOr404(s.tables, c.params[0]) : null;
      const name = text(c.body, 'name', 'название', 20, true);
      const seats = number(c.body, 'seats', 'мест', 1, 100, true);
      const sort = number(c.body, 'sort', 'порядок', 0, 100000, false, current ? current.sort : Math.max(0, ...s.tables.filter((x) => !x.deleted).map((x) => x.sort)) + 10);
      if (s.tables.some((x) => !x.deleted && x !== current && lower(x.name) === lower(name))) invalid('name', 'Столик «' + name + '» уже есть');
      const row = current || { id: ++s.seq.table, deleted: false };
      Object.assign(row, { name, seats, active: bool(c.body.active, true), sort });
      markUnsynced(row);
      if (!current) { s.tables.push(row); c.status = 201; }
      const open = s.orders.find((o) => o.table_id === row.id && OPEN.includes(o.status));
      return { table: tableOut(s, row, open) };
    },
    deleteTable(s, c) {
      const row = findOr404(s.tables, c.params[0]);
      const open = s.orders.find((o) => o.table_id === row.id && OPEN.includes(o.status));
      if (open) invalid('table_id', 'За столиком открыт заказ №' + open.number + ' — сначала закройте его');
      row.deleted = true;
      markUnsynced(row);
      return { ok: true };
    },
    staff(s) {
      const rank = { owner: 0, admin: 1, cashier: 2, waiter: 3, cook: 4 };
      return { staff: s.staff.slice().sort((a, b) => (b.active - a.active) || ((rank[a.role] ?? 5) - (rank[b.role] ?? 5)) || byName(a, b)).map(staffOut) };
    },
    saveStaff(s, c) {
      const current = c.params[0] ? s.staff.find((e) => e.id === Number(c.params[0])) || fail(404, 'Не найдено') : null;
      if (current && current.role === 'owner') invalid('employee_id', 'Владельца меняют в кассе: Ещё → Сотрудники');
      const name = text(c.body, 'name', 'имя', 60, true);
      const role = c.body.role;
      if (!STAFF_ROLES.includes(role)) invalid('role', role ? 'Недопустимое значение поля «роль»' : 'Заполните поле «роль»');
      const phone = text(c.body, 'phone', 'телефон', 20, false);
      const pin = pinRule(c.body, !current);
      const row = current || { id: ++s.seq.emp, active: true };
      Object.assign(row, { name, role, phone: phone === '+998' ? null : phone });
      if (pin) row.pin = pin;
      markUnsynced(row);
      if (!current) { s.staff.push(row); c.status = 201; }
      return { employee: staffOut(row) };
    },
    toggleStaff(s, c) {
      const e = s.staff.find((x) => x.id === Number(c.params[0])) || fail(404, 'Не найдено');
      if (e.role === 'owner') invalid('employee_id', 'Владельца меняют в кассе: Ещё → Сотрудники');
      if (e.id === c.me.id) invalid('employee_id', 'Нельзя отключить самого себя');
      e.active = !e.active;
      if (!e.active) Object.keys(s.tokens).forEach((t) => { if (s.tokens[t] === e.id) delete s.tokens[t]; });
      markUnsynced(e);
      return { employee: staffOut(e) };
    },
    reports(s, c) {
      let from = c.query.from ? parseYmd(c.query.from) : addDays(today(), -6);
      let to = c.query.to ? parseYmd(c.query.to) : today();
      if (from === null) invalid('from', 'Поле «начало периода» должно быть датой');
      if (to === null) invalid('to', 'Поле «конец периода» должно быть датой');
      if (from > to) invalid('from', 'Начало периода позже конца');
      if ((to - from) / (24 * HOUR) >= 366) invalid('from', 'Период — не больше года');
      return report(s, from, to);
    },
    settings(s) { return { store: storeOut(s), settings: settingsOut(s), codepages: ['cp866', 'cp1251'], widths: [48, 42, 32], terminal_driver: 'json' }; },
    saveTerminal(s, c) {
      const enabled = bool(c.body.terminal_enabled, false);
      const test = bool(c.body.terminal_test, false);
      const host = str(c.body.terminal_host);
      if (host && !/^[A-Za-z0-9.\-:]+$/.test(host)) invalid('terminal_host', 'Недопустимое значение поля «адрес терминала»');
      const port = number(c.body, 'terminal_port', 'порт терминала', 1, 65535, false, null);
      if (enabled && !test && (!host || !port)) invalid('terminal_host', 'Укажите адрес и порт терминала в локальной сети, например 192.168.1.60 и 8888, или включите тестовый режим');
      if (!enabled && (s.payments || []).some((p) => p.status === 'pending')) invalid('terminal_enabled', 'Сейчас идёт оплата на терминале — дождитесь её окончания');
      Object.assign(s.settings, { terminal_enabled: enabled, terminal_test: test, terminal_host: host || null, terminal_port: port });
      return admin.settings(s);
    },
    testTerminal(s, c) {
      if (!s.settings.terminal_enabled) invalid('terminal_enabled', 'Терминал выключен — включите его и сохраните настройки');
      if (s.settings.terminal_test) return { ok: true, message: 'Тестовый режим: работает эмулятор терминала — оплата и отказ имитируются, деньги не списываются' };
      c.status = 422;
      return { ok: false, message: 'Терминал ' + s.settings.terminal_host + ':' + s.settings.terminal_port + ' недоступен — в демо-версии настоящего терминала нет, включите тестовый режим' };
    },
    saveRestaurant(s, c) {
      const name = text(c.body, 'name', 'название', 60, true);
      const address = text(c.body, 'address', 'адрес', 120, false);
      const cook = number(c.body, 'cook_minutes', 'норматив приготовления', 1, 240, true);
      const header = text(c.body, 'receipt_header', 'шапка чека', 120, false);
      const footer = text(c.body, 'receipt_footer', 'подвал чека', 120, false);
      Object.assign(s.store, { name, address });
      Object.assign(s.settings, { cook_minutes: cook, receipt_header: header, receipt_footer: footer, unsynced: true });
      return admin.settings(s);
    },
    savePrinter(s, c) {
      const mode = c.body.printer_mode;
      if (!['off', 'network', 'browser'].includes(mode)) invalid('printer_mode', 'Недопустимое значение поля «способ печати»');
      const host = str(c.body.printer_host);
      if (host && !/^[A-Za-z0-9.\-:]+$/.test(host)) invalid('printer_host', 'Недопустимое значение поля «IP-адрес принтера»');
      if (mode === 'network' && !host) invalid('printer_host', 'Укажите IP-адрес принтера, например 192.168.1.50');
      const port = number(c.body, 'printer_port', 'порт принтера', 1, 65535, false, 9100);
      const codepage = c.body.printer_codepage;
      if (!['cp866', 'cp1251'].includes(codepage)) invalid('printer_codepage', 'Недопустимое значение поля «кодовая страница»');
      const width = Number(c.body.printer_width);
      if (![48, 42, 32].includes(width)) invalid('printer_width', 'Недопустимое значение поля «ширина ленты»');
      Object.assign(s.settings, { printer_mode: mode, printer_host: host || null, printer_port: port, printer_codepage: codepage, printer_width: width });
      return admin.settings(s);
    },
    testPrint(s, c) {
      if (!printing(s)) invalid('printer_mode', 'Печать выключена — выберите сетевой принтер или печать из браузера');
      const doc = testDoc(s);
      const job = enqueue(s, 'test', 'Тестовая печать', doc);
      const x = s.settings;
      if (x.printer_mode === 'network') {
        if (job.status !== 'done') { c.status = 422; return { ok: false, message: job.last_error, job: jobOut(job) }; }
        return { ok: true, message: 'Отправлено на принтер ' + x.printer_host + ':' + x.printer_port + '. В демо принтер виртуальный — чек виден в лаунчере, раздел «Принтер кухни»', job: jobOut(job) };
      }
      return { ok: true, message: 'Задание отправлено на экран кухни — он напечатает его через браузер', job: jobOut(job), document: doc };
    },
    jobs(s) { return { jobs: s.jobs.slice().sort((a, b) => b.id - a.id).slice(0, 50).map((j) => jobOut(j)) }; },
    retry(s, c) {
      const j = s.jobs.find((x) => x.id === Number(c.params[0])) || fail(404, 'Не найдено');
      if (j.status === 'done') invalid('job', 'Этот чек уже напечатан — для копии используйте повторную печать на экране кухни');
      Object.assign(j, { status: 'pending', attempts: 0, next: null, last_error: null });
      processQueue(s);
      return { job: jobOut(j) };
    },
    sync(s) { return { status: syncSummary(s), log: s.logs.slice().sort((a, b) => b.id - a.id).slice(0, 30).map(logOut) }; },
    saveSync(s, c) {
      const url = str(c.body.url).replace(/\/+$/, '');
      if (!url) invalid('url', 'Заполните поле «адрес онлайн-сервера»');
      if (!/^https?:\/\/[^\s/]+/.test(url)) invalid('url', 'Поле «адрес онлайн-сервера» должно быть адресом вида https://…');
      const token = str(c.body.token);
      if (token && token.length < 20) invalid('token', '«sync-токен»: минимум 20 символов');
      if (!token && !s.settings.sync_token) invalid('token', 'Введите sync-токен, выпущенный в онлайн-админке');
      s.settings.sync_url = url;
      if (token) s.settings.sync_token = true;
      return admin.sync(s);
    },
    ping(s, c) {
      if (s.syncOffline) { c.status = 422; return { ok: false, message: 'Нет связи с онлайн-сервером (' + s.settings.sync_url + '): Could not resolve host' }; }
      return { ok: true, message: 'Связь есть: онлайн-сервер ресторана «' + s.store.name + '» (в демо онлайн-сервер имитируется)' };
    },
    run(s, c) {
      const log = runSync(s, 'manual');
      if (log.status !== 'ok') c.status = 422;
      return { ok: log.status === 'ok', run: logOut(log), status: syncSummary(s) };
    },
    token() { fail(409, 'Sync-токен выпускается в админке онлайн-сервера (DEPLOY_MODE=online)'); },
  };

  // ---------------------------------------------------------------- вход
  const auth0 = {
    options(s, c) {
      const app = c.query.app;
      if (!APPS.includes(app)) invalid('app', 'Недопустимое значение поля «приложение»');
      const homeRole = HOME_ROLE[app];
      const rank = (e) => (e.role === homeRole ? 0 : e.role === 'admin' ? 1 : 2);
      return {
        app, mode: 'local', store: storeOut(s), stores: [{ id: s.store.id, name: s.store.name }], needs_setup: false,
        setup_code_required: false, setup_ready: true, demo_hint: HINT,
        employees: s.staff.filter((e) => e.active && ACCESS[app].includes(e.role)).sort((a, b) => rank(a) - rank(b) || byName(a, b))
          .map((e) => ({ id: e.id, name: e.name, role: e.role, role_name: ROLE_NAMES[e.role] })),
      };
    },
    login(s, c) {
      const pin = str(c.body.pin);
      if (!pin) invalid('pin', 'Заполните поле «PIN»');
      if (!/^\d{4,6}$/.test(pin)) invalid('pin', 'PIN — от 4 до 6 цифр');
      const app = c.body.app;
      if (!APPS.includes(app)) invalid('app', 'Недопустимое значение поля «приложение»');
      const e = s.staff.find((x) => x.id === Number(c.body.employee_id) && x.active);
      if (!e || e.pin !== pin) invalid('pin', 'Неверный PIN');
      const token = randomToken();
      s.tokens[token] = e.id;
      const to = ACCESS[app].includes(e.role) ? app : home(e.role);
      return { token, employee: staffOut(e), store: storeOut(s), app: to, home: URLS[to], redirect: to !== app };
    },
    logout(s, c) { delete s.tokens[c.token]; return { ok: true }; },
    me(s, c) {
      const e = c.me;
      const apps = APPS.concat('kassa').filter((a) => ACCESS[a].includes(e.role)).map((a) => ({ app: a, url: URLS[a], name: APP_NAMES[a] }));
      const sync = syncSummary(s);
      return {
        employee: staffOut(e), store: storeOut(s), stores: [storeOut(s)], apps, mode: 'local', mode_name: 'Локальный сервер ресторана (демо)',
        timezone: timezone(), poll_seconds: 5,
        settings: { cook_minutes: s.settings.cook_minutes, printer_mode: s.settings.printer_mode, terminal: { enabled: !!s.settings.terminal_enabled, test: !!s.settings.terminal_test } },
        sync_configured: sync.configured, sync_attention: manager(e) ? sync.attention : false,
      };
    },
  };

  // ---------------------------------------------------------------- маршруты (как routes/api.php)
  const R = (method, pattern, fn, gate) => ({ method, re: new RegExp('^' + pattern + '$'), fn, gate });
  const ROUTES = [
    R('GET', 'options', auth0.options, 'public'),
    R('POST', 'login', auth0.login, 'public'),
    R('POST', 'logout', auth0.logout),
    R('GET', 'me', auth0.me),
    ...['waiter', 'cashier'].flatMap((app) => [
      R('GET', app + '/state', floor.state, app),
      R('GET', app + '/menu', floor.menu, app),
      R('POST', app + '/orders', floor.open, app),
      R('GET', app + '/orders/(\\d+)', floor.show, app),
      R('POST', app + '/orders/(\\d+)/items', floor.add, app),
      R('POST', app + '/orders/(\\d+)/items/(\\d+)/cancel', floor.cancelItem, app),
      R('POST', app + '/orders/(\\d+)/status', floor.status, app),
      R('POST', app + '/orders/(\\d+)/serve', floor.serve, app),
      R('POST', app + '/orders/(\\d+)/tickets/(\\d+)/reprint', floor.reprint, app),
      R('POST', app + '/orders/(\\d+)/bill', floor.bill, app),
      R('GET', app + '/orders/(\\d+)/receipt', floor.receipt, app),
      R('POST', app + '/orders/(\\d+)/pay', floor.pay, app),
      R('POST', app + '/orders/(\\d+)/cancel', floor.cancel, app),
    ]),
    R('POST', 'cashier/takeaway', floor.takeaway, 'cashier'),
    R('POST', 'cashier/orders/(\\d+)/handout', floor.handout, 'cashier'),
    R('POST', 'cashier/orders/(\\d+)/terminal', floor.terminalStart, 'cashier'),
    R('GET', 'cashier/terminal/(\\d+)', floor.terminalShow, 'cashier'),
    R('POST', 'cashier/terminal/(\\d+)/cancel', floor.terminalCancel, 'cashier'),
    R('POST', 'cashier/terminal/(\\d+)/emulate', floor.terminalEmulate, 'cashier'),
    R('GET', 'kitchen/board', kitchen.board, 'kitchen'),
    R('POST', 'kitchen/print-jobs/(\\d+)/claim', kitchen.claim, 'kitchen'),
    R('GET', 'admin/dashboard', admin.dashboard, 'admin'),
    R('GET', 'admin/orders', admin.orders, 'admin'),
    R('GET', 'admin/orders/(\\d+)', admin.order, 'admin'),
    R('POST', 'admin/orders/(\\d+)/status', admin.status, 'admin'),
    R('POST', 'admin/orders/(\\d+)/handout', admin.handout, 'admin'),
    R('POST', 'admin/orders/(\\d+)/tickets/(\\d+)/reprint', admin.reprint, 'admin'),
    R('GET', 'admin/menu', admin.menu, 'admin'),
    R('POST', 'admin/categories', admin.saveCategory, 'admin'),
    R('PUT', 'admin/categories/(\\d+)', admin.saveCategory, 'admin'),
    R('DELETE', 'admin/categories/(\\d+)', admin.deleteCategory, 'admin'),
    R('POST', 'admin/dishes', admin.saveDish, 'admin'),
    R('PUT', 'admin/dishes/(\\d+)', admin.saveDish, 'admin'),
    R('DELETE', 'admin/dishes/(\\d+)', admin.deleteDish, 'admin'),
    R('POST', 'admin/dishes/(\\d+)/stop', admin.stop, 'admin'),
    R('GET', 'admin/tables', admin.tables, 'admin'),
    R('POST', 'admin/tables', admin.saveTable, 'admin'),
    R('PUT', 'admin/tables/(\\d+)', admin.saveTable, 'admin'),
    R('DELETE', 'admin/tables/(\\d+)', admin.deleteTable, 'admin'),
    R('GET', 'admin/staff', admin.staff, 'admin'),
    R('POST', 'admin/staff', admin.saveStaff, 'admin'),
    R('PUT', 'admin/staff/(\\d+)', admin.saveStaff, 'admin'),
    R('POST', 'admin/staff/(\\d+)/toggle', admin.toggleStaff, 'admin'),
    R('GET', 'admin/reports', admin.reports, 'admin'),
    R('GET', 'admin/settings', admin.settings, 'admin'),
    R('PUT', 'admin/settings/restaurant', admin.saveRestaurant, 'admin'),
    R('PUT', 'admin/settings/printer', admin.savePrinter, 'admin'),
    R('POST', 'admin/settings/printer/test', admin.testPrint, 'admin'),
    R('PUT', 'admin/settings/terminal', admin.saveTerminal, 'admin'),
    R('POST', 'admin/settings/terminal/test', admin.testTerminal, 'admin'),
    R('GET', 'admin/print-jobs', admin.jobs, 'admin'),
    R('POST', 'admin/print-jobs/(\\d+)/retry', admin.retry, 'admin'),
    R('GET', 'admin/sync', admin.sync, 'admin'),
    R('PUT', 'admin/sync', admin.saveSync, 'admin'),
    R('POST', 'admin/sync/ping', admin.ping, 'admin'),
    R('POST', 'admin/sync/run', admin.run, 'admin'),
    R('POST', 'admin/sync/token', admin.token, 'admin'),
    R('DELETE', 'admin/sync/token', admin.token, 'admin'),
  ];

  /** Запрос приложения: { status, data } — как ответ сервера. */
  async function request(method, url, body, token) {
    await new Promise((resolve) => setTimeout(resolve, 30));
    const u = new URL(url, location.href);
    if (u.pathname.indexOf('/api/kassa/') !== -1) {
      return { status: 404, data: { message: 'В демо-версии «Касса и склад» — отдельная демонстрация: ' + URLS.kassa } };
    }
    const m = /\/api\/restaurant\/(.+)$/.exec(u.pathname);
    let route = null;
    let params = [];
    for (const r of ROUTES) {
      const hit = m && r.method === method && r.re.exec(m[1]);
      if (hit) { route = r; params = hit.slice(1); break; }
    }
    if (!route) return { status: 404, data: { message: 'Не найдено' } };

    const s = load();
    const ctx = { query: Object.fromEntries(u.searchParams.entries()), body: JSON.parse(JSON.stringify(body || {})), params, token, status: 200, me: null, app: route.gate };
    let changed = false;
    try {
      if (route.gate !== 'public') {
        ctx.me = auth(s, token);
        if (route.gate) allow(route.gate, ctx.me);
      }
      changed = processQueue(s);
      const data = route.fn(s, ctx);
      if (method !== 'GET' || changed) persist(s);
      touch();
      return { status: ctx.status, data: JSON.parse(JSON.stringify(data)) };
    } catch (e) {
      if (changed) persist(s);
      touch();
      if (e instanceof Http) return { status: e.status, data: e.data };
      console.error(e);
      return { status: 500, data: { message: 'Ошибка демо-версии: ' + e.message } };
    }
  }

  // ---------------------------------------------------------------- управление демо из лаунчера
  const DEFAULT_LOGIN = { admin: 'admin', waiter: 'waiter', cashier: 'cashier', kitchen: 'cook' };
  window.RESTAURANT_DEMO = {
    key: KEY,
    urls: URLS,
    assets: 'assets/',
    pins: PINS,
    hint: HINT,
    request,
    /** Приложения лаунчера открываются уже с входом: администратор, официант, кассир и повар. */
    ensureLogins() {
      const s = load();
      let changed = false;
      Object.entries(DEFAULT_LOGIN).forEach(([app, role]) => {
        let token = null;
        try { token = JSON.parse(localStorage.getItem('restoran:' + app + ':token') || 'null'); } catch (e) { token = null; }
        const e = token && s.staff.find((x) => x.id === s.tokens[token]);
        if (e && e.active && ACCESS[app].includes(e.role)) return;
        const who = s.staff.find((x) => x.role === role && x.active);
        if (!who) return;
        const fresh = randomToken();
        s.tokens[fresh] = who.id;
        try { localStorage.setItem('restoran:' + app + ':token', JSON.stringify(fresh)); } catch (err) { /* без автоматического входа */ }
        changed = true;
      });
      if (changed) persist(s);
    },
    /** Начать демо заново: свежие данные, все входы и черновики на этом устройстве удаляются. */
    reset() {
      try {
        Object.keys(localStorage).filter((k) => k.indexOf('restoran:') === 0 || k.indexOf('restoran-demo:') === 0).forEach((k) => localStorage.removeItem(k));
      } catch (e) { /* */ }
      memory = null;
      persist(seed(now()));
      touch();
    },
    state() { return load(); },
    /** Чеки, которые «вышли» из виртуального принтера (сетевой или печать из браузера), новые сверху. */
    printed(limit = 30) {
      return load().jobs.filter((j) => j.status === 'done').sort((a, b) => b.id - a.id).slice(0, limit)
        .map((j) => ({ id: j.id, kind: j.kind, kind_name: KIND_NAMES[j.kind], title: j.title, document: j.document, printed_at: iso(j.printed), via: j.via }));
    },
    queue() {
      const s = load();
      return { mode: s.settings.printer_mode, on: s.printerOn, pending: s.jobs.filter((j) => j.status === 'pending').length, failed: s.jobs.filter((j) => j.status === 'failed').length, offline: s.syncOffline };
    },
    /** Включить или выключить виртуальный принтер (проверка очереди и повторов). */
    setPrinter(on) {
      const s = load();
      s.printerOn = !!on;
      if (on) s.jobs.forEach((j) => { if (j.status === 'pending') j.next = null; });
      processQueue(s);
      persist(s);
    },
    /** Имитировать отсутствие связи с онлайн-сервером (пометка «есть неотправленные данные»). */
    setSyncOffline(on) {
      const s = load();
      s.syncOffline = !!on;
      if (on) runSync(s, 'schedule');
      persist(s);
    },
  };
})();
