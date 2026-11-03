/*
 * Админка ресторана: главная, меню и стоп-лист, столики, сотрудники и PIN, заказы, отчёты,
 * склад (касса), настройки — параметры ресторана, принтер кухни, режим сервера и синхронизация.
 */
'use strict';
(function () {
  const { html, raw } = R;
  const A = { section: 'home', data: {}, cat: 'all', reportRange: 'week', from: null, to: null, orderFilter: 'open', orderDate: null };
  const ICON = {
    home: '<path d="M4 11 12 4l8 7v8a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1z"/>',
    menu: '<path d="M5 4h14M5 9h14M5 14h9M5 19h9"/>',
    tables: '<rect x="3" y="7" width="18" height="5" rx="1.5"/><path d="M6 12v7M18 12v7"/>',
    staff: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.3-5.5 6.5-5.5s5.9 1.9 6.5 5.5M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20c-.4-2.7-1.8-4.4-3.8-5.1"/>',
    orders: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
    reports: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    stock: '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9z"/><path d="m12 12 8-4.5M12 12v9M12 12 4 7.5"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
  };
  const SECTIONS = [
    ['home', 'Главная'], ['menu', 'Меню'], ['tables', 'Столики'], ['staff', 'Сотрудники'],
    ['orders', 'Заказы'], ['reports', 'Отчёты'], ['stock', 'Склад'], ['settings', 'Настройки'],
  ];
  const svg = (name) => raw('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICON[name] + '</svg>');
  const PAY = { cash: 'Наличные', card: 'Карта' };

  R.start((me) => {
    A.orderDate = R.today();
    R.set(R.$('#brand-name'), me.store.name);
    R.set(R.$('#brand-mode'), me.mode_name);
    renderNav();
    renderFoot();
    window.addEventListener('hashchange', route);
    route();
  });

  function renderNav() {
    const nav = SECTIONS.map(([key, label]) => html`<a href="#${key}" class="${A.section === key ? 'on' : ''}">${svg(key)}<span>${label}</span>${key === 'home' && R.me.sync_attention ? html`<span class="count">!</span>` : ''}</a>`);
    R.set(R.$('#side-nav'), nav);
    R.set(R.$('#bottom-nav'), nav);
  }

  function renderFoot() {
    const me = R.me;
    R.set(R.$('#side-foot'), html`
      ${me.employee.role === 'owner' && me.stores.length ? html`<label class="label" for="store-switch" style="color:#9aa3c0">Ресторан</label>
        <select class="select" id="store-switch" data-change="switchStore" style="background:#1d2540;color:#fff;border-color:#2b3557">
          ${me.stores.map((s) => html`<option value="${s.id}"${raw(s.id === me.store.id ? ' selected' : '')}>${s.name}</option>`)}
          <option value="new">+ Новый ресторан…</option></select>` : ''}
      <div>${me.employee.name}<br><span style="color:#9aa3c0">${me.employee.role_name}</span></div>
      <button class="btn" data-act="openApps">Приложения</button>
      <button class="btn" data-act="logout">Выйти</button>`);
  }

  function route() {
    const key = (location.hash || '#home').slice(1);
    A.section = SECTIONS.some(([k]) => k === key) ? key : 'home';
    renderNav();
    const label = SECTIONS.find(([k]) => k === A.section)[1];
    R.set(R.$('#title'), label);
    R.set(R.$('#eyebrow'), R.me.store.name);
    R.set(R.$('#top-actions'), '');
    R.set(R.$('#view'), html`<div class="empty">Загрузка…</div>`);
    ({ home, menu, tables, staff, orders, reports, stock, settings })[A.section]();
    R.$('#view').focus({ preventScroll: true });
  }
  const reload = () => route();
  const load = async (path) => {
    try { return await R.api('GET', path); } catch (e) { R.fail(e); R.set(R.$('#view'), html`<div class="card empty">${e.message}</div>`); return null; }
  };
  const view = (content) => R.set(R.$('#view'), content);
  const actions = (content) => R.set(R.$('#top-actions'), content);

  async function submitForm(form, method, path, after) {
    const btn = R.$('button.btn-primary', form) || R.$('button:not([type=button])', form);
    return R.busy(btn, async () => {
      try {
        const res = await R.api(method, path, R.formData(form));
        R.closeModal();
        if (after) await after(res);
        return res;
      } catch (e) { R.fail(e); return null; }
    });
  }

  // ---------------------------------------------------------------- Главная
  async function home() {
    const d = await load('/admin/dashboard');
    if (!d) return;
    A.data.dashboard = d;
    const s = d.sync;
    renderBanner(s);
    actions(html`<a class="btn" href="/waiter">Официант</a><a class="btn" href="/kitchen">Кухня</a>`);
    view(html`
      <div class="stats">
        <div class="stat"><div class="k">Выручка сегодня</div><div class="v">${R.money(d.today.revenue)} <small>сум</small></div></div>
        <div class="stat"><div class="k">Оплачено заказов</div><div class="v">${d.today.orders}</div></div>
        <div class="stat"><div class="k">Средний чек</div><div class="v">${R.money(d.today.avg)} <small>сум</small></div></div>
        <div class="stat"><div class="k">Занято столиков</div><div class="v">${d.tables.busy} <small>из ${d.tables.total}</small></div></div>
        <div class="stat"><div class="k">На кухне</div><div class="v">${d.kitchen.new + d.kitchen.cooking} <small>· готово ${d.kitchen.ready}</small></div></div>
      </div>
      <div class="grid-2" style="margin-top:16px">
        <section class="card"><div class="card-h"><h2>Открытые заказы</h2><a class="btn btn-sm btn-ghost" href="#orders">Все заказы</a></div>
          ${d.open_orders.length ? html`<div class="list">${d.open_orders.map((o) => orderRow(o))}</div>` : html`<div class="empty">Открытых заказов нет</div>`}</section>
        <section>
          <div class="card"><div class="card-h"><h2>Принтер кухни</h2><a class="btn btn-sm btn-ghost" href="#settings">Настройки</a></div>
            <p>${{ off: 'Печать выключена', network: 'Сетевой принтер ESC/POS', browser: 'Печать из браузера на экране кухни' }[d.printer.mode]}</p>
            ${d.printer.pending ? html`<p class="pill warn">Ждут печати: ${d.printer.pending}</p> ` : ''}${d.printer.failed ? html`<p class="pill bad">Не напечатано за сутки: ${d.printer.failed}</p>` : ''}
            ${d.printer.last_error ? html`<p class="sub">${d.printer.last_error}</p>` : ''}</div>
          ${syncCard(s)}
        </section>
      </div>`);
  }

  function renderBanner(s) {
    const banner = R.$('#banner');
    if (s && s.mode === 'local' && s.attention) {
      banner.hidden = false;
      banner.className = 'banner' + (s.last && s.last.status === 'failed' ? ' bad' : '');
      R.set(banner, html`<span class="grow">Есть неотправленные данные: ${s.pending_total} ${R.plural(s.pending_total, 'запись', 'записи', 'записей')}.
        ${s.last ? (s.last.status === 'failed' ? 'Последняя синхронизация не удалась: ' + (s.last.error || '') : 'Последняя синхронизация: ' + R.dateTime(s.last.finished_at)) : 'Синхронизации ещё не было.'}
        Повтор — автоматически при следующем запуске.</span>
        <button class="btn btn-sm" data-act="syncNow">Синхронизировать сейчас</button>`);
    } else {
      banner.hidden = true;
    }
  }

  function syncCard(s) {
    if (s.mode === 'online') {
      return html`<div class="card"><div class="card-h"><h2>Онлайн-сервер</h2><span class="mode-badge online">online</span></div>
        <p class="sub">Последний приём данных: ${s.last_push ? R.dateTime(s.last_push.finished_at) + (s.last_push.status === 'failed' ? ' — с ошибками' : '') : 'ещё не было'}</p>
        <a class="btn btn-sm" href="#settings">Sync-токен ресторана</a></div>`;
    }
    return html`<div class="card"><div class="card-h"><h2>Синхронизация</h2><span class="mode-badge local">локальный сервер</span></div>
      ${s.configured ? html`<p class="sub">С ${s.url} · ежедневно в ${s.schedule}</p>
          <p>Последняя: ${s.last ? R.dateTime(s.last.finished_at || s.last.started_at) + ' — ' + (s.last.status === 'ok' ? 'успешно' : s.last.status === 'running' ? 'идёт' : 'ошибка') : 'ещё не было'}</p>
          <p class="sub">Ждут отправки: заказы ${s.pending.orders}, продажи ${s.pending.sales}, склад ${s.pending.stock}, справочники ${s.pending.menu}</p>
          <button class="btn btn-sm btn-primary" data-act="syncNow">Синхронизировать сейчас</button>`
        : html`<p class="sub">Не настроена: ресторан работает автономно. Чтобы отправлять отчёты на онлайн-сервер, укажите его адрес и sync-токен.</p>
          <a class="btn btn-sm" href="#settings">Настроить</a>`}</div>`;
  }

  function orderRow(o) {
    return html`<button class="list-row" style="width:100%;border-left:0;border-right:0;border-bottom:0;background:none;text-align:left" data-act="orderDetail" data-id="${o.id}">
      <div class="grow"><div class="t">№ ${o.number} · стол ${o.table_name}</div><div class="s">${o.waiter.name || '—'} · ${R.time(o.created_at)}${o.guests ? ' · гостей ' + o.guests : ''}</div></div>
      <span class="st-pill st-${o.status}">${R.STATUS[o.status]}</span><b class="nowrap">${R.money(o.total)}</b></button>`;
  }

  // ---------------------------------------------------------------- Меню
  async function menu() {
    const d = await load('/admin/menu');
    if (!d) return;
    A.data.menu = d;
    actions(html`<button class="btn" data-act="editCategory">+ Категория</button><button class="btn btn-primary" data-act="editDish"${raw(d.categories.length ? '' : ' disabled')}>+ Блюдо</button>`);
    renderMenu();
  }

  function renderMenu() {
    const d = A.data.menu;
    const unsynced = (x) => x.unsynced && R.me.mode === 'local' && R.me.sync_configured ? html` <span class="pill warn" title="Изменено здесь, ещё не отправлено на онлайн-сервер">не отправлено</span>` : '';
    const dishes = d.dishes.filter((x) => A.cat === 'all' || (A.cat === 'stop' ? x.stop : x.category_id === A.cat));
    const catName = Object.fromEntries(d.categories.map((c) => [c.id, c.name]));
    view(html`
      ${R.me.sync_configured ? html`<p class="sub" style="margin:0 0 12px">Ресторан синхронизируется с онлайн-сервером: меню удобнее править там. Если одно и то же блюдо изменили и здесь, и там, при синхронизации побеждает онлайн-версия.</p>` : ''}
      <div class="cat-chips">
        <button class="chip${A.cat === 'all' ? ' on' : ''}" data-act="menuCat" data-id="all">Все блюда · ${d.dishes.length}</button>
        ${d.categories.map((c) => html`<button class="chip${A.cat === c.id ? ' on' : ''}" data-act="menuCat" data-id="${c.id}">${c.name} · ${c.dishes}</button>`)}
        <button class="chip${A.cat === 'stop' ? ' on' : ''}" data-act="menuCat" data-id="stop">Стоп-лист · ${d.dishes.filter((x) => x.stop).length}</button>
      </div>
      <div class="grid-2">
        <section class="card"><div class="card-h"><h2>Блюда</h2></div>
          ${dishes.length ? html`<div class="list">${dishes.map((x) => html`<div class="list-row dish-row${x.active ? '' : ' off'}">
            <div class="grow"><div class="t">${x.name}${x.active ? '' : html` <span class="pill">скрыто</span>`}${unsynced(x)}</div>
              <div class="s">${catName[x.category_id] || 'Без категории'}${x.description ? ' · ' + x.description : ''}</div></div>
            <span class="price">${R.money(x.price)}</span>
            <button class="btn btn-sm toggle-stop ${x.stop ? 'btn-danger' : ''}" data-act="toggleStop" data-id="${x.id}" title="Стоп-лист">${x.stop ? 'В стопе' : 'В продаже'}</button>
            <button class="btn btn-sm" data-act="editDish" data-id="${x.id}">Изменить</button></div>`)}</div>`
            : html`<div class="empty">${d.categories.length ? 'Блюд нет — добавьте первое' : 'Сначала добавьте категорию: «Салаты», «Супы», «Напитки»…'}</div>`}
        </section>
        <section class="card"><div class="card-h"><h2>Категории</h2></div>
          ${d.categories.length ? html`<div class="list">${d.categories.map((c) => html`<div class="list-row"><div class="grow"><div class="t">${c.name}${unsynced(c)}</div><div class="s">${c.dishes} ${R.plural(c.dishes, 'блюдо', 'блюда', 'блюд')} · порядок ${c.sort}</div></div>
            <button class="btn btn-sm" data-act="editCategory" data-id="${c.id}">Изменить</button></div>`)}</div>` : html`<div class="empty">Категорий нет</div>`}
        </section>
      </div>`);
  }

  // ---------------------------------------------------------------- Столики
  async function tables() {
    const d = await load('/admin/tables');
    if (!d) return;
    A.data.tables = d.tables;
    actions(html`<button class="btn btn-primary" data-act="editTable">+ Столик</button>`);
    const STATUS = { free: ['ok', 'свободен'], busy: ['warn', 'занят'], bill: ['violet', 'ждёт счёт'] };
    view(html`<section class="card">${d.tables.length ? html`<div class="list">${d.tables.map((t) => html`<div class="list-row${t.active ? '' : ' off'}">
        <span class="avatar sm" style="background:#334155">${t.name}</span>
        <div class="grow"><div class="t">Столик ${t.name}${t.active ? '' : html` <span class="pill">не используется</span>`}</div>
          <div class="s">${t.seats} ${R.plural(t.seats, 'место', 'места', 'мест')} · порядок ${t.sort}${t.order ? ' · заказ № ' + t.order.number + ' на ' + R.money(t.order.total) : ''}</div></div>
        <span class="pill ${STATUS[t.status][0]}">${STATUS[t.status][1]}</span>
        <button class="btn btn-sm" data-act="editTable" data-id="${t.id}">Изменить</button></div>`)}</div>`
      : html`<div class="empty">Столиков нет — добавьте их, и они появятся на схеме зала у официанта</div>`}</section>`);
  }

  // ---------------------------------------------------------------- Сотрудники
  async function staff() {
    const d = await load('/admin/staff');
    if (!d) return;
    A.data.staff = d.staff;
    actions(html`<button class="btn btn-primary" data-act="editStaff">+ Сотрудник</button>`);
    const home = { owner: '/admin', admin: '/admin', waiter: '/waiter', cook: '/kitchen', seller: '/kassa/' };
    view(html`<section class="card"><div class="card-h"><h2>Команда</h2><span class="sub">Вход по PIN: роль определяет, какое приложение откроется</span></div>
      <div class="list">${d.staff.map((e) => html`<div class="list-row${e.active ? '' : ' off'}">
        <span class="avatar sm ${e.role}">${R.initials(e.name)}</span>
        <div class="grow"><div class="t">${e.name}${e.active ? '' : html` <span class="pill">отключён</span>`}${e.global ? html` <span class="pill violet">все рестораны</span>` : ''}</div>
          <div class="s">${e.role_name} · ${home[e.role] || ''}${e.phone ? ' · ' + e.phone : ''}</div></div>
        ${e.global ? '' : html`<button class="btn btn-sm" data-act="editStaff" data-id="${e.id}">Изменить</button>
          ${e.id === R.me.employee.id ? '' : html`<button class="btn btn-sm${e.active ? '' : ' btn-soft'}" data-act="toggleStaff" data-id="${e.id}">${e.active ? 'Отключить' : 'Вернуть'}</button>`}`}
      </div>`)}</div></section>`);
  }

  // ---------------------------------------------------------------- Заказы
  async function orders() {
    const q = A.orderFilter === 'open' ? '?filter=open' : '?filter=day&date=' + A.orderDate;
    const d = await load('/admin/orders' + q);
    if (!d) return;
    const paid = d.orders.filter((o) => o.status === 'paid');
    actions(html`<div class="seg"><button class="${A.orderFilter === 'open' ? 'on' : ''}" data-act="orderFilter" data-v="open">Открытые</button><button class="${A.orderFilter === 'day' ? 'on' : ''}" data-act="orderFilter" data-v="day">За день</button></div>
      ${A.orderFilter === 'day' ? html`<input class="input" type="date" value="${A.orderDate}" data-change="orderDate" style="width:auto" aria-label="День">` : ''}`);
    view(html`<section class="card">
      ${A.orderFilter === 'day' ? html`<p class="sub">Заказов: ${d.orders.length} · оплачено ${paid.length} на ${R.sum(paid.reduce((s, o) => s + o.total, 0))}</p>` : ''}
      ${d.orders.length ? html`<div class="list">${d.orders.map((o) => orderRow(o))}</div>` : html`<div class="empty">Заказов нет</div>`}</section>`);
  }

  async function orderDetail(id) {
    let o;
    try { o = (await R.api('GET', '/admin/orders/' + id)).order; } catch (e) { R.fail(e); return; }
    R.modal(html`<h2>Заказ № ${o.number} · стол ${o.table_name}</h2>
      <p class="sub">${o.waiter.name || '—'} · открыт ${R.dateTime(o.created_at)}${o.guests ? ' · гостей ' + o.guests : ''} · <span class="st-pill st-${o.status}">${R.STATUS[o.status]}</span></p>
      ${o.comment ? html`<p class="sub">Комментарий: ${o.comment}</p>` : ''}
      <div class="table-wrap"><table class="data"><thead><tr><th>Блюдо</th><th class="num">Кол-во</th><th class="num">Сумма</th><th>Статус</th></tr></thead>
        <tbody>${o.items.map((i) => html`<tr><td>${i.name}${i.comment ? html`<div class="sub">${i.comment}</div>` : ''}${i.batch > 1 ? html` <span class="pill">дозаказ</span>` : ''}</td>
          <td class="num">${i.qty}</td><td class="num">${R.money(i.sum)}</td><td><span class="st-pill st-${i.status}">${R.STATUS[i.status] || i.status}</span></td></tr>`)}</tbody></table></div>
      <p style="margin-top:12px"><b>Итого: ${R.sum(o.total)}</b>${o.payment ? ' · ' + PAY[o.payment] : ''}${o.paid_at ? ' · оплачен ' + R.dateTime(o.paid_at) : ''}${o.sale_number ? ' · чек кассы № ' + o.sale_number : ''}</p>
      ${o.cancel_reason ? html`<p class="sub">Причина отмены: ${o.cancel_reason}</p>` : ''}`, { wide: true });
  }

  // ---------------------------------------------------------------- Отчёты
  function range() {
    const today = R.today();
    if (A.reportRange === 'today') return [today, today];
    if (A.reportRange === 'week') return [R.today(-6), today];
    if (A.reportRange === 'month') return [R.today(-29), today];
    return [A.from || R.today(-6), A.to || today];
  }

  async function reports() {
    const [from, to] = range();
    const d = await load('/admin/reports?from=' + from + '&to=' + to);
    if (!d) return;
    const PRESETS = [['today', 'Сегодня'], ['week', '7 дней'], ['month', '30 дней'], ['custom', 'Период']];
    actions(html`<div class="seg">${PRESETS.map(([k, l]) => html`<button class="${A.reportRange === k ? 'on' : ''}" data-act="reportRange" data-v="${k}">${l}</button>`)}</div>
      ${A.reportRange === 'custom' ? html`<input class="input" type="date" value="${from}" data-change="reportFrom" style="width:auto" aria-label="С"><input class="input" type="date" value="${to}" data-change="reportTo" style="width:auto" aria-label="По">` : ''}`);
    const max = Math.max(1, ...d.by_day.map((x) => x.revenue));
    const table = (rows, label) => rows.length ? html`<div class="table-wrap"><table class="data"><thead><tr><th>${label}</th><th class="num">Заказов</th><th class="num">Выручка</th><th class="num">Средний чек</th></tr></thead>
      <tbody>${rows.map((r) => html`<tr><td>${r.name}</td><td class="num">${r.orders}</td><td class="num">${R.money(r.revenue)}</td><td class="num">${R.money(r.avg)}</td></tr>`)}</tbody></table></div>` : html`<div class="empty">Нет оплаченных заказов</div>`;
    const showLabels = d.by_day.length <= 31;
    view(html`
      <p class="sub" style="margin:0 0 12px">${R.date(d.from + 'T12:00:00')} — ${R.date(d.to + 'T12:00:00')} · по оплаченным заказам, в часовом поясе ресторана</p>
      <div class="stats">
        <div class="stat"><div class="k">Выручка</div><div class="v">${R.money(d.summary.revenue)} <small>сум</small></div></div>
        <div class="stat"><div class="k">Заказов</div><div class="v">${d.summary.orders}</div></div>
        <div class="stat"><div class="k">Средний чек</div><div class="v">${R.money(d.summary.avg)} <small>сум</small></div></div>
        <div class="stat"><div class="k">Гостей</div><div class="v">${d.summary.guests}</div></div>
        <div class="stat"><div class="k">Отменено заказов</div><div class="v">${d.summary.cancelled}</div></div>
      </div>
      <section class="card" style="margin-top:16px"><div class="card-h"><h2>Выручка по дням</h2></div>
        <div class="bars">${d.by_day.map((x) => html`<div class="bar" title="${R.date(x.day + 'T12:00:00')}: ${R.money(x.revenue)} сум, заказов ${x.orders}, средний чек ${R.money(x.avg)}">
          <i style="height:${Math.round((x.revenue / max) * 100)}%"></i>${showLabels ? html`<span>${x.day.slice(8)}.${x.day.slice(5, 7)}</span>` : ''}</div>`)}</div></section>
      <div class="grid-2" style="margin-top:16px">
        <section class="card"><div class="card-h"><h2>По официантам</h2></div>${table(d.by_waiter, 'Официант')}</section>
        <section class="card"><div class="card-h"><h2>По столикам</h2></div>${table(d.by_table, 'Столик')}</section>
        <section class="card"><div class="card-h"><h2>Популярные блюда</h2></div>
          ${d.top_dishes.length ? html`<div class="table-wrap"><table class="data"><thead><tr><th>Блюдо</th><th class="num">Порций</th><th class="num">Выручка</th></tr></thead>
            <tbody>${d.top_dishes.map((x) => html`<tr><td>${x.name}</td><td class="num">${x.qty}</td><td class="num">${R.money(x.revenue)}</td></tr>`)}</tbody></table></div>` : html`<div class="empty">Нет данных</div>`}</section>
        <section class="card"><div class="card-h"><h2>Способы оплаты</h2></div>
          ${d.by_payment.map((p) => html`<div class="list-row"><div class="grow"><div class="t">${PAY[p.payment]}</div><div class="s">${p.orders} ${R.plural(p.orders, 'заказ', 'заказа', 'заказов')}</div>
            <div class="meter"><i style="width:${d.summary.revenue ? Math.round((p.revenue / d.summary.revenue) * 100) : 0}%"></i></div></div><b class="nowrap">${R.money(p.revenue)}</b></div>`)}</section>
      </div>`);
  }

  // ---------------------------------------------------------------- Склад
  function stock() {
    view(html`<section class="card" style="max-width:720px"><div class="card-h"><h2>Склад продуктов</h2></div>
      <p>Склад, приход от поставщиков, инвентаризация и прогнозы остатков ведутся в приложении «Касса и склад» — с тем же PIN.</p>
      <p class="sub">Оплаченные заказы ресторана попадают в продажи кассы: их видно в отчётах и журнале кассы. Приход, инвентаризации и остатки уходят на онлайн-сервер при синхронизации.</p>
      <a class="btn btn-primary btn-lg" href="/kassa/">Открыть «Кассу и склад»</a></section>`);
  }

  // ---------------------------------------------------------------- Настройки
  async function settings() {
    const [d, s, jobs] = await Promise.all([load('/admin/settings'), load('/admin/sync'), load('/admin/print-jobs')]);
    if (!d || !s) return;
    A.data.settings = d;
    A.data.sync = s;
    const st = d.settings;
    const sy = s.status;
    view(html`<div class="grid-2">
      <section class="card"><div class="card-h"><h2>Ресторан</h2>${st.unsynced && sy.configured ? html`<span class="pill warn">не отправлено</span>` : ''}</div>
        <form data-submit="saveRestaurant">
          <div class="field"><label for="s-name">Название</label><input class="input" id="s-name" name="name" required maxlength="60" value="${d.store.name}"></div>
          <div class="field"><label for="s-address">Адрес</label><input class="input" id="s-address" name="address" maxlength="120" value="${d.store.address || ''}"></div>
          <div class="field"><label for="s-cook">Норматив приготовления, минут</label><input class="input" id="s-cook" name="cook_minutes" type="number" min="1" max="240" required value="${st.cook_minutes}">
            <span class="hint">На экране кухни таймер заказа желтеет на 70 % норматива и краснеет, когда он превышен</span></div>
          <div class="field"><label for="s-head">Шапка гостевого чека</label><input class="input" id="s-head" name="receipt_header" maxlength="120" value="${st.receipt_header || ''}" placeholder="Тел. +998 …, Wi-Fi: …"></div>
          <div class="field"><label for="s-foot">Подвал гостевого чека</label><input class="input" id="s-foot" name="receipt_footer" maxlength="120" value="${st.receipt_footer || ''}" placeholder="Спасибо, что были у нас!"></div>
          <div class="form-actions"><button class="btn btn-primary">Сохранить</button></div>
        </form></section>

      <section class="card"><div class="card-h"><h2>Принтер кухни</h2></div>
        <form data-submit="savePrinter">
          <div class="field"><label for="p-mode">Способ печати</label><select class="select" id="p-mode" name="printer_mode" data-change="printerMode">
            ${[['off', 'Не печатать'], ['network', 'Сетевой принтер ESC/POS (IP и порт)'], ['browser', 'Печать из браузера на экране кухни']].map(([v, l]) => html`<option value="${v}"${raw(st.printer_mode === v ? ' selected' : '')}>${l}</option>`)}</select></div>
          <div data-printer="network"${raw(st.printer_mode === 'network' ? '' : ' hidden')}>
            <div class="row2"><div class="field"><label for="p-host">IP-адрес принтера</label><input class="input mono" id="p-host" name="printer_host" value="${st.printer_host || ''}" placeholder="192.168.1.50" inputmode="decimal"></div>
              <div class="field"><label for="p-port">Порт</label><input class="input mono" id="p-port" name="printer_port" type="number" min="1" max="65535" value="${st.printer_port || 9100}"></div></div>
          </div>
          <div data-printer="browser"${raw(st.printer_mode === 'browser' ? '' : ' hidden')}><p class="sub">Чеки печатает экран кухни (/kitchen) через окно печати браузера. Чтобы печать шла без диалога, запустите на кухонном компьютере Chrome с флагом <code>--kiosk-printing</code>.</p></div>
          <div class="row2"><div class="field"><label for="p-cp">Кодовая страница кириллицы</label><select class="select" id="p-cp" name="printer_codepage">${d.codepages.map((c) => html`<option value="${c}"${raw(st.printer_codepage === c ? ' selected' : '')}>${c === 'cp866' ? 'CP866 (ESC t 17)' : 'Windows-1251 (ESC t 46)'}</option>`)}</select></div>
            <div class="field"><label for="p-w">Символов в строке</label><select class="select" id="p-w" name="printer_width">${d.widths.map((w) => html`<option value="${w}"${raw(st.printer_width === w ? ' selected' : '')}>${w}${w === 48 ? ' — лента 80 мм' : w === 32 ? ' — лента 58 мм' : ''}</option>`)}</select></div></div>
          <div class="form-actions"><button type="button" class="btn" data-act="testPrint"${raw(st.printer_mode === 'off' ? ' disabled' : '')}>Тестовая печать</button><button class="btn btn-primary">Сохранить</button></div>
        </form>
        ${jobs && jobs.jobs.length ? html`<h3 style="margin:16px 0 4px;font-size:15px">Последние задания печати</h3><div class="list">${jobs.jobs.slice(0, 8).map((j) => html`<div class="list-row">
          <div class="grow"><div class="t">${j.title}</div><div class="s">${R.dateTime(j.created_at)} · ${j.kind_name}${j.attempts ? ' · попыток ' + j.attempts : ''}${j.last_error && j.status !== 'done' ? ' · ' + j.last_error : ''}</div></div>
          <span class="pill ${{ done: 'ok', pending: 'warn', printing: 'info', failed: 'bad' }[j.status]}">${{ done: 'напечатан', pending: 'ждёт', printing: 'печатается', failed: 'не напечатан' }[j.status]}</span>
          ${j.status === 'failed' || j.status === 'pending' ? html`<button class="btn btn-sm" data-act="retryJob" data-id="${j.id}">Повторить</button>` : ''}</div>`)}</div>` : ''}
      </section>

      ${syncSettings(sy, s.log)}
    </div>`);
  }

  function syncSettings(sy, log) {
    const logList = log.length ? html`<h3 style="margin:16px 0 4px;font-size:15px">Журнал</h3><div class="list">${log.slice(0, 12).map((l) => html`<div class="list-row log-row">
      <div class="grow"><div class="t">${R.dateTime(l.started_at)} · ${{ sync: 'синхронизация', push: 'приём данных', pull: 'выдача меню' }[l.direction] || l.direction}${l.trigger ? ' (' + ({ schedule: 'по расписанию', retry: 'повтор', manual: 'вручную', remote: 'от локального сервера' }[l.trigger] || l.trigger) + ')' : ''}</div>
        <div class="s">${counts('Отправлено', l.sent)}${counts('Получено', l.received)}${l.error ? ' Ошибка: ' + l.error : ''}</div></div>
      <span class="pill ${l.status === 'ok' ? 'ok' : l.status === 'running' ? 'info' : 'bad'}">${l.status === 'ok' ? 'успешно' : l.status === 'running' ? 'идёт' : 'ошибка'}</span></div>`)}</div>` : '';

    if (sy.mode === 'online') {
      return html`<section class="card" style="grid-column:1/-1"><div class="card-h"><h2>Сервер и синхронизация</h2><span class="mode-badge online">DEPLOY_MODE=online</span></div>
        <p>Это <b>онлайн-сервер</b>: сводная админка и отчёты, правка меню и настроек. Локальный сервер ресторана раз в день присылает сюда завершённые заказы, продажи и движения склада и забирает меню, цены, столики, сотрудников и настройки. При конфликте правок побеждает версия этого сервера.</p>
        <div class="card-h" style="margin-top:12px"><h2>Sync-токен ресторана «${R.me.store.name}»</h2></div>
        <p class="sub">${sy.token_issued ? 'Выпущен ' + R.dateTime(sy.token_issued_at) + ' (заканчивается на ' + sy.token_hint + '). Новый токен заменит прежний.' : 'Токен ещё не выпущен.'}</p>
        <div class="form-actions" style="justify-content:flex-start"><button class="btn btn-primary" data-act="issueToken">${sy.token_issued ? 'Выпустить новый токен' : 'Выпустить токен'}</button>
          ${sy.token_issued ? html`<button class="btn" data-act="revokeToken">Отозвать</button>` : ''}</div>
        <p class="sub">Последний приём данных: ${sy.last_push ? R.dateTime(sy.last_push.finished_at) : 'ещё не было'} · последняя выдача меню: ${sy.last_pull ? R.dateTime(sy.last_pull.finished_at) : 'ещё не было'}</p>
        ${logList}</section>`;
    }
    return html`<section class="card" style="grid-column:1/-1"><div class="card-h"><h2>Сервер и синхронизация</h2><span class="mode-badge local">DEPLOY_MODE=local</span></div>
      <p>Это <b>локальный сервер ресторана</b>: официанты, кухня и печать работают в локальной сети без интернета. Раз в день (в ${sy.schedule}, ${sy.timezone}) он отправляет на онлайн-сервер завершённые заказы, продажи и движения склада и получает меню, цены, столики, сотрудников и настройки.</p>
      ${!sy.configured ? html`<p class="pill">Синхронизация не настроена — ресторан работает автономно</p>`
        : sy.pending_total ? html`<p class="pill ${sy.attention ? 'bad' : 'warn'}">Ждут отправки: заказы ${sy.pending.orders}, продажи ${sy.pending.sales}, склад ${sy.pending.stock}, справочники ${sy.pending.menu}</p>` : html`<p class="pill ok">Всё отправлено</p>`}
      <form data-submit="saveSync" style="margin-top:12px">
        <div class="row2"><div class="field"><label for="y-url">Адрес онлайн-сервера</label><input class="input mono" id="y-url" name="url" type="url" required value="${sy.url || ''}" placeholder="https://restoran.example.uz"></div>
          <div class="field"><label for="y-token">Sync-токен</label><input class="input mono" id="y-token" name="token" autocomplete="off" placeholder="${sy.token_set ? 'сохранён — введите новый, чтобы заменить' : 'rs_… из онлайн-админки'}"></div></div>
        <div class="form-actions" style="justify-content:flex-start"><button class="btn btn-primary">Сохранить</button>
          <button type="button" class="btn" data-act="syncPing"${raw(sy.configured ? '' : ' disabled')}>Проверить связь</button>
          <button type="button" class="btn" data-act="syncNow"${raw(sy.configured ? '' : ' disabled')}>Синхронизировать сейчас</button></div>
      </form>
      <p class="sub">Последняя успешная: ${sy.last_ok_at ? R.dateTime(sy.last_ok_at) : 'ещё не было'}. При неудаче данные остаются здесь и уходят при следующем запуске (повтор — каждый час).</p>
      ${logList}</section>`;
  }

  function counts(label, obj) {
    const NAMES = { orders: 'заказы', sales: 'продажи', returns: 'возвраты', incomes: 'приходы', audits: 'инвентаризации', products: 'товары', menu: 'справочники', conflicts: 'конфликты', rejected: 'отклонено', settings: 'настройки', categories: 'категории', dishes: 'блюда', tables: 'столики', staff: 'сотрудники' };
    const parts = Object.entries(obj || {}).filter(([, v]) => v).map(([k, v]) => (NAMES[k] || k) + ' ' + v);
    return parts.length ? label + ': ' + parts.join(', ') + '. ' : '';
  }

  // ---------------------------------------------------------------- формы
  function dishForm(x) {
    const cats = A.data.menu.categories;
    const cat = x ? x.category_id : (typeof A.cat === 'number' ? A.cat : cats[0] && cats[0].id);
    R.modal(html`<h2>${x ? 'Блюдо' : 'Новое блюдо'}</h2>
      <form data-submit="saveDish" data-id="${x ? x.id : ''}">
        <div class="field"><label for="d-name">Название</label><input class="input" id="d-name" name="name" required maxlength="80" value="${x ? x.name : ''}" autofocus></div>
        <div class="row2"><div class="field"><label for="d-cat">Категория</label><select class="select" id="d-cat" name="category_id">${cats.map((c) => html`<option value="${c.id}"${raw(c.id === cat ? ' selected' : '')}>${c.name}</option>`)}</select></div>
          <div class="field"><label for="d-price">Цена, сум</label><input class="input" id="d-price" name="price" type="number" min="0" required inputmode="numeric" value="${x ? x.price : ''}"></div></div>
        <div class="field"><label for="d-desc">Состав / описание</label><textarea class="textarea" id="d-desc" name="description" maxlength="300">${x ? x.description || '' : ''}</textarea></div>
        <div class="row2"><label class="check"><input type="checkbox" name="active"${raw(!x || x.active ? ' checked' : '')}> Есть в меню</label>
          <label class="check"><input type="checkbox" name="stop"${raw(x && x.stop ? ' checked' : '')}> Стоп-лист (временно нет)</label></div>
        <div class="field"><label for="d-sort">Порядок в меню</label><input class="input" id="d-sort" name="sort" type="number" min="0" value="${x ? x.sort : 0}"></div>
        <div class="form-actions">${x ? html`<button type="button" class="btn btn-danger" data-act="deleteDish" data-id="${x.id}" style="margin-right:auto">Удалить</button>` : ''}
          <button type="button" class="btn" data-act="modalClose">Отмена</button><button class="btn btn-primary">Сохранить</button></div>
      </form>`);
  }

  Object.assign(R.actions, {
    menuCat(el) { A.cat = el.dataset.id === 'all' || el.dataset.id === 'stop' ? el.dataset.id : Number(el.dataset.id); renderMenu(); },
    editDish(el) { dishForm(el.dataset.id ? A.data.menu.dishes.find((x) => x.id === Number(el.dataset.id)) : null); },
    saveDish(form) { const id = form.dataset.id; submitForm(form, id ? 'PUT' : 'POST', id ? '/admin/dishes/' + id : '/admin/dishes', () => { R.toast('Сохранено', 'ok'); return menu(); }); },
    async deleteDish(el) {
      if (!(await R.confirm('Удалить блюдо?', 'Оно исчезнет из меню; в истории заказов останется.', 'Удалить', true))) return;
      try { await R.api('DELETE', '/admin/dishes/' + el.dataset.id); R.toast('Удалено', 'ok'); menu(); } catch (e) { R.fail(e); }
    },
    async toggleStop(el) {
      const x = A.data.menu.dishes.find((d) => d.id === Number(el.dataset.id));
      await R.busy(el, async () => {
        try {
          const res = await R.api('POST', '/admin/dishes/' + x.id + '/stop', { stop: !x.stop });
          Object.assign(x, res.dish);
          R.toast(x.stop ? '«' + x.name + '» в стоп-листе' : '«' + x.name + '» снова в продаже', 'ok');
          renderMenu();
        } catch (e) { R.fail(e); }
      });
    },
    editCategory(el) {
      const c = el.dataset.id ? A.data.menu.categories.find((x) => x.id === Number(el.dataset.id)) : null;
      R.modal(html`<h2>${c ? 'Категория' : 'Новая категория'}</h2><form data-submit="saveCategory" data-id="${c ? c.id : ''}">
        <div class="field"><label for="c-name">Название</label><input class="input" id="c-name" name="name" required maxlength="40" value="${c ? c.name : ''}" autofocus></div>
        <div class="field"><label for="c-sort">Порядок</label><input class="input" id="c-sort" name="sort" type="number" min="0" value="${c ? c.sort : ''}" placeholder="в конец"></div>
        <div class="form-actions">${c ? html`<button type="button" class="btn btn-danger" data-act="deleteCategory" data-id="${c.id}" style="margin-right:auto">Удалить</button>` : ''}
          <button type="button" class="btn" data-act="modalClose">Отмена</button><button class="btn btn-primary">Сохранить</button></div></form>`);
    },
    saveCategory(form) { const id = form.dataset.id; submitForm(form, id ? 'PUT' : 'POST', id ? '/admin/categories/' + id : '/admin/categories', () => menu()); },
    async deleteCategory(el) {
      if (!(await R.confirm('Удалить категорию?', '', 'Удалить', true))) return;
      try { await R.api('DELETE', '/admin/categories/' + el.dataset.id); R.closeModal(); menu(); } catch (e) { R.fail(e); }
    },
    editTable(el) {
      const t = el.dataset.id ? A.data.tables.find((x) => x.id === Number(el.dataset.id)) : null;
      R.modal(html`<h2>${t ? 'Столик ' + t.name : 'Новый столик'}</h2><form data-submit="saveTable" data-id="${t ? t.id : ''}">
        <div class="row2"><div class="field"><label for="t-name">Номер или название</label><input class="input" id="t-name" name="name" required maxlength="20" value="${t ? t.name : ''}" autofocus placeholder="12, VIP, Терраса 3"></div>
          <div class="field"><label for="t-seats">Мест</label><input class="input" id="t-seats" name="seats" type="number" min="1" max="100" required value="${t ? t.seats : 4}"></div></div>
        <div class="row2"><label class="check"><input type="checkbox" name="active"${raw(!t || t.active ? ' checked' : '')}> Используется</label>
          <div class="field"><label for="t-sort">Порядок на схеме</label><input class="input" id="t-sort" name="sort" type="number" min="0" value="${t ? t.sort : ''}" placeholder="в конец"></div></div>
        <div class="form-actions">${t ? html`<button type="button" class="btn btn-danger" data-act="deleteTable" data-id="${t.id}" style="margin-right:auto">Удалить</button>` : ''}
          <button type="button" class="btn" data-act="modalClose">Отмена</button><button class="btn btn-primary">Сохранить</button></div></form>`);
    },
    saveTable(form) { const id = form.dataset.id; submitForm(form, id ? 'PUT' : 'POST', id ? '/admin/tables/' + id : '/admin/tables', () => tables()); },
    async deleteTable(el) {
      if (!(await R.confirm('Удалить столик?', 'История заказов столика сохранится.', 'Удалить', true))) return;
      try { await R.api('DELETE', '/admin/tables/' + el.dataset.id); R.closeModal(); tables(); } catch (e) { R.fail(e); }
    },
    editStaff(el) {
      const e = el.dataset.id ? A.data.staff.find((x) => x.id === Number(el.dataset.id)) : null;
      R.modal(html`<h2>${e ? e.name : 'Новый сотрудник'}</h2><form data-submit="saveStaff" data-id="${e ? e.id : ''}">
        <div class="field"><label for="e-name">Имя</label><input class="input" id="e-name" name="name" required maxlength="60" value="${e ? e.name : ''}" autofocus></div>
        <div class="row2"><div class="field"><label for="e-role">Роль</label><select class="select" id="e-role" name="role">
            ${[['waiter', 'Официант — /waiter'], ['cook', 'Повар — /kitchen'], ['admin', 'Администратор — /admin']].map(([v, l]) => html`<option value="${v}"${raw(e && e.role === v ? ' selected' : '')}>${l}</option>`)}</select></div>
          <div class="field"><label for="e-phone">Телефон</label><input class="input" id="e-phone" name="phone" maxlength="20" value="${e ? e.phone || '' : ''}" inputmode="tel"></div></div>
        <div class="field"><label for="e-pin">${e ? 'Новый PIN (оставьте пустым, чтобы не менять)' : 'PIN (4–6 цифр)'}</label>
          <input class="input mono" id="e-pin" name="pin" inputmode="numeric" maxlength="6" autocomplete="off"${raw(e ? '' : ' required')}>
          <span class="hint">Простые PIN не принимаются: одинаковые цифры, подряд (1234, 4321), частые (2580, 1212)</span></div>
        <div class="form-actions"><button type="button" class="btn" data-act="modalClose">Отмена</button><button class="btn btn-primary">Сохранить</button></div></form>`);
    },
    saveStaff(form) { const id = form.dataset.id; submitForm(form, id ? 'PUT' : 'POST', id ? '/admin/staff/' + id : '/admin/staff', () => { R.toast('Сохранено', 'ok'); return staff(); }); },
    async toggleStaff(el) {
      const e = A.data.staff.find((x) => x.id === Number(el.dataset.id));
      if (e.active && !(await R.confirm('Отключить ' + e.name + '?', 'Сотрудник сразу выйдет на всех устройствах.', 'Отключить', true))) return;
      try { await R.api('POST', '/admin/staff/' + e.id + '/toggle'); staff(); } catch (err) { R.fail(err); }
    },
    orderFilter(el) { A.orderFilter = el.dataset.v; orders(); },
    orderDate(el) { A.orderDate = el.value || R.today(); orders(); },
    orderDetail(el) { orderDetail(el.dataset.id); },
    reportRange(el) { A.reportRange = el.dataset.v; if (A.reportRange === 'custom' && !A.from) { [A.from, A.to] = range(); } reports(); },
    reportFrom(el) { A.from = el.value; reports(); },
    reportTo(el) { A.to = el.value; reports(); },
    saveRestaurant(form) {
      submitForm(form, 'PUT', '/admin/settings/restaurant', (res) => {
        R.me.store = res.store;
        R.set(R.$('#brand-name'), res.store.name);
        R.toast('Сохранено', 'ok');
        return settings();
      });
    },
    printerMode(el) {
      R.$$('[data-printer]').forEach((x) => { x.hidden = x.dataset.printer !== el.value; });
    },
    savePrinter(form) { submitForm(form, 'PUT', '/admin/settings/printer', () => { R.toast('Настройки принтера сохранены', 'ok'); return settings(); }); },
    async testPrint(el) {
      await R.busy(el, async () => {
        try {
          const res = await R.api('POST', '/admin/settings/printer/test');
          R.toast(res.message, 'ok');
          settings();
        } catch (e) { R.fail(e); settings(); }
      });
    },
    async retryJob(el) {
      try { await R.api('POST', '/admin/print-jobs/' + el.dataset.id + '/retry'); R.toast('Задание снова в очереди', 'ok'); settings(); } catch (e) { R.fail(e); }
    },
    saveSync(form) { submitForm(form, 'PUT', '/admin/sync', () => { R.toast('Сохранено', 'ok'); return settings(); }); },
    async syncPing(el) {
      await R.busy(el, async () => {
        try { R.toast((await R.api('POST', '/admin/sync/ping')).message, 'ok'); } catch (e) { R.fail(e); }
      });
    },
    async syncNow(el) {
      await R.busy(el, async () => {
        try {
          const res = await R.api('POST', '/admin/sync/run', undefined, { timeout: 300000 });
          R.toast('Синхронизация прошла: ' + counts('отправлено', res.run.sent) + counts('получено', res.run.received), 'ok');
        } catch (e) { R.fail(e); }
        R.me = await R.api('GET', '/me');
        reload();
      });
    },
    async issueToken(el) {
      if (A.data.sync.status.token_issued && !(await R.confirm('Выпустить новый токен?', 'Прежний перестанет работать — локальному серверу понадобится новый.', 'Выпустить'))) return;
      await R.busy(el, async () => {
        try {
          const res = await R.api('POST', '/admin/sync/token');
          R.modal(html`<h2>Sync-токен ресторана</h2><p class="sub">${res.message}</p><div class="token-box">${res.token}</div>
            <div class="form-actions"><button class="btn" data-act="copyToken" data-token="${res.token}">Скопировать</button><button class="btn btn-primary" data-act="modalClose">Готово</button></div>`, { onClose: () => settings() });
        } catch (e) { R.fail(e); }
      });
    },
    async copyToken(el) {
      try { await navigator.clipboard.writeText(el.dataset.token); R.toast('Скопировано', 'ok'); } catch (e) { R.toast('Выделите токен и скопируйте вручную'); }
    },
    async revokeToken() {
      if (!(await R.confirm('Отозвать токен?', 'Локальный сервер не сможет синхронизироваться, пока не получит новый токен.', 'Отозвать', true))) return;
      try { await R.api('DELETE', '/admin/sync/token'); settings(); } catch (e) { R.fail(e); }
    },
    openApps() {
      R.modal(html`<h2>Приложения ресторана</h2><p class="sub">Каждое ставится на устройство отдельно: откройте адрес и выберите «Установить» или «На экран Домой».</p>
        <div class="list">${[['/waiter', 'Официант', 'планшет или телефон официанта'], ['/kitchen', 'Кухня', 'монитор или планшет на кухне'], ['/admin', 'Админка', 'этот экран'], ['/kassa/', 'Касса и склад', 'склад, приход, инвентаризация, прогнозы']].map(([u, n, s]) => html`<a class="list-row" href="${u}" style="text-decoration:none;color:inherit"><div class="grow"><div class="t">${n}</div><div class="s">${location.origin}${u} · ${s}</div></div></a>`)}</div>`);
    },
    async switchStore(el) {
      if (el.value === 'new') {
        el.value = String(R.me.store.id);
        R.modal(html`<h2>Новый ресторан</h2><p class="sub">Своё меню, столики, сотрудники, заказы и склад. На онлайн-сервере для каждого ресторана выпускается свой sync-токен.</p>
          <form data-submit="createStore"><div class="field"><label for="n-name">Название</label><input class="input" id="n-name" name="name" required maxlength="60" autofocus></div>
          <div class="field"><label for="n-addr">Адрес</label><input class="input" id="n-addr" name="address" maxlength="120"></div>
          <div class="form-actions"><button type="button" class="btn" data-act="modalClose">Отмена</button><button class="btn btn-primary">Создать</button></div></form>`);
        return;
      }
      try {
        await R.api('POST', '/stores/' + el.value + '/switch', undefined, { base: '/api/kassa' });
        location.hash = '#home';
        location.reload();
      } catch (e) { R.fail(e); }
    },
    async createStore(form) {
      const btn = R.$('.btn-primary', form);
      await R.busy(btn, async () => {
        try {
          const res = await R.api('POST', '/stores', R.formData(form), { base: '/api/kassa' });
          await R.api('POST', '/stores/' + res.store.id + '/switch', undefined, { base: '/api/kassa' });
          location.hash = '#settings';
          location.reload();
        } catch (e) { R.fail(e); }
      });
    },
  });
})();
