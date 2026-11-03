<?php

namespace App\Services\Restaurant;

use App\Models\Kassa\Employee;
use App\Models\Kassa\Sale;
use App\Models\Kassa\SaleItem;
use App\Models\Kassa\Store;
use App\Models\Restaurant\Category;
use App\Models\Restaurant\Dish;
use App\Models\Restaurant\KitchenTicket;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\OrderItem;
use App\Models\Restaurant\Settings;
use App\Models\Restaurant\Table;
use App\Services\Kassa\KassaDemoService;
use App\Support\Kassa\Clock;
use App\Support\Kassa\StoreContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Демо-ресторан (только для разработки и показа, как демо кассы): 22 блюда в 5 категориях, 8 столиков,
 * по одному сотруднику каждой роли, оплаченные заказы за две недели и несколько открытых заказов
 * в разных статусах, чтобы все три приложения сразу показывали работу. Генерация детерминирована.
 */
class RestaurantDemoService
{
    public const NAME = 'Демо-ресторан «Навруз»';

    public const PINS = ['admin' => '1111', 'waiter' => '2222', 'cook' => '3333'];

    public const HINT = 'Демо: администратор — PIN 1111, официант — 2222, повар — 3333';

    /** Таблицы ресторана и кассы с данными магазина: позиции раньше документов (внешние ключи). */
    public const STORE_TABLES = [
        'restaurant_print_jobs', 'restaurant_order_items', 'restaurant_kitchen_tickets', 'restaurant_orders',
        'restaurant_dishes', 'restaurant_categories', 'restaurant_tables', 'restaurant_settings', 'restaurant_sync_logs',
        'kassa_audit_items', 'kassa_audits', 'kassa_income_items', 'kassa_incomes', 'kassa_return_items',
        'kassa_returns', 'kassa_sale_items', 'kassa_sales', 'kassa_products',
    ];

    // [категория, название, состав, цена, популярность]
    private const MENU = [
        ['Салаты', 'Ачичук', 'Помидоры, репчатый лук, острый перец, базилик', 18000, 6],
        ['Салаты', 'Салат «Ташкент»', 'Зелёная редька, отварная говядина, яйцо, жареный лук', 35000, 4],
        ['Салаты', 'Цезарь с курицей', 'Романо, куриное филе, пармезан, сухарики, соус цезарь', 42000, 3],
        ['Салаты', 'Овощной салат', 'Огурцы, помидоры, перец, зелень, растительное масло', 25000, 3],
        ['Супы', 'Шурпа', 'Баранина, картофель, морковь, нут, зелень', 38000, 5],
        ['Супы', 'Лагман', 'Тянутая лапша, говядина, овощи, чеснок', 40000, 7],
        ['Супы', 'Мастава', 'Рис, говядина, овощи, катык', 32000, 3],
        ['Супы', 'Мошхурда', 'Маш, рис, говядина, овощи', 30000, 2],
        ['Горячие блюда', 'Плов чайханский', 'Рис девзира, баранина, жёлтая морковь, нут, изюм', 45000, 10],
        ['Горячие блюда', 'Манты (5 шт.)', 'Рубленая баранина, лук, курдючный жир; подаются со сметаной', 38000, 6],
        ['Горячие блюда', 'Шашлык из баранины', 'Каре ягнёнка на мангале, лук, зелень', 35000, 7],
        ['Горячие блюда', 'Шашлык из курицы', 'Куриное бедро в маринаде, на мангале', 28000, 5],
        ['Горячие блюда', 'Казан-кабоб', 'Баранина с картофелем в казане', 65000, 2],
        ['Горячие блюда', 'Долма', 'Виноградные листья, рубленое мясо, рис, катык', 42000, 2],
        ['Выпечка', 'Самса с мясом', 'Слоёное тесто, баранина, лук; из тандыра', 12000, 8],
        ['Выпечка', 'Самса с тыквой', 'Слоёное тесто, тыква, лук', 10000, 3],
        ['Выпечка', 'Лепёшка тандырная', 'Горячая лепёшка из тандыра', 5000, 9],
        ['Напитки', 'Чай зелёный (чайник)', 'Зелёный чай, 0,8 л', 8000, 9],
        ['Напитки', 'Чай чёрный с лимоном', 'Чайник 0,8 л, лимон', 10000, 5],
        ['Напитки', 'Компот домашний', 'Сухофрукты, 0,5 л', 9000, 5],
        ['Напитки', 'Айран', 'Кисломолочный напиток, 0,3 л', 9000, 4],
        ['Напитки', 'Вода минеральная 0,5 л', 'Без газа или с газом', 6000, 4],
    ];

