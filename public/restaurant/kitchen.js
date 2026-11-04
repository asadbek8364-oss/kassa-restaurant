/*
 * Экран кухни (KDS) — пассивный настенный дисплей: его никто не трогает, кнопок нет.
 * Колонки «Новые → Готовятся → Готово», карточка — номер, столик или «Навынос», состав, прошедшее время и
 * заметная подсветка, когда норматив приготовления превышен. Отметки ставят официант, кассир и админка.
 * Новая карточка появляется мягко, остальные плавно сдвигаются; переход в другую колонку — плавный переезд.
 * Обновление — опрос сервера каждые 5 секунд (в локальной сети это надёжнее SSE: не держит процессы PHP
 * и само восстанавливается после обрыва Wi-Fi или перезапуска сервера). Звук нового заказа — если браузер
 * разрешил (в Chrome на кухне: --autoplay-policy=no-user-gesture-required). Печать из браузера — сама.
 * Выйти: откройте /kitchen?logout.
 */
'use strict';
(function () {
  const { html } = R;
  const K = {
    board: null,
    seen: null, // id карточек, которые уже были на доске (звук — только на новые)
    signature: '',
    timer: null,
    poll: 5000,
    printing: false,
    error: '',
  };
  const COLUMNS = [['new', 'Новые', 'Новых заказов нет'], ['cooking', 'Готовятся', 'Ничего не готовится'], ['ready', 'Готово', 'Пусто']];
  const ITEM_STATUS = { new: '', cooking: 'готовится', ready: 'готово', served: 'подано', cancelled: 'отмена' };

  R.start((me) => {
    K.poll = (me.poll_seconds || 5) * 1000;
    R.set(R.$('#brand-name'), me.store.name + ' · кухня');
    R.keepAwake();
    // звук: браузер разрешает его после касания экрана или с флагом автозапуска в Chrome
    R.unlockAudio();
    document.addEventListener('pointerdown', () => R.unlockAudio(), { passive: true });
    R.live = () => refresh();
    tick();
    setInterval(tick, 1000);
    window.addEventListener('resize', () => fit(R.$('#view')));
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
    if (K.seen && board.cards.some((c) => !K.seen.has(c.id))) R.beep('new');
    K.seen = ids;
    K.board = board;
    const signature = JSON.stringify([board.cards, board.stop_list]);
    if (signature !== K.signature) {
      K.signature = signature;
      render();
    }
    renderAlerts();
    if (board.printer_mode === 'browser' && board.print_jobs.length) printPending();
  }

  // ---------------------------------------------------------------- доска
  function render() {
    const b = K.board;
    const byCol = { new: [], cooking: [], ready: [] };
    b.cards.forEach((c) => byCol[c.column] && byCol[c.column].push(c));
    const view = R.$('#view');
    // колонки — постоянные, карточки внутри — с ключами: новые появляются, переехавшие плывут на место
    if (!R.$('.kds-col', view)) {
      R.set(view, COLUMNS.map(([key, title]) => html`<section class="kds-col ${key}" aria-label="${title}">
        <div class="kds-col-h"><span class="dot"></span><span class="tt">${title}</span><span class="n" data-count="${key}">0</span></div>
        <div class="kds-cards" data-col="${key}"></div></section>`));
    }
    // одна общая отрисовка всех колонок, чтобы карточка, сменившая колонку, плавно переехала
    const before = new Map();
    R.$$('.kcard', view).forEach((el) => before.set(el.dataset.key, el.getBoundingClientRect()));
    COLUMNS.forEach(([key, , empty]) => {
      R.set(R.$('[data-count="' + key + '"]', view), String(byCol[key].length));
      R.set(R.$('[data-col="' + key + '"]', view), byCol[key].length ? byCol[key].map(card) : html`<div class="kds-empty">${empty}</div>`);
    });
    fit(view);
    animate(before);
    const stop = b.stop_list;
    R.set(R.$('#stop-list'), stop.length ? html`Стоп-лист: <b>${stop.join(', ')}</b>` : '');
    tick();
  }

  // заказов много — карточки плотнее (два шага), чтобы колонки помещались на экране без прокрутки
  function fit(view) {
    if (!view) return;
    const over = () => R.$$('.kds-cards', view).some((c) => c.scrollHeight > c.clientHeight + 2);
    view.classList.remove('dense', 'denser');
    if (over()) {
      view.classList.add('dense');
      if (over()) view.classList.add('denser');
    }
  }

  // плавность без скачков: новые карточки проявляются, остальные доезжают до нового места
  function animate(before) {
    if (R.still() || !K.animated) { K.animated = true; return; }
    R.$$('.kcard', R.$('#view')).forEach((el) => {
      const was = before.get(el.dataset.key);
      if (!was) {
        el.classList.add('is-new');
        el.addEventListener('animationend', () => el.classList.remove('is-new'), { once: true });
        return;
      }
      const now = el.getBoundingClientRect();
      const dx = was.left - now.left;
      const dy = was.top - now.top;
      if ((Math.abs(dx) > 1 || Math.abs(dy) > 1) && el.animate) {
        el.animate([{ transform: 'translate(' + dx + 'px,' + dy + 'px)' }, { transform: 'none' }], { duration: 360, easing: 'cubic-bezier(.2,.8,.2,1)' });
      }
    });
  }

  function card(c) {
    const items = c.items.filter((i) => i.status !== 'served' || c.column === 'ready');
    return html`<article class="kcard ${c.column}" data-key="${c.id}" data-card="${c.id}">
      ${c.batch > 1 ? html`<span class="kcard-tag">Дозаказ</span>` : ''}
      <div class="kcard-h">
        <span class="kcard-no">№ ${c.number}</span>
        <span class="kcard-place${c.takeaway ? ' takeaway' : ''}">${c.takeaway ? 'Навынос' : 'Стол ' + c.table_name}</span>
        <span class="ktimer" data-timer="${c.id}">0:00</span>
      </div>
      <ul class="kitems">${items.map((i) => html`<li class="kitem ${i.status}">
        <span class="q">${i.qty}×</span><span class="nm">${i.name}${i.comment ? html`<span class="cm">${i.comment}</span>` : ''}</span>${ITEM_STATUS[i.status] ? html`<span class="ks">${ITEM_STATUS[i.status]}</span>` : ''}</li>`)}</ul>
      ${c.comment ? html`<div class="kcomment">${c.comment}</div>` : ''}
    </article>`;
  }

  // таймеры и часы — раз в секунду, без перерисовки доски; просрочка — подсветка всей карточки
  function tick() {
    R.set(R.$('#clock'), R.clock());
    if (!K.board) return;
    const norm = (K.board.cook_minutes || 15) * 60;
    K.board.cards.forEach((c) => {
      const el = R.$('[data-timer="' + c.id + '"]');
      if (!el) return;
      const box = el.closest('.kcard');
      if (c.column === 'ready' && c.ready_at) {
        const took = Math.max(0, Math.floor((new Date(c.ready_at) - new Date(c.sent_at)) / 1000));
        el.textContent = R.mmss(took);
        el.className = 'ktimer done';
        el.title = 'Приготовлено за ' + Math.round(took / 60) + ' мин';
        box.classList.remove('over');
        return;
      }
      const sec = R.elapsed(c.sent_at);
      const over = sec >= norm;
      el.textContent = R.mmss(sec);
      el.className = 'ktimer' + (over ? ' over' : sec >= norm * 0.7 ? ' warn' : '');
      el.title = 'Норматив ' + Math.round(norm / 60) + ' мин';
      box.classList.toggle('over', over);
    });
  }

  function renderAlerts() {
    const b = K.board;
    const alerts = [];
    if (K.error) alerts.push(html`<div class="kds-alert bad">${K.error}</div>`);
    if (b) {
      b.cancelled.forEach((x) => alerts.push(html`<div class="kds-alert bad">Отмена · № ${x.number} · ${x.place || (x.table_name ? 'стол ' + x.table_name : 'навынос')}: ${x.qty}× ${x.name}</div>`));
      if (b.printer_problem) alerts.push(html`<div class="kds-alert warn">Принтер: ${b.printer_problem.message}${b.printer_problem.waiting ? ' · ждут печати: ' + b.printer_problem.waiting : ''}</div>`);
    }
    const sig = JSON.stringify(alerts.map(String));
    if (sig !== K.alerts) {
      K.alerts = sig;
      R.set(R.$('#alerts'), alerts);
    }
  }

  // ---------------------------------------------------------------- печать из браузера — сама, без кнопок
  async function printPending() {
    if (K.printing) return;
    K.printing = true;
    try {
      for (const job of K.board.print_jobs) {
        try {
          const res = await R.api('POST', '/kitchen/print-jobs/' + job.id + '/claim');
          await R.printDoc(res.job.document);
        } catch (e) {
          if (e.status !== 409) { K.error = e.message; renderAlerts(); break; }
        }
      }
    } finally {
      K.printing = false;
    }
  }
})();
