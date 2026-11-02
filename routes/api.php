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
| Сотрудники входят по PIN и получают Bearer-токен (kassa.auth).
| Все суммы, остатки и отчёты считаются на сервере.
|
*/
Route::prefix('kassa')->name('kassa.')->group(function () {
    Route::get('/options', [KassaAuth::class, 'options'])->middleware('throttle:kassa-public')->name('options');
    Route::post('/setup', [KassaAuth::class, 'setup'])->middleware('throttle:kassa-setup')->name('setup');
    Route::post('/login', [KassaAuth::class, 'login'])->middleware('throttle:kassa-login')->name('login');

    Route::middleware(['kassa.auth', 'throttle:kassa-api'])->group(function () {
        Route::post('/logout', [KassaAuth::class, 'logout'])->name('logout');
        Route::get('/bootstrap', KassaBootstrap::class)->name('bootstrap');

        Route::get('/dashboard', [KassaReports::class, 'dashboard'])->name('dashboard');
        Route::get('/reports', [KassaReports::class, 'show'])->name('reports');
        Route::get('/journal', [KassaReports::class, 'journal'])->name('journal');

        Route::get('/products', [KassaProducts::class, 'index'])->name('products.index');
        Route::get('/products/barcode', [KassaProducts::class, 'barcode'])->name('products.barcode');
        Route::get('/products/{product}', [KassaProducts::class, 'show'])->whereNumber('product')->name('products.show');
        Route::post('/products', [KassaProducts::class, 'store'])->name('products.store');
        Route::put('/products/{product}', [KassaProducts::class, 'update'])->whereNumber('product')->name('products.update');

        Route::get('/sales', [KassaSales::class, 'index'])->name('sales.index');
        Route::get('/sales/{sale}', [KassaSales::class, 'show'])->whereNumber('sale')->name('sales.show');
        Route::post('/sales', [KassaSales::class, 'store'])->name('sales.store');

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
            Route::post('/data/demo', [KassaStore::class, 'demo'])->name('data.demo');
            Route::post('/data/wipe', [KassaStore::class, 'wipe'])->name('data.wipe');
        });
    });
});
