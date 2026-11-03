<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ресторанная версия: меню, столики, заказы и позиции со статусами, кухонные тикеты (отправки на кухню),
 * задания печати, журнал синхронизаций и настройки ресторана. Один магазин (kassa_stores) = один ресторан,
 * у каждой строки store_id. Деньги — целые суммы.
 *
 * Синхронизация с онлайн-сервером:
 *  - справочники (столики, категории, блюда, настройки) — uuid, version (версия на онлайн-сервере),
 *    synced_at (NULL — есть локальные изменения, ещё не отправленные), мягкое удаление;
 *  - заказы — uuid и synced_at: завершённый заказ уходит на онлайн-сервер один раз и там больше не меняется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('store_id')->unique();
            $t->uuid('uuid')->unique();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('synced_at')->nullable();
            // Параметры ресторана — приходят с онлайн-сервера
            $t->unsignedSmallInteger('cook_minutes')->default(15);
            $t->string('receipt_header', 120)->nullable();
            $t->string('receipt_footer', 120)->nullable();
            // Принтер кухни — только этот сервер, не синхронизируется
            $t->string('printer_mode', 10)->default('off'); // off | network | browser
            $t->string('printer_host', 100)->nullable();
            $t->unsignedInteger('printer_port')->default(9100);
            $t->string('printer_codepage', 10)->default('cp866');
            $t->unsignedTinyInteger('printer_width')->default(48);
            // Синхронизация: на локальном сервере — адрес онлайн-сервера и токен (зашифрован),
            // на онлайн-сервере — хеш выданного токена
            $t->string('sync_url', 200)->nullable();
            $t->text('sync_token')->nullable();
            $t->char('sync_token_hash', 64)->nullable()->unique();
            $t->string('sync_token_hint', 12)->nullable();
            $t->timestamp('sync_token_issued_at')->nullable();
            $t->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('store_id')->index();
            $t->uuid('uuid')->unique();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('synced_at')->nullable();
            $t->string('name', 20);
            $t->unsignedSmallInteger('seats')->default(4);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('sort')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('restaurant_categories', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('store_id')->index();
            $t->uuid('uuid')->unique();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('synced_at')->nullable();
            $t->string('name', 40);
            $t->unsignedInteger('sort')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('restaurant_dishes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('store_id')->index();
            $t->uuid('uuid')->unique();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('synced_at')->nullable();
            $t->foreignId('category_id')->nullable()->constrained('restaurant_categories')->nullOnDelete();
            $t->string('name', 80);
            $t->string('description', 300)->nullable(); // состав
            $t->unsignedBigInteger('price');
            $t->boolean('active')->default(true); // есть в меню
            $t->boolean('stop')->default(false);  // стоп-лист: временно нет
            $t->unsignedInteger('sort')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('store_id')->index();
            $t->uuid('uuid')->unique();
            $t->unsignedInteger('number');
            $t->foreignId('table_id')->nullable()->constrained('restaurant_tables')->nullOnDelete();
            $t->string('table_name', 20)->nullable();
            $t->foreignId('waiter_id')->nullable()->constrained('kassa_employees')->nullOnDelete();
            $t->string('waiter_name', 60)->nullable();
            $t->unsignedSmallInteger('guests')->nullable();
            $t->string('comment', 200)->nullable();
            $t->string('status', 10)->default('new'); // new | cooking | ready | served | paid | cancelled
            $t->unsignedBigInteger('total')->default(0);
            $t->string('payment', 10)->nullable(); // cash | card
            $t->unsignedBigInteger('received')->nullable();
            $t->unsignedBigInteger('change_amount')->default(0);
            $t->foreignId('sale_id')->nullable()->constrained('kassa_sales')->nullOnDelete();
            $t->foreignId('closed_by')->nullable()->constrained('kassa_employees')->nullOnDelete();
            $t->timestamp('bill_at')->nullable(); // гость попросил счёт
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 100)->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->index(['store_id', 'status']);
            $t->index(['store_id', 'paid_at']);
            $t->index(['store_id', 'number']);
        });

        // Отправка на кухню: 1 — заказ, 2, 3… — дозаказы. Кухня получает отдельный чек на каждую отправку.
        Schema::create('restaurant_kitchen_tickets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('store_id')->index();
            $t->foreignId('order_id')->constrained('restaurant_orders')->cascadeOnDelete();
            $t->unsignedSmallInteger('batch');
            $t->foreignId('employee_id')->nullable()->constrained('kassa_employees')->nullOnDelete();
            $t->string('comment', 200)->nullable();
            $t->uuid('client_uuid')->nullable()->unique(); // повтор отправки с устройства не создаёт второй тикет
            $t->timestamp('sent_at');
            $t->timestamps();
            $t->unique(['order_id', 'batch']);
        });

        Schema::create('restaurant_order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('store_id')->index();
            $t->foreignId('order_id')->constrained('restaurant_orders')->cascadeOnDelete();
            $t->foreignId('ticket_id')->nullable()->constrained('restaurant_kitchen_tickets')->nullOnDelete();
            $t->unsignedSmallInteger('batch')->default(1);
            $t->foreignId('dish_id')->nullable()->constrained('restaurant_dishes')->nullOnDelete();
            $t->string('name', 80);
            $t->unsignedBigInteger('price');
            $t->unsignedInteger('qty');
            $t->string('comment', 120)->nullable();
            $t->string('status', 10)->default('new'); // new | cooking | ready | served | cancelled
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('cooking_at')->nullable();
            $t->timestamp('ready_at')->nullable();
            $t->timestamp('served_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['store_id', 'status']);
        });

        Schema::create('restaurant_print_jobs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('store_id')->index();
            $t->foreignId('order_id')->nullable()->constrained('restaurant_orders')->nullOnDelete();
            $t->foreignId('ticket_id')->nullable()->constrained('restaurant_kitchen_tickets')->nullOnDelete();
            $t->string('kind', 10); // kitchen | cancel | guest | test
            $t->string('title', 80);
            $t->json('document'); // чек: строки со стилями, из него строятся ESC/POS и страница для браузера
            $t->string('status', 10)->default('pending'); // pending | printing | done | failed
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->string('last_error', 300)->nullable();
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('locked_until')->nullable();
            $t->timestamp('printed_at')->nullable();
            $t->string('printed_via', 10)->nullable(); // network | browser
            $t->timestamps();
            $t->index(['store_id', 'status']);
        });

        Schema::create('restaurant_sync_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('store_id')->index();
            $t->string('direction', 10); // sync — запуск на локальном сервере; push | pull — запрос, принятый онлайн-сервером
            $t->string('trigger', 10)->nullable(); // schedule | retry | manual | remote
            $t->string('status', 10); // running | ok | failed
            $t->json('sent')->nullable();
            $t->json('received')->nullable();
            $t->text('error')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'restaurant_sync_logs', 'restaurant_print_jobs', 'restaurant_order_items', 'restaurant_kitchen_tickets',
            'restaurant_orders', 'restaurant_dishes', 'restaurant_categories', 'restaurant_tables', 'restaurant_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
