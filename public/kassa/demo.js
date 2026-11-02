/*
 * Демо-данные для «Касса и склад».
 * Генерация детерминирована (фиксированный seed), даты строятся относительно текущего момента:
 * история продаж за 30 дней, несколько продаж сегодня, приходы, возвраты и одна инвентаризация.
 */
'use strict';
window.KassaDemo = (function () {
  const DAY = 864e5;

  function rng(seed) {
    return function () {
      seed = (seed + 0x6d2b79f5) | 0;
      let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  function ean13(d12) {
    let s = 0;
    for (let i = 0; i < 12; i++) s += Number(d12[i]) * (i % 2 ? 3 : 1);
    return d12 + ((10 - (s % 10)) % 10);
  }

  function startOfDay(t) {
    const d = new Date(t);
    d.setHours(0, 0, 0, 0);
    return d.getTime();
  }

  // [категория, название, закупка, розница, остаток, мин. остаток, популярность]
  const CATALOG = [
    ['chargers', 'Сетевое ЗУ Apple 20W USB-C', 165000, 249000, 14, 4, 6],
    ['chargers', 'Сетевое ЗУ Samsung 25W Super Fast', 110000, 179000, 11, 4, 5],
    ['chargers', 'Сетевое ЗУ Xiaomi 33W + кабель Type-C', 85000, 139000, 9, 4, 5],
    ['chargers', 'ЗУ Baseus GaN 65W, 3 порта', 260000, 399000, 5, 2, 2],
    ['chargers', 'Автомобильное ЗУ Baseus 30W', 70000, 119000, 8, 3, 2],
    ['chargers', 'Беспроводное ЗУ Samsung 15W', 160000, 259000, 4, 2, 1],
    ['chargers', 'Power Bank Xiaomi 10000 мА·ч 22.5W', 170000, 269000, 10, 3, 3],
    ['chargers', 'Power Bank Baseus 20000 мА·ч 30W', 250000, 389000, 6, 2, 2],
    ['chargers', 'MagSafe зарядка Apple 15W', 290000, 449000, 0, 2, 1],

    ['cables', 'Кабель Apple USB-C – Lightning, 1 м', 120000, 189000, 2, 5, 4],
    ['cables', 'Кабель Apple USB-C – USB-C 60W, 1 м', 140000, 219000, 7, 3, 3],
    ['cables', 'Кабель Baseus USB-C – USB-C 100W, 2 м', 55000, 99000, 15, 5, 4],
    ['cables', 'Кабель Hoco X88 USB – Type-C, 1 м', 14000, 35000, 46, 15, 10],
    ['cables', 'Кабель Hoco X88 USB – Lightning, 1 м', 16000, 39000, 38, 15, 9],
    ['cables', 'Кабель Hoco X88 USB – Micro-USB, 1 м', 12000, 29000, 21, 10, 4],
    ['cables', 'Кабель 3-в-1 Lightning / Type-C / Micro', 28000, 59000, 12, 5, 3],
    ['cables', 'Кабель Ugreen USB-C – USB-C 60W, 1 м', 45000, 79000, 18, 6, 4],

    ['cases', 'Чехол Silicone Case iPhone 15', 38000, 89000, 17, 5, 7],
    ['cases', 'Чехол прозрачный MagSafe iPhone 15 Pro', 52000, 119000, 12, 5, 6],
    ['cases', 'Чехол Silicone Case iPhone 13 / 14', 32000, 75000, 20, 5, 6],
    ['cases', 'Чехол Spigen Ultra Hybrid iPhone 16 Pro', 140000, 229000, 6, 3, 3],
    ['cases', 'Чехол-книжка Samsung Galaxy A55', 42000, 95000, 9, 4, 4],
    ['cases', 'Чехол силиконовый Samsung Galaxy S24', 38000, 85000, 3, 4, 3],
    ['cases', 'Чехол противоударный Redmi Note 13', 26000, 65000, 14, 5, 5],
    ['cases', 'Чехол прозрачный Samsung Galaxy A15', 12000, 35000, 25, 8, 5],

    ['screen', 'Защитное стекло 9D iPhone 15 / 15 Pro', 9000, 39000, 4, 10, 12],
    ['screen', 'Защитное стекло 9D iPhone 13 / 14', 8000, 35000, 33, 10, 10],
    ['screen', 'Защитное стекло 9D Samsung Galaxy A55', 8000, 35000, 19, 8, 6],
    ['screen', 'Защитное стекло 9D Redmi Note 13', 7000, 30000, 26, 8, 7],
    ['screen', 'Стекло «Антишпион» iPhone 15 Pro Max', 18000, 65000, 8, 4, 3],
    ['screen', 'Гидрогелевая плёнка (нарезка на плоттере)', 12000, 49000, 60, 15, 5],
    ['screen', 'Защита камеры iPhone 15 Pro', 12000, 45000, 11, 5, 4],

    ['audio', 'Наушники Apple AirPods Pro 2 (USB-C)', 2550000, 3190000, 1, 2, 1],
    ['audio', 'Наушники Samsung Galaxy Buds FE', 780000, 1090000, 4, 2, 1],
    ['audio', 'Наушники Xiaomi Redmi Buds 6 Active', 170000, 279000, 9, 3, 3],
    ['audio', 'Наушники Hoco EW04 TWS', 90000, 159000, 13, 4, 3],
    ['audio', 'Наушники Apple EarPods USB-C', 170000, 259000, 6, 3, 2],
    ['audio', 'Колонка JBL Go 4', 390000, 569000, 1, 2, 1],
    ['audio', 'Переходник USB-C – 3,5 мм', 18000, 45000, 16, 5, 3],

    ['accessories', 'Автодержатель магнитный Baseus', 65000, 129000, 7, 3, 2],
    ['accessories', 'Попсокет с рисунком', 9000, 29000, 34, 10, 5],
    ['accessories', 'Кольцо-держатель для телефона', 6000, 20000, 28, 10, 3],
    ['accessories', 'Монопод-трипод Bluetooth', 75000, 149000, 5, 2, 1],
    ['accessories', 'Карта памяти SanDisk microSD 64 ГБ', 70000, 115000, 12, 4, 2],
    ['accessories', 'Карта памяти SanDisk microSD 128 ГБ', 115000, 179000, 7, 3, 2],
    ['accessories', 'Флешка SanDisk Dual Drive USB-C 64 ГБ', 95000, 155000, 6, 3, 1],
    ['accessories', 'Ремешок силиконовый Apple Watch 45 мм', 25000, 69000, 15, 5, 2],
    ['accessories', 'Ключ-скрепка для SIM', 1000, 5000, 120, 30, 2],
  ];

  const EMPLOYEES = [
    { id: 'e1', name: 'Бахтиёр Каримов', role: 'owner', phone: '+998 90 912-45-67', active: true },
    { id: 'e2', name: 'Азиз Рахимов', role: 'seller', phone: '+998 93 541-20-18', active: true },
    { id: 'e3', name: 'Дилноза Юсупова', role: 'seller', phone: '+998 97 730-55-02', active: true },
    { id: 'e4', name: 'Шахзод Тошматов', role: 'seller', phone: '+998 99 104-87-33', active: true },
  ];

  const CUSTOMERS = ['Тимур', 'Нигора', 'Сардор', 'Мадина', 'Жасур', 'Камола', 'Ойбек', 'Лола',
    'Бехзод', 'Дильшод', 'Севара', 'Улугбек', 'Гульнора', 'Фаррух'];
  const SUPPLIERS = ['ООО «Mobile Distribution»', 'Baseus Uzbekistan', 'ИП Рахмонов (оптовый склад)', 'Hoco Tashkent'];
  const REASONS = ['Брак', 'Не подошёл', 'Передумал'];

  function create(now) {
    now = now || Date.now();
    const r = rng(20261102);
    const pick = (arr) => arr[Math.floor(r() * arr.length)];
    const today = startOfDay(now);

    const products = CATALOG.map(([category, name, buyPrice, sellPrice, stock, min, w], i) => ({
      id: 'p' + (i + 1),
      name, category,
      barcode: ean13('4781520' + String(i + 1).padStart(5, '0')),
      buyPrice, sellPrice, stock, min,
      _w: w,
    }));
    const totalW = products.reduce((s, p) => s + p._w, 0);
    const weighted = () => {
      let x = r() * totalW;
      for (const p of products) { x -= p._w; if (x <= 0) return p; }
      return products[products.length - 1];
    };

    function makeSale(ts, userId) {
      const n = r() < 0.62 ? 1 : r() < 0.75 ? 2 : 3;
      const chosen = [];
      let guard = 0;
      while (chosen.length < n && guard++ < 20) {
        const p = weighted();
        if (!chosen.includes(p)) chosen.push(p);
      }
      const items = chosen.map((p) => ({
        pid: p.id, name: p.name,
        qty: p.sellPrice < 60000 && r() < 0.22 ? 2 : 1,
        price: p.sellPrice, cost: p.buyPrice,
      }));
      const total = items.reduce((s, i) => s + i.qty * i.price, 0);
      const x = r();
      const payment = x < 0.45 ? 'cash' : x < 0.8 ? 'card' : 'qr';
      let received = null;
      if (payment === 'cash') {
        const step = total > 500000 ? 100000 : 10000;
        received = r() < 0.45 ? total : Math.ceil((total + 1) / step) * step;
      }
      return {
        id: '', no: 0, ts, userId,
        customer: r() < 0.38 ? pick(CUSTOMERS) : '',
        payment, items, total, received,
      };
    }

    const sales = [];
    const sellerPool = ['e2', 'e2', 'e2', 'e3', 'e3', 'e3', 'e4', 'e4', 'e1'];
    for (let d = 30; d >= 1; d--) {
      const day = startOfDay(today - d * DAY + DAY / 2);
      const wd = new Date(day).getDay();
      const count = 13 + Math.floor(r() * 9) + (wd === 0 || wd === 6 ? 5 : 0);
      for (let k = 0; k < count; k++) {
        const ts = day + 10 * 3600e3 + Math.floor(r() * 11 * 3600e3);
        sales.push(makeSale(ts, pick(sellerPool)));
      }
    }

    // Сегодня: 8 продаж между открытием магазина и текущим моментом
    const open = today + 10 * 3600e3;
    const from = now - open > 90 * 60e3 ? open : Math.max(today, now - 3 * 3600e3);
    const to = now - 2 * 60e3;
    const todayCount = 8;
    for (let k = 0; k < todayCount; k++) {
      const ts = Math.round(from + ((to - from) * (k + 0.2 + r() * 0.6)) / todayCount);
      sales.push(makeSale(ts, k % 3 === 2 ? 'e3' : 'e2'));
    }

    sales.sort((a, b) => a.ts - b.ts);
    sales.forEach((s, i) => { s.no = 1001 + i; s.id = 's' + s.no; });

    // Возвраты по чекам прошлых дней
    const returns = [];
    const past = sales.filter((s) => s.ts < today - 2 * DAY && s.ts > today - 26 * DAY);
    const used = new Set();
    for (let k = 0; k < 5; k++) {
      const s = pick(past);
      if (used.has(s.id)) continue;
      used.add(s.id);
      const it = s.items[0];
      const ts = Math.min(startOfDay(s.ts + DAY + Math.floor(r() * DAY)) + 12 * 3600e3 + Math.floor(r() * 6 * 3600e3), now - 3600e3);
      returns.push({
        id: '', no: 0, ts, saleId: s.id, saleNo: s.no, userId: pick(['e2', 'e3', 'e4']),
        payment: s.payment, reason: pick(REASONS),
        items: [{ pid: it.pid, name: it.name, qty: 1, price: it.price, cost: it.cost }],
        total: it.price,
      });
    }
    returns.sort((a, b) => a.ts - b.ts);
    returns.forEach((x, i) => { x.no = i + 1; x.id = 'r' + x.no; });

    // Приходы от поставщиков
    const incomes = [];
    [28, 21, 15, 9, 4, 1].forEach((d, k) => {
      const ts = startOfDay(today - d * DAY + DAY / 2) + 9 * 3600e3 + Math.floor(r() * 40 * 60e3);
      const supplier = SUPPLIERS[k % SUPPLIERS.length];
      const lines = [];
      const n = 4 + Math.floor(r() * 5);
      let guard = 0;
      while (lines.length < n && guard++ < 40) {
        const p = weighted();
        if (lines.some((l) => l.pid === p.id)) continue;
        const qty = p.buyPrice > 500000 ? 2 : p.buyPrice > 100000 ? 3 + Math.floor(r() * 4) : 10 + Math.floor(r() * 21);
        lines.push({ pid: p.id, name: p.name, qty, cost: p.buyPrice });
      }
      incomes.push({
        id: 'i' + (k + 1), no: k + 1, ts, userId: 'e1', supplier, items: lines,
        total: lines.reduce((s, l) => s + l.qty * l.cost, 0),
      });
    });

    // Инвентаризация защитных стёкол и чехлов 12 дней назад
    const auditTs = startOfDay(today - 12 * DAY + DAY / 2) + 20 * 3600e3 + 50 * 60e3;
    const auditItems = products
      .filter((p) => p.category === 'screen' || p.category === 'cases')
      .map((p) => {
        const expected = p.stock + 6;
        let actual = expected;
        if (p.id === 'p27') actual = expected - 2;
        if (p.id === 'p29') actual = expected - 1;
        if (p.id === 'p24') actual = expected + 1;
        return { pid: p.id, name: p.name, expected, actual, cost: p.buyPrice };
      });
    const audits = [{ id: 'a1', no: 1, ts: auditTs, userId: 'e1', items: auditItems }];

    products.forEach((p) => { delete p._w; });

    return {
      v: 1,
      meta: { demo: true, createdAt: now },
      store: { name: 'Mobi Aksessuar', address: 'г. Ташкент, Юнусабадский р-н, ул. Амира Темура, 107' },
      products,
      employees: EMPLOYEES.map((e) => ({ ...e })),
      currentUserId: 'e2',
      sales, returns, incomes, audits,
      seq: {
        sale: 1001 + sales.length,
        ret: returns.length + 1,
        inc: incomes.length + 1,
        aud: audits.length + 1,
      },
      cart: { items: [], customer: '', payment: 'cash', received: '' },
      incomeDraft: { supplier: '', lines: [] },
      auditDraft: { counts: {} },
    };
  }

  return { create };
})();
