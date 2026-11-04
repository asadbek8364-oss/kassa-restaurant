/*
 * Зал — общий модуль официанта (/waiter) и кассира (/cashier).
 *
 * Схема зала → столик → набор заказа (меню, количество, комментарии) → «Отправить на кухню», дозаказ,
 * отметки «готовится / готово / подано» (экран кухни их не ставит), счёт и оплата.
 * Кассир дополнительно: заказы навынос (очередь, «Выдан»), оплата заранее и карта через терминал кассы
 * (сумма уходит на терминал, экран ждёт результат; в тестовом режиме — имитация оплаты и отказа).
 *
 * Телефон (390–430 px, ориентир — 402 px): блюда в две колонки, поиск и категории закреплены сверху,
 * итог и «Отправить на кухню» — в нижней панели, заказ открывается «шторкой». Планшет (от 768 px) — меню
 * и заказ рядом, как раньше. Черновик заказа хранится на устройстве до отправки; повтор отправки
 * не создаёт дублей (client_uuid).
 */
'use strict';
(function () {
  const { html, raw } = R;
  const QUICK_COMMENTS = ['без лука', 'острое', 'не острое', 'без зелени', 'без соли', 'соус отдельно', 'поменьше масла', 'с собой'];
  // следующий шаг отправки на кухню: новое → готовится → готово → подано
  const STEP = { new: ['cooking', 'Готовится'], cooking: ['ready', 'Готово'], ready: ['served', 'Подано'] };
  const W = {
    cfg: {},
    state: null,
    menu: null,
    menuVersion: null,
    view: 'tables', // tables | table | takeaway | order
    depth: 0,
    screen: null, // что сейчас нарисовано (чтобы обновлять части, а не весь экран)
    tableId: null,
    orderId: null,
    order: null,
    compose: null, // { kind: table|takeaway, tableId, orderId, lines: [{dish_id, qty, comment}], comment, guests, client_uuid }
    cat: 'all',
    q: '',
    mine: false,
    ready: {}, // заказ → сколько позиций было готово (сигнал о новой готовности)
    readyInit: false,
    poll: 5000,
    timer: null,
    pay: null,
    term: null, // оплата на терминале: { id, payment, timer }
    receipt: null,
  };

  R.floor = { start };

  function start(cfg) {
    W.cfg = Object.assign({ api: '/' + R.app, takeaway: false, terminal: false, mineFilter: false }, cfg || {});
    W.mine = W.cfg.mineFilter ? R.local.get(R.app + ':mine', false) : false;
    R.start(async (me) => {
      W.poll = (me.poll_seconds || 5) * 1000;
      R.set(R.$('#who'), html`<span class="avatar sm ${me.employee.role}">${R.initials(me.employee.name)}</span><span>${R.firstName(me.employee.name)}</span>`);
      R.onNetwork = (ok) => { R.$('#net').hidden = ok; R.set(R.$('#net'), 'Нет связи'); };
      R.keepAwake();
      window.addEventListener('hashchange', route);
      R.live = () => refresh();
      await loadMenu();
      await refresh();
      route();
    });
  }

  const api = (method, path, body, opts) => R.api(method, W.cfg.api + path, body, opts);
  const typing = () => !!(document.activeElement && document.activeElement.closest('[data-input]'));
  const isManager = () => ['owner', 'admin'].includes(R.me.employee.role);
  const qtySum = (list) => list.reduce((s, i) => s + i.qty, 0);
  const itemLabel = (o, status) => (o && o.type === 'takeaway' && status === 'served' ? 'Выдано' : R.ITEM_STATUS[status] || status);

  // ---------------------------------------------------------------- данные
  async function loadMenu() {
    try {
      W.menu = await api('GET', '/menu');
      W.menuVersion = W.menu.menu_version;
    } catch (e) { R.fail(e); }
  }

  async function refresh() {
    clearTimeout(W.timer);
    try {
      W.state = await api('GET', '/state');
      R.syncClock(W.state.server_time);
      const menuChanged = W.state.menu_version !== W.menuVersion;
      if (menuChanged) await loadMenu();
      notifyReady();
      if (W.orderId && !W.compose) {
        try { W.order = (await api('GET', '/orders/' + W.orderId)).order; } catch (e) { if (e.status === 404) W.order = null; }
      }
      if (!W.compose) rerender();
      // при наборе заказа перерисовываем только при смене меню (стоп-лист) и не во время ввода текста
      else if (menuChanged && !typing()) { renderDishes(); renderCats(); }
      renderTabs();
    } catch (e) {
      if (e.status === 401) return;
    }
    W.timer = setTimeout(refresh, document.visibilityState === 'visible' ? W.poll : W.poll * 4);
  }
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && R.me) refresh(); });

  async function loadOrder() {
    if (!W.orderId) return;
    try {
      W.order = (await api('GET', '/orders/' + W.orderId)).order;
    } catch (e) {
      if (e.status === 404) { W.order = null; R.toast('Заказ не найден', 'bad'); location.hash = home(); return; }
      R.fail(e);
    }
    if (!W.compose) rerender();
  }

  // готовность с кухни: звук, вибрация и подсветка (свои заказы — в первую очередь)
  function notifyReady() {
    const now = {};
    const fresh = [];
    const orders = W.state.tables.filter((t) => t.order).map((t) => Object.assign({ label: 'стол ' + t.name }, t.order))
      .concat((W.cfg.takeaway ? W.state.takeaway || [] : []).map((o) => Object.assign({ label: 'навынос № ' + o.number }, o)));
    orders.forEach((o) => {
      now[o.id] = o.ready_count || 0;
      const before = W.ready[o.id];
      if (before !== undefined && now[o.id] > before) fresh.push(o);
    });
    const firstLoad = !W.readyInit;
    W.ready = now;
    W.readyInit = true;
    if (firstLoad || !fresh.length) return;
    const mine = fresh.filter((o) => o.waiter.id === R.me.employee.id);
    R.toast('Готово: ' + (mine.length ? mine : fresh).map((o) => o.label).join(', '), 'ok');
    R.unlockAudio();
    R.beep('ready');
    if (navigator.vibrate) navigator.vibrate([120, 80, 120]);
  }

  const tableById = (id) => (W.state ? W.state.tables.find((t) => t.id === id) : null);
  const dishById = (id) => (W.menu ? W.menu.dishes.find((d) => d.id === id) : null);
  const home = () => (W.view === 'order' && W.cfg.takeaway ? '#takeaway' : '#tables');

  // ---------------------------------------------------------------- маршруты
  function route() {
    const h = location.hash;
    const before = W.depth;
    let m;
    W.compose = null;
    W.order = null;
    W.screen = null;
    if ((m = h.match(/^#table\/(\d+)(\/add)?$/))) {
      W.view = 'table';
      W.tableId = Number(m[1]);
      const t = tableById(W.tableId);
      W.orderId = t && t.order ? t.order.id : null;
      if (m[2] || !W.orderId) startCompose('table', W.tableId, W.orderId);
      W.depth = W.compose ? 2 : 1;
    } else if (W.cfg.takeaway && (m = h.match(/^#order\/(\d+)(\/add)?$/))) {
      W.view = 'order';
      W.tableId = null;
      W.orderId = Number(m[1]);
      if (m[2]) startCompose('takeaway', null, W.orderId);
      W.depth = W.compose ? 2 : 1;
    } else if (W.cfg.takeaway && h === '#takeaway/new') {
      W.view = 'order';
      W.tableId = null;
      W.orderId = null;
      startCompose('takeaway', null, null);
      W.depth = 2;
    } else {
      W.view = W.cfg.takeaway && h === '#takeaway' ? 'takeaway' : 'tables';
      W.tableId = null;
      W.orderId = null;
      W.depth = 0;
    }
    rerender();
    R.enter(R.$('#view'), W.depth < before);
    window.scrollTo(0, 0);
    if (W.orderId && !W.compose) loadOrder();
  }

  function rerender() {
    if (!W.state) return;
    R.$('#back').hidden = W.view === 'tables' || W.view === 'takeaway';
    if (W.compose) renderCompose();
    else if (W.view === 'tables') renderTables();
    else if (W.view === 'takeaway') renderQueue();
    else renderOrder();
    renderTabs();
    // открыть оплату, если её попросили до загрузки заказа (кнопка «Оплата» в очереди, новый заказ навынос)
    if (W.payAfterLoad && W.order && W.order.id === W.payAfterLoad && !W.compose) {
      W.payAfterLoad = null;
      if (!W.order.paid && !['paid', 'cancelled'].includes(W.order.status)) payDialog();
    }
  }

  // кассир: «Зал» и «Навынос» — на верхних экранах
  function renderTabs() {
    const el = R.$('#tabs');
    if (!el) return;
    el.hidden = !W.cfg.takeaway || !W.state || (W.view !== 'tables' && W.view !== 'takeaway');
    if (el.hidden) return;
    const busy = W.state.tables.filter((t) => t.status !== 'free').length;
    const tw = W.state.takeaway || [];
    const ready = tw.filter((o) => o.status === 'ready').length;
    R.set(el, html`<a href="#tables" class="${W.view === 'tables' ? 'on' : ''}">Зал <span class="c">${busy}</span></a>
      <a href="#takeaway" class="${W.view === 'takeaway' ? 'on' : ''}">Навынос <span class="c${ready ? ' hot' : ''}" title="${ready ? 'готово к выдаче: ' + ready : ''}">${tw.length}</span></a>
      <span class="grow"></span>
      <a class="btn btn-primary only-wide" href="#takeaway/new">+ Заказ навынос</a>`);
  }

  // ---------------------------------------------------------------- схема зала
  const TABLE_STATUS = { free: 'Свободен', busy: 'Занят', bill: 'Ждёт счёт' };

  function renderTables() {
    const st = W.state;
    const me = R.me.employee.id;
    const tables = st.tables.filter((t) => !W.mine || (t.order && t.order.waiter.id === me) || !t.order);
    const busy = st.tables.filter((t) => t.status !== 'free').length;
    R.set(R.$('#eyebrow'), R.me.store.name);
    R.set(R.$('#title'), 'Зал · занято ' + busy + ' из ' + st.tables.length);
    R.$('#bar').hidden = true;
    if (W.screen !== 'tables') {
      W.screen = 'tables';
      R.set(R.$('#view'), html`
        ${st.mode === 'online' ? html`<div class="banner" style="margin:0 0 12px">Это онлайн-сервер: заказы принимает локальный сервер ресторана.</div>` : ''}
        <div class="w-filters" id="filters"></div>
        <div class="tables-grid" id="tables-grid"></div>
        <div id="orphans"></div>`);
    }
    R.set(R.$('#filters'), html`
      ${W.cfg.mineFilter ? html`<div class="seg"><button class="${W.mine ? '' : 'on'}" data-act="mine" data-v="0">Все столики</button><button class="${W.mine ? 'on' : ''}" data-act="mine" data-v="1">Мои и свободные</button></div>` : ''}
      <div class="legend"><span><i style="background:#fff;border:2px solid #b8e2cf"></i>свободен</span><span><i style="background:#fff3db;border:2px solid #f2c76e"></i>занят</span><span><i style="background:#ede5ff;border:2px solid #b69af5"></i>ждёт счёт</span></div>`);
    R.keyed(R.$('#tables-grid'), tables.length ? tables.map((t) => html`<button class="t-tile t-${t.status}" data-key="${t.id}" data-sig="${t.status}:${t.order ? t.order.ready_count : 0}" data-act="openTable" data-id="${t.id}">
        <span class="n">${t.name}</span>
        <span class="st">${TABLE_STATUS[t.status]}</span>
        ${t.order ? html`<span class="meta">${R.firstName(t.order.waiter.name)} · ${t.order.guests ? t.order.guests + ' г. · ' : ''}${R.ago(t.order.created_at)}</span>
          <span class="sum">${R.money(t.order.total)}</span>
          ${t.order.ready_count ? html`<span class="ready">Готово ${t.order.ready_count}</span>` : ''}` : html`<span class="meta">${t.seats} ${R.plural(t.seats, 'место', 'места', 'мест')}</span>`}
      </button>`) : html`<div class="card empty" style="grid-column:1/-1">Столиков нет — администратор добавляет их в админке: /admin → Столики.</div>`);
    R.set(R.$('#orphans'), st.orphans.length ? html`<div class="card" style="margin-top:16px"><div class="card-h"><h2>Заказы без столика</h2></div>
      ${st.orphans.map((o) => html`<div class="list-row"><div class="grow"><div class="t">№ ${o.number} · ${o.table_name}</div><div class="s">${R.statusName(o)} · ${R.money(o.total)}</div></div></div>`)}</div>` : '');
  }

  // ---------------------------------------------------------------- кассир: очередь навынос
  function renderQueue() {
    const list = (W.state.takeaway || []).slice().sort((a, b) => a.number - b.number);
    R.set(R.$('#eyebrow'), R.me.store.name);
    R.set(R.$('#title'), 'Навынос · в работе ' + list.length);
    const bar = R.$('#bar');
    bar.hidden = false;
    bar.className = 'w-bar';
    R.set(bar, html`<button class="btn btn-lg btn-primary btn-block" data-act="newTakeaway">+ Новый заказ навынос</button>`);
    if (W.screen !== 'takeaway') {
      W.screen = 'takeaway';
      R.set(R.$('#view'), html`<div id="tw-list"></div>`);
    }
    const groups = [
      ['ready', 'Готово к выдаче', list.filter((o) => o.status === 'ready')],
      ['cooking', 'Готовится', list.filter((o) => o.status === 'new' || o.status === 'cooking')],
      ['served', 'Выдан — ждёт оплаты', list.filter((o) => o.status === 'served')],
    ].filter((g) => g[2].length);
    R.keyed(R.$('#tw-list'), groups.length ? groups.map(([key, title, orders]) => html`<h2 class="tw-section" data-key="s-${key}">${title} · ${orders.length}</h2>
      <div class="tw-grid" data-key="g-${key}">${orders.map(twCard)}</div>`)
      : html`<div class="card empty" data-key="empty">Заказов навынос нет. Нажмите «Новый заказ навынос» — заказ уйдёт на кухню, оплатить можно сразу или при выдаче.</div>`, { selector: '[data-key]' });
  }

  function twCard(o) {
    const step = STEP[o.status] && o.status !== 'ready' ? STEP[o.status] : null;
    const lines = (o.lines || []).map((l) => l.qty + '× ' + l.name).join(' · ');
    return html`<article class="tw-card${o.status === 'ready' ? ' st-ready-card' : ''}" data-key="o${o.id}" data-sig="${o.status}:${o.paid ? 1 : 0}">
      <button class="tw-head" data-act="openOrder" data-id="${o.id}"><span class="no">№ ${o.number}</span><span class="who">${o.comment || ''}</span><span class="st-pill st-${o.status}">${R.statusName(o)}</span></button>
      ${lines ? html`<div class="tw-items">${lines}</div>` : ''}
      <div class="tw-meta"><b>${R.sum(o.total)}</b><span class="pill ${o.paid ? 'ok' : 'warn'}">${o.paid ? 'оплачен' + (o.payment === 'card' ? ' картой' : o.payment === 'cash' ? ' наличными' : '') : 'не оплачен'}</span><span>· ${R.ago(o.created_at)}</span></div>
      <div class="tw-actions">
        ${step ? html`<button class="btn" data-act="orderStep" data-id="${o.id}" data-to="${step[0]}">${step[1]}</button>` : ''}
        ${o.status === 'ready' ? html`<button class="btn btn-ok" data-act="handout" data-id="${o.id}">Выдан</button>` : ''}
        ${o.paid ? '' : html`<button class="btn btn-primary" data-act="payOrder" data-id="${o.id}">Оплата</button>`}
      </div>
    </article>`;
  }

  // ---------------------------------------------------------------- заказ
  function aggregate(items) {
    const active = items.filter((i) => i.status !== 'cancelled');
    const pending = active.filter((i) => i.status !== 'served');
    if (!active.length) return 'cancelled';
    if (!pending.length) return 'served';
    if (pending.every((i) => i.status === 'ready')) return 'ready';
    if (pending.every((i) => i.status === 'new')) return 'new';
    return 'cooking';
  }

  function renderOrder() {
    const t = W.tableId ? tableById(W.tableId) : null;
    const o = W.order;
    R.set(R.$('#eyebrow'), o ? R.place(o) : t ? 'Стол ' + t.name : 'Навынос');
    if (!o) {
      R.set(R.$('#title'), 'Заказ…');
      R.set(R.$('#view'), html`<div class="empty">Загрузка заказа…</div>`);
      R.$('#bar').hidden = true;
      W.screen = null;
      return;
    }
    const takeaway = o.type === 'takeaway';
    const open = !['paid', 'cancelled'].includes(o.status);
    R.set(R.$('#title'), 'Заказ № ' + o.number);
    const key = 'order:' + o.id;
    if (W.screen !== key) {
      W.screen = key;
      R.set(R.$('#view'), html`<div class="card" id="order-card"></div>`);
    }
    const batches = {};
    o.items.forEach((i) => { (batches[i.batch] = batches[i.batch] || []).push(i); });
    const ticket = (b) => o.tickets.find((x) => x.batch === Number(b)) || {};
    const pendingTerm = (o.terminal || []).find((x) => x.status === 'pending');
    const printing = W.state.printer_mode !== 'off';
    const parts = [];
    parts.push(html`<div class="card-h" data-key="head" data-sig="${o.status}:${o.paid}"><div style="min-width:0"><h2>${R.place(o)} · № ${o.number}</h2>
        <div class="sub">${o.waiter.name}${o.guests ? ' · гостей ' + o.guests : ''} · открыт в ${R.time(o.created_at)} (${R.ago(o.created_at)})</div></div>
        <span class="st-pill st-${o.status}">${R.statusName(o)}</span></div>`);
    if (o.comment) parts.push(html`<p class="sub" data-key="comment" style="color:var(--warn);font-weight:600;margin:0 0 6px">${takeaway ? '' : 'К заказу: '}${o.comment}</p>`);
    if (o.paid && open) parts.push(html`<p data-key="paid"><span class="pill ok wrap">Оплачен ${o.payment === 'card' ? 'картой' : 'наличными'} в ${R.time(o.paid_at)} — заказ закроется, когда его выдадут</span></p>`);
    else if (o.bill_at && open && !takeaway) parts.push(html`<p data-key="bill"><span class="pill violet">Счёт выдан в ${R.time(o.bill_at)}</span></p>`);
    if (pendingTerm) parts.push(html`<div class="banner" data-key="term" style="margin:8px 0"><span class="grow">Идёт оплата на терминале: ${R.sum(pendingTerm.amount)}</span><button class="btn btn-sm" data-act="pay">Открыть</button></div>`);
    Object.keys(batches).sort((a, b) => a - b).forEach((b) => {
      const items = batches[b];
      const tk = ticket(b);
      const agg = aggregate(items);
      const step = open && STEP[agg] && !(takeaway && agg === 'ready') ? STEP[agg] : null;
      parts.push(html`<div class="batch-h" data-key="b${b}" data-sig="${agg}"><span class="t">${Number(b) === 1 ? 'Заказ' : 'Дозаказ'} · ${R.time(tk.sent_at)}${tk.comment && Number(b) > 1 ? ' · ' + tk.comment : ''}</span>
        ${agg !== 'cancelled' ? html`<span class="st-pill st-${agg}">${itemLabel(o, agg)}</span>` : ''}
        ${step ? html`<button class="btn btn-sm btn-soft" data-act="batchStep" data-ticket="${tk.id}" data-to="${step[0]}">${step[0] === 'served' ? 'Подано' : step[1]}</button>` : ''}
        ${open && printing && tk.id ? html`<button class="btn btn-sm btn-ghost" data-act="reprint" data-ticket="${tk.id}" title="Повторить кухонный чек" aria-label="Повторить кухонный чек отправки ${b}">⎙</button>` : ''}</div>`);
      items.forEach((i) => {
        const editable = open && ['new', 'cooking', 'ready'].includes(i.status);
        const cancel = open && !o.paid && i.status !== 'cancelled' && (i.status === 'new' || isManager());
        parts.push(html`<div class="o-line ${i.status}" data-key="i${i.id}" data-sig="${i.status}">
          <div class="grow"><div class="nm">${i.qty} × ${i.name}</div>${i.comment ? html`<div class="cm">${i.comment}</div>` : ''}<div class="pr">${R.money(i.sum)}</div></div>
          ${editable ? html`<button class="st-pill st-${i.status}" data-act="itemStatus" data-id="${i.id}" aria-label="${i.name}: ${itemLabel(o, i.status)} — изменить">${itemLabel(o, i.status)}</button>`
            : html`<span class="st-pill st-${i.status}">${itemLabel(o, i.status)}</span>`}
          ${cancel ? html`<button class="btn btn-sm" data-act="cancelItem" data-id="${i.id}" aria-label="Отменить ${i.name}">✕</button>` : ''}
        </div>`);
      });
    });
    parts.push(html`<div class="list-row" data-key="total" data-sig="${o.total}" style="margin-top:8px"><div class="grow t">Итого</div><div class="big-total">${R.sum(o.total)}</div></div>`);
    parts.push(html`<div class="form-actions" data-key="actions" style="justify-content:flex-start">
        ${open && !o.paid ? html`<button class="btn btn-ghost" data-act="cancelOrder">Отменить заказ</button>` : ''}
        <button class="btn btn-ghost" data-act="showBill">Чек на экране</button>
      </div>`);
    R.keyed(R.$('#order-card'), parts, { changed: true });
    renderOrderBar(o, open);
  }

  function renderOrderBar(o, open) {
    const bar = R.$('#bar');
    if (!open) { bar.hidden = true; return; }
    const ready = qtySum(o.items.filter((i) => i.status === 'ready'));
    bar.hidden = false;
    if (o.type === 'takeaway') {
      const allReady = o.items.some((i) => i.status === 'ready') && !o.items.some((i) => i.status === 'new' || i.status === 'cooking');
      const buttons = [];
      if (!o.paid) buttons.push(html`<button class="btn btn-lg btn-soft" data-act="addMore">+ Дозаказ</button>`);
      if (allReady) buttons.push(html`<button class="btn btn-lg btn-ok" data-act="handout" data-id="${o.id}">Выдан</button>`);
      if (!o.paid) buttons.push(html`<button class="btn btn-lg btn-primary" data-act="pay">Оплата</button>`);
      if (!buttons.length) buttons.push(html`<span class="sub" style="flex:1;text-align:center">Оплачен — отметьте «Выдан», когда всё будет готово</span>`);
      bar.className = 'w-bar bar-' + buttons.length;
      R.set(bar, buttons);
      return;
    }
    bar.className = 'w-bar bar-3';
    R.set(bar, html`
      <button class="btn btn-lg btn-soft" data-act="addMore">+ Дозаказ</button>
      ${ready ? html`<button class="btn btn-lg btn-ok only-wide" data-act="serve">Подано (${ready})</button>` : ''}
      <span class="grow only-wide"></span>
      <button class="btn btn-lg" data-act="bill">Счёт</button>
      <button class="btn btn-lg btn-primary" data-act="pay">Оплата</button>`);
  }

  // ---------------------------------------------------------------- набор заказа (новый, дозаказ, навынос)
  const draftKey = (c) => R.app + ':draft:' + (c.kind === 'takeaway' ? 'takeaway:' + (c.orderId || 'new') : c.tableId + ':' + (c.orderId || 'new'));

  function startCompose(kind, tableId, orderId) {
    const blank = { kind, tableId, orderId, lines: [], comment: '', guests: '', client_uuid: R.uuid() };
    W.compose = Object.assign(blank, R.local.get(draftKey(blank)) || {}, { kind, tableId, orderId });
    W.cat = 'all';
    W.q = '';
  }
  const saveDraft = () => { if (W.compose) R.local.set(draftKey(W.compose), W.compose); };
  const dropDraft = () => { if (W.compose) R.local.del(draftKey(W.compose)); };
  const cartQty = (dishId) => W.compose.lines.filter((l) => l.dish_id === dishId).reduce((s, l) => s + l.qty, 0);
  const cartCount = () => W.compose.lines.reduce((s, l) => s + l.qty, 0);
  const cartTotal = () => W.compose.lines.reduce((s, l) => s + l.qty * ((dishById(l.dish_id) || {}).price || 0), 0);

  function composeTitle(c) {
    if (c.kind === 'takeaway') return c.orderId ? 'Дозаказ навынос' : 'Заказ навынос';
    return c.orderId ? 'Дозаказ' : 'Новый заказ';
  }
  function composeHeading(c) {
    const t = tableById(c.tableId);
    if (c.kind === 'takeaway') return c.orderId ? 'Дозаказ к № ' + orderNumber(c.orderId) : 'Навынос';
    return c.orderId ? 'Дозаказ к № ' + (t && t.order ? t.order.number : '') : 'Заказ · стол ' + (t ? t.name : '');
  }
  function orderNumber(id) {
    const o = (W.state.takeaway || []).find((x) => x.id === id);
    return o ? o.number : '';
  }

  function renderCompose() {
    const c = W.compose;
    const t = tableById(c.tableId);
    R.set(R.$('#eyebrow'), c.kind === 'takeaway' ? (c.orderId ? 'Навынос · № ' + orderNumber(c.orderId) : R.me.store.name) : 'Стол ' + (t ? t.name : ''));
    R.set(R.$('#title'), composeTitle(c));
    if (W.screen !== 'compose') {
      W.screen = 'compose';
      R.set(R.$('#view'), html`<div class="order-layout">
        <section style="min-width:0">
          <div class="menu-tools">
            <div class="search"><input class="input" type="search" placeholder="Поиск блюда" value="${W.q}" data-input="search" aria-label="Поиск блюда" enterkeyhint="search" autocomplete="off"></div>
            <div class="menu-cats" id="cats" role="tablist" aria-label="Категории меню"></div>
          </div>
          <div class="dish-grid" id="dishes"></div>
          <div id="dishes-empty"></div>
        </section>
        <aside class="order-panel card only-wide" id="cart"></aside>
      </div>`);
    }
    renderCats();
    renderDishes();
    renderCart();
    renderComposeBar(false);
  }

  function renderCats() {
    const menu = W.menu || { categories: [] };
    R.set(R.$('#cats'), html`<button class="chip${W.cat === 'all' ? ' on' : ''}" data-act="cat" data-id="all" role="tab" aria-selected="${W.cat === 'all'}">Все</button>
      ${menu.categories.map((cat) => html`<button class="chip${W.cat === cat.id ? ' on' : ''}" data-act="cat" data-id="${cat.id}" role="tab" aria-selected="${W.cat === cat.id}">${cat.name}</button>`)}`);
  }

  function renderDishes() {
    const menu = W.menu || { dishes: [] };
    const q = W.q.trim().toLowerCase();
    const dishes = menu.dishes.filter((d) => (W.cat === 'all' || d.category_id === W.cat) && (!q || d.name.toLowerCase().includes(q)));
    R.set(R.$('#dishes'), dishes.map((d) => dishTile(d)));
    R.set(R.$('#dishes-empty'), dishes.length ? '' : html`<div class="empty">Ничего не найдено</div>`);
  }

  function dishTile(d) {
    const n = W.compose ? cartQty(d.id) : 0;
    return html`<button class="dish-btn${d.stop ? ' stop' : ''}${n ? ' in-cart' : ''}" data-act="addDish" data-id="${d.id}"${raw(d.stop ? ' aria-disabled="true"' : '')} title="${d.description || ''}" aria-label="${d.name}, ${R.money(d.price)} сум${n ? ', в заказе ' + n : ''}">
      <span class="dn">${d.name}</span><span class="dp">${R.money(d.price)}</span>${n ? html`<span class="dq">${n}</span>` : ''}</button>`;
  }

  // плитка блюда после добавления: только её счётчик, без перерисовки меню
  function updateTile(dishId, bump) {
    const el = R.$('.dish-btn[data-id="' + dishId + '"]');
    const d = dishById(dishId);
    if (!el || !d) return;
    const n = cartQty(dishId);
    let badge = R.$('.dq', el);
    el.classList.toggle('in-cart', n > 0);
    el.setAttribute('aria-label', d.name + ', ' + R.money(d.price) + ' сум' + (n ? ', в заказе ' + n : ''));
    if (!n) { if (badge) badge.remove(); return; }
    if (!badge) { badge = document.createElement('span'); badge.className = 'dq'; el.appendChild(badge); }
    badge.textContent = String(n);
    if (bump && !R.still()) R.restart(badge, 'bump');
  }

  function cartLines() {
    const c = W.compose;
    return c.lines.length ? c.lines.map((l, i) => {
      const d = dishById(l.dish_id) || { name: 'Блюдо', price: 0 };
      return html`<div class="o-line" data-key="${l.dish_id}|${l.comment}" data-sig="${l.qty}"><div class="grow"><div class="nm">${d.name}</div>
          <button class="btn btn-sm btn-ghost note-btn" data-act="lineComment" data-i="${i}">${l.comment ? html`<span class="cm">${l.comment}</span>` : '+ комментарий'}</button>
          <div class="pr">${R.money(d.price * l.qty)}</div></div>
        <div class="qty"><button data-act="qty" data-i="${i}" data-d="-1" aria-label="Меньше">−</button><b>${l.qty}</b><button data-act="qty" data-i="${i}" data-d="1" aria-label="Больше">+</button></div></div>`;
    }) : html`<div class="empty" data-key="empty">Нажимайте на блюда — они появятся здесь</div>`;
  }

  // поля заказа: в панели (планшет) и в «шторке» (телефон) — свои id, значения общие
  function cartFields(where) {
    const c = W.compose;
    const comment = c.kind === 'takeaway' ? 'Комментарий: имя гостя, ко времени' : c.orderId ? 'Комментарий к дозаказу' : 'Комментарий к заказу';
    return html`${c.orderId || c.kind === 'takeaway' ? '' : html`<div class="field" style="margin-top:12px"><label for="${where}-guests">Гостей</label><input class="input" id="${where}-guests" type="number" min="0" max="99" inputmode="numeric" value="${c.guests}" data-input="guests"></div>`}
      <div class="field"${raw(c.orderId || c.kind === 'takeaway' ? ' style="margin-top:12px"' : '')}><label for="${where}-comment">${comment}</label><input class="input" id="${where}-comment" maxlength="200" value="${c.comment}" data-input="comment" placeholder="${c.kind === 'takeaway' ? 'Анвар, заберёт в 13:30' : 'аллергия, подать сразу…'}"></div>`;
  }

  // заказ: панель справа (планшет) и «шторка» (телефон) — одно содержимое
  function renderCart() {
    const c = W.compose;
    const panel = R.$('#cart');
    if (panel) {
      if (!R.$('#cart-lines', panel)) {
        R.set(panel, html`<div class="card-h"><h2 id="cart-title"></h2><button class="btn btn-sm btn-ghost" data-act="clearCart" id="cart-clear">Очистить</button></div>
          <div id="cart-lines"></div><div id="cart-fields"></div>`);
        R.set(R.$('#cart-fields', panel), cartFields('panel'));
      }
      R.set(R.$('#cart-title', panel), composeHeading(c));
      R.$('#cart-clear', panel).hidden = !c.lines.length;
      R.keyed(R.$('#cart-lines', panel), cartLines(), { changed: true });
    }
    const sheet = R.$('#sheet-lines');
    if (sheet) {
      R.keyed(sheet, cartLines(), { changed: true });
      const send = R.$('#sheet-send');
      if (send) { send.disabled = !c.lines.length; R.set(send, 'Отправить на кухню · ' + R.sum(cartTotal())); }
      const clear = R.$('#sheet-clear');
      if (clear) clear.hidden = !c.lines.length;
    }
  }

  function renderComposeBar(bump) {
    const c = W.compose;
    const count = cartCount();
    const bar = R.$('#bar');
    bar.hidden = false;
    bar.className = 'w-bar';
    R.set(bar, html`<button class="cart-sum" data-act="openCart" aria-label="Открыть заказ: ${count} позиций на ${R.sum(cartTotal())}">
        <span class="n${count ? '' : ' zero'}">${count}</span><span class="s"><b>${R.sum(cartTotal())}</b><small>${count ? 'заказ · открыть' : 'выберите блюда'}</small></span></button>
      <div class="total only-wide"><b>${R.sum(cartTotal())}</b><span>${count} ${R.plural(count, 'позиция', 'позиции', 'позиций')}</span></div>
      ${c.orderId ? html`<button class="btn btn-lg only-wide" data-act="cancelCompose">Отмена</button>` : ''}
      <button class="btn btn-lg btn-primary send" data-act="send"${raw(count ? '' : ' disabled')}>Отправить на кухню</button>`);
    if (bump) R.restart(R.$('.cart-sum', bar), 'bump');
  }

  function cartSheet() {
    const c = W.compose;
    R.modal(html`<h2>${composeHeading(c)}</h2>
      <div id="sheet-lines"></div>
      ${cartFields('sheet')}
      <div class="form-actions">
        <button class="btn btn-ghost" data-act="clearCart" id="sheet-clear"${raw(c.lines.length ? '' : ' hidden')} style="margin-right:auto">Очистить</button>
        ${c.orderId ? html`<button class="btn" data-act="cancelCompose">Отменить дозаказ</button>` : ''}
        <button class="btn btn-lg btn-primary btn-block" id="sheet-send" data-act="send"${raw(c.lines.length ? '' : ' disabled')}>Отправить на кухню · ${R.sum(cartTotal())}</button>
      </div>`, { sheet: true, focus: false });
    R.keyed(R.$('#sheet-lines'), cartLines());
  }

  // поля ввода без перерисовки (чтобы не терялся фокус)
  document.addEventListener('input', (ev) => {
    const el = ev.target.closest('[data-input]');
    if (!el || !W.compose) return;
    const k = el.dataset.input;
    if (k === 'search') {
      W.q = el.value;
      renderDishes();
      return;
    }
    W.compose[k] = el.value;
    // то же поле в панели и в «шторке» — одинаковое значение
    R.$$('[data-input="' + k + '"]').forEach((other) => { if (other !== el) other.value = el.value; });
    saveDraft();
  });

  // ---------------------------------------------------------------- действия
  async function send(btn) {
    const c = W.compose;
    const body = {
      items: c.lines.map((l) => ({ dish_id: l.dish_id, qty: l.qty, comment: l.comment || null })),
      comment: c.comment || null,
      client_uuid: c.client_uuid,
    };
    await R.busy(btn, async () => {
      try {
        let res;
        if (c.orderId) {
          res = await api('POST', '/orders/' + c.orderId + '/items', body);
        } else if (c.kind === 'takeaway') {
          res = await api('POST', '/takeaway', body);
        } else {
          body.table_id = c.tableId;
          body.guests = c.guests === '' ? null : Number(c.guests);
          res = await api('POST', '/orders', body);
        }
        dropDraft();
        R.closeModal();
        R.toast(res.replay ? 'Заказ уже был отправлен' : c.kind === 'takeaway' && !c.orderId ? 'Заказ № ' + res.order.number + ' на кухне' : 'Отправлено на кухню', 'ok');
        if (c.kind === 'takeaway') {
          // гость у кассы — сразу предлагаем оплату (можно и позже, при выдаче)
          if (!c.orderId && !res.replay) W.payAfterLoad = res.order.id;
          location.hash = '#order/' + res.order.id;
          refresh();
          return;
        }
        // заказ столика: сразу показываем его, не дожидаясь обновления схемы зала
        Object.assign(W, { compose: null, order: res.order, orderId: res.order.id, view: 'table', tableId: c.tableId, depth: 1, screen: null });
        if (location.hash !== '#table/' + c.tableId) history.replaceState(null, '', '#table/' + c.tableId);
        rerender();
        R.enter(R.$('#view'), true);
        window.scrollTo(0, 0);
        await refresh();
      } catch (e) {
        if (e.status === 409 && e.data && e.data.order_id && !c.orderId && c.kind === 'table') {
          // столик успели занять — предлагаем добавить блюда в его заказ
          await refresh();
          if (await R.confirm('Столик уже занят', e.message + '. Добавить эти блюда в этот заказ?', 'Добавить')) {
            dropDraft();
            c.orderId = e.data.order_id;
            c.client_uuid = R.uuid();
            saveDraft();
            await send(btn);
          }
          return;
        }
        R.fail(e);
      }
    });
  }

  // запрос с перерисовкой заказа; ответ сервера — свежий заказ
  async function orderAction(btn, path, body, done) {
    await R.busy(btn, async () => {
      try {
        const res = await api('POST', path, body || {});
        if (res && res.order && W.order && res.order.id === W.order.id) W.order = res.order;
        if (done) R.toast(typeof done === 'function' ? done(res) : done, 'ok');
        await refresh();
        if (W.order && !W.compose) rerender();
      } catch (e) { R.fail(e); refresh(); }
    });
  }

  Object.assign(R.actions, {
    back() {
      if (W.compose && W.compose.orderId) location.hash = W.compose.kind === 'takeaway' ? '#order/' + W.compose.orderId : '#table/' + W.tableId;
      else location.hash = W.view === 'order' && W.cfg.takeaway ? '#takeaway' : '#tables';
    },
    mine(el) { W.mine = el.dataset.v === '1'; R.local.set(R.app + ':mine', W.mine); renderTables(); },
    openTable(el) { location.hash = '#table/' + el.dataset.id; },
    openOrder(el) { location.hash = '#order/' + el.dataset.id; },
    newTakeaway() { location.hash = '#takeaway/new'; },
    cat(el) {
      W.cat = el.dataset.id === 'all' ? 'all' : Number(el.dataset.id);
      renderCats();
      renderDishes();
      R.enter(R.$('#dishes'), true);
      const on = R.$('#cats .chip.on');
      if (on && on.scrollIntoView) on.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: R.still() ? 'auto' : 'smooth' });
    },
    addDish(el) {
      const d = dishById(Number(el.dataset.id));
      if (!d) return;
      if (d.stop) { R.toast('«' + d.name + '» в стоп-листе', 'bad'); return; }
      const line = W.compose.lines.find((l) => l.dish_id === d.id && !l.comment);
      if (line) line.qty = Math.min(99, line.qty + 1);
      else W.compose.lines.push({ dish_id: d.id, qty: 1, comment: '' });
      saveDraft();
      updateTile(d.id, true);
      renderCart();
      renderComposeBar(true);
      if (navigator.vibrate) navigator.vibrate(8);
    },
    qty(el) {
      const i = Number(el.dataset.i);
      const line = W.compose.lines[i];
      line.qty = Math.min(99, line.qty + Number(el.dataset.d));
      if (line.qty <= 0) W.compose.lines.splice(i, 1);
      saveDraft();
      updateTile(line.dish_id, Number(el.dataset.d) > 0);
      renderCart();
      renderComposeBar(false);
    },
    openCart() { if (W.compose) cartSheet(); },
    async clearCart() {
      if (!(await R.confirm('Очистить заказ?', 'Все выбранные блюда будут убраны.', 'Очистить', true))) return;
      const ids = W.compose.lines.map((l) => l.dish_id);
      W.compose.lines = [];
      saveDraft();
      ids.forEach((id) => updateTile(id, false));
      renderCart();
      renderComposeBar(false);
    },
    lineComment(el) {
      const i = Number(el.dataset.i);
      const line = W.compose.lines[i];
      const d = dishById(line.dish_id) || { name: '' };
      const box = R.modal(html`<h2>${d.name}</h2><p class="sub">Комментарий повару</p>
        <div class="cat-chips">${QUICK_COMMENTS.map((q) => html`<button class="chip" data-quick="${q}">${q}</button>`)}</div>
        <form data-submit="saveComment"><input type="hidden" name="i" value="${i}">
          <div class="field"><input class="input" name="comment" maxlength="120" value="${line.comment}" autofocus placeholder="без лука, острое…"></div>
          <div class="form-actions"><button type="button" class="btn" data-act="modalClose">Отмена</button><button class="btn btn-primary">Сохранить</button></div></form>`, { sheet: true });
      box.addEventListener('click', (ev) => {
        const q = ev.target.closest('[data-quick]');
        if (!q) return;
        const input = R.$('input[name=comment]', box);
        input.value = input.value ? input.value + ', ' + q.dataset.quick : q.dataset.quick;
        input.focus();
      });
    },
    saveComment(form) {
      const data = R.formData(form);
      const line = W.compose.lines[Number(data.i)];
      line.comment = data.comment.slice(0, 120);
      // одинаковые блюда с одинаковым комментарием — одной строкой
      const twin = W.compose.lines.find((l) => l !== line && l.dish_id === line.dish_id && l.comment === line.comment);
      if (twin) { twin.qty = Math.min(99, twin.qty + line.qty); W.compose.lines.splice(W.compose.lines.indexOf(line), 1); }
      saveDraft();
      R.closeModal();
      renderCart();
      renderComposeBar(false);
    },
    send: (el) => send(el),
    cancelCompose() {
      const c = W.compose;
      dropDraft();
      R.closeModal();
      W.compose = null;
      location.hash = c.kind === 'takeaway' ? (c.orderId ? '#order/' + c.orderId : '#takeaway') : '#table/' + c.tableId;
      route();
    },
    addMore() { location.hash = W.order && W.order.type === 'takeaway' ? '#order/' + W.order.id + '/add' : '#table/' + W.tableId + '/add'; },
    serve: (el) => orderAction(el, '/orders/' + W.orderId + '/serve', {}, 'Отмечено: подано'),
    batchStep(el) {
      const to = el.dataset.to;
      orderAction(el, '/orders/' + W.orderId + '/status', { status: to, ticket_id: Number(el.dataset.ticket) }, { cooking: 'Отмечено: готовится', ready: 'Отмечено: готово', served: 'Отмечено: подано' }[to]);
    },
    orderStep(el) {
      const to = el.dataset.to;
      orderAction(el, '/orders/' + el.dataset.id + '/status', { status: to }, 'Заказ: ' + (to === 'cooking' ? 'готовится' : 'готово'));
    },
    reprint: (el) => orderAction(el, '/orders/' + W.orderId + '/tickets/' + el.dataset.ticket + '/reprint', {}, 'Кухонный чек отправлен на печать'),
    handout(el) {
      const id = Number(el.dataset.id);
      orderAction(el, '/orders/' + id + '/handout', {}, (res) => (res.order.status === 'paid' ? 'Выдан. Заказ закрыт' : 'Выдан — осталось принять оплату'))
        .then(() => { if (W.view === 'order' && W.order && W.order.status === 'paid') location.hash = '#takeaway'; });
    },
    itemStatus(el) {
      const item = W.order.items.find((i) => i.id === Number(el.dataset.id));
      if (!item) return;
      const o = W.order;
      R.modal(html`<h2>${item.qty} × ${item.name}</h2><p class="sub">Сейчас: ${itemLabel(o, item.status)}. Кухня только показывает заказ — отметку ставите вы.</p>
        <div class="status-pick">${['new', 'cooking', 'ready', 'served'].map((s) => html`<button class="${item.status === s ? 'on' : ''}" data-act="setItem" data-id="${item.id}" data-to="${s}"${raw(item.status === s ? ' disabled' : '')}>${itemLabel(o, s)}</button>`)}</div>`, { sheet: true, focus: false });
    },
    setItem(el) {
      R.closeModal();
      orderAction(null, '/orders/' + W.orderId + '/status', { status: el.dataset.to, item_ids: [Number(el.dataset.id)] });
    },
    async cancelItem(el) {
      const item = W.order.items.find((i) => i.id === Number(el.dataset.id));
      if (!(await R.confirm('Отменить «' + item.name + '»?', item.status === 'new' ? 'Кухня получит чек отмены.' : 'Блюдо уже готовится или подано — отмена администратором.', 'Отменить позицию', true))) return;
      orderAction(null, '/orders/' + W.orderId + '/items/' + item.id + '/cancel', {}, 'Позиция отменена');
    },
    cancelOrder() {
      R.modal(html`<h2>Отменить заказ № ${W.order.number}?</h2><p class="sub">Заказ закроется без оплаты. Если блюда уже готовятся, отменить может только администратор.</p>
        <form data-submit="doCancelOrder"><div class="field"><label for="reason">Причина</label><input class="input" id="reason" name="reason" maxlength="100" placeholder="гости ушли, ошибка официанта…"></div>
        <div class="form-actions"><button type="button" class="btn" data-act="modalClose">Не отменять</button><button class="btn btn-danger">Отменить заказ</button></div></form>`);
    },
    async doCancelOrder(form) {
      const btn = R.$('.btn-danger', form);
      await R.busy(btn, async () => {
        try {
          await api('POST', '/orders/' + W.orderId + '/cancel', { reason: R.formData(form).reason || null });
          R.closeModal();
          R.toast('Заказ отменён', 'ok');
          location.hash = W.order && W.order.type === 'takeaway' ? '#takeaway' : '#tables';
          await refresh();
        } catch (e) { R.fail(e); }
      });
    },
    async bill(el) {
      await R.busy(el, async () => {
        try {
          const res = await api('POST', '/orders/' + W.orderId + '/bill', { print: true });
          W.order = res.order;
          showReceipt(res.receipt, res.printed ? (res.printer_mode === 'network' ? 'Счёт печатается на принтере кухни' : 'Счёт отправлен на экран кухни для печати') : 'Печать выключена в настройках — покажите счёт гостю или напечатайте здесь');
          refresh();
        } catch (e) { R.fail(e); }
      });
    },
    async showBill() {
      try { showReceipt((await api('GET', '/orders/' + W.orderId + '/receipt')).receipt, ''); } catch (e) { R.fail(e); }
    },
    printHere() { if (W.receipt) R.printDoc(W.receipt); },
    pay() { payDialog(); },
    payOrder(el) {
      W.payAfterLoad = Number(el.dataset.id);
      location.hash = '#order/' + el.dataset.id;
    },
    payMethod(el) { W.pay.method = el.dataset.m; payDialog(true); },
    payQuick(el) { W.pay.received = el.dataset.v; payDialog(true); },
    async payConfirm(el) {
      const received = W.pay.method === 'cash' && W.pay.received !== '' ? Number(String(W.pay.received).replace(/\D/g, '')) : null;
      await R.busy(el, async () => {
        try {
          const res = await api('POST', '/orders/' + W.orderId + '/pay', { payment: W.pay.method, received });
          paid(res.order);
        } catch (e) { R.fail(e); }
      });
    },
    async payTerminal(el) {
      await R.busy(el, async () => {
        try {
          const res = await api('POST', '/orders/' + W.orderId + '/terminal');
          termAccept(res);
        } catch (e) { R.fail(e); }
      });
    },
    async termCancel(el) {
      await R.busy(el, async () => {
        try { termAccept(await api('POST', '/terminal/' + W.term.id + '/cancel')); } catch (e) { R.fail(e); }
      });
    },
    async termEmulate(el) {
      await R.busy(el, async () => {
        try { termAccept(await api('POST', '/terminal/' + W.term.id + '/emulate', { result: el.dataset.result })); } catch (e) { R.fail(e); }
      });
    },
    termAgain() { W.term = null; payDialog(true); },
    termCash() { W.term = null; W.pay.method = 'cash'; payDialog(true); },
    termDone() {
      const o = W.order;
      W.term = null;
      R.closeModal();
      if (o) paid(o, true);
    },
    account() {
      R.modal(html`<h2>${R.me.employee.name}</h2><p class="sub">${R.me.employee.role_name} · ${R.me.store.name}</p>
        <p class="sub">На общем устройстве выйдите, чтобы следующий сотрудник вошёл под своим PIN.</p>
        <div class="form-actions">${R.me.apps.filter((a) => a.app !== R.app).map((a) => html`<a class="btn" href="${a.url}">${a.name}</a>`)}
          <button class="btn btn-danger" data-act="logout">Выйти</button></div>`);
    },
  });

  function showReceipt(doc, note) {
    W.receipt = doc;
    R.modal(html`<h2>Счёт</h2>${note ? html`<p class="sub">${note}</p>` : ''}${R.receiptHtml(doc)}
      <div class="form-actions"><button class="btn" data-act="printHere">Печать с этого устройства</button><button class="btn btn-primary" data-act="modalClose">Готово</button></div>`, { focus: false });
  }

  // оплата прошла: столик свободен / навынос — ждёт выдачи или закрыт
  function paid(o, quiet) {
    W.term = null;
    R.closeModal();
    if (!quiet) R.toast(o.payment === 'cash' && o.change ? 'Оплачено. Сдача: ' + R.sum(o.change) : o.status === 'paid' ? 'Заказ оплачен и закрыт' : 'Оплачено — выдайте заказ, когда он будет готов', 'ok');
    if (o.type === 'takeaway') location.hash = o.status === 'paid' ? '#takeaway' : '#order/' + o.id;
    else location.hash = '#tables';
    refresh();
  }

  // ---------------------------------------------------------------- оплата: наличные со сдачей, карта (у кассира — через терминал)
  function payDialog(keep) {
    const o = W.order;
    if (!o) return;
    W.pay = W.pay && W.pay.orderId === o.id ? W.pay : { orderId: o.id, method: 'cash', received: '' };
    const p = W.pay;
    const terminal = W.cfg.terminal && W.state.terminal && W.state.terminal.enabled;
    const pendingTerm = (o.terminal || []).find((x) => x.status === 'pending');
    if (terminal && (W.term || pendingTerm)) {
      if (!W.term) termWatch(pendingTerm);
      termDialog(keep);
      return;
    }
    const takeaway = o.type === 'takeaway';
    const received = Number(String(p.received).replace(/\D/g, '')) || 0;
    const change = received - o.total;
    const pending = takeaway ? [] : o.items.filter((i) => i.status === 'new' || i.status === 'cooking');
    const cooking = takeaway && o.items.some((i) => i.status === 'new' || i.status === 'cooking');
    const rounds = [...new Set([o.total, Math.ceil(o.total / 10000) * 10000, Math.ceil(o.total / 50000) * 50000, Math.ceil(o.total / 100000) * 100000, Math.ceil(o.total / 200000) * 200000])];
    const cardBtn = terminal
      ? html`<button class="btn btn-lg btn-primary" data-act="payTerminal"${raw(pending.length ? ' disabled' : '')}>Отправить на терминал</button>`
      : html`<button class="btn btn-lg btn-primary" data-act="payConfirm"${raw(pending.length ? ' disabled' : '')}>${takeaway && cooking ? 'Оплачено картой' : 'Закрыть заказ'}</button>`;
    const box = R.modal(html`<h2>Оплата · ${R.place(o)}</h2><p class="sub">Заказ № ${o.number}</p>
      <div class="big-total">${R.sum(o.total)}</div>
      ${pending.length ? html`<p class="banner bad" style="margin:12px 0">Ещё готовятся: ${pending.map((i) => i.name).join(', ')}. Закрыть заказ можно, когда блюда отмечены «Готово», или отмените эти позиции.</p>` : ''}
      ${cooking ? html`<p class="sub" style="margin:8px 0 0">Оплата заранее: заказ закроется, когда его выдадут гостю.</p>` : ''}
      <div class="pay-grid"><button class="pay-btn${p.method === 'cash' ? ' on' : ''}" data-act="payMethod" data-m="cash">Наличные</button><button class="pay-btn${p.method === 'card' ? ' on' : ''}" data-act="payMethod" data-m="card">Карта</button></div>
      ${p.method === 'cash' ? html`<div class="field"><label for="received">Получено от гостя</label><input class="input" id="received" inputmode="numeric" value="${p.received}" placeholder="${R.money(o.total)}" data-pay="received">
        <div class="cash-quick">${rounds.map((v) => html`<button class="chip" data-act="payQuick" data-v="${v}">${R.money(v)}</button>`)}</div>
        <div class="change" id="change">${received ? (change >= 0 ? 'Сдача: ' + R.sum(change) : 'Не хватает: ' + R.sum(-change)) : ''}</div></div>`
        : html`<p class="sub">${terminal ? 'Сумма уйдёт на терминал кассы. Заказ закроется, когда терминал подтвердит оплату.' + (W.state.terminal.test ? ' Тестовый режим: оплата имитируется.' : '') : 'Проверьте, что оплата на терминале прошла, и закройте заказ.'}</p>`}
      <div class="form-actions"><button class="btn btn-lg" data-act="modalClose">Отмена</button>
        ${p.method === 'card' ? cardBtn : html`<button class="btn btn-lg btn-primary" data-act="payConfirm"${raw(pending.length || (received && change < 0) ? ' disabled' : '')}>${takeaway && cooking ? 'Принять оплату' : 'Закрыть заказ'}</button>`}</div>`,
    { focus: false, keep: !!keep });
    const input = R.$('[data-pay=received]', box);
    if (input) {
      input.addEventListener('input', () => {
        p.received = input.value;
        const r = Number(input.value.replace(/\D/g, '')) || 0;
        const ch = r - o.total;
        R.set(R.$('#change', box), r ? (ch >= 0 ? 'Сдача: ' + R.sum(ch) : 'Не хватает: ' + R.sum(-ch)) : '');
        R.$('[data-act=payConfirm]', box).disabled = !!pending.length || (r > 0 && ch < 0);
      });
    }
  }

  // ---------------------------------------------------------------- терминал: ожидание и результат
  function termWatch(payment) {
    clearTimeout(W.term && W.term.timer);
    W.term = { id: payment.id, payment, timer: null };
    if (payment.status === 'pending') W.term.timer = setTimeout(termPoll, 1500);
  }
  function termAccept(res) {
    W.order = res.order;
    termWatch(res.payment);
    if (res.payment.status === 'pending') W.term.timer = setTimeout(termPoll, res.poll_ms || 1500);
    if (res.payment.status === 'approved') R.beep('ready');
    termDialog(true);
    if (res.payment.status !== 'pending') refresh();
  }
  async function termPoll() {
    if (!W.term) return;
    if (!R.modalOpen()) { W.term = null; return; } // окно закрыли — оплата продолжается, вернуться можно из заказа
    try {
      termAccept(await api('GET', '/terminal/' + W.term.id));
    } catch (e) {
      if (W.term) W.term.timer = setTimeout(termPoll, 3000);
    }
  }
  function termDialog(keep) {
    const t = W.term && W.term.payment;
    const o = W.order;
    if (!t || !o) return;
    const amount = html`<div class="amount">${R.sum(t.amount)}</div>`;
    let body;
    if (t.status === 'pending') {
      body = html`<div class="term-ring" role="progressbar" aria-label="Ожидание терминала"></div><h3>Ожидаем оплату на терминале</h3>${amount}
        <p class="sub">Гость прикладывает карту и вводит PIN на терминале кассы. Заказ закроется, когда терминал подтвердит оплату.</p>
        ${t.message && !t.test ? html`<p class="sub">${t.message}</p>` : ''}
        <div class="form-actions" style="justify-content:center"><button class="btn btn-lg" data-act="termCancel">Отменить оплату</button></div>
        ${t.test ? html`<div class="term-emu"><span class="label">Тестовый режим — эмулятор терминала: имитировать</span>
          <div class="form-actions"><button class="btn btn-ok" data-act="termEmulate" data-result="approved">Гость оплатил</button><button class="btn btn-danger" data-act="termEmulate" data-result="declined">Банк отказал</button></div></div>` : ''}`;
    } else if (t.status === 'approved') {
      body = html`<div class="term-mark ok">✓</div><h3>Оплачено картой</h3>${amount}
        <p class="sub">${[t.card, t.reference ? 'RRN ' + t.reference : '', t.auth_code ? 'код ' + t.auth_code : ''].filter(Boolean).join(' · ')}${t.test ? ' · тестовый режим' : ''}</p>
        <p class="sub">${o.status === 'paid' ? 'Заказ закрыт.' : 'Заказ оплачен — выдайте его, когда всё будет готово.'}</p>
        <div class="form-actions" style="justify-content:center"><button class="btn btn-lg btn-primary" data-act="termDone">Готово</button></div>`;
    } else {
      const title = { declined: 'Оплата отклонена', cancelled: 'Оплата отменена', error: 'Ошибка терминала' }[t.status] || t.status_name;
      body = html`<div class="term-mark ${t.status === 'cancelled' ? 'muted' : 'bad'}">${t.status === 'cancelled' ? '–' : '✕'}</div><h3>${title}</h3>${amount}
        <p class="sub">${t.message || ''} Заказ не закрыт — деньги не получены.</p>
        <div class="form-actions" style="justify-content:center"><button class="btn btn-lg" data-act="termCash">Наличными</button><button class="btn btn-lg btn-primary" data-act="termAgain">Повторить картой</button></div>`;
    }
    R.modal(html`<h2>Оплата картой · ${R.place(o)}</h2><p class="sub">Заказ № ${o.number}</p><div class="term" aria-live="polite">${body}</div>`, { focus: false, keep: !!keep });
  }
})();
