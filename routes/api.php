<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\Kassa\AuditController as KassaAudits;
use App\Http\Controllers\Api\Kassa\AuthController as KassaAuth;
use App\Http\Controllers\Api\Kassa\BootstrapController as KassaBootstrap;
use App\Http\Controllers\Api\Kassa\EmployeeController as KassaEmployees;
use App\Http\Controllers\Api\Kassa\IncomeController as KassaIncomes;
use App\Http\Controllers\Api\Kassa\ProductController as KassaProducts;
use App\Http\Controllers\Api\Kassa\RefundController as KassaRefunds;
use App\Http\Controllers\Api\Kassa\ReportController as KassaReports;
use App\Http\Controllers\Api\Kassa\SaleController as KassaSales;
use App\Http\Controllers\Api\Kassa\StoreController as KassaStore;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\PosApiController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\Restaurant\Admin\DashboardController as RestaurantDashboard;
use App\Http\Controllers\Api\Restaurant\Admin\MenuController as RestaurantMenu;
use App\Http\Controllers\Api\Restaurant\Admin\ReportController as RestaurantReports;
use App\Http\Controllers\Api\Restaurant\Admin\SettingsController as RestaurantSettings;
use App\Http\Controllers\Api\Restaurant\Admin\StaffController as RestaurantStaff;
use App\Http\Controllers\Api\Restaurant\Admin\SyncController as RestaurantSync;
use App\Http\Controllers\Api\Restaurant\Admin\TableController as RestaurantTables;
use App\Http\Controllers\Api\Restaurant\AuthController as RestaurantAuth;
use App\Http\Controllers\Api\Restaurant\CashierController as RestaurantCashier;
use App\Http\Controllers\Api\Restaurant\KitchenController as RestaurantKitchen;
use App\Http\Controllers\Api\Restaurant\SyncServerController as RestaurantSyncServer;
use App\Http\Controllers\Api\Restaurant\WaiterController as RestaurantWaiter;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\WarehouseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes v1
|--------------------------------------------------------------------------
|
| Token-based (Sanctum) REST API. All responses JSON.
| Public: auth endpoints + webhooks. Everything else requires Bearer token.
|
*/

// Public webhooks (payment gateways) — signature verified, no auth
Route::prefix('webhooks')->group(function () {
    Route::post('/midtrans', [PaymentWebhookController::class, 'midtrans'])->name('webhooks.midtrans');
    Route::post('/xendit', [PaymentWebhookController::class, 'xendit'])->name('webhooks.xendit');
});