    private const TABLES = [['1', 4], ['2', 4], ['3', 4], ['4', 4], ['5', 6], ['6', 6], ['7', 8], ['VIP', 12]];

    private const COMMENTS = ['без лука', 'острое', 'без зелени', 'поменьше соли', 'хорошо прожарить', 'соус отдельно'];

    private Randomizer $r;

    /** Демо разрешено только явно (KASSA_DEMO_ENABLED=true) и никогда на рабочем сервере. */
    public static function allowed(): bool
    {
        return KassaDemoService::allowed();
    }

    /** Создаёт (пересоздаёт) демо-ресторан. Другие магазины и рестораны не трогает. */
    public function load(?CarbonImmutable $now = null): Store
    {
        if (! self::allowed()) {
            throw new RuntimeException('Демо-данные отключены: KASSA_DEMO_ENABLED=false или APP_ENV=production.');
        }
        $now ??= Clock::now();
        $context = app(StoreContext::class);
        $this->r = new Randomizer(new Mt19937(20261104));

        return DB::transaction(function () use ($now, $context) {
            foreach (Store::where('name', self::NAME)->where('is_demo', true)->get() as $old) {
                self::wipeStore($old);
            }
            $store = Store::create(['name' => self::NAME, 'address' => 'г. Ташкент, ул. Навои, 30', 'is_demo' => true]);
            $context->set($store);

            Settings::current()->update(['cook_minutes' => 15, 'receipt_footer' => 'Спасибо, что были у нас! Ждём снова', 'printer_mode' => 'off']);
            $staff = [
                'admin' => Employee::create(['name' => 'Дилшод Каримов', 'role' => 'admin', 'phone' => '+998 90 123-45-67', 'pin' => self::PINS['admin']]),
                'waiter' => Employee::create(['name' => 'Азиз Рахимов', 'role' => 'waiter', 'phone' => '+998 93 541-20-18', 'pin' => self::PINS['waiter']]),
                'cook' => Employee::create(['name' => 'Шахзода Юсупова', 'role' => 'cook', 'phone' => '+998 97 730-55-02', 'pin' => self::PINS['cook']]),
            ];

            $categories = [];
            $dishes = [];
            foreach (self::MENU as $i => [$category, $name, $description, $price, $weight]) {
                $categories[$category] ??= Category::create(['name' => $category, 'sort' => (count($categories) + 1) * 10]);
                $dishes[] = ['dish' => Dish::create([
                    'category_id' => $categories[$category]->id, 'name' => $name, 'description' => $description,
                    'price' => $price, 'active' => true, 'stop' => $name === 'Казан-кабоб', 'sort' => ($i + 1) * 10,
                ]), 'w' => $weight];
            }
            $tables = [];
            foreach (self::TABLES as $i => [$name, $seats]) {
                $tables[] = Table::create(['name' => $name, 'seats' => $seats, 'active' => true, 'sort' => ($i + 1) * 10]);
            }

            $this->history($store, $now, $staff, $dishes, $tables);
            $this->openOrders($store, $now, $staff, $dishes, $tables);

            return $store;
        });
    }

