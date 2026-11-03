<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Касса и склад»: несколько магазинов и офлайн-чеки.
 *
 * - Каждая строка товаров, документов и их позиций принадлежит магазину (store_id).
 *   Владельцы общие для всех магазинов (store_id = NULL), продавцы — сотрудники одного магазина.
 *   Токен входа помнит, в каком магазине сейчас работает сотрудник.
 * - Номера документов и штрих-коды уникальны в пределах магазина.
 * - Чек, пробитый без связи: synced_at — когда он дошёл до сервера, client_total — сумма на устройстве,
 *   shortage в позиции — сколько штук не нашлось на складе при синхронизации.
 */
return new class extends Migration
{
    /** Таблицы с данными магазина. */
    private const STORE_TABLES = [
        'kassa_products', 'kassa_sales', 'kassa_sale_items', 'kassa_returns', 'kassa_return_items',
        'kassa_incomes', 'kassa_income_items', 'kassa_audits', 'kassa_audit_items',
    ];

    /** Документы с номером: номер уникален внутри магазина. */
    private const NUMBERED = ['kassa_sales', 'kassa_returns', 'kassa_incomes', 'kassa_audits'];

    public function up(): void
    {
        foreach ([...self::STORE_TABLES, 'kassa_employees', 'kassa_tokens'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('store_id')->nullable()->index();
            });
        }

        Schema::table('kassa_sales', function (Blueprint $t) {
            $t->timestamp('synced_at')->nullable();
            $t->unsignedBigInteger('client_total')->nullable();
        });
        Schema::table('kassa_sale_items', function (Blueprint $t) {
            $t->unsignedInteger('shortage')->default(0);
        });

        // Всё, что было до этой миграции, относится к первому (единственному) магазину.
        $hasData = DB::table('kassa_employees')->exists() || DB::table('kassa_products')->exists();
        $storeId = DB::table('kassa_stores')->orderBy('id')->value('id');
        if (! $storeId && $hasData) {
            $storeId = DB::table('kassa_stores')->insertGetId(['name' => 'Мой магазин', 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($storeId) {
            foreach ([...self::STORE_TABLES, 'kassa_tokens'] as $table) {
                DB::table($table)->update(['store_id' => $storeId]);
            }
            DB::table('kassa_employees')->where('role', '!=', 'owner')->update(['store_id' => $storeId]);
        }

        Schema::table('kassa_products', function (Blueprint $t) {
            $t->dropUnique(['barcode']);
            $t->unique(['store_id', 'barcode']);
        });
        foreach (self::NUMBERED as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropUnique(['number']);
                $t->unique(['store_id', 'number']);
            });
        }
        Schema::table('kassa_sales', function (Blueprint $t) {
            $t->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('kassa_sales', function (Blueprint $t) {
            $t->dropIndex(['store_id', 'created_at']);
        });
        foreach (self::NUMBERED as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropUnique(['store_id', 'number']);
                $t->unique(['number']);
            });
        }
        Schema::table('kassa_products', function (Blueprint $t) {
            $t->dropUnique(['store_id', 'barcode']);
            $t->unique(['barcode']);
        });

        Schema::table('kassa_sale_items', function (Blueprint $t) {
            $t->dropColumn('shortage');
        });
        Schema::table('kassa_sales', function (Blueprint $t) {
            $t->dropColumn(['synced_at', 'client_total']);
        });
        foreach ([...self::STORE_TABLES, 'kassa_employees', 'kassa_tokens'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex($table.'_store_id_index');
                $t->dropColumn('store_id');
            });
        }
    }
};
