/*
 * Экран кухни (KDS): доска «Новые → Готовятся → Готово», таймеры с нормативом, звук нового заказа,
 * отметки повара, повторная печать кухонного чека, печать из браузера, стоп-лист.
 * Обновление — опрос сервера каждые 5 секунд (в локальной сети это надёжнее SSE: не держит процессы PHP
 * и само восстанавливается после обрыва Wi-Fi или перезапуска сервера).
 */
'use strict';
(function () {
  const { html, raw } = R;
  const K = {
    board: null,
    seen: null, // id карточек, которые уже были на доске (звук — только на новые)
    fresh: new Set(),
    signature: '',
    timer: null,
    poll: 5000,
    sound: R.local.get('kitchen:sound', true),
    autoprint: R.local.get('kitchen:autoprint', true),
    printing: false,
    error: '',
  };
  const COLUMNS = [['new', 'Новые'], ['cooking', 'Готовятся'], ['ready', 'Готово']];
  const NEXT = { new: 'cooking', cooking: 'ready', ready: 'cooking' };
  const ITEM_STATUS = { new: 'новое', cooking: 'готовится', ready: 'готово', served: 'выдано', cancelled: 'отмена' };

  R.start((me) => {
    K.poll = (me.poll_seconds || 5) * 1000;
    R.set(R.$('#brand-name'), me.store.name + ' · кухня');
    R.set(R.$('#who'), R.firstName(me.employee.name));
    R.$('#sound-btn').setAttribute('aria-pressed', K.sound ? 'true' : 'false');
    if (K.sound && !R.audioReady()) R.$('#unlock').hidden = false;
    R.keepAwake();
    R.live = () => refresh();
    tick();
    setInterval(tick, 1000);
    refresh();
  });

  // ---------------------------------------------------------------- опрос сервера
  async function refresh() {
    clearTimeout(K.timer);
    try {
      const board = await R.api('GET', '/kitchen/board', undefined, { timeout: 10000 });
      R.syncClock(board.server_time);
      K.error = '';
      accept(board);
    } catch (e) {
      if (e.status === 401) return;
      K.error = e.status === 0 ? 'Нет связи с сервером — показаны последние данные' : e.message;
      renderAlerts();
    }
    K.timer = setTimeout(refresh, document.visibilityState === 'visible' ? K.poll : K.poll * 3);
  }
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && R.me) refresh(); });

  function accept(board) {
    const ids = new Set(board.cards.map((c) => c.id));
    if (K.seen) {
      const arrived = board.cards.filter((c) => !K.seen.has(c.id));
      if (arrived.length) {
        arrived.forEach((c) => K.fresh.add(c.id));
        if (K.sound) R.beep('new');
        setTimeout(() => { arrived.forEach((c) => K.fresh.delete(c.id)); }, 4000);
      }
    }
    K.seen = ids;
    K.board = board;
    const signature = JSON.stringify([board.cards, board.cancelled, board.stop_list, board.printer_problem, [...K.fresh]]);
    if (signature !== K.signature) {
      K.signature = signature;
      render();
    }
    renderAlerts();
    if (board.printer_mode === 'browser' && board.print_jobs.length && K.autoprint) printPending();
  }

  // ---------------------------------------------------------------- доска
  function render() {
    const b = K.board;
    const byCol = { new: [], cooking: [], ready: [] };
    b.cards.forEach((c) => byCol[c.column] && byCol[c.column].push(c));
    R.set(R.$('#view'), COLUMNS.map(([key, title]) => html`<section class="kds-col ${key}">
      <div class="kds-col-h"><span>${title}</span><b>${byCol[key].length}</b></div>
      <div class="kds-cards">${byCol[key].length ? byCol[key].map((c) => card(c)) : html`<div class="kds-empty">${key === 'new' ? 'Новых заказов нет' : key === 'cooking' ? 'Ничего не готовится' : 'Пусто'}</div>`}</div>
    </section>`));
    R.set(R.$('#stop-btn'), 'Стоп-лист' + (b.stop_list.length ? ' · ' + b.stop_list.length : ''));
    tick();
  }

  function card(c) {
    const items = c.items.filter((i) => i.status !== 'served' || c.column === 'ready');
    return html`<article class="kcard${K.fresh.has(c.id) ? ' fresh' : ''}" data-card="${c.id}">
      <div class="kcard-h">
        <div class="kcard-no">${c.batch > 1 ? html`<small>Дозаказ</small>` : ''}№ ${c.number}</div>
        <div class="kcard-table">Стол ${c.table_name}</div>
        <div class="ktimer" data-timer="${c.id}">0:00</div>
      </div>
      <div class="kcard-meta">${c.waiter || ''}${c.guests ? ' · гостей ' + c.guests : ''} · отправлен в ${R.time(c.sent_at)}</div>
      <ul class="kitems">${items.map((i) => html`<li><button class="kitem ${i.status}" data-act="itemTap" data-id="${i.id}" data-status="${i.status}"${raw(['served', 'cancelled'].includes(i.status) ? ' disabled' : '')}>
        <span class="q">${i.qty} ×</span><span class="nm">${i.name}${i.comment ? html`<span class="cm">${i.comment}</span>` : ''}</span><span class="ks">${ITEM_STATUS[i.status]}</span></button></li>`)}</ul>
      ${c.comment ? html`<div class="kcomment">${c.comment}</div>` : ''}
      <div class="kcard-a">
        ${c.column === 'new' ? html`<button class="kds-btn go" data-act="start" data-id="${c.id}">Начать</button>` : ''}
        ${c.column !== 'ready' ? html`<button class="kds-btn done" data-act="ready" data-id="${c.id}">Готово</button>` : html`<button class="kds-btn" data-act="served" data-id="${c.id}">Выдано</button>`}
        <button class="kds-btn print" data-act="reprint" data-id="${c.id}" aria-label="Печать кухонного чека заказа ${c.number}">Печать</button>
      </div>
    </article>`;
  }

  // таймеры и часы — раз в секунду, без перерисовки доски
  function tick() {
    R.set(R.$('#clock'), R.clock());
    if (!K.board) return;
    const norm = (K.board.cook_minutes || 15) * 60;
    K.board.cards.forEach((c) => {
      const el = R.$('[data-timer="' + c.id + '"]');
      if (!el) return;
      if (c.column === 'ready' && c.ready_at) {
        const took = Math.max(0, Math.floor((new Date(c.ready_at) - new Date(c.sent_at)) / 1000));
        el.textContent = R.mmss(took);
        el.className = 'ktimer done';
        el.title = 'Приготовлено за ' + Math.round(took / 60) + ' мин';
        return;
      }
      const sec = R.elapsed(c.sent_at);
      el.textContent = R.mmss(sec);
      el.className = 'ktimer' + (sec >= norm ? ' over' : sec >= norm * 0.7 ? ' warn' : '');
      el.title = 'Норматив ' + Math.round(norm / 60) + ' мин';
    });
  }

  function renderAlerts() {
    const b = K.board;
    const alerts = [];
    if (K.error) alerts.push(html`<div class="kds-alert bad">${K.error}</div>`);
    if (b) {
      b.cancelled.forEach((x) => alerts.push(html`<div class="kds-alert bad">ОТМЕНА № ${x.number} · стол ${x.table_name}: ${x.qty} × ${x.name}</div>`));
      if (b.printer_problem) alerts.push(html`<div class="kds-alert warn">Принтер: ${b.printer_problem.message}${b.printer_problem.waiting ? ' · ждут печати: ' + b.printer_problem.waiting : ''}</div>`);
      if (b.printer_mode === 'browser' && b.print_jobs.length && !K.autoprint) {
        alerts.push(html`<button class="kds-alert info" data-act="printNow">Напечатать чеки: ${b.print_jobs.length}</button>`);
      }
    }
    R.set(R.$('#alerts'), alerts);
  }

  // ---------------------------------------------------------------- печать из браузера
  async function printPending() {
    if (K.printing) return;
    K.printing = true;
    try {
      for (const job of K.board.print_jobs) {
        try {
          const res = await R.api('POST', '/kitchen/print-jobs/' + job.id + '/claim');
          await R.printDoc(res.job.document);
        } catch (e) {
          if (e.status !== 409) { R.fail(e); break; }
        }
      }
    } finally {
      K.printing = false;
    }
  }

  // ---------------------------------------------------------------- действия повара
  const act = async (el, path, done) => {
    await R.busy(el, async () => {
      try {
        await R.api('POST', path);
        if (done) R.toast(done, 'ok');
        await refresh();
      } catch (e) { R.fail(e); refresh(); }
    });
  };

  Object.assign(R.actions, {
    start: (el) => act(el, '/kitchen/tickets/' + el.dataset.id + '/start'),
    ready: (el) => act(el, '/kitchen/tickets/' + el.dataset.id + '/ready'),
    served: (el) => act(el, '/kitchen/tickets/' + el.dataset.id + '/served'),
    reprint: (el) => act(el, '/kitchen/tickets/' + el.dataset.id + '/reprint', 'Кухонный чек отправлен на печать'),
    async itemTap(el) {
      const next = NEXT[el.dataset.status];
      if (!next) return;
      await R.busy(el, async () => {
        try {
          await R.api('POST', '/kitchen/items/' + el.dataset.id + '/status', { status: next });
          await refresh();
        } catch (e) { R.fail(e); }
      });
    },
    printNow: () => printPending(),
    unlock() {
      R.unlockAudio();
      R.$('#unlock').hidden = true;
      R.beep('ready');
    },
    sound(el) {
      K.sound = !K.sound;
      R.local.set('kitchen:sound', K.sound);
      el.setAttribute('aria-pressed', K.sound ? 'true' : 'false');
      if (K.sound) { R.unlockAudio(); R.beep('ready'); }
      R.toast(K.sound ? 'Звук нового заказа включён' : 'Звук выключен');
    },
    fullscreen() {
      if (document.fullscreenElement) document.exitFullscreen();
      else if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen().catch(() => R.toast('Браузер не разрешил полноэкранный режим'));
    },
    account() {
      const mode = K.board ? K.board.printer_mode : 'off';
      R.modal(html`<h2>${R.me.employee.name}</h2><p class="sub">${R.me.employee.role_name} · ${R.me.store.name}</p>
        ${mode === 'browser' ? html`<label class="check"><input type="checkbox" data-change="autoprint"${raw(K.autoprint ? ' checked' : '')}> Печатать чеки с этого экрана автоматически</label>
          <p class="sub">Печать из браузера: без диалога печати — запустите Chrome с флагом --kiosk-printing.</p>` : ''}
        <div class="form-actions">${R.me.apps.filter((a) => a.app !== 'kitchen').map((a) => html`<a class="btn" href="${a.url}">${a.name}</a>`)}
          <button class="btn btn-danger" data-act="logout">Выйти</button></div>`);
    },
    autoprint(el) {
      K.autoprint = el.checked;
      R.local.set('kitchen:autoprint', K.autoprint);
      renderAlerts();
    },
    async stopList() {
      let dishes;
      try { dishes = (await R.api('GET', '/kitchen/menu')).dishes; } catch (e) { R.fail(e); return; }
      const draw = () => R.modal(html`<h2>Стоп-лист</h2><p class="sub">Нажмите на блюдо, которое закончилось, — официанты сразу не смогут его заказать.</p>
        <div class="stop-grid">${dishes.map((d) => html`<button class="${d.stop ? 'on' : ''}" data-act="toggleStop" data-id="${d.id}">${d.stop ? '⛔ ' : ''}${d.name}</button>`)}</div>`, { wide: true, focus: false });
      R.actions.toggleStop = async (el) => {
        const dish = dishes.find((d) => d.id === Number(el.dataset.id));
        try {
          const res = await R.api('POST', '/kitchen/dishes/' + dish.id + '/stop', { stop: !dish.stop });
          dish.stop = res.dish.stop;
          draw();
          refresh();
        } catch (e) { R.fail(e); }
      };
      draw();
    },
  });
})();
