<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кассир ресторана: заказы навынос и банковский терминал кассы.
 *
 * - restaurant_orders.type — table (заказ столика) или takeaway (навынос, без столика). Навынос можно оплатить
 *   заранее: продажа создаётся сразу, а заказ закрывается, когда его выдали гостю;
 * - restaurant_settings.terminal_* — терминал кассы в локальной сети: включён, адрес и порт, тестовый режим
 *   (эмулятор вместо терминала). Как и принтер — настройка этого сервера, между серверами не передаётся;
 * - restaurant_terminal_payments — оплаты картой через терминал: сумма уходит на терминал, заказ закрывается
 *   только после подтверждения (approved). Отказ и отмена оставляют заказ открытым.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $t) {
            $t->string('type', 10)->default('table'); // table | takeaway
            $t->index(['store_id', 'type', 'status']);
        });

        Schema::table('restaurant_settings', function (Blueprint $t) {
            $t->boolean('terminal_enabled')->default(false);
            $t->string('terminal_host', 100)->nullable();
            $t->unsignedInteger('terminal_port')->nullable();
            $t->boolean('terminal_test')->default(false); // эмулятор: имитирует оплату и отказ, денег не списывает
        });

        Schema::create('restaurant_terminal_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('store_id')->index();
            $t->foreignId('order_id')->constrained('restaurant_orders')->cascadeOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained('kassa_employees')->nullOnDelete();
            $t->unsignedBigInteger('amount');
            $t->string('driver', 20); // emulator | json | драйвер конкретного терминала
            $t->string('status', 10)->default('pending'); // pending | approved | declined | cancelled | error
            $t->string('message', 200)->nullable();
            $t->string('reference', 40)->nullable(); // номер операции терминала (RRN)
            $t->string('auth_code', 20)->nullable();
            $t->string('card', 30)->nullable(); // маска карты: •••• 1234
            $t->json('meta')->nullable(); // состояние драйвера (у эмулятора — заданный исход)
            $t->unsignedSmallInteger('errors')->default(0); // неудачные опросы терминала подряд
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['store_id', 'order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_terminal_payments');
        Schema::table('restaurant_settings', function (Blueprint $t) {
            $t->dropColumn(['terminal_enabled', 'terminal_host', 'terminal_port', 'terminal_test']);
        });
        Schema::table('restaurant_orders', function (Blueprint $t) {
            $t->dropIndex(['store_id', 'type', 'status']);
            $t->dropColumn('type');
        });
    }
};
