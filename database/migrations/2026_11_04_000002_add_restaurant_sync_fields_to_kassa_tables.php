<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Поля синхронизации с онлайн-сервером в таблицах «Кассы и склада».
 *
 * - uuid — общий идентификатор записи на локальном и онлайн-сервере (id у серверов разные);
 * - продажи, возвраты, приходы и инвентаризации уходят на онлайн-сервер только добавлением:
 *   cloud_synced_at — когда документ принят онлайн-сервером. Колонка названа не synced_at, потому что
 *   kassa_sales.synced_at уже означает «офлайн-чек дошёл с телефона до сервера»;
 * - сотрудники ресторана приходят с онлайн-сервера: version и synced_at — как у справочников меню;
 * - kassa_stores.next_order_no — нумерация заказов ресторана.
 */
return new class extends Migration
{
    /** Документы, которые уходят на онлайн-сервер. */
    private const DOCS = ['kassa_sales', 'kassa_returns', 'kassa_incomes', 'kassa_audits'];

    /** Таблицы, которым нужен uuid. */
    private const WITH_UUID = ['kassa_employees', 'kassa_products', 'kassa_sales', 'kassa_sale_items', 'kassa_returns', 'kassa_incomes', 'kassa_audits'];

    public function up(): void
    {
        Schema::table('kassa_stores', function (Blueprint $t) {
            $t->unsignedInteger('next_order_no')->default(1);
        });

        foreach (self::WITH_UUID as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->uuid('uuid')->nullable()->unique();
            });
        }
        foreach (self::DOCS as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('cloud_synced_at')->nullable();
            });
        }
        Schema::table('kassa_employees', function (Blueprint $t) {
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('synced_at')->nullable();
        });

        // Уже существующим записям — свои uuid.
        foreach (self::WITH_UUID as $table) {
            DB::table($table)->whereNull('uuid')->orderBy('id')->select('id')->chunkById(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('kassa_employees', function (Blueprint $t) {
            $t->dropColumn(['version', 'synced_at']);
        });
        foreach (self::DOCS as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('cloud_synced_at');
            });
        }
        foreach (self::WITH_UUID as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique($table.'_uuid_unique');
                $t->dropColumn('uuid');
            });
        }
        Schema::table('kassa_stores', function (Blueprint $t) {
            $t->dropColumn('next_order_no');
        });
    }
};
