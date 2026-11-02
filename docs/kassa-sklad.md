# Касса и склад

Мобильное веб-приложение для магазина аксессуаров для телефонов: продажи, склад, приход, возвраты,
инвентаризация, отчёты, журнал и сотрудники. Клиент — статические файлы в `public/kassa/`.
Данные хранятся на сервере этого Laravel-приложения (таблицы `kassa_*`, по умолчанию в одном файле SQLite),
там же выполняются все расчёты.

## Развёртывание на чистом сервере (Ubuntu 24.04)

Эти шаги проверены на чистом клоне: `composer install --no-dev`, SQLite, nginx + PHP-FPM 8.3, `APP_ENV=production`.
Не проверены только получение HTTPS-сертификата (certbot) и DNS — их нужно сделать на своём домене.

```bash
# 1. Пакеты: PHP 8.3 с расширениями, nginx, git, composer
sudo apt update
sudo apt install -y nginx git unzip curl openssl \
  php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-zip php8.3-intl php8.3-gd php8.3-bcmath
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer

# 2. Код
sudo git clone -b claude/phone-accessories-pos-app-co6o5k \
  https://github.com/asadbek8364-oss/point-of-sales.git /var/www/kassa
sudo chown -R $USER /var/www/kassa
cd /var/www/kassa
composer install --no-dev --optimize-autoloader

# 3. Настройки: .env.example уже рассчитан на рабочий сервер (production, debug выключен, SQLite)
cp .env.example .env
php artisan key:generate --force
sed -i "s|^APP_URL=.*|APP_URL=https://kassa.example.uz|; s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=kassa.example.uz|" .env
sed -i "s|^KASSA_SETUP_CODE=.*|KASSA_SETUP_CODE=$(openssl rand -hex 6)|" .env
grep KASSA_SETUP_CODE .env          # запишите код — он понадобится в мастере один раз

# 4. База SQLite и кэш маршрутов
touch database/database.sqlite
php artisan migrate --force
php artisan route:cache && php artisan view:cache && php artisan event:cache
sudo chown -R www-data:www-data storage bootstrap/cache database
```

`php artisan config:cache` и `php artisan optimize` не используйте: настройки документации API
основного приложения (`config/scramble.php`) не кэшируются. Деплой самого репозитория тоже их не кэширует.

5. nginx — `/etc/nginx/sites-available/kassa` (замените домен):

```nginx
server {
    listen 80;
    server_name kassa.example.uz;
    root /var/www/kassa/public;
    index index.html;
    charset utf-8;
    client_max_body_size 2m;

    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy same-origin always;

    # Корень сайта открывает кассу; остальная часть POS-системы на этом сервере закрыта.
    location = / { return 302 /kassa/; }
    location /kassa/ { try_files $uri $uri/ =404; }
    location = /kassa/sw.js { add_header Cache-Control "no-cache" always; try_files $uri =404; }

    # API кассы обрабатывает Laravel.
    location /api/kassa/ { try_files $uri /index.php?$query_string; }
    location = /index.php {
        internal;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location / { return 404; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/kassa /etc/nginx/sites-enabled/kassa
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

# 6. HTTPS (обязательно: без него не работают камера-сканер, установка на телефон и защищённые cookie)
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d kassa.example.uz
```

7. Откройте `https://kassa.example.uz` — откроется мастер первого запуска. Введите:
   - код установки из `.env`;
   - название магазина;
   - имя владельца;
   - свой PIN (4–6 цифр).

   Дальше владелец добавляет товары и продавцов, у каждого продавца свой PIN.

С таким конфигом снаружи открыты только `/kassa/` и `/api/kassa/`. Остальная POS-система (`/setup`, `/login`, `/api/v1`),
файл `.env` и файл базы снаружи недоступны.

### Обновление

```bash
cd /var/www/kassa
git pull
composer install --no-dev --optimize-autoloader
# база, storage и bootstrap/cache принадлежат www-data — artisan запускаем от его имени
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan event:cache
```

### Резервная копия

Все данные кассы — один файл `database/database.sqlite`. Копию безопасно делать на ходу:

```bash
sqlite3 /var/www/kassa/database/database.sqlite ".backup '/var/backups/kassa-$(date +%F).sqlite'"
```

(`sudo apt install sqlite3`). Добавьте эту команду в `cron` раз в день и храните копии вне сервера.

