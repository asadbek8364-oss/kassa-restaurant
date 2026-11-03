<?php

namespace App\Services\Kassa;

use App\Models\Kassa\Employee;
use App\Models\Kassa\Store;
use App\Support\Kassa\Clock;
use App\Support\Kassa\Ean13;
use App\Support\Kassa\StoreContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Демо (только для разработки): два магазина аксессуаров с общим владельцем.
 * Основной — 50 товаров, 3 продавца, продажи за 30 дней (8 — сегодня), приходы, возвраты, инвентаризация;
 * филиал — 23 товара и 1 продавец. Генерация детерминирована, даты — относительно «сейчас».
 */
class KassaDemoService
{
    /** PIN-коды демо-сотрудников. */
    public const PINS = ['owner' => '1111', 'e2' => '2222', 'e3' => '3333', 'e4' => '4444', 'e5' => '5555'];

    /** Таблицы с данными магазина: позиции раньше документов (внешние ключи). */
    private const DATA_TABLES = [
        'kassa_audit_items', 'kassa_audits', 'kassa_income_items', 'kassa_incomes', 'kassa_return_items',
        'kassa_returns', 'kassa_sale_items', 'kassa_sales', 'kassa_products',
    ];

    /** Данные ресторанов (заказы, меню, столики…) — удаляются вместе с магазинами. */
    private const RESTAURANT_TABLES = [
        'restaurant_print_jobs', 'restaurant_order_items', 'restaurant_kitchen_tickets', 'restaurant_orders',
        'restaurant_dishes', 'restaurant_categories', 'restaurant_tables', 'restaurant_settings', 'restaurant_sync_logs',
    ];