Route::prefix('v1')->group(function () {
    // Auth (public)
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('api.auth.login');
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,10')->name('api.auth.register');
    });

    // Protected endpoints (Sanctum token required)
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::prefix('auth')->group(function () {
            Route::get('/me', [AuthController::class, 'me'])->name('api.auth.me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
        });

        // Master data — Sanctum abilities mirror Spatie permission names stamped on the token at login
        Route::apiResource('products', ProductController::class)
            ->middlewareFor(['index', 'show'], 'abilities:products-access')
            ->middlewareFor('store', 'abilities:products-create')
            ->middlewareFor('update', 'abilities:products-edit')
            ->middlewareFor('destroy', 'abilities:products-delete')
            ->names([
                'index' => 'api.products.index',
                'store' => 'api.products.store',
                'show' => 'api.products.show',
                'update' => 'api.products.update',
                'destroy' => 'api.products.destroy',
            ]);

        Route::apiResource('customers', CustomerController::class)
            ->middlewareFor(['index', 'show'], 'abilities:customers-access')
            ->middlewareFor('store', 'abilities:customers-create')
            ->middlewareFor('update', 'abilities:customers-edit')
            ->middlewareFor('destroy', 'abilities:customers-delete')
            ->names([
                'index' => 'api.customers.index',
                'store' => 'api.customers.store',
                'show' => 'api.customers.show',
                'update' => 'api.customers.update',
                'destroy' => 'api.customers.destroy',
            ]);

        Route::apiResource('categories', CategoryController::class)
            ->middlewareFor(['index', 'show'], 'abilities:categories-access')
            ->middlewareFor('store', 'abilities:categories-create')
            ->middlewareFor('update', 'abilities:categories-edit')
            ->middlewareFor('destroy', 'abilities:categories-delete')
            ->names([
                'index' => 'api.categories.index',
                'store' => 'api.categories.store',
                'show' => 'api.categories.show',
                'update' => 'api.categories.update',
                'destroy' => 'api.categories.destroy',
            ]);

        Route::apiResource('warehouses', WarehouseController::class)
            ->middlewareFor(['index', 'show'], 'abilities:warehouses-access')
            ->middlewareFor('store', 'abilities:warehouses-create')
            ->middlewareFor('update', 'abilities:warehouses-update')
            ->middlewareFor('destroy', 'abilities:warehouses-delete')
            ->names([
                'index' => 'api.warehouses.index',
                'store' => 'api.warehouses.store',
                'show' => 'api.warehouses.show',
                'update' => 'api.warehouses.update',
                'destroy' => 'api.warehouses.destroy',
            ]);

        Route::apiResource('suppliers', SupplierController::class)
            ->middlewareFor(['index', 'show'], 'abilities:suppliers-access')
            ->middlewareFor('store', 'abilities:suppliers-access')
            ->middlewareFor('update', 'abilities:suppliers-access')
            ->middlewareFor('destroy', 'abilities:suppliers-access')
            ->names([
                'index' => 'api.suppliers.index',
                'store' => 'api.suppliers.store',
                'show' => 'api.suppliers.show',
                'update' => 'api.suppliers.update',
                'destroy' => 'api.suppliers.destroy',
            ]);

        // POS (mobile kasir) — requires the pos-access ability
        Route::prefix('pos')->middleware('abilities:pos-access')->group(function () {
            // Shift
            Route::get('/shift', [PosApiController::class, 'currentShift'])->name('api.pos.shift');
            Route::post('/shift/open', [PosApiController::class, 'openShift'])->name('api.pos.shift.open');
            Route::post('/shift/close', [PosApiController::class, 'closeShift'])->name('api.pos.shift.close');

            // Products & scan
            Route::get('/products', [PosApiController::class, 'products'])->name('api.pos.products');
            Route::post('/products/scan', [PosApiController::class, 'scan'])->name('api.pos.products.scan');

            // Cart
            Route::get('/cart', [PosApiController::class, 'cart'])->name('api.pos.cart');
            Route::post('/cart', [PosApiController::class, 'addToCart'])->name('api.pos.cart.add');
            Route::put('/cart/{cart}', [PosApiController::class, 'updateCart'])->name('api.pos.cart.update');
            Route::delete('/cart/{cart}', [PosApiController::class, 'removeFromCart'])->name('api.pos.cart.remove');
            Route::delete('/cart', [PosApiController::class, 'clearCart'])->name('api.pos.cart.clear');

            // Hold / resume
            Route::post('/hold', [PosApiController::class, 'holdCart'])->name('api.pos.hold');
            Route::get('/holds', [PosApiController::class, 'heldCarts'])->name('api.pos.holds');
            Route::post('/holds/{holdId}/resume', [PosApiController::class, 'resumeHold'])->name('api.pos.holds.resume');
            Route::delete('/holds/{holdId}', [PosApiController::class, 'deleteHold'])->name('api.pos.holds.delete');

            // Checkout & transactions
            Route::post('/checkout', [PosApiController::class, 'checkout'])->name('api.pos.checkout');
            Route::post('/transactions/sync', [PosApiController::class, 'syncTransactions'])->name('api.pos.transactions.sync');
            Route::get('/transactions', [PosApiController::class, 'transactions'])->name('api.pos.transactions');
            Route::get('/transactions/{transaction}', [PosApiController::class, 'transactionDetail'])->name('api.pos.transactions.show');
        });
    });
});

