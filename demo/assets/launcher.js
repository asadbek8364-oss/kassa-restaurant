/*
 * Лаунчер демо-версии: обзор, приложения официанта, кухни и админки во фреймах (по отдельности и рядом),
 * виртуальный принтер кухни, руководство и PDF. Приложения и лаунчер работают с одними данными
 * эмулятора (assets/demo-server.js) — изменение в одном окне сразу видно в остальных.
 */
'use strict';
(function () {
  const D = window.RESTAURANT_DEMO;
  const $ = (sel, root = document) => root.querySelector(sel);
  const esc = (v) => String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const store = {
    get(k, d) { try { const v = localStorage.getItem('restoran-demo:launcher:' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem('restoran-demo:launcher:' + k, JSON.stringify(v)); } catch (e) { /* без запоминания */ } },
  };

  const ICON = {
    overview: '<path d="M4 11 12 4l8 7v8a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1z"/>',
    waiter: '<path d="M5 15a7 7 0 0 1 14 0z"/><path d="M3 18h18M12 8V6"/>',
    kitchen: '<path d="M7 14c-2.4-.4-4-2.3-3.6-4.5.4-2 2.3-3.4 4.4-3.1.6-2.2 2.6-3.6 4.9-3.4 2.1.2 3.8 1.7 4.2 3.7 2-.2 3.9 1.1 4.2 3.1.4 2.2-1.2 4.1-3.6 4.4"/><path d="M7 14v6h10v-6M7 17h10"/>',
    admin: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    together: '<rect x="2" y="5" width="9" height="14" rx="2"/><rect x="13" y="5" width="9" height="14" rx="2"/>',
    printer: '<path d="M7 8V3h10v5"/><rect x="3" y="8" width="18" height="9" rx="2"/><path d="M7 14h10v7H7z"/>',
    guide: '<path d="M4 4h6a3 3 0 0 1 3 3v13a2 2 0 0 0-2-2H4zM20 4h-6a3 3 0 0 0-3 3v13a2 2 0 0 1 2-2h7z"/>',
  };
  const svg = (name) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICON[name] + '</svg>';

  const APPS = {
    waiter: { title: 'Официант', who: 'Азиз Рахимов', pin: D.pins.waiter, url: D.urls.waiter, icon: 'assets/icons/waiter.svg',
      sub: 'Схема зала, заказ на столик, отправка на кухню, дозаказ, счёт и оплата',
      points: ['Схема зала: свободен, занят, ждёт счёт', 'Заказ с комментариями «без лука», «острое»', 'Отправка на кухню одной кнопкой и дозаказ', 'Готовность с кухни, счёт, наличные или карта'] },
    kitchen: { title: 'Кухня', who: 'Шахзода Юсупова', pin: D.pins.cook, url: D.urls.kitchen, icon: 'assets/icons/kitchen.svg',
      sub: 'Доска «Новые → Готовятся → Готово», таймеры, звук нового заказа, стоп-лист',
      points: ['Колонки «Новые → Готовятся → Готово»', 'Таймер по нормативу (15 минут)', 'Звук нового заказа, крупный шрифт', 'Стоп-лист и повторная печать чека'] },
    admin: { title: 'Админка', who: 'Дилшод Каримов', pin: D.pins.admin, url: D.urls.admin, icon: 'assets/icons/admin.svg',
      sub: 'Меню и стоп-лист, столики, сотрудники, отчёты, принтер и синхронизация',
      points: ['Меню, цены и стоп-лист, столики', 'Сотрудники и PIN-коды', 'Отчёты: по дням, официантам, столикам', 'Принтер кухни и синхронизация'] },
  };
  const NAV = [
    ['overview', 'Обзор', ''],
    ['waiter', 'Официант', 'PIN ' + D.pins.waiter],
    ['kitchen', 'Кухня', 'PIN ' + D.pins.cook],
    ['admin', 'Админка', 'PIN ' + D.pins.admin],
    ['together', 'Официант + кухня', 'заказ в реальном времени'],
    ['printer', 'Принтер кухни', 'виртуальный'],
    ['guide', 'Руководство', 'как пользоваться'],
  ];
  const VIA = { network: 'сетевой принтер', browser: 'печать из браузера' };
  const MODE = { off: 'не печатать', network: 'сетевой принтер ESC/POS', browser: 'печать из браузера' };

  let view = 'overview';
  let device = store.get('device', 'tablet');
  let seen = new Set(D.printed(60).map((j) => j.id));

  D.ensureLogins();

  // ---------------------------------------------------------------- навигация
  function renderNav() {
    const q = D.queue();
    $('#nav').innerHTML = NAV.map(([key, label, sub]) => '<a href="#' + key + '" class="' + (view === key ? 'on' : '') + '">' + svg(key)
      + '<span>' + esc(label) + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</span>'
      + (key === 'printer' && !q.on ? '<b class="tag">выкл</b>' : '') + '</a>').join('');
  }

  function route() {
    const key = (location.hash || '#overview').slice(1);
    view = NAV.some(([k]) => k === key) ? key : 'overview';
    ['overview', 'app', 'printer', 'guide'].forEach((v) => { $('#view-' + v).hidden = true; });
    $('.ln').classList.toggle('compact', view === 'together');
    $('#stage').innerHTML = ''; // фреймы других разделов выгружаются: в каждый момент работает один экземпляр приложения
    if (view === 'overview') overview();
    else if (view === 'printer') printer();
    else if (view === 'guide') guide();
    else if (view === 'together') together();
    else app(view);
    renderNav();
    window.scrollTo(0, 0);
  }

  // ---------------------------------------------------------------- обзор
  function overview() {
    const q = D.queue();
    const el = $('#view-overview');
    el.hidden = false;
    el.innerHTML = `
      <div class="ln-hero">
        <div>
          <h1>Ресторан: официант, кухня и админка</h1>
          <p>Демо-версия работает прямо в браузере — без установки, регистрации и сервера. Демо-ресторан «Навруз»: 22 блюда, 8 столиков,
          история заказов за две недели и открытые заказы в разных статусах. Всё, что вы сделаете, сохраняется только в этом браузере.</p>
          <div class="ln-hero-actions">
            <a class="ln-btn ln-btn-accent" href="#together">Официант + кухня рядом</a>
            <a class="ln-btn" href="#guide">Руководство</a>
            <a class="ln-btn" href="restaurant-guide.pdf" download>Скачать PDF</a>
          </div>
        </div>
        <div class="ln-hero-pins">
          ${['waiter', 'kitchen', 'admin'].map((k) => `<div class="ln-pin"><img src="${APPS[k].icon}" alt=""><div><b>${esc(APPS[k].title)}</b><span>${esc(APPS[k].who)}</span></div><code>${esc(APPS[k].pin)}</code></div>`).join('')}
        </div>
      </div>

      <section class="ln-section">
        <h2>Приложения</h2>
        <p class="lead">Каждое приложение ресторана — отдельная PWA со своим адресом: /waiter, /kitchen, /admin. В лаунчере они уже открыты под демо-сотрудниками; кнопка «Выйти» внутри покажет вход по PIN.</p>
        <div class="ln-cards">
          ${['waiter', 'kitchen', 'admin'].map((k) => `<article class="ln-card ${k}">
            <div class="ln-card-h"><img src="${APPS[k].icon}" alt=""><div><h3>${esc(APPS[k].title)}</h3><small>${esc(APPS[k].who)} · PIN ${esc(APPS[k].pin)}</small></div></div>
            <ul>${APPS[k].points.map((p) => '<li>' + esc(p) + '</li>').join('')}</ul>
            <div class="ln-card-a"><a class="ln-btn ln-btn-accent" href="#${k}">Открыть здесь</a><a class="ln-btn" href="${APPS[k].url}" target="_blank" rel="noopener">В новой вкладке</a></div>
          </article>`).join('')}
        </div>
      </section>

      <section class="ln-section">
        <h2>Попробуйте за 5 минут</h2>
        <ol class="ln-steps">
          <li><b>Откройте <a href="#together">«Официант + кухня»</a></b><span>Слева — планшет официанта, справа — экран кухни, сбоку — лента принтера кухни.</span></li>
          <li><b>Официант принимает заказ</b><span>Нажмите свободный (белый) столик, выберите блюда, добавьте комментарий и нажмите «Отправить на кухню».</span></li>
          <li><b>Кухня получает заказ</b><span>Через секунду он появится в колонке «Новые» со звуковым сигналом, а кухонный чек без цен — на ленте принтера.</span></li>
          <li><b>Повар готовит</b><span>«Начать» → «Готово». У официанта на столике загорится зелёная отметка «Готово».</span></li>
          <li><b>Официант закрывает заказ</b><span>«Подано» → «Счёт» → «Оплата»: наличные со сдачей или карта. Столик снова свободен.</span></li>
          <li><b>Администратор смотрит результат</b><span>В <a href="#admin">админке</a> — выручка на главной и в отчётах, меню, стоп-лист, принтер и синхронизация.</span></li>
        </ol>
      </section>

      <section class="ln-section">
        <h2>Режимы демо</h2>
        <div class="ln-cards">
          <article class="ln-card">
            <div class="ln-switch"><div><b>Принтер кухни включён</b><span>Выключите — чеки встанут в очередь, кухня и админка покажут предупреждение. Включите — очередь напечатается по порядку.</span></div>
              <button class="ln-toggle" role="switch" aria-checked="${q.on}" aria-label="Принтер кухни" data-act="printerToggle"></button></div>
            <div class="ln-switch"><div><b>Сбой связи с онлайн-сервером</b><span>Включите — на главной админки появится пометка «есть неотправленные данные», синхронизация вернёт ошибку.</span></div>
              <button class="ln-toggle" role="switch" aria-checked="${q.offline}" aria-label="Сбой связи с онлайн-сервером" data-act="syncToggle"></button></div>
          </article>
          <article class="ln-card">
            <div class="ln-card-h"><img src="assets/icons/admin.svg" alt=""><div><h3>Склад, приход, прогнозы</h3><small>«Касса и склад» — отдельная демо-версия</small></div></div>
            <p>Ресторан ведёт склад продуктов в существующей «Кассе и складе»: приход, инвентаризация, прогнозы остатков. Оплаченные заказы попадают в продажи кассы.</p>
            <div class="ln-card-a"><a class="ln-btn" href="${esc(D.urls.kassa)}" target="_blank" rel="noopener">Открыть демо кассы</a></div>
          </article>
          <article class="ln-card">
            <div class="ln-card-h"><img src="assets/icons/waiter.svg" alt=""><div><h3>Начать заново</h3><small>Свежие демо-данные</small></div></div>
            <p>Вернуть ресторан к исходному состоянию: удаляются ваши заказы, правки меню и входы на этом устройстве.</p>
            <div class="ln-card-a"><button class="ln-btn" data-act="reset">Начать демо заново</button></div>
          </article>
        </div>
        <p class="ln-note">Демо работает без сервера: вместо него в браузере — эмулятор с той же логикой, что и рабочая версия. Рабочая версия ставится на компьютер в ресторане,
        работает в локальной сети без интернета и раз в день синхронизируется с онлайн-сервером. Подробно — в руководстве.</p>
      </section>`;
  }

  // ---------------------------------------------------------------- приложения во фреймах
  function frame(url, cls, title) {
    const f = document.createElement('iframe');
    f.className = 'ln-frame ' + (cls || '');
    f.src = url;
    f.title = title;
    f.allow = 'autoplay; fullscreen; clipboard-write';
    f.setAttribute('allowfullscreen', '');
    return f;
  }

  function app(key) {
    const a = APPS[key];
    $('#view-app').hidden = false;
    $('#app-title').textContent = a.title;
    $('#app-sub').textContent = a.sub + ' · вход: ' + a.who + ', PIN ' + a.pin;
    const tools = [];
    if (key === 'waiter') tools.push('<div class="ln-seg" role="group" aria-label="Устройство"><button data-act="device" data-v="tablet" class="' + (device === 'tablet' ? 'on' : '') + '">Планшет</button><button data-act="device" data-v="phone" class="' + (device === 'phone' ? 'on' : '') + '">Телефон</button></div>');
    tools.push('<button class="ln-btn ln-btn-sm" data-act="reload">Обновить</button>');
    tools.push('<a class="ln-btn ln-btn-sm" href="' + a.url + '" target="_blank" rel="noopener">В новой вкладке</a>');
    $('#app-tools').innerHTML = tools.join('');
    const stage = $('#stage');
    stage.className = 'ln-stage';
    stage.appendChild(frame(a.url, key === 'waiter' ? device : key === 'kitchen' ? 'dark' : '', a.title));
  }

  function together() {
    $('#view-app').hidden = false;
    $('#app-title').textContent = 'Официант + кухня';
    $('#app-sub').textContent = 'Официант на телефоне слева, экран кухни справа: отправьте заказ — он сразу появится на кухне и на ленте принтера. Нажмите на экран кухни, чтобы включить звук.';
    $('#app-tools').innerHTML = '<button class="ln-btn ln-btn-sm" data-act="reload">Обновить</button><a class="ln-btn ln-btn-sm" href="#printer">Все чеки принтера</a>';
    const stage = $('#stage');
    stage.className = 'ln-stage ln-together';
    stage.appendChild(frame(APPS.waiter.url, 'phone', 'Официант'));
    stage.appendChild(frame(APPS.kitchen.url, 'dark', 'Кухня'));
    const panel = document.createElement('div');
    panel.className = 'ln-tape-panel';
    panel.innerHTML = '<h3>Принтер кухни</h3><div class="ln-tape" id="side-tape"></div>';
    stage.appendChild(panel);
    renderTape($('#side-tape'), 8);
  }

  // ---------------------------------------------------------------- виртуальный принтер
  function receiptHtml(doc) {
    const width = (doc && doc.width) || 48;
    return '<div class="receipt">' + ((doc && doc.lines) || []).map((l) => {
      if (l.t === 'rule') return '<div class="rl rule">' + esc((l.char || '-').repeat(width)) + '</div>';
      if (l.t === 'feed') return '<div style="height:1em"></div>';
      const cls = ['rl', l.size === 'l' ? 'l' : l.size === 't' ? 't' : '', l.bold ? 'b' : '', l.invert ? 'inv' : '', l.align === 'center' ? 'c' : l.align === 'right' ? 'r' : ''].filter(Boolean).join(' ');
      if (l.t === 'row') return '<div class="' + cls + ' rrow"><span>' + esc(l.left) + '</span><span>' + esc(l.right) + '</span></div>';
      return '<div class="' + cls + '">' + esc(l.text) + '</div>';
    }).join('') + '</div>';
  }
  const timeOf = (iso) => (iso ? String(iso).slice(11, 16) : '');

  function renderTape(el, limit) {
    if (!el) return;
    const jobs = D.printed(limit);
    el.innerHTML = jobs.length ? jobs.map((j) => '<div class="ln-paper' + (seen.has(j.id) ? '' : ' fresh') + '"><div class="ln-paper-h"><span>' + esc(j.kind_name) + ' · ' + esc(timeOf(j.printed_at))
      + '</span><span>' + esc(VIA[j.via] || '') + '</span></div>' + receiptHtml(j.document) + '</div>').join('')
      : '<div class="ln-empty">Чеков пока нет — отправьте заказ на кухню</div>';
    jobs.forEach((j) => seen.add(j.id));
  }

  function printer() {
    const q = D.queue();
    const el = $('#view-printer');
    el.hidden = false;
    el.innerHTML = `
      <div class="ln-printer-head">
        <div><h1>Принтер кухни (виртуальный)</h1>
          <p>Так выглядят чеки на ленте 80 мм: кухонный чек при отправке заказа — крупно и без цен, дозаказ — только новые позиции, отмена, счёт гостю с ценами и итогом.
          В рабочей версии это сетевой ESC/POS-принтер (IP и порт 9100) или печать из браузера на экране кухни.</p></div>
        <div class="ln-printer-state"><button class="ln-toggle" role="switch" aria-checked="${q.on}" aria-label="Принтер включён" data-act="printerToggle"></button>
          <div><b>${q.on ? 'Принтер включён' : 'Принтер выключен'}</b><br><span style="color:var(--muted);font-size:14px">${q.pending ? 'ждут печати: ' + q.pending : 'очередь пуста'}${q.failed ? ' · не напечатано: ' + q.failed : ''}</span></div></div>
      </div>
      ${q.mode !== 'network' ? `<p class="ln-note" style="padding:0 28px">В админке выбран способ печати «${esc(MODE[q.mode])}». Виртуальный принтер печатает в режиме «Сетевой принтер ESC/POS»${q.mode === 'browser' ? '; сейчас чеки печатает экран кухни через окно печати браузера' : ''}.</p>` : ''}
      <div class="ln-tape" id="tape"></div>`;
    renderTape($('#tape'), 40);
  }

  function guide() {
    $('#view-guide').hidden = false;
    const f = $('#guide-frame');
    if (!f.getAttribute('src')) f.src = 'guide.html';
  }

  // ---------------------------------------------------------------- действия
  let toastTimer = null;
  function toast(message) {
    const t = $('#toast');
    t.textContent = message;
    t.className = 'ln-toast show';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.className = 'ln-toast'; }, 3000);
  }

  const actions = {
    reset() {
      if (!window.confirm('Начать демо заново? Ваши заказы, правки меню и входы на этом устройстве будут удалены.')) return;
      D.reset();
      D.ensureLogins();
      seen = new Set(D.printed(60).map((j) => j.id));
      toast('Демо-данные обновлены');
      route();
    },
    printerToggle() {
      const on = !D.queue().on;
      D.setPrinter(on);
      toast(on ? 'Принтер включён — очередь печатается по порядку' : 'Принтер выключен — новые чеки встанут в очередь');
      route();
    },
    syncToggle() {
      const on = !D.queue().offline;
      D.setSyncOffline(on);
      toast(on ? 'Связи с онлайн-сервером нет — смотрите главную админки' : 'Связь восстановлена — нажмите «Синхронизировать сейчас» в админке');
      route();
    },
    device(el) {
      device = el.dataset.v;
      store.set('device', device);
      route();
    },
    reload() { document.querySelectorAll('#stage iframe').forEach((f) => { f.src = f.src; }); },
  };
  document.addEventListener('click', (ev) => {
    const el = ev.target.closest('[data-act]');
    if (!el || !actions[el.dataset.act]) return;
    ev.preventDefault();
    actions[el.dataset.act](el);
  });

  // изменения из приложений (другие фреймы и вкладки): новая лента принтера, состояние в меню
  window.addEventListener('storage', (ev) => {
    if (ev.key !== D.key) return;
    renderNav();
    if (view === 'printer') printer();
    if (view === 'together') renderTape($('#side-tape'), 8);
  });
  window.addEventListener('hashchange', route);
  route();
})();