    // [категория, название, закупка, розница, остаток, мин. остаток, популярность]
    private const CATALOG = [
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

    private const CUSTOMERS = ['Тимур', 'Нигора', 'Сардор', 'Мадина', 'Жасур', 'Камола', 'Ойбек', 'Лола', 'Бехзод', 'Дильшод', 'Севара', 'Улугбек', 'Гульнора', 'Фаррух'];

    private const SUPPLIERS = ['ООО «Mobile Distribution»', 'Baseus Uzbekistan', 'ИП Рахмонов (оптовый склад)', 'Hoco Tashkent'];

    /** Второй демо-магазин: номера строк каталога первого магазина, свой остаток и спрос. */
    private const BRANCH = [
        // [строка CATALOG, остаток, мин. остаток, популярность]
        [0, 3, 2, 3], [2, 6, 3, 3], [6, 2, 2, 2], [9, 1, 3, 3], [11, 8, 4, 2], [12, 19, 8, 6], [13, 4, 8, 6],
        [17, 9, 3, 4], [18, 5, 3, 3], [19, 12, 4, 3], [23, 7, 3, 3], [24, 14, 5, 3], [25, 9, 6, 6], [26, 12, 6, 5],
        [28, 15, 5, 4], [34, 6, 2, 2], [35, 7, 2, 2], [38, 9, 3, 2], [40, 18, 6, 3], [43, 5, 2, 1],
        [21, 8, 2, 0], [36, 4, 1, 0], [45, 6, 2, 0],
    ];

    /** Залежавшийся товар: заведён давно и с тех пор не продавался. [категория, название, закупка, розница, остаток] */
    private const STALE = [
        ['cases', 'Чехол-книжка iPhone 11 (прошлое поколение)', 30000, 69000, 14],
        ['cables', 'Кабель Lightning – 3,5 мм', 24000, 49000, 9],
    ];

    private Randomizer $r;

    /** Подсказка на экране входа демо-магазина. */
    public static function hint(): string
    {
        return 'Демо: PIN владельца 1111, продавцов 2222, 3333, 4444; во втором магазине — 5555';
    }

    /** Демо разрешено только явно (KASSA_DEMO_ENABLED=true) и никогда на рабочем сервере. */
    public static function allowed(): bool
    {
        return (bool) config('kassa.demo_enabled') && ! app()->environment('production');
    }

    /**
     * Полностью заменяет данные кассы двумя демо-магазинами с общим владельцем:
     * «Mobi Aksessuar» (48 товаров + 2 залежавшихся, 3 продавца) и филиал (23 товара, 1 продавец).
     */
    public function load(?CarbonImmutable $now = null): void
    {
        if (! self::allowed()) {
            throw new RuntimeException('Демо-данные отключены: KASSA_DEMO_ENABLED=false или APP_ENV=production.');
        }
        $now ??= Clock::now();
        $context = app(StoreContext::class);

        DB::transaction(function () use ($now, $context) {
            $this->wipeAll();
            $main = Store::create(['name' => 'Mobi Aksessuar', 'address' => 'г. Ташкент, Юнусабадский р-н, ул. Амира Темура, 107', 'is_demo' => true]);
            $branch = Store::create(['name' => 'Mobi Aksessuar Chilonzor', 'address' => 'г. Ташкент, Чиланзарский р-н, ул. Бунёдкор, 12', 'is_demo' => true]);

            $context->set($main);
            $owner = Employee::create(['name' => 'Бахтиёр Каримов', 'role' => 'owner', 'phone' => '+998 90 912-45-67', 'pin' => self::PINS['owner']]);
            $staff = [
                'e1' => $owner,
                'e2' => Employee::create(['name' => 'Азиз Рахимов', 'role' => 'seller', 'phone' => '+998 93 541-20-18', 'pin' => self::PINS['e2']]),
                'e3' => Employee::create(['name' => 'Дилноза Юсупова', 'role' => 'seller', 'phone' => '+998 97 730-55-02', 'pin' => self::PINS['e3']]),
                'e4' => Employee::create(['name' => 'Шахзод Тошматов', 'role' => 'seller', 'phone' => '+998 99 104-87-33', 'pin' => self::PINS['e4']]),
            ];
            $this->r = new Randomizer(new Mt19937(20261102));
            $this->fill($main, $now, self::CATALOG, $staff, [
                'per_day' => [13, 9, 5], 'pool' => ['e2', 'e2', 'e2', 'e3', 'e3', 'e3', 'e4', 'e4', 'e1'],
                'today' => fn (int $k) => $k % 3 === 2 ? 'e3' : 'e2', 'today_count' => 8, 'returns' => 5,
                'returners' => ['e2', 'e3', 'e4'], 'income_days' => [28, 21, 15, 9, 4, 1], 'audit' => true,
                'barcode' => '4781520',
            ]);
            $this->addStale($main, $now);

            $context->set($branch);
            $branchStaff = [
                'e1' => $owner,
                'e5' => Employee::create(['name' => 'Мадина Алиева', 'role' => 'seller', 'phone' => '+998 94 220-61-48', 'pin' => self::PINS['e5']]),
            ];
            $catalog = array_map(function ($b) {
                $row = self::CATALOG[$b[0]];

                return [$row[0], $row[1], $row[2], $row[3], $b[1], $b[2], $b[3]];
            }, self::BRANCH);
            $this->r = new Randomizer(new Mt19937(20261103));
            $this->fill($branch, $now, $catalog, $branchStaff, [
                'per_day' => [5, 6, 3], 'pool' => ['e5', 'e5', 'e5', 'e1'],
                'today' => fn (int $k) => 'e5', 'today_count' => 4, 'returns' => 2,
                'returners' => ['e5'], 'income_days' => [25, 12, 3], 'audit' => false,
                'barcode' => '4781520',
                'barcode_index' => array_column(self::BRANCH, 0),
            ]);

            $context->set($main);
        });
    }

    /**
     * Каталог, продажи за 30 дней и сегодня, возвраты, приходы и инвентаризация одного магазина.
     * Даты — относительно «сейчас», генерация детерминирована (seed задаёт load()).
     */
    private function fill(Store $store, CarbonImmutable $now, array $catalog, array $staff, array $o): void
    {
        $today = $now->startOfDay();
        $ts = fn (CarbonImmutable $t) => Clock::toDb($t);
        $emp = fn (string $k) => $staff[$k]->id;
        $sid = $store->id;

        $products = [];
        foreach ($catalog as $i => [$cat, $name, $buy, $sell, $stock, $min, $w]) {
            $code = $o['barcode'].str_pad((string) (($o['barcode_index'][$i] ?? $i) + 1), 5, '0', STR_PAD_LEFT);
            $id = DB::table('kassa_products')->insertGetId([
                'store_id' => $sid, 'name' => $name, 'category' => $cat, 'barcode' => $code.Ean13::checkDigit($code),
                'buy_price' => $buy, 'sell_price' => $sell, 'stock' => $stock, 'min_stock' => $min,
                'created_at' => $ts($today->subDays(31)), 'updated_at' => $ts($today->subDays(31)),
            ]);
            $products[] = ['id' => $id, 'name' => $name, 'buy' => $buy, 'sell' => $sell, 'w' => $w, 'cat' => $cat];
        }
        $selling = array_values(array_filter($products, fn ($p) => $p['w'] > 0));
        $totalW = array_sum(array_column($selling, 'w'));
        $weighted = function () use ($selling, $totalW) {
            $x = $this->r->nextFloat() * $totalW;
            foreach ($selling as $p) {
                $x -= $p['w'];
                if ($x <= 0) {
                    return $p;
                }
            }

            return end($selling);
        };

        // Продажи: 30 прошлых дней + сегодня
        [$base, $spread, $weekend] = $o['per_day'];
        $plans = [];
        $pool = $o['pool'];
        for ($d = 30; $d >= 1; $d--) {
            $day = $today->subDays($d);
            $count = $base + $this->int($spread) + ($day->isWeekend() ? $weekend : 0);
            for ($k = 0; $k < $count; $k++) {
                $plans[] = [$day->addSeconds(10 * 3600 + $this->int(11 * 3600)), $pool[$this->int(count($pool))]];
            }
        }
        // Сегодняшние продажи — строго между началом дня (или открытием в 10:00) и текущим моментом,
        // даже если демо загружают сразу после полуночи.
        $open = $today->setTime(10, 0);
        $from = $now > $open->addMinutes(90) ? $open : max($today, $now->subHours(3));
        $end = max($from, $now->subMinute());
        $span = (int) $from->diffInSeconds($end, true);
        $n = $o['today_count'];
        for ($k = 0; $k < $n; $k++) {
            $plans[] = [$from->addSeconds((int) ($span * ($k + 0.2 + $this->r->nextFloat() * 0.6) / $n)), ($o['today'])($k)];
        }
        usort($plans, fn ($a, $b) => $a[0] <=> $b[0]);

        $number = 1001;
        $saleRows = [];
        foreach ($plans as [$at, $seller]) {
            $n = $this->r->nextFloat() < 0.62 ? 1 : ($this->r->nextFloat() < 0.75 ? 2 : 3);
            $chosen = [];
            for ($g = 0; count($chosen) < $n && $g < 20; $g++) {
                $p = $weighted();
                $chosen[$p['id']] = $p;
            }
            $items = [];
            $total = $cost = 0;
            foreach ($chosen as $p) {
                $qty = $p['sell'] < 60000 && $this->r->nextFloat() < 0.22 ? 2 : 1;
                $items[] = ['store_id' => $sid, 'product_id' => $p['id'], 'name' => $p['name'], 'qty' => $qty, 'price' => $p['sell'], 'cost' => $p['buy']];
                $total += $qty * $p['sell'];
                $cost += $qty * $p['buy'];
            }
            $x = $this->r->nextFloat();
            $payment = $x < 0.45 ? 'cash' : ($x < 0.8 ? 'card' : 'qr');
            $received = null;
            if ($payment === 'cash') {
                $step = $total > 500000 ? 100000 : 10000;
                $received = $this->r->nextFloat() < 0.45 ? $total : (int) (ceil(($total + 1) / $step) * $step);
            }
            $saleId = DB::table('kassa_sales')->insertGetId([
                'store_id' => $sid, 'number' => $number++, 'employee_id' => $emp($seller),
                'customer' => $this->r->nextFloat() < 0.38 ? self::CUSTOMERS[$this->int(count(self::CUSTOMERS))] : null,
                'payment' => $payment, 'total' => $total, 'cost_total' => $cost,
                'received' => $received, 'change_amount' => $received ? $received - $total : 0,
                'created_at' => $ts($at), 'updated_at' => $ts($at),
            ]);
            $itemIds = [];
            foreach ($items as $it) {
                $itemIds[] = DB::table('kassa_sale_items')->insertGetId(['sale_id' => $saleId] + $it);
            }
            $saleRows[] = ['id' => $saleId, 'at' => $at, 'payment' => $payment, 'item_id' => $itemIds[0], 'item' => $items[0]];
        }

        // Возвраты по чекам прошлых дней
        $past = array_values(array_filter($saleRows, fn ($s) => $s['at'] < $today->subDays(2) && $s['at'] > $today->subDays(26)));
        $used = [];
        $returnNo = 1;
        $reasons = ['Брак', 'Не подошёл', 'Передумал'];
        $returners = $o['returners'];
        for ($k = 0; $k < $o['returns'] && $past; $k++) {
            $s = $past[$this->int(count($past))];
            if (isset($used[$s['id']])) {
                continue;
            }
            $used[$s['id']] = true;
            $at = min($s['at']->addDay()->startOfDay()->addSeconds(12 * 3600 + $this->int(6 * 3600)), $now->subHour());
            $it = $s['item'];
            $rid = DB::table('kassa_returns')->insertGetId([
                'store_id' => $sid, 'number' => $returnNo++, 'sale_id' => $s['id'], 'employee_id' => $emp($returners[$this->int(count($returners))]),
                'payment' => $s['payment'], 'reason' => $reasons[$this->int(3)],
                'total' => $it['price'], 'cost_total' => $it['cost'], 'created_at' => $ts($at), 'updated_at' => $ts($at),
            ]);
            DB::table('kassa_return_items')->insert([
                'store_id' => $sid, 'return_id' => $rid, 'sale_item_id' => $s['item_id'], 'product_id' => $it['product_id'],
                'name' => $it['name'], 'qty' => 1, 'price' => $it['price'], 'cost' => $it['cost'],
            ]);
        }

        // Приходы от поставщиков
        $incomeNo = 1;
        foreach ($o['income_days'] as $k => $d) {
            $at = $today->subDays($d)->addSeconds(9 * 3600 + $this->int(40 * 60));
            $lines = [];
            $n = 4 + $this->int(5);
            for ($g = 0; count($lines) < $n && $g < 40; $g++) {
                $p = $weighted();
                if (isset($lines[$p['id']])) {
                    continue;
                }
                $qty = $p['buy'] > 500000 ? 2 : ($p['buy'] > 100000 ? 3 + $this->int(4) : 10 + $this->int(21));
                $lines[$p['id']] = ['store_id' => $sid, 'product_id' => $p['id'], 'name' => $p['name'], 'qty' => $qty, 'cost' => $p['buy']];
            }
            $iid = DB::table('kassa_incomes')->insertGetId([
                'store_id' => $sid, 'number' => $incomeNo++, 'employee_id' => $emp('e1'), 'supplier' => self::SUPPLIERS[$k % count(self::SUPPLIERS)],
                'total' => array_sum(array_map(fn ($l) => $l['qty'] * $l['cost'], $lines)),
                'created_at' => $ts($at), 'updated_at' => $ts($at),
            ]);
            DB::table('kassa_income_items')->insert(array_map(fn ($l) => ['income_id' => $iid] + $l, array_values($lines)));
        }

        // Инвентаризация защитных стёкол и чехлов 12 дней назад
        $auditNo = 1;
        if ($o['audit']) {
            $at = $today->subDays(12)->setTime(20, 50);
            $aid = DB::table('kassa_audits')->insertGetId(['store_id' => $sid, 'number' => $auditNo++, 'employee_id' => $emp('e1'), 'created_at' => $ts($at), 'updated_at' => $ts($at)]);
            $diff = ['Защитное стекло 9D iPhone 13 / 14' => -2, 'Защитное стекло 9D Redmi Note 13' => -1, 'Чехол противоударный Redmi Note 13' => 1];
            $stock = array_column($catalog, 4, 1);
            $rows = [];
            foreach ($products as $p) {
                if ($p['cat'] === 'screen' || $p['cat'] === 'cases') {
                    $expected = $stock[$p['name']] + 6;
                    $rows[] = ['store_id' => $sid, 'audit_id' => $aid, 'product_id' => $p['id'], 'name' => $p['name'], 'expected' => $expected, 'actual' => $expected + ($diff[$p['name']] ?? 0), 'cost' => $p['buy']];
                }
            }
            DB::table('kassa_audit_items')->insert($rows);
        }

        $store->update(['next_sale_no' => $number, 'next_return_no' => $returnNo, 'next_income_no' => $incomeNo, 'next_audit_no' => $auditNo]);
    }

    /** Товар, который завезли два месяца назад и с тех пор ни разу не продали, — пример для раздела «Прогнозы». */
    private function addStale(Store $store, CarbonImmutable $now): void
    {
        $at = Clock::toDb($now->startOfDay()->subDays(64)->setTime(11, 0));
        foreach (self::STALE as $i => [$cat, $name, $buy, $sell, $stock]) {
            $code = '47815209'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
            DB::table('kassa_products')->insert([
                'store_id' => $store->id, 'name' => $name, 'category' => $cat, 'barcode' => $code.Ean13::checkDigit($code),
                'buy_price' => $buy, 'sell_price' => $sell, 'stock' => $stock, 'min_stock' => 2, 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }

    /** Удаляет товары и документы текущего магазина и сбрасывает его нумерацию; сотрудники остаются. */
    public function wipe(): void
    {
        $store = Store::current();
        DB::transaction(function () use ($store) {
            foreach (self::DATA_TABLES as $table) {
                DB::table($table)->where('store_id', $store->id)->delete();
            }
            $store->update(['is_demo' => false, 'next_sale_no' => 1001, 'next_return_no' => 1, 'next_income_no' => 1, 'next_audit_no' => 1]);
        });
    }

    /** Удаляет все магазины кассы со всеми данными, сотрудниками и входами (и данные ресторанов этих магазинов). */
    private function wipeAll(): void
    {
        foreach ([...self::RESTAURANT_TABLES, ...self::DATA_TABLES, 'kassa_tokens', 'kassa_employees', 'kassa_stores'] as $table) {
            DB::table($table)->delete();
        }
        app(StoreContext::class)->forget();
    }

    private function int(int $maxExclusive): int
    {
        return $this->r->getInt(0, $maxExclusive - 1);
    }
}