/*
|--------------------------------------------------------------------------
| «Касса и склад» (public/kassa) — /api/kassa
|--------------------------------------------------------------------------
|
| Сотрудники входят по PIN и получают Bearer-токен (kassa.auth), который помнит магазин.
| Данные каждого магазина изолированы (StoreScope). Все суммы, остатки, отчёты и прогнозы считаются на сервере.
|
*/
Route::prefix('kassa')->name('kassa.')->group(function () {
    Route::get('/options', [KassaAuth::class, 'options'])->middleware('throttle:kassa-public')->name('options');
    Route::post('/setup', [KassaAuth::class, 'setup'])->middleware('throttle:kassa-setup')->name('setup');
    Route::post('/login', [KassaAuth::class, 'login'])->middleware('throttle:kassa-login')->name('login');

    // официант и повар работают в своих приложениях ресторана — в кассу их не пускает kassa.app:kassa
    Route::middleware(['kassa.auth', 'kassa.app:kassa', 'throttle:kassa-api'])->group(function () {
        Route::post('/logout', [KassaAuth::class, 'logout'])->name('logout');
        Route::get('/ping', [KassaAuth::class, 'ping'])->name('ping');
        Route::get('/bootstrap', KassaBootstrap::class)->name('bootstrap');

        Route::get('/dashboard', [KassaReports::class, 'dashboard'])->name('dashboard');
        Route::get('/reports', [KassaReports::class, 'show'])->name('reports');
        Route::get('/journal', [KassaReports::class, 'journal'])->name('journal');
        Route::get('/forecasts', [KassaReports::class, 'forecasts'])->name('forecasts');

        Route::get('/products', [KassaProducts::class, 'index'])->name('products.index');
        Route::get('/products/barcode', [KassaProducts::class, 'barcode'])->name('products.barcode');
        Route::get('/products/{product}', [KassaProducts::class, 'show'])->whereNumber('product')->name('products.show');
        Route::post('/products', [KassaProducts::class, 'store'])->name('products.store');
        Route::put('/products/{product}', [KassaProducts::class, 'update'])->whereNumber('product')->name('products.update');

        Route::get('/sales', [KassaSales::class, 'index'])->name('sales.index');
        Route::get('/sales/{sale}', [KassaSales::class, 'show'])->whereNumber('sale')->name('sales.show');
        Route::post('/sales', [KassaSales::class, 'store'])->name('sales.store');
        Route::post('/sales/sync', [KassaSales::class, 'sync'])->name('sales.sync');

        Route::get('/returns', [KassaRefunds::class, 'index'])->name('returns.index');
        Route::get('/returns/{refund}', [KassaRefunds::class, 'show'])->whereNumber('refund')->name('returns.show');
        Route::post('/returns', [KassaRefunds::class, 'store'])->name('returns.store');

        Route::get('/incomes', [KassaIncomes::class, 'index'])->name('incomes.index');
        Route::get('/incomes/{income}', [KassaIncomes::class, 'show'])->whereNumber('income')->name('incomes.show');
        Route::post('/incomes', [KassaIncomes::class, 'store'])->name('incomes.store');

        Route::get('/audits', [KassaAudits::class, 'index'])->name('audits.index');
        Route::get('/audits/{audit}', [KassaAudits::class, 'show'])->whereNumber('audit')->name('audits.show');
        Route::post('/audits', [KassaAudits::class, 'store'])->name('audits.store');

        Route::get('/employees', [KassaEmployees::class, 'index'])->name('employees.index');
        Route::get('/employees/{employee}', [KassaEmployees::class, 'show'])->whereNumber('employee')->name('employees.show');

        Route::middleware('kassa.owner')->group(function () {
            Route::delete('/products/{product}', [KassaProducts::class, 'destroy'])->whereNumber('product')->name('products.destroy');
            Route::post('/employees', [KassaEmployees::class, 'store'])->name('employees.store');
            Route::put('/employees/{employee}', [KassaEmployees::class, 'update'])->whereNumber('employee')->name('employees.update');
            Route::post('/employees/{employee}/toggle', [KassaEmployees::class, 'toggle'])->whereNumber('employee')->name('employees.toggle');
            Route::put('/store', [KassaStore::class, 'update'])->name('store.update');
            Route::get('/stores', [KassaStore::class, 'index'])->name('stores.index');
            Route::post('/stores', [KassaStore::class, 'create'])->name('stores.store');
            Route::post('/stores/{store}/switch', [KassaStore::class, 'switch'])->whereNumber('store')->name('stores.switch');
            Route::post('/data/demo', [KassaStore::class, 'demo'])->name('data.demo');
            Route::post('/data/wipe', [KassaStore::class, 'wipe'])->name('data.wipe');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Ресторан — /api/restaurant (приложения /admin, /waiter, /cashier, /kitchen)
|--------------------------------------------------------------------------
|
| Вход — тот же PIN и тот же токен, что в кассе (kassa.auth). Доступ по роли (kassa.app):
| админка — владелец и администратор; официант — официант и администратор; кассир — кассир и администратор;
| кухня — повар и администратор. Экран кухни только показывает заказы: отметки «готовится / готово / подано»
| ставят официант, кассир и админка. У кассира — заказы навынос и оплата через банковский терминал.
| Синхронизация (sync/*) — только на онлайн-сервере, по sync-токену ресторана (restaurant.sync).
|
*/
// Зал: столики, заказы, отметки статусов, счёт и оплата — одинаково у официанта и кассира
$floor = function (string $controller) {
    Route::get('/state', [$controller, 'state'])->name('state');
    Route::get('/menu', [$controller, 'menu'])->name('menu');
    Route::post('/orders', [$controller, 'open'])->name('orders.open');
    Route::get('/orders/{order}', [$controller, 'show'])->whereNumber('order')->name('orders.show');
    Route::post('/orders/{order}/items', [$controller, 'add'])->whereNumber('order')->name('orders.add');
    Route::post('/orders/{order}/items/{item}/cancel', [$controller, 'cancelItem'])->whereNumber(['order', 'item'])->name('orders.items.cancel');
    Route::post('/orders/{order}/status', [$controller, 'status'])->whereNumber('order')->name('orders.status');
    Route::post('/orders/{order}/serve', [$controller, 'serve'])->whereNumber('order')->name('orders.serve');
    Route::post('/orders/{order}/tickets/{ticket}/reprint', [$controller, 'reprint'])->whereNumber(['order', 'ticket'])->name('orders.reprint');
    Route::post('/orders/{order}/bill', [$controller, 'bill'])->whereNumber('order')->name('orders.bill');
    Route::get('/orders/{order}/receipt', [$controller, 'receipt'])->whereNumber('order')->name('orders.receipt');
    Route::post('/orders/{order}/pay', [$controller, 'pay'])->whereNumber('order')->name('orders.pay');
    Route::post('/orders/{order}/cancel', [$controller, 'cancel'])->whereNumber('order')->name('orders.cancel');
};

Route::prefix('restaurant')->name('restaurant.')->group(function () use ($floor) {
    Route::get('/options', [RestaurantAuth::class, 'options'])->middleware('throttle:kassa-public')->name('options');
    Route::post('/login', [RestaurantAuth::class, 'login'])->middleware('throttle:kassa-login')->name('login');

    Route::prefix('sync')->name('sync.')->middleware(['throttle:restaurant-sync', 'restaurant.sync'])->group(function () {
        Route::get('/ping', [RestaurantSyncServer::class, 'ping'])->name('ping');
        Route::post('/push', [RestaurantSyncServer::class, 'push'])->name('push');
        Route::get('/pull', [RestaurantSyncServer::class, 'pull'])->name('pull');
    });

    Route::middleware(['kassa.auth', 'throttle:kassa-api'])->group(function () use ($floor) {
        Route::post('/logout', [RestaurantAuth::class, 'logout'])->name('logout');
        Route::get('/me', [RestaurantAuth::class, 'whoami'])->name('me');

        Route::prefix('waiter')->name('waiter.')->middleware('kassa.app:waiter')->group(fn () => $floor(RestaurantWaiter::class));

        Route::prefix('cashier')->name('cashier.')->middleware('kassa.app:cashier')->group(function () use ($floor) {
            $floor(RestaurantCashier::class);
            Route::post('/takeaway', [RestaurantCashier::class, 'takeaway'])->name('takeaway');
            Route::post('/orders/{order}/handout', [RestaurantCashier::class, 'handout'])->whereNumber('order')->name('orders.handout');
            Route::post('/orders/{order}/terminal', [RestaurantCashier::class, 'terminalStart'])->whereNumber('order')->name('terminal.start');
            Route::get('/terminal/{payment}', [RestaurantCashier::class, 'terminalShow'])->whereNumber('payment')->name('terminal.show');
            Route::post('/terminal/{payment}/cancel', [RestaurantCashier::class, 'terminalCancel'])->whereNumber('payment')->name('terminal.cancel');
            Route::post('/terminal/{payment}/emulate', [RestaurantCashier::class, 'terminalEmulate'])->whereNumber('payment')->name('terminal.emulate');
        });

        // Кухня — пассивный дисплей: доска заказов и печать чеков из браузера
        Route::prefix('kitchen')->name('kitchen.')->middleware('kassa.app:kitchen')->group(function () {
            Route::get('/board', [RestaurantKitchen::class, 'board'])->name('board');
            Route::post('/print-jobs/{job}/claim', [RestaurantKitchen::class, 'claimPrint'])->whereNumber('job')->name('print.claim');
        });

        Route::prefix('admin')->name('admin.')->middleware('kassa.app:admin')->group(function () {
            Route::get('/dashboard', [RestaurantDashboard::class, 'show'])->name('dashboard');
            Route::get('/orders', [RestaurantDashboard::class, 'orders'])->name('orders.index');
            Route::get('/orders/{order}', [RestaurantDashboard::class, 'order'])->whereNumber('order')->name('orders.show');
            Route::post('/orders/{order}/status', [RestaurantDashboard::class, 'status'])->whereNumber('order')->name('orders.status');
            Route::post('/orders/{order}/handout', [RestaurantDashboard::class, 'handout'])->whereNumber('order')->name('orders.handout');
            Route::post('/orders/{order}/tickets/{ticket}/reprint', [RestaurantDashboard::class, 'reprint'])->whereNumber(['order', 'ticket'])->name('orders.reprint');

            Route::get('/menu', [RestaurantMenu::class, 'index'])->name('menu');
            Route::post('/categories', [RestaurantMenu::class, 'storeCategory'])->name('categories.store');
            Route::put('/categories/{category}', [RestaurantMenu::class, 'updateCategory'])->whereNumber('category')->name('categories.update');
            Route::delete('/categories/{category}', [RestaurantMenu::class, 'destroyCategory'])->whereNumber('category')->name('categories.destroy');
            Route::post('/dishes', [RestaurantMenu::class, 'storeDish'])->name('dishes.store');
            Route::put('/dishes/{dish}', [RestaurantMenu::class, 'updateDish'])->whereNumber('dish')->name('dishes.update');
            Route::delete('/dishes/{dish}', [RestaurantMenu::class, 'destroyDish'])->whereNumber('dish')->name('dishes.destroy');
            Route::post('/dishes/{dish}/stop', [RestaurantMenu::class, 'stop'])->whereNumber('dish')->name('dishes.stop');

            Route::get('/tables', [RestaurantTables::class, 'index'])->name('tables.index');
            Route::post('/tables', [RestaurantTables::class, 'store'])->name('tables.store');
            Route::put('/tables/{table}', [RestaurantTables::class, 'update'])->whereNumber('table')->name('tables.update');
            Route::delete('/tables/{table}', [RestaurantTables::class, 'destroy'])->whereNumber('table')->name('tables.destroy');

            Route::get('/staff', [RestaurantStaff::class, 'index'])->name('staff.index');
            Route::post('/staff', [RestaurantStaff::class, 'store'])->name('staff.store');
            Route::put('/staff/{employee}', [RestaurantStaff::class, 'update'])->whereNumber('employee')->name('staff.update');
            Route::post('/staff/{employee}/toggle', [RestaurantStaff::class, 'toggle'])->whereNumber('employee')->name('staff.toggle');

            Route::get('/reports', [RestaurantReports::class, 'show'])->name('reports');

            Route::get('/settings', [RestaurantSettings::class, 'show'])->name('settings');
            Route::put('/settings/restaurant', [RestaurantSettings::class, 'updateRestaurant'])->name('settings.restaurant');
            Route::put('/settings/printer', [RestaurantSettings::class, 'updatePrinter'])->name('settings.printer');
            Route::post('/settings/printer/test', [RestaurantSettings::class, 'testPrint'])->name('settings.printer.test');
            Route::put('/settings/terminal', [RestaurantSettings::class, 'updateTerminal'])->name('settings.terminal');
            Route::post('/settings/terminal/test', [RestaurantSettings::class, 'testTerminal'])->name('settings.terminal.test');
            Route::get('/print-jobs', [RestaurantSettings::class, 'printJobs'])->name('print-jobs.index');
            Route::post('/print-jobs/{job}/retry', [RestaurantSettings::class, 'retryJob'])->whereNumber('job')->name('print-jobs.retry');

            Route::get('/sync', [RestaurantSync::class, 'show'])->name('sync');
            Route::put('/sync', [RestaurantSync::class, 'update'])->name('sync.update');
            Route::post('/sync/ping', [RestaurantSync::class, 'ping'])->name('sync.ping');
            Route::post('/sync/run', [RestaurantSync::class, 'run'])->name('sync.run');
            Route::post('/sync/token', [RestaurantSync::class, 'issueToken'])->name('sync.token');
            Route::delete('/sync/token', [RestaurantSync::class, 'revokeToken'])->name('sync.token.revoke');
        });
    });
});
