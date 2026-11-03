/*
 * Официант: схема зала → столик → заказ (меню, количество, комментарии) → «Отправить на кухню»,
 * дозаказ, готовность с кухни, «Подано», счёт гостю и оплата (наличные/карта).
 * Черновик заказа хранится на устройстве до отправки; повтор отправки не создаёт дублей (client_uuid).
 */
'use strict';
(function () {
  const { html, raw } = R;
  const W = {
    state: null,
    menu: null,
    menuVersion: null,
    view: 'tables',
    tableId: null,
    order: null,
    orderId: null,
    compose: null, // { tableId, orderId, lines: [{dish_id, qty, comment}], comment, guests, client_uuid }
    cat: 'all',
    q: '',
    mine: R.local.get('waiter:mine', false),
    ready: {}, // заказ → сколько позиций было готово (сигнал о новой готовности)
    poll: 5000,
    timer: null,
  };
  const QUICK_COMMENTS = ['без лука', 'острое', 'не острое', 'без зелени', 'без соли', 'соус отдельно', 'поменьше масла', 'с собой'];

  R.start(async (me) => {
    W.poll = (me.poll_seconds || 5) * 1000;
    R.set(R.$('#who'), html`<span class="avatar sm ${me.employee.role}">${R.initials(me.employee.name)}</span>${R.firstName(me.employee.name)}`);
    R.onNetwork = (ok) => { R.$('#net').hidden = ok; R.set(R.$('#net'), 'Нет связи'); };
    R.keepAwake();
    window.addEventListener('hashchange', route);
    R.live = () => refresh();
    await loadMenu();
    await refresh();
    route();
  });

  // ---------------------------------------------------------------- данные
  async function loadMenu() {
    try {
      W.menu = await R.api('GET', '/waiter/menu');
      W.menuVersion = W.menu.menu_version;
    } catch (e) { R.fail(e); }
  }

  async function refresh() {
    clearTimeout(W.timer);
    try {
      W.state = await R.api('GET', '/waiter/state');
      R.syncClock(W.state.server_time);
      const menuChanged = W.state.menu_version !== W.menuVersion;
      if (menuChanged) await loadMenu();
      notifyReady();
      if (W.view === 'table' && W.orderId && !W.compose) {
        try { W.order = (await R.api('GET', '/waiter/orders/' + W.orderId)).order; } catch (e) { if (e.status === 404) W.order = null; }
      }
      if (!W.compose) rerender();
      // при наборе заказа перерисовываем только при смене меню (стоп-лист) и не во время ввода текста
      else if (menuChanged && !(document.activeElement && document.activeElement.closest('[data-input]'))) renderCompose();
    } catch (e) {
      if (e.status === 401) return;
    }
    W.timer = setTimeout(refresh, document.visibilityState === 'visible' ? W.poll : W.poll * 4);
  }
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && R.me) refresh(); });

  // готовность с кухни: звук, вибрация и подсветка
  function notifyReady() {
    const now = {};
    const fresh = [];
    W.state.tables.forEach((t) => {
      if (!t.order) return;
      now[t.order.id] = t.order.ready_count || 0;
      const before = W.ready[t.order.id];
      if (before !== undefined && now[t.order.id] > before) fresh.push(t);
    });
    const firstLoad = Object.keys(W.ready).length === 0 && !W.readyInit;
    W.ready = now;
    W.readyInit = true;
    if (firstLoad || !fresh.length) return;
    const mine = fresh.filter((t) => t.order.waiter.id === R.me.employee.id);
    const list = mine.length ? mine : fresh;
    R.toast('Готово: ' + list.map((t) => 'стол ' + t.name).join(', '), 'ok');
    R.unlockAudio();
    R.beep('ready');
    if (navigator.vibrate) navigator.vibrate([120, 80, 120]);
  }

  const tableById = (id) => (W.state ? W.state.tables.find((t) => t.id === id) : null);
  const dishById = (id) => (W.menu ? W.menu.dishes.find((d) => d.id === id) : null);

  // ---------------------------------------------------------------- маршруты
  function route() {
    const m = location.hash.match(/^#table\/(\d+)(\/add)?$/);
    if (m) {
      W.view = 'table';
      W.tableId = Number(m[1]);
      const t = tableById(W.tableId);
      W.orderId = t && t.order ? t.order.id : null;
      W.order = null;
      if (m[2] || !W.orderId) startCompose(W.tableId, W.orderId);
      else W.compose = null;
      if (W.orderId) refresh();
    } else {
      W.view = 'tables';
      W.tableId = null;
      W.orderId = null;
      W.order = null;
      W.compose = null;
    }
    rerender();
    window.scrollTo(0, 0);
  }

  function rerender() {
    if (!W.state) return;
    R.$('#back').hidden = W.view === 'tables';
    if (W.view === 'tables') renderTables();
    else if (W.compose) renderCompose();
    else renderOrder();
  }

  // ---------------------------------------------------------------- схема зала
  function renderTables() {
    const me = R.me.employee.id;
    const tables = W.state.tables.filter((t) => !W.mine || (t.order && t.order.waiter.id === me) || !t.order);
    const busy = W.state.tables.filter((t) => t.status !== 'free').length;
    R.set(R.$('#eyebrow'), R.me.store.name);
    R.set(R.$('#title'), 'Зал · занято ' + busy + ' из ' + W.state.tables.length);
    R.$('#bar').hidden = true;
    const STATUS = { free: 'Свободен', busy: 'Занят', bill: 'Ждёт счёт' };
    R.set(R.$('#view'), html`
      ${W.state.mode === 'online' ? html`<div class="banner">Это онлайн-сервер: заказы принимает локальный сервер ресторана.</div>` : ''}
      <div class="w-filters">
        <div class="seg"><button class="${W.mine ? '' : 'on'}" data-act="mine" data-v="0">Все столики</button><button class="${W.mine ? 'on' : ''}" data-act="mine" data-v="1">Мои и свободные</button></div>
        <div class="legend"><span><i style="background:#fff;border:2px solid #b8e2cf"></i>свободен</span><span><i style="background:#fff3db;border:2px solid #f2c76e"></i>занят</span><span><i style="background:#ede5ff;border:2px solid #b69af5"></i>ждёт счёт</span></div>
      </div>
      <div class="tables-grid">${tables.map((t) => html`<button class="t-tile t-${t.status}" data-act="openTable" data-id="${t.id}">
        <span class="n">${t.name}</span>
        <span class="st">${STATUS[t.status]}</span>
        ${t.order ? html`<span class="meta">${R.firstName(t.order.waiter.name)} · ${t.order.guests ? t.order.guests + ' г. · ' : ''}${R.ago(t.order.created_at)}</span>
          <span class="sum">${R.money(t.order.total)}</span>
          ${t.order.ready_count ? html`<span class="ready">Готово ${t.order.ready_count}</span>` : ''}` : html`<span class="meta">${t.seats} ${R.plural(t.seats, 'место', 'места', 'мест')}</span>`}
      </button>`)}</div>
      ${W.state.orphans.length ? html`<div class="card" style="margin-top:16px"><div class="card-h"><h2>Заказы без столика</h2></div>
        ${W.state.orphans.map((o) => html`<div class="list-row"><div class="grow"><div class="t">№ ${o.number} · ${o.table_name}</div><div class="s">${R.STATUS[o.status]} · ${R.money(o.total)}</div></div></div>`)}</div>` : ''}
      ${tables.length ? '' : html`<div class="card empty">Столиков нет — администратор добавляет их в админке: /admin → Столики.</div>`}`);
  }

  // ---------------------------------------------------------------- заказ столика
  function renderOrder() {
    const t = tableById(W.tableId);
    const o = W.order;
    R.set(R.$('#eyebrow'), 'Стол ' + (t ? t.name : ''));
    if (!o) {
      R.set(R.$('#title'), 'Заказ…');
      R.set(R.$('#view'), html`<div class="empty">Загрузка заказа…</div>`);
      R.$('#bar').hidden = true;
      return;
    }
    R.set(R.$('#title'), 'Заказ № ' + o.number);
    const batches = {};
    o.items.forEach((i) => { (batches[i.batch] = batches[i.batch] || []).push(i); });
    const ticket = (b) => o.tickets.find((x) => x.batch === Number(b));
    const ready = o.items.filter((i) => i.status === 'ready');
    const manager = ['owner', 'admin'].includes(R.me.employee.role);
    R.set(R.$('#view'), html`<div class="card">
      <div class="card-h"><div><h2>Стол ${o.table_name} · № ${o.number}</h2>
        <div class="sub">${o.waiter.name}${o.guests ? ' · гостей ' + o.guests : ''} · открыт в ${R.time(o.created_at)} (${R.ago(o.created_at)})</div></div>
        <span class="st-pill st-${o.status}">${R.STATUS[o.status]}</span></div>
      ${o.comment ? html`<p class="sub" style="color:var(--warn);font-weight:600">К заказу: ${o.comment}</p>` : ''}
      ${o.bill_at ? html`<p class="pill violet">Счёт выдан в ${R.time(o.bill_at)}</p>` : ''}
      ${Object.keys(batches).sort((a, b) => a - b).map((b) => html`
        <div class="batch-h">${Number(b) === 1 ? 'Заказ' : 'Дозаказ'} · ${R.time(ticket(b) && ticket(b).sent_at)}${ticket(b) && ticket(b).comment ? ' · ' + ticket(b).comment : ''}</div>
        ${batches[b].map((i) => html`<div class="o-line ${i.status}">
          <div class="grow"><div class="nm">${i.qty} × ${i.name}</div>${i.comment ? html`<div class="cm">${i.comment}</div>` : ''}<div class="pr">${R.money(i.sum)}</div></div>
          <span class="st-pill st-${i.status}">${R.STATUS[i.status] || i.status}</span>
          ${i.status === 'new' || (manager && i.status !== 'cancelled') ? html`<button class="btn btn-sm" data-act="cancelItem" data-id="${i.id}" aria-label="Отменить ${i.name}">✕</button>` : ''}
        </div>`)}`)}
      <div class="list-row" style="margin-top:8px"><div class="grow t">Итого</div><div class="big-total">${R.sum(o.total)}</div></div>
      <div class="form-actions" style="justify-content:flex-start">
        <button class="btn btn-ghost" data-act="cancelOrder">Отменить заказ</button>
        <button class="btn btn-ghost" data-act="showBill">Чек на экране</button>
      </div>
    </div>`);
    const bar = R.$('#bar');
    bar.hidden = false;
    R.set(bar, html`
      <button class="btn btn-lg btn-soft" data-act="addMore">+ Дозаказ</button>
      ${ready.length ? html`<button class="btn btn-lg btn-ok" data-act="serve">Подано (${ready.reduce((s, i) => s + i.qty, 0)})</button>` : ''}
      <span class="grow"></span>
      <button class="btn btn-lg" data-act="bill">Счёт</button>
      <button class="btn btn-lg btn-primary" data-act="pay">Оплата</button>`);
  }

  // ---------------------------------------------------------------- набор заказа (новый или дозаказ)
  const draftKey = (tableId, orderId) => 'waiter:draft:' + tableId + ':' + (orderId || 'new');

  function startCompose(tableId, orderId) {
    W.compose = R.local.get(draftKey(tableId, orderId)) || { tableId, orderId, lines: [], comment: '', guests: '', client_uuid: R.uuid() };
    W.compose.tableId = tableId;
    W.compose.orderId = orderId;
    W.cat = 'all';
    W.q = '';
  }
  const saveDraft = () => { if (W.compose) R.local.set(draftKey(W.compose.tableId, W.compose.orderId), W.compose); };
  const dropDraft = () => { if (W.compose) R.local.del(draftKey(W.compose.tableId, W.compose.orderId)); };
  const cartQty = (dishId) => W.compose.lines.filter((l) => l.dish_id === dishId).reduce((s, l) => s + l.qty, 0);
  const cartTotal = () => W.compose.lines.reduce((s, l) => s + l.qty * ((dishById(l.dish_id) || {}).price || 0), 0);

  function renderCompose() {
    const c = W.compose;
    const t = tableById(c.tableId);
    R.set(R.$('#eyebrow'), 'Стол ' + (t ? t.name : ''));
    R.set(R.$('#title'), c.orderId ? 'Дозаказ' : 'Новый заказ');
    const menu = W.menu || { categories: [], dishes: [] };
    const q = W.q.toLowerCase();
    const dishes = menu.dishes.filter((d) => (W.cat === 'all' || d.category_id === W.cat) && (!q || d.name.toLowerCase().includes(q)));
    const count = c.lines.reduce((s, l) => s + l.qty, 0);
    R.set(R.$('#view'), html`<div class="order-layout">
      <section>
        <div class="search"><input class="input" type="search" placeholder="Поиск блюда" value="${W.q}" data-input="search" aria-label="Поиск блюда" enterkeyhint="search"></div>
        <div class="menu-cats"><button class="chip${W.cat === 'all' ? ' on' : ''}" data-act="cat" data-id="all">Все</button>
          ${menu.categories.map((cat) => html`<button class="chip${W.cat === cat.id ? ' on' : ''}" data-act="cat" data-id="${cat.id}">${cat.name}</button>`)}</div>
        <div class="dish-grid">${dishes.map((d) => {
          const n = cartQty(d.id);
          return html`<button class="dish-btn${d.stop ? ' stop' : ''}" data-act="addDish" data-id="${d.id}"${raw(d.stop ? ' aria-disabled="true"' : '')} title="${d.description || ''}">
            <span class="dn">${d.name}</span><span class="dp">${R.money(d.price)}</span>${n ? html`<span class="dq">${n}</span>` : ''}</button>`;
        })}</div>
        ${dishes.length ? '' : html`<div class="empty">Ничего не найдено</div>`}
      </section>
      <aside class="order-panel card">
        <div class="card-h"><h2>${c.orderId ? 'Дозаказ к № ' + (t && t.order ? t.order.number : '') : 'Заказ · стол ' + (t ? t.name : '')}</h2>${count ? html`<button class="btn btn-sm btn-ghost" data-act="clearCart">Очистить</button>` : ''}</div>
        ${c.lines.length ? c.lines.map((l, i) => {
          const d = dishById(l.dish_id) || { name: 'Блюдо', price: 0 };
          return html`<div class="o-line"><div class="grow"><div class="nm">${d.name}</div>
              <button class="btn btn-sm btn-ghost" style="padding:0;min-height:28px" data-act="lineComment" data-i="${i}">${l.comment ? html`<span class="cm">${l.comment}</span>` : '+ комментарий'}</button>
              <div class="pr">${R.money(d.price * l.qty)}</div></div>
            <div class="qty"><button data-act="qty" data-i="${i}" data-d="-1" aria-label="Меньше">−</button><b>${l.qty}</b><button data-act="qty" data-i="${i}" data-d="1" aria-label="Больше">+</button></div></div>`;
        }) : html`<div class="empty">Нажимайте на блюда слева — они появятся здесь</div>`}
        ${c.orderId ? '' : html`<div class="field" style="margin-top:12px"><label for="guests">Гостей</label><input class="input" id="guests" type="number" min="0" max="99" inputmode="numeric" value="${c.guests}" data-input="guests"></div>`}
        <div class="field"><label for="ocomment">Комментарий ${c.orderId ? 'к дозаказу' : 'к заказу'}</label><input class="input" id="ocomment" maxlength="200" value="${c.comment}" data-input="comment" placeholder="аллергия, подать сразу…"></div>
      </aside>
    </div>`);
    const bar = R.$('#bar');
    bar.hidden = false;
    R.set(bar, html`<div class="total"><b>${R.sum(cartTotal())}</b><span>${count} ${R.plural(count, 'позиция', 'позиции', 'позиций')}</span></div>
      ${c.orderId ? html`<button class="btn btn-lg" data-act="cancelCompose">Отмена</button>` : ''}
      <button class="btn btn-lg btn-primary" data-act="send"${raw(count ? '' : ' disabled')}>Отправить на кухню</button>`);
  }

  // поля ввода без перерисовки (чтобы не терялся фокус)
  document.addEventListener('input', (ev) => {
    const el = ev.target.closest('[data-input]');
    if (!el || !W.compose) return;
    const k = el.dataset.input;
    if (k === 'search') {
      W.q = el.value;
      const pos = el.selectionStart;
      renderCompose();
      const input = R.$('[data-input=search]');
      input.focus();
      try { input.setSelectionRange(pos, pos); } catch (e) { /* */ }
      return;
    }
    W.compose[k] = el.value;
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
          res = await R.api('POST', '/waiter/orders/' + c.orderId + '/items', body);
        } else {
          body.table_id = c.tableId;
          body.guests = c.guests === '' ? null : Number(c.guests);
          res = await R.api('POST', '/waiter/orders', body);
        }
        dropDraft();
        W.compose = null;
        W.order = res.order;
        W.orderId = res.order.id;
        R.toast(res.replay ? 'Заказ уже был отправлен' : 'Отправлено на кухню', 'ok');
        if (location.hash !== '#table/' + c.tableId) history.replaceState(null, '', '#table/' + c.tableId);
        await refresh();
        rerender();
      } catch (e) {
        if (e.status === 409 && e.data && e.data.order_id && !c.orderId) {
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

  Object.assign(R.actions, {
    back() { location.hash = W.view === 'table' && W.compose && W.compose.orderId ? '#table/' + W.tableId : '#tables'; },
    mine(el) { W.mine = el.dataset.v === '1'; R.local.set('waiter:mine', W.mine); renderTables(); },
    openTable(el) { location.hash = '#table/' + el.dataset.id; },
    cat(el) { W.cat = el.dataset.id === 'all' ? 'all' : Number(el.dataset.id); renderCompose(); },
    addDish(el) {
      const d = dishById(Number(el.dataset.id));
      if (!d) return;
      if (d.stop) { R.toast('«' + d.name + '» в стоп-листе', 'bad'); return; }
      const line = W.compose.lines.find((l) => l.dish_id === d.id && !l.comment);
      if (line) line.qty = Math.min(99, line.qty + 1);
      else W.compose.lines.push({ dish_id: d.id, qty: 1, comment: '' });
      saveDraft();
      renderCompose();
    },
    qty(el) {
      const i = Number(el.dataset.i);
      const line = W.compose.lines[i];
      line.qty += Number(el.dataset.d);
      if (line.qty <= 0) W.compose.lines.splice(i, 1);
      if (line.qty > 99) line.qty = 99;
      saveDraft();
      renderCompose();
    },
    async clearCart() {
      if (await R.confirm('Очистить заказ?', 'Все выбранные блюда будут убраны.', 'Очистить', true)) {
        W.compose.lines = [];
        saveDraft();
        renderCompose();
      }
    },
    lineComment(el) {
      const i = Number(el.dataset.i);
      const line = W.compose.lines[i];
      const d = dishById(line.dish_id) || { name: '' };
      const box = R.modal(html`<h2>${d.name}</h2><p class="sub">Комментарий повару</p>
        <div class="cat-chips">${QUICK_COMMENTS.map((q) => html`<button class="chip" data-quick="${q}">${q}</button>`)}</div>
        <form data-submit="saveComment"><input type="hidden" name="i" value="${i}">
          <div class="field"><input class="input" name="comment" maxlength="120" value="${line.comment}" autofocus placeholder="без лука, острое…"></div>
          <div class="form-actions"><button type="button" class="btn" data-act="modalClose">Отмена</button><button class="btn btn-primary">Сохранить</button></div></form>`);
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
      renderCompose();
    },
    send: (el) => send(el),
    cancelCompose() { dropDraft(); W.compose = null; location.hash = '#table/' + W.tableId; route(); },
    addMore() { location.hash = '#table/' + W.tableId + '/add'; },
    async serve(el) {
      await R.busy(el, async () => {
        try {
          W.order = (await R.api('POST', '/waiter/orders/' + W.orderId + '/serve', {})).order;
          R.toast('Отмечено: подано', 'ok');
          await refresh();
        } catch (e) { R.fail(e); }
      });
    },
    async cancelItem(el) {
      const item = W.order.items.find((i) => i.id === Number(el.dataset.id));
      if (!(await R.confirm('Отменить «' + item.name + '»?', item.status === 'new' ? 'Кухня получит чек отмены.' : 'Блюдо уже готовится или подано — отмена администратором.', 'Отменить позицию', true))) return;
      try {
        W.order = (await R.api('POST', '/waiter/orders/' + W.orderId + '/items/' + item.id + '/cancel', {})).order;
        R.toast('Позиция отменена', 'ok');
        await refresh();
      } catch (e) { R.fail(e); }
    },
    async cancelOrder() {
      R.modal(html`<h2>Отменить заказ № ${W.order.number}?</h2><p class="sub">Заказ закроется без оплаты. Если кухня уже начала готовить, отменить может только администратор.</p>
        <form data-submit="doCancelOrder"><div class="field"><label for="reason">Причина</label><input class="input" id="reason" name="reason" maxlength="100" placeholder="гости ушли, ошибка официанта…"></div>
        <div class="form-actions"><button type="button" class="btn" data-act="modalClose">Не отменять</button><button class="btn btn-danger">Отменить заказ</button></div></form>`);
    },
    async doCancelOrder(form) {
      const btn = R.$('.btn-danger', form);
      await R.busy(btn, async () => {
        try {
          await R.api('POST', '/waiter/orders/' + W.orderId + '/cancel', { reason: R.formData(form).reason || null });
          R.closeModal();
          R.toast('Заказ отменён', 'ok');
          location.hash = '#tables';
          await refresh();
        } catch (e) { R.fail(e); }
      });
    },
    async bill(el) {
      await R.busy(el, async () => {
        try {
          const res = await R.api('POST', '/waiter/orders/' + W.orderId + '/bill', { print: true });
          W.order = res.order;
          showReceipt(res.receipt, res.printed ? (res.printer_mode === 'network' ? 'Счёт печатается на принтере кухни' : 'Счёт отправлен на экран кухни для печати') : 'Печать выключена в настройках — покажите счёт гостю или напечатайте здесь');
          refresh();
        } catch (e) { R.fail(e); }
      });
    },
    async showBill() {
      try { showReceipt((await R.api('GET', '/waiter/orders/' + W.orderId + '/receipt')).receipt, ''); } catch (e) { R.fail(e); }
    },
    printHere() { if (W.receipt) R.printDoc(W.receipt); },
    pay() { payDialog(); },
    payMethod(el) { W.pay.method = el.dataset.m; payDialog(); },
    payQuick(el) { W.pay.received = el.dataset.v; payDialog(); },
    async payConfirm(el) {
      const received = W.pay.method === 'cash' && W.pay.received !== '' ? Number(String(W.pay.received).replace(/\D/g, '')) : null;
      await R.busy(el, async () => {
        try {
          const res = await R.api('POST', '/waiter/orders/' + W.orderId + '/pay', { payment: W.pay.method, received });
          const o = res.order;
          R.closeModal();
          R.toast(o.payment === 'cash' && o.change ? 'Оплачено. Сдача: ' + R.sum(o.change) : 'Заказ оплачен и закрыт', 'ok');
          location.hash = '#tables';
          await refresh();
        } catch (e) { R.fail(e); }
      });
    },
    account() {
      R.modal(html`<h2>${R.me.employee.name}</h2><p class="sub">${R.me.employee.role_name} · ${R.me.store.name}</p>
        <p class="sub">На общем планшете выйдите, чтобы следующий официант вошёл под своим PIN.</p>
        <div class="form-actions">${R.me.apps.filter((a) => a.app !== 'waiter').map((a) => html`<a class="btn" href="${a.url}">${a.name}</a>`)}
          <button class="btn btn-danger" data-act="logout">Выйти</button></div>`);
    },
  });

  function showReceipt(doc, note) {
    W.receipt = doc;
    R.modal(html`<h2>Счёт</h2>${note ? html`<p class="sub">${note}</p>` : ''}${R.receiptHtml(doc)}
      <div class="form-actions"><button class="btn" data-act="printHere">Печать с этого устройства</button><button class="btn btn-primary" data-act="modalClose">Готово</button></div>`, { focus: false });
  }

  function payDialog() {
    const o = W.order;
    if (!o) return;
    W.pay = W.pay && W.pay.orderId === o.id ? W.pay : { orderId: o.id, method: 'cash', received: '' };
    const p = W.pay;
    const received = Number(String(p.received).replace(/\D/g, '')) || 0;
    const change = received - o.total;
    const pending = o.items.filter((i) => i.status === 'new' || i.status === 'cooking');
    const rounds = [...new Set([o.total, Math.ceil(o.total / 10000) * 10000, Math.ceil(o.total / 50000) * 50000, Math.ceil(o.total / 100000) * 100000, Math.ceil(o.total / 200000) * 200000])];
    const box = R.modal(html`<h2>Оплата · стол ${o.table_name}</h2><p class="sub">Заказ № ${o.number}</p>
      <div class="big-total">${R.sum(o.total)}</div>
      ${pending.length ? html`<p class="banner bad" style="margin:12px 0">Ещё готовятся: ${pending.map((i) => i.name).join(', ')}. Закрыть заказ можно, когда кухня отметит «Готово», или отмените эти позиции.</p>` : ''}
      <div class="pay-grid"><button class="pay-btn${p.method === 'cash' ? ' on' : ''}" data-act="payMethod" data-m="cash">Наличные</button><button class="pay-btn${p.method === 'card' ? ' on' : ''}" data-act="payMethod" data-m="card">Карта</button></div>
      ${p.method === 'cash' ? html`<div class="field"><label for="received">Получено от гостя</label><input class="input" id="received" inputmode="numeric" value="${p.received}" placeholder="${R.money(o.total)}" data-pay="received">
        <div class="cash-quick">${rounds.map((v) => html`<button class="chip" data-act="payQuick" data-v="${v}">${R.money(v)}</button>`)}</div>
        <div class="change" id="change">${received ? (change >= 0 ? 'Сдача: ' + R.sum(change) : 'Не хватает: ' + R.sum(-change)) : ''}</div></div>` : ''}
      <div class="form-actions"><button class="btn btn-lg" data-act="modalClose">Отмена</button>
        <button class="btn btn-lg btn-primary" data-act="payConfirm"${raw(pending.length || (p.method === 'cash' && received && change < 0) ? ' disabled' : '')}>Закрыть заказ</button></div>`, { focus: false });
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
})();