    /** Оплаченные заказы за 14 прошлых дней и сегодня до текущего часа (с продажами кассы). */
    private function history(Store $store, CarbonImmutable $now, array $staff, array $dishes, array $tables): void
    {
        $today = $now->startOfDay();
        $plans = [];
        for ($d = 14; $d >= 0; $d--) {
            $day = $today->subDays($d);
            $count = $d === 0 ? 4 : 8 + $this->int(6) + ($day->isWeekend() ? 4 : 0);
            for ($k = 0; $k < $count; $k++) {
                $opened = $day->addSeconds(11 * 3600 + $this->int(10 * 3600));
                if ($d === 0) {
                    // сегодня — только уже прошедшие заказы
                    $opened = max($today, $now->subMinutes(60 + $this->int(240)));
                }
                $plans[] = $opened;
            }
        }
        sort($plans);
        $orderNo = 1;
        $saleNo = 1001;
        foreach ($plans as $opened) {
            $paidAt = max($opened->addMinute(), min($opened->addMinutes(35 + $this->int(50)), $now->subMinutes(5)));
            $table = $tables[$this->int(count($tables))];
            $waiter = $this->r->nextFloat() < 0.8 ? $staff['waiter'] : $staff['admin'];
            $lines = $this->pick($dishes, 2 + $this->int(4));
            $total = array_sum(array_map(fn ($l) => $l['dish']->price * $l['qty'], $lines));
            $payment = $this->r->nextFloat() < 0.55 ? 'card' : 'cash';
            $received = $payment === 'cash' ? (int) (ceil($total / 50000) * 50000) : null;
            $at = fn (CarbonImmutable $t) => Clock::toDb($t);

            $sale = Sale::create([
                'number' => $saleNo++, 'employee_id' => $waiter->id, 'customer' => 'Стол '.$table->name.' · заказ №'.$orderNo,
                'payment' => $payment, 'total' => $total, 'cost_total' => 0, 'received' => $received,
                'change_amount' => $received ? $received - $total : 0, 'created_at' => $at($paidAt), 'updated_at' => $at($paidAt),
            ]);
            $order = Order::create([
                'number' => $orderNo++, 'table_id' => $table->id, 'table_name' => $table->name, 'waiter_id' => $waiter->id,
                'waiter_name' => $waiter->name, 'guests' => 1 + $this->int($table->seats), 'status' => 'paid', 'total' => $total,
                'payment' => $payment, 'received' => $received, 'change_amount' => $received ? $received - $total : 0,
                'sale_id' => $sale->id, 'closed_by' => $waiter->id, 'bill_at' => $at($paidAt->subMinutes(3)), 'paid_at' => $at($paidAt),
                'created_at' => $at($opened), 'updated_at' => $at($paidAt),
            ]);
            foreach ($lines as $l) {
                OrderItem::create([
                    'order_id' => $order->id, 'batch' => 1, 'dish_id' => $l['dish']->id, 'name' => $l['dish']->name,
                    'price' => $l['dish']->price, 'qty' => $l['qty'], 'comment' => $l['comment'], 'status' => 'served',
                    'sent_at' => $at($opened), 'cooking_at' => $at($opened->addMinutes(2)), 'ready_at' => $at($opened->addMinutes(14)),
                    'served_at' => $at($opened->addMinutes(16)), 'created_at' => $at($opened), 'updated_at' => $at($paidAt),
                ]);
                SaleItem::create(['sale_id' => $sale->id, 'name' => $l['dish']->name, 'qty' => $l['qty'], 'price' => $l['dish']->price, 'cost' => 0]);
            }
        }
        $store->update(['next_order_no' => $orderNo, 'next_sale_no' => $saleNo]);
    }

