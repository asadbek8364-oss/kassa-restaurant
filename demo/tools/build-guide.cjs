#!/usr/bin/env node
/*
 * Скриншоты для руководства и PDF-версия руководства — по живой демо-версии.
 *
 *   node demo/tools/build-guide.cjs            # скриншоты в demo/guide/ и demo/restaurant-guide.pdf
 *   node demo/tools/build-guide.cjs --pdf-only # только PDF
 *
 * Нужен Playwright с Chromium: npm i -D playwright && npx playwright install chromium
 * (или глобально установленный playwright). Скрипт сам поднимает веб-сервер для каталога demo/.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const http = require('http');
const { chromium } = require('playwright');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, 'guide');
const PDF = path.join(ROOT, 'restaurant-guide.pdf');
const pdfOnly = process.argv.includes('--pdf-only');
const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.pdf': 'application/pdf' };

function serve() {
  const server = http.createServer((req, res) => {
    const url = decodeURIComponent(new URL(req.url, 'http://x').pathname);
    const file = path.join(ROOT, url === '/' ? 'index.html' : url);
    if (!file.startsWith(ROOT) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) { res.writeHead(404); res.end('not found'); return; }
    res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
    fs.createReadStream(file).pipe(res);
  });
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server)));
}

const shot = (page, name, opts = {}) => page.screenshot(Object.assign({ path: path.join(OUT, name + '.jpg'), type: 'jpeg', quality: 82 }, opts));

async function pin(page, who, code) {
  await page.locator('.staff-tile', { hasText: who }).click();
  for (const d of code) await page.locator(`.pin-pad button[data-key="${d}"]`).click();
}

async function screenshots(base, browser) {
  fs.mkdirSync(OUT, { recursive: true });
  const errors = [];
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1.5, locale: 'ru-RU', reducedMotion: 'reduce' });
  const watch = (p, n) => { p.on('pageerror', (e) => errors.push(n + ': ' + e.message)); };
  const settle = (p, ms = 350) => p.waitForTimeout(ms);
  const table = (p, name) => p.locator('.t-tile', { has: p.locator('.n', { hasText: new RegExp('^' + name + '$') }) });

  // ---- лаунчер (свежие демо-данные, входы под демо-сотрудниками)
  const launcher = await ctx.newPage(); watch(launcher, 'launcher');
  await launcher.goto(base + '/index.html');
  await launcher.evaluate(() => localStorage.clear());
  await launcher.goto(base + '/index.html');
  await launcher.waitForSelector('.ln-hero');
  await shot(launcher, 'launcher');

  // ---- экран входа (отдельный профиль браузера — без входа)
  const fresh = await browser.newContext({ viewport: { width: 1180, height: 820 }, deviceScaleFactor: 1.5, locale: 'ru-RU', reducedMotion: 'reduce' });
  const login = await fresh.newPage();
  await login.goto(base + '/waiter.html');
  await login.locator('.staff-tile', { hasText: 'Азиз' }).click();
  for (const d of '22') await login.locator(`.pin-pad button[data-key="${d}"]`).click();
  await shot(login, 'login');
  await fresh.close();

  // ---- официант на планшете: новый заказ на столик 1 с комментарием
  const w = await ctx.newPage(); watch(w, 'waiter');
  await w.setViewportSize({ width: 1180, height: 820 });
  await w.goto(base + '/waiter.html');
  await w.waitForSelector('.t-tile');
  await table(w, '1').click();
  await w.waitForSelector('.dish-btn');
  for (const [dish, times] of [['Плов чайханский', 2], ['Ачичук', 1], ['Самса с мясом', 2], ['Чай зелёный', 1]]) {
    for (let i = 0; i < times; i++) await w.locator('.dish-btn', { hasText: dish }).click();
  }
  await w.locator('#cart [data-act=lineComment]').first().click();
  await w.locator('[data-quick="без лука"]').click();
  await settle(w);
  await shot(w, 'waiter-comment');
  await w.locator('form[data-submit=saveComment] button.btn-primary').click();
  await w.fill('#panel-guests', '3');
  await w.fill('#panel-comment', 'подать салат сразу');
  await w.locator('#panel-guests').blur();
  await settle(w);
  await shot(w, 'waiter-new-order');
  await w.locator('#bar [data-act=send]').click();
  await w.waitForSelector('[data-act=batchStep]');

  // дозаказ к столику 3 и отмена одной позиции на столике 2 — для экрана кухни и ленты принтера
  await w.locator('[data-act=back]').click();
  await table(w, '3').click();
  await w.locator('[data-act=addMore]').click();
  await w.locator('.dish-btn', { hasText: 'Чай чёрный с лимоном' }).click();
  await w.locator('.dish-btn', { hasText: 'Самса с тыквой' }).click();
  await w.locator('#bar [data-act=send]').click();
  await w.waitForSelector('text=Дозаказ ·');
  await w.locator('[data-act=back]').click();
  await table(w, '2').click();
  await w.locator('.o-line', { hasText: 'Чай зелёный' }).locator('[data-act=cancelItem]').click();
  await w.locator('.modal [data-confirm="1"]').click();
  await w.waitForSelector('.o-line.cancelled');

  // таймеры: один заказ подходит к нормативу, другой — просрочен
  await w.evaluate(() => {
    const key = window.RESTAURANT_DEMO.key;
    const s = JSON.parse(localStorage.getItem(key));
    const age = { 2: 17, 3: 11 }; // столик → минут с отправки первой отправки
    s.tickets.forEach((t) => {
      const o = s.orders.find((x) => x.id === t.order_id);
      const tb = s.tables.find((x) => x.id === o.table_id);
      if (tb && age[tb.name] && t.batch === 1 && ['new', 'cooking', 'ready', 'served'].includes(o.status)) {
        const sent = Date.now() - age[tb.name] * 60000;
        t.sent = sent;
        o.created = sent;
        s.items.filter((i) => i.ticket_id === t.id).forEach((i) => { i.sent = sent; if (i.cooking) i.cooking = sent + 60000; });
      }
    });
    localStorage.setItem(key, JSON.stringify(s));
  });

  // ---- кухня: настенный экран без кнопок
  const k = await ctx.newPage(); watch(k, 'kitchen');
  await k.setViewportSize({ width: 1600, height: 900 });
  await k.goto(base + '/kitchen.html');
  await k.waitForSelector('.kcard');
  await k.waitForTimeout(1200);
  await shot(k, 'kitchen-board');

  // ---- официант отмечает «Готовится» и «Готово» (кухня только показывает) → подано → счёт → оплата
  await w.locator('[data-act=back]').click();
  await table(w, '1').click();
  await w.locator('[data-act=batchStep]').click();
  await settle(w, 500);
  await w.locator('[data-act=batchStep]').click();
  await settle(w, 500);
  await w.locator('[data-act=back]').click();
  await w.waitForTimeout(800);
  await shot(w, 'waiter-tables');
  await table(w, '1').click();
  await w.waitForSelector('#bar [data-act=serve]');
  await settle(w);
  await shot(w, 'waiter-order');
  await w.locator('#bar [data-act=serve]').click();
  await settle(w);
  await w.locator('#bar [data-act=bill]').click();
  await w.waitForSelector('.modal .receipt');
  await settle(w);
  await shot(w, 'waiter-bill');
  await w.locator('.modal .btn-primary[data-act=modalClose]').click();
  await settle(w);
  await w.locator('#bar [data-act=pay]').click();
  await w.waitForSelector('.pay-grid');
  await w.locator('.cash-quick .chip').nth(2).click();
  await settle(w);
  await shot(w, 'waiter-pay');
  await w.locator('[data-act=payConfirm]').click();
  await w.waitForSelector('.t-tile');

  // ---- официант на телефоне 402 px: зал, набор заказа, заказ
  const phone = await ctx.newPage(); watch(phone, 'phone');
  await phone.setViewportSize({ width: 402, height: 874 });
  await phone.goto(base + '/waiter.html');
  await phone.waitForSelector('.t-tile');
  await settle(phone);
  await shot(phone, 'waiter-phone');
  await phone.locator('.t-tile.t-free').first().click();
  await phone.waitForSelector('.dish-btn');
  for (const dish of ['Лагман', 'Лагман', 'Самса с мясом', 'Компот домашний']) await phone.locator('.dish-btn', { hasText: dish }).click();
  await settle(phone);
  await shot(phone, 'waiter-phone-menu');
  await phone.locator('.cart-sum').click();
  await phone.locator('#sheet-send').click();
  await phone.waitForSelector('[data-act=batchStep]');
  await phone.locator('[data-act=batchStep]').click();
  await settle(phone, 600);
  await shot(phone, 'waiter-phone-order');
  await phone.close();

  // ---- кассир: очередь навынос, оплата картой через терминал (тестовый режим)
  const c = await ctx.newPage(); watch(c, 'cashier');
  await c.setViewportSize({ width: 1180, height: 820 });
  await c.goto(base + '/cashier.html#takeaway');
  await c.waitForSelector('.tw-card');
  await settle(c);
  await shot(c, 'cashier-queue');
  await c.locator('[data-act=newTakeaway]').click();
  await c.waitForSelector('.dish-btn');
  for (const dish of ['Плов чайханский', 'Самса с мясом', 'Самса с мясом', 'Айран']) await c.locator('.dish-btn', { hasText: dish }).click();
  await c.fill('#panel-comment', 'Тимур, заберёт сам');
  await c.locator('#bar [data-act=send]').click();
  await c.waitForSelector('.pay-grid');
  await c.locator('[data-act=payMethod][data-m=card]').click();
  await c.locator('[data-act=payTerminal]').click();
  await c.waitForSelector('.term-ring');
  await settle(c, 600);
  await shot(c, 'cashier-terminal');
  await c.locator('[data-act=termEmulate][data-result=approved]').click();
  await c.waitForSelector('.term-mark.ok');
  await c.locator('[data-act=termDone]').click();
  await settle(c);

  // ---- принтер и «официант + кухня»
  await launcher.goto(base + '/index.html#printer');
  await launcher.waitForSelector('#tape .ln-paper');
  await launcher.waitForTimeout(700);
  await shot(launcher, 'printer');
  const wide = await ctx.newPage();
  await wide.setViewportSize({ width: 1920, height: 1080 });
  await wide.goto(base + '/index.html#together');
  await wide.frameLocator('iframe[title="Кухня"]').locator('.kcard').first().waitFor();
  await wide.waitForTimeout(1200);
  await shot(wide, 'together', { scale: 'css' });
  await wide.close();

  // ---- админка
  const a = await ctx.newPage(); watch(a, 'admin');
  await a.goto(base + '/admin.html');
  await a.waitForSelector('.stat');
  await settle(a);
  await shot(a, 'admin-home');
  await a.goto(base + '/admin.html#menu');
  await a.waitForSelector('.dish-row');
  await settle(a);
  await shot(a, 'admin-menu');
  await a.locator('.dish-row', { hasText: 'Плов чайханский' }).locator('[data-act=editDish]').click();
  await a.waitForSelector('form[data-submit=saveDish]');
  await settle(a);
  await shot(a, 'admin-dish');
  await a.locator('.modal-x').click();
  await a.goto(base + '/admin.html#tables');
  await a.waitForSelector('.list-row');
  await settle(a);
  await shot(a, 'admin-tables');
  await a.goto(base + '/admin.html#staff');
  await a.waitForSelector('.list-row');
  await settle(a);
  await shot(a, 'admin-staff');
  await a.setViewportSize({ width: 1440, height: 1280 });
  await a.goto(base + '/admin.html#reports');
  await a.waitForSelector('.bars');
  await a.waitForTimeout(400);
  await shot(a, 'admin-reports');
  await a.goto(base + '/admin.html#settings');
  await a.waitForSelector('form[data-submit=saveSync]');
  await a.waitForTimeout(400);
  await shot(a, 'admin-settings', { clip: { x: 0, y: 0, width: 1440, height: 1100 } });
  await a.locator('form[data-submit=saveSync]').locator('xpath=ancestor::section[1]').screenshot({ path: path.join(OUT, 'admin-sync.jpg'), type: 'jpeg', quality: 82 });
  await a.setViewportSize({ width: 1440, height: 900 });
  // сбой связи с онлайн-сервером → пометка на главной
  await a.evaluate(() => window.RESTAURANT_DEMO.setSyncOffline(true));
  await a.goto(base + '/admin.html#home');
  await a.waitForSelector('#banner:not([hidden])');
  await settle(a);
  await shot(a, 'admin-banner', { clip: { x: 248, y: 0, width: 1192, height: 330 } });
  await a.evaluate(() => window.RESTAURANT_DEMO.setSyncOffline(false));
  await ctx.close();
  if (errors.length) throw new Error('Ошибки на страницах: ' + errors.join('; '));
}

async function pdf(base, browser) {
  const page = await browser.newPage();
  await page.goto(base + '/guide.html', { waitUntil: 'networkidle' });
  await page.emulateMedia({ media: 'print' });
  await page.pdf({
    path: PDF,
    format: 'A4',
    printBackground: true,
    preferCSSPageSize: true,
    displayHeaderFooter: true,
    headerTemplate: '<span></span>',
    footerTemplate: '<div style="width:100%;font-size:8px;color:#8a90a3;padding:0 15mm;display:flex;justify-content:space-between;font-family:sans-serif">'
      + '<span>Ресторан — руководство пользователя</span><span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>',
    tagged: true,
    outline: true,
  });
  await page.close();
}

(async () => {
  const server = await serve();
  const base = 'http://127.0.0.1:' + server.address().port;
  const browser = await chromium.launch();
  try {
    if (!pdfOnly) await screenshots(base, browser);
    await pdf(base, browser);
    const size = (f) => (fs.statSync(f).size / 1024).toFixed(0) + ' КБ';
    if (!pdfOnly) fs.readdirSync(OUT).sort().forEach((f) => console.log('guide/' + f, size(path.join(OUT, f))));
    console.log('restaurant-guide.pdf', size(PDF));
  } finally {
    await browser.close();
    server.close();
  }
})().catch((e) => { console.error(e); process.exit(1); });
