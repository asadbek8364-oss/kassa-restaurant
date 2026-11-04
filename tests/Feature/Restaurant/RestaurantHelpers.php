<?php

namespace Tests\Feature\Restaurant;

use App\Models\Kassa\Employee;
use App\Models\Kassa\Store;
use App\Models\Kassa\Token;
use App\Models\Restaurant\Category;
use App\Models\Restaurant\Dish;
use App\Models\Restaurant\Settings;
use App\Models\Restaurant\Table;
use App\Services\Restaurant\Printing\FakePrinterTransport;
use App\Services\Restaurant\Printing\PrinterTransport;
use Illuminate\Testing\TestResponse;

/**
 * Ресторан для тестов: администратор, официант, кассир и повар, меню (плов, лагман, чай), два столика.
 * PIN-коды — «сложные», чтобы проходили правила кассы.
 */
trait RestaurantHelpers
{
    protected Store $store;

    protected Employee $admin;

    protected Employee $waiter;

    protected Employee $cook;

    protected Employee $cashier;

    protected Category $hot;

    protected Dish $plov;

    protected Dish $lagman;

    protected Dish $tea;

    protected Table $t1;

    protected Table $t2;

    protected FakePrinterTransport $printer;

    protected function setUpRestaurant(): void
    {
        $this->printer = new FakePrinterTransport;
        $this->app->instance(PrinterTransport::class, $this->printer);

        $this->store = Store::current();
        $this->store->update(['name' => 'Тестовый ресторан']);
        $this->admin = Employee::create(['name' => 'Дилшод Каримов', 'role' => 'admin', 'pin' => '5728']);
        $this->waiter = Employee::create(['name' => 'Азиз Рахимов', 'role' => 'waiter', 'pin' => '2846']);
        $this->cook = Employee::create(['name' => 'Шахзода Юсупова', 'role' => 'cook', 'pin' => '3957']);
        $this->cashier = Employee::create(['name' => 'Малика Турсунова', 'role' => 'cashier', 'pin' => '4862']);

        $this->hot = Category::create(['name' => 'Горячее', 'sort' => 10]);
        $drinks = Category::create(['name' => 'Напитки', 'sort' => 20]);
        $this->plov = Dish::create(['category_id' => $this->hot->id, 'name' => 'Плов чайханский', 'price' => 45000]);
        $this->lagman = Dish::create(['category_id' => $this->hot->id, 'name' => 'Лагман', 'price' => 40000]);
        $this->tea = Dish::create(['category_id' => $drinks->id, 'name' => 'Чай зелёный', 'price' => 8000]);
        $this->t1 = Table::create(['name' => '1', 'seats' => 4, 'sort' => 10]);
        $this->t2 = Table::create(['name' => '2', 'seats' => 6, 'sort' => 20]);
    }

    /** Войти сотрудником (токен выдаётся напрямую, как после входа по PIN). */
    protected function actAs(Employee $employee): static
    {
        $this->withToken(Token::issue($employee, $this->store));

        return $this;
    }

    protected function printerMode(string $mode, array $extra = []): Settings
    {
        $settings = Settings::current();
        $settings->update(['printer_mode' => $mode, 'printer_host' => '192.168.1.50', 'printer_port' => 9100] + $extra);

        return $settings;
    }

    protected function openOrder(Table $table, array $items, array $extra = []): TestResponse
    {
        return $this->postJson('/api/restaurant/waiter/orders', ['table_id' => $table->id, 'items' => $items] + $extra);
    }

    protected function line(Dish $dish, int $qty = 1, ?string $comment = null): array
    {
        return ['dish_id' => $dish->id, 'qty' => $qty, 'comment' => $comment];
    }

    /**
     * Отметка вместо кухонного экрана (он только показывает заказы): официант, кассир или админка ставят
     * «готовится», «готово», «подано» всему заказу, одной отправке или позициям.
     */
    protected function mark(array $order, string $status, array $extra = [], string $app = 'waiter'): TestResponse
    {
        return $this->postJson("/api/restaurant/{$app}/orders/{$order['id']}/status", ['status' => $status] + $extra);
    }

    /** Карточки доски кухни по колонкам: column => [card, …]. */
    protected function board(): array
    {
        $cards = $this->getJson('/api/restaurant/kitchen/board')->assertOk()->json('cards');
        $by = ['new' => [], 'cooking' => [], 'ready' => []];
        foreach ($cards as $card) {
            $by[$card['column']][] = $card;
        }

        return $by;
    }

    /** Столик на схеме зала официанта. */
    protected function tableState(Table $table): array
    {
        return collect($this->getJson('/api/restaurant/waiter/state')->assertOk()->json('tables'))->firstWhere('id', $table->id);
    }
}