    /** Открытые заказы: новый, готовится, готов, ждёт счёт — по одному на столик. */
    private function openOrders(Store $store, CarbonImmutable $now, array $staff, array $dishes, array $tables): void
    {
        $byName = collect($dishes)->keyBy(fn ($d) => $d['dish']->name)->map(fn ($d) => $d['dish']);
        $plans = [
            // [столик, минут назад, гостей, [[блюдо, кол-во, комментарий, статус]], комментарий к заказу, счёт]
            [1, 3, 2, [['Лагман', 2, 'без зелени', 'new'], ['Самса с мясом', 2, null, 'new'], ['Чай зелёный (чайник)', 1, null, 'new']], null, false],
            [2, 9, 4, [['Плов чайханский', 3, null, 'cooking'], ['Ачичук', 2, null, 'ready'], ['Лепёшка тандырная', 2, null, 'cooking'], ['Шашлык из баранины', 4, 'хорошо прожарить', 'new']], 'у гостя аллергия на орехи', false],
            [4, 18, 3, [['Манты (5 шт.)', 2, null, 'ready'], ['Шурпа', 1, 'острое', 'ready'], ['Компот домашний', 3, null, 'ready']], null, false],
            [6, 55, 5, [['Плов чайханский', 2, null, 'served'], ['Шашлык из курицы', 3, null, 'served'], ['Айран', 2, null, 'served'], ['Чай чёрный с лимоном', 1, null, 'served']], null, true],
        ];
        $number = $store->fresh()->next_order_no;
        foreach ($plans as [$tableIndex, $minutes, $guests, $lines, $comment, $bill]) {
            $table = $tables[$tableIndex];
            $sent = $now->subMinutes($minutes);
            $waiter = $staff['waiter'];
            $order = Order::create([
                'number' => $number++, 'table_id' => $table->id, 'table_name' => $table->name, 'waiter_id' => $waiter->id,
                'waiter_name' => $waiter->name, 'guests' => $guests, 'comment' => $comment, 'status' => 'new',
                'bill_at' => $bill ? Clock::toDb($now->subMinutes(2)) : null,
                'created_at' => Clock::toDb($sent), 'updated_at' => Clock::toDb($sent),
            ]);
            $ticket = KitchenTicket::create(['order_id' => $order->id, 'batch' => 1, 'employee_id' => $waiter->id, 'comment' => $comment, 'sent_at' => Clock::toDb($sent)]);
            foreach ($lines as [$name, $qty, $itemComment, $status]) {
                $dish = $byName[$name];
                $times = ['sent_at' => Clock::toDb($sent)];
                if ($status !== 'new') {
                    $times['cooking_at'] = Clock::toDb($sent->addMinutes(1));
                }
                if (in_array($status, ['ready', 'served'], true)) {
                    $times['ready_at'] = Clock::toDb($sent->addMinutes(min($minutes - 1, 14)));
                }
                if ($status === 'served') {
                    $times['served_at'] = Clock::toDb($sent->addMinutes(min($minutes - 1, 16)));
                }
                OrderItem::create([
                    'order_id' => $order->id, 'ticket_id' => $ticket->id, 'batch' => 1, 'dish_id' => $dish->id, 'name' => $dish->name,
                    'price' => $dish->price, 'qty' => $qty, 'comment' => $itemComment, 'status' => $status,
                ] + $times);
            }
            $order->refreshStatus();
            $order->save();
        }
        $store->update(['next_order_no' => $number]);
    }

    /** Несколько разных блюд с учётом популярности. */
    private function pick(array $dishes, int $n): array
    {
        $available = array_values(array_filter($dishes, fn ($d) => ! $d['dish']->stop));
        $total = array_sum(array_column($available, 'w'));
        $chosen = [];
        for ($g = 0; count($chosen) < $n && $g < 30; $g++) {
            $x = $this->r->nextFloat() * $total;
            foreach ($available as $d) {
                $x -= $d['w'];
                if ($x <= 0) {
                    $chosen[$d['dish']->id] ??= [
                        'dish' => $d['dish'],
                        'qty' => 1 + ($this->r->nextFloat() < 0.3 ? $this->int(3) : 0),
                        'comment' => $this->r->nextFloat() < 0.12 ? self::COMMENTS[$this->int(count(self::COMMENTS))] : null,
                    ];
                    break;
                }
            }
        }

        return array_values($chosen);
    }

    /** Удаляет ресторан со всеми данными, сотрудниками и входами. */
    public static function wipeStore(Store $store): void
    {
        foreach (self::STORE_TABLES as $table) {
            DB::table($table)->where('store_id', $store->id)->delete();
        }
        $employees = DB::table('kassa_employees')->where('store_id', $store->id)->pluck('id');
        DB::table('kassa_tokens')->whereIn('employee_id', $employees)->orWhere('store_id', $store->id)->delete();
        DB::table('kassa_employees')->whereIn('id', $employees)->delete();
        $store->delete();
    }

    private function int(int $maxExclusive): int
    {
        return $this->r->getInt(0, $maxExclusive - 1);
    }
}