## Первый запуск и безопасность

- **Демо-данных нет.** При первом открытии — мастер: код установки, название магазина, владелец и его собственный PIN.
- **Код установки** (`KASSA_SETUP_CODE`) защищает мастер: без него посторонний мог бы создать магазин раньше владельца.
  На рабочем сервере (`APP_ENV=production`) без заданного кода мастер закрыт и показывает, что нужно сделать.
  После создания магазина мастер больше не открывается.
- **PIN** — 4–6 цифр. Сервер отклоняет простые: одинаковые цифры (1111), подряд (1234, 4321), частые (2580, 1212 и т. п.).
- После 5 неверных PIN подряд вход этого сотрудника закрыт на 15 минут.
- PIN и токены входа хранятся только в виде хешей. Вход действует 30 дней с продлением при активности (`KASSA_TOKEN_TTL_DAYS`).
- Отключённый сотрудник сразу теряет доступ.
- Только владелец может: удалять товары, добавлять, изменять и отключать сотрудников, менять название и адрес магазина.
  Продавцы продают, принимают приход, оформляют возвраты и инвентаризацию, добавляют и меняют товары.
- У каждого маршрута свой лимит запросов (`kassa-public`, `kassa-setup`, `kassa-login`, `kassa-api` в `AppServiceProvider`).
  Счётчики раздельные, потому что все телефоны магазина обычно выходят в сеть с одного IP.

## База данных

По умолчанию — SQLite, файл `database/database.sqlite`. Отдельный сервер базы не нужен.
Для одновременной работы нескольких касс в `config/database.php` включены:

| Настройка | Значение | Зачем |
|-----------|----------|-------|
| `DB_JOURNAL_MODE` | `wal` | Чтение не ждёт записи |
| `DB_BUSY_TIMEOUT` | `10000` | Касса ждёт до 10 с, пока другая запишет, вместо ошибки «database is locked» |
| `DB_TRANSACTION_MODE` | `IMMEDIATE` | Транзакция сразу занимает запись (PHP 8.4+) |
| `DB_SYNCHRONOUS` | `normal` | Рекомендуемый режим для WAL |

На PHP 8.3 тот же порядок гарантирует сама касса: первая команда каждой операции — запись в строку магазина.
Проверено: 10 параллельных процессов продают товар с остатком 3 — 3 продажи, 7 отказов «недостаточно на складе»,
номера чеков подряд, без ошибок блокировки. Все миграции проекта проходят на чистом SQLite.

Папка `database/` должна быть доступна PHP на запись: рядом с базой создаются файлы `-wal` и `-shm`.
Для MySQL укажите `DB_CONNECTION=mysql` и параметры подключения в `.env`.

## Демо-магазин (только для разработки)

48 товаров, 4 сотрудника (PIN владельца 1111, продавцов 2222, 3333, 4444), продажи за 30 дней. Работает, только если
`KASSA_DEMO_ENABLED=true` и `APP_ENV` не `production`. На рабочем сервере:

- команды `php artisan kassa:demo` нет;
- `POST /api/kassa/data/demo`, `/data/wipe` и демо в мастере отвечают 404;
- кнопок «Загрузить демо» и «Начать с чистого листа» нет.

## Что считает сервер

| Операция | Что делает сервер |
|----------|-------------------|
| Продажа `POST /api/kassa/sales` | Берёт розничные цены из базы (цены от клиента игнорируются), проверяет остатки, считает итог, себестоимость и сдачу, списывает товар, присваивает номер чека, записывает продавца по токену. Повтор с тем же `client_uuid` не создаёт второй чек |
| Возврат `POST /api/kassa/returns` | Не даёт вернуть больше купленного минус уже возвращённое, считает сумму по цене из чека, возвращает товар на склад |
| Приход `POST /api/kassa/incomes` | Увеличивает остатки, обновляет закупочную цену, считает сумму документа |
| Инвентаризация `POST /api/kassa/audits` | Фиксирует учётный остаток на момент проведения и выставляет остаток по факту; считает недостачу и излишек |
| Отчёты `GET /api/kassa/reports?period=today\|week\|month\|all` | Выручка, чеки, проданные единицы, средний чек, валовая прибыль и маржа; график по часам, дням или месяцам; оплаты, продавцы, топ товаров, низкие остатки, стоимость склада |
| Главная `GET /api/kassa/dashboard` | Итоги дня, товары с низким остатком, последние продажи |
| Журнал `GET /api/kassa/journal` | Все операции по убыванию времени, фильтр по типу, поиск, итог выручки за каждый день |

