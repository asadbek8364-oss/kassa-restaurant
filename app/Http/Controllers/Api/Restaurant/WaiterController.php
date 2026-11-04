<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Api\Restaurant\Concerns\OrderStatusActions;
use App\Models\Restaurant\Category;
use App\Models\Restaurant\Dish;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\OrderItem;
use App\Models\Restaurant\Settings;
use App\Models\Restaurant\Table;
use App\Services\Restaurant\OrderService;
use App\Support\Kassa\Clock;
use App\Support\Restaurant\Mode;
use App\Support\Restaurant\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Приложение официанта: схема зала, меню, заказ на столик, дозаказ, отметки «готовится / готово / подано»,
 * счёт и оплата. Те же действия — основа приложения кассира (CashierController).
 */
class WaiterController extends RestaurantController
{
    use OrderStatusActions;

    public function __construct(protected readonly OrderService $orders) {}

    /**
     * Схема зала: столики со статусом (свободен / занят / ждёт счёт) и открытыми заказами.
     * menu_version меняется при любой правке меню — тогда приложение перезагружает меню (стоп-лист виден сразу).
     */
    public function state(): JsonResponse
    {
        $all = Order::with('items')->whereIn('status', Order::OPEN)->orderBy('id')->get();
        $open = $all->where('type', 'table')->keyBy('table_id');
        $tables = Table::where('active', true)->orderBy('sort')->orderBy('id')->get();
        $settings = Settings::current();

        return response()->json([
            'server_time' => Clock::now()->toIso8601String(),
            'mode' => Mode::current(),
            'printer_mode' => $settings->printer_mode,
            'terminal' => ['enabled' => $settings->terminalOn(), 'test' => (bool) $settings->terminal_test],
            'menu_version' => $this->menuVersion(),
            'tables' => $tables->map(fn (Table $t) => Presenter::table($t, $open->get($t->id)))->values()->all(),
            // заказы без столика (столик удалили, пока гости сидят) — чтобы их можно было закрыть
            'orphans' => $open->filter(fn (Order $o, $tableId) => ! $tables->contains('id', $tableId))
                ->map(fn (Order $o) => Presenter::orderSummary($o))->values()->all(),
            // открытые заказы навынос (ведёт кассир)
            'takeaway' => $all->where('type', 'takeaway')->map(fn (Order $o) => Presenter::orderSummary($o) + ['lines' => Presenter::lines($o)])->values()->all(),
        ]);
    }

    public function menu(): JsonResponse
    {
        $categories = Category::orderBy('sort')->orderBy('name')->get();

        return response()->json([
            'menu_version' => $this->menuVersion(),
            'categories' => $categories->map(fn ($c) => Presenter::category($c))->all(),
            'dishes' => Dish::where('active', true)->orderBy('sort')->orderBy('name')->get()
                ->map(fn ($d) => Presenter::dish($d))->all(),
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json(['order' => Presenter::order($order)]);
    }

    /** Открыть заказ на столик и отправить на кухню (печатается кухонный чек). */
    public function open(Request $request): JsonResponse
    {
        $data = $this->check($request, $this->itemRules() + [
            'table_id' => 'required|integer',
            'guests' => 'nullable|integer|min:0|max:99',
        ]);
        $table = Table::find($data['table_id']);
        if (! $table) {
            return response()->json(['message' => 'Столик не найден — обновите схему зала'], 404);
        }
        [$order, $replay] = $this->orders->open($this->me($request), $table, $data['items'], $data['comment'] ?? null, $data['guests'] ?? null, $data['client_uuid'] ?? null);

        return response()->json(['order' => Presenter::order($order->fresh()), 'replay' => $replay], $replay ? 200 : 201);
    }

    /** Дозаказ: новые позиции уходят на кухню отдельным чеком. */
    public function add(Request $request, Order $order): JsonResponse
    {
        $data = $this->check($request, $this->itemRules());
        [$order, $replay] = $this->orders->addItems($this->me($request), $order, $data['items'], $data['comment'] ?? null, $data['client_uuid'] ?? null);

        return response()->json(['order' => Presenter::order($order->fresh()), 'replay' => $replay], $replay ? 200 : 201);
    }

    /** Подано: готовые позиции (все или выбранные) отданы гостям. */
    public function serve(Request $request, Order $order): JsonResponse
    {
        $data = $this->check($request, ['item_ids' => 'nullable|array|max:200', 'item_ids.*' => 'integer']);
        $order = $this->orders->serve($order, $data['item_ids'] ?? null, $this->me($request));

        return response()->json(['order' => Presenter::order($order->fresh())]);
    }

    /** Счёт гостю: столик «ждёт счёт», гостевой чек — на принтер (если включён) и на экран. */
    public function bill(Request $request, Order $order): JsonResponse
    {
        $data = $this->check($request, ['print' => 'nullable|boolean']);
        [$order, $receipt] = $this->orders->bill($order, (bool) ($data['print'] ?? true));
        $mode = Settings::current()->printer_mode;

        return response()->json([
            'order' => Presenter::order($order->fresh()),
            'receipt' => $receipt,
            'printer_mode' => $mode,
            'printed' => ($data['print'] ?? true) && $mode !== 'off',
        ]);
    }

    /** Гостевой чек (например, оплаченного заказа) — показать или напечатать с устройства. */
    public function receipt(Order $order): JsonResponse
    {
        return response()->json(['receipt' => $this->orders->guestReceipt($order)]);
    }

    /** Оплата (наличные или карта) и закрытие: заказ попадает в продажи и отчёты. */
    public function pay(Request $request, Order $order): JsonResponse
    {
        $data = $this->check($request, [
            'payment' => ['required', Rule::in(Order::PAYMENTS)],
            'received' => 'nullable|integer|min:0|max:100000000000',
        ]);
        [$order, $replay] = $this->orders->pay($this->me($request), $order, $data['payment'], isset($data['received']) ? (int) $data['received'] : null);

        return response()->json(['order' => Presenter::order($order->fresh()), 'replay' => $replay]);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $data = $this->check($request, ['reason' => 'nullable|string|max:100']);
        $order = $this->orders->cancel($this->me($request), $order, $data['reason'] ?? null);

        return response()->json(['order' => Presenter::order($order->fresh())]);
    }

    public function cancelItem(Request $request, Order $order, OrderItem $item): JsonResponse
    {
        abort_unless($item->order_id === $order->id, 404, 'Позиция не из этого заказа');
        $data = $this->check($request, ['reason' => 'nullable|string|max:100']);
        $order = $this->orders->cancelItem($this->me($request), $item, $data['reason'] ?? null);

        return response()->json(['order' => Presenter::order($order->fresh())]);
    }

    protected function itemRules(): array
    {
        return [
            'items' => 'required|array|min:1|max:100',
            'items.*.dish_id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1|max:99',
            'items.*.comment' => 'nullable|string|max:120',
            'comment' => 'nullable|string|max:200',
            'client_uuid' => 'nullable|uuid',
        ];
    }

    /** Меняется при любой правке категорий и блюд (в том числе стоп-листа). */
    private function menuVersion(): string
    {
        $parts = [];
        foreach ([Category::withTrashed(), Dish::withTrashed()] as $query) {
            $row = $query->toBase()->selectRaw('COUNT(*) as n, MAX(updated_at) as t, MAX(id) as i')->first();
            $parts[] = $row->n.'.'.$row->t.'.'.$row->i;
        }

        return substr(sha1(implode('|', $parts)), 0, 12);
    }
}