Формулы: выручка = продажи − возвраты (возврат учитывается в день возврата); средний чек = продажи / число чеков;
валовая прибыль = выручка − себестоимость (закупочная цена на момент продажи).
«Сегодня», неделя и месяц считаются в часовом поясе магазина `KASSA_TIMEZONE` (по умолчанию `Asia/Tashkent`).

## Что хранится на телефоне

Только черновики, чтобы ничего не терялось при случайном закрытии: корзина, незаконченный приход и пересчёт
инвентаризации. Плюс токен входа. Всё остальное приходит с сервера. При возвращении в приложение данные
обновляются, так что продажи с других телефонов сразу видны.

Без интернета продавать нельзя: чек пробивается только после ответа сервера. Корзина при этом сохраняется,
а повторная отправка того же чека безопасна (`client_uuid`).

## API

Все запросы — JSON. Всем, кроме `options`, `setup` и `login`, нужен заголовок `Authorization: Bearer <token>`.

| Метод и путь | Назначение |
|--------------|------------|
| `GET /api/kassa/options` | Название магазина, сотрудники для экрана входа, нужна ли первая настройка, нужен ли код установки |
| `POST /api/kassa/setup` | Первая настройка (только пока нет сотрудников): `setup_code`, `store_name`, `owner_name`, `pin` |
| `POST /api/kassa/login`, `POST /api/kassa/logout` | Вход по `employee_id` + `pin`, выход |
| `GET /api/kassa/bootstrap` | Кто вошёл, магазин, сотрудники, каталог, популярные товары, поставщики |
| `GET/POST /api/kassa/products`, `GET/PUT/DELETE /api/kassa/products/{id}`, `GET /api/kassa/products/barcode` | Каталог, карточка с движением товара, новый внутренний EAN-13 |
| `GET/POST /api/kassa/sales`, `GET /api/kassa/sales/{id}` | Чеки (`?number=` — поиск по номеру) |
| `GET/POST /api/kassa/returns`, `GET /api/kassa/returns/{id}` | Возвраты |
| `GET/POST /api/kassa/incomes`, `GET /api/kassa/incomes/{id}` | Приходы |
| `GET/POST /api/kassa/audits`, `GET /api/kassa/audits/{id}` | Инвентаризации |
| `GET /api/kassa/dashboard`, `/reports`, `/journal` | Сводки и отчёты |
| `GET /api/kassa/employees`, `/employees/{id}` | Сотрудники со статистикой |
| `POST /api/kassa/employees`, `PUT /employees/{id}`, `POST /employees/{id}/toggle` | Управление сотрудниками (владелец) |
| `PUT /api/kassa/store` | Название и адрес магазина (владелец) |

## Разработка на своём компьютере

```bash
cp .env.example .env
# APP_ENV=local, APP_DEBUG=true, APP_URL=http://localhost:8000, SESSION_SECURE_COOKIE=false
# по желанию KASSA_DEMO_ENABLED=true для демо-магазина
php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan serve        # http://localhost:8000/kassa/index.html
php artisan test --filter=Kassa
```

## Код

| Где | Что |
|-----|-----|
| `database/migrations/2026_11_02_000001_create_kassa_tables.php` | Таблицы `kassa_*` |
| `app/Models/Kassa/` | Модели |
| `app/Services/Kassa/KassaService.php` | Продажа, возврат, приход, инвентаризация, товары |
| `app/Services/Kassa/KassaReportService.php` | Сводки, отчёты, журнал, статистика сотрудников |
| `app/Services/Kassa/KassaDemoService.php` | Демо-магазин (только вне production) |
| `app/Rules/KassaPin.php` | Проверка PIN на простоту |
| `app/Http/Controllers/Api/Kassa/` | API |
| `app/Http/Middleware/AuthenticateKassa.php`, `EnsureKassaOwner.php` | Вход по токену, права владельца |
| `tests/Feature/Kassa/KassaApiTest.php` | Тесты API |
| `public/kassa/` | Клиент: `index.html`, `styles.css`, `app.js`, офлайн-оболочка `sw.js`, манифест и иконки |
