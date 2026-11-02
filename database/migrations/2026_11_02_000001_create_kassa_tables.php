<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Касса и склад» (public/kassa) — отдельный учёт магазина аксессуаров.
 * Деньги храним целыми суммами (узбекский сум без тийинов).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Одна строка: название магазина и счётчики номеров документов.
        Schema::create('kassa_stores', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('address', 120)->nullable();
            $table->boolean('is_demo')->default(false);
            $table->unsignedInteger('next_sale_no')->default(1001);
            $table->unsignedInteger('next_return_no')->default(1);
            $table->unsignedInteger('next_income_no')->default(1);
            $table->unsignedInteger('next_audit_no')->default(1);
            $table->timestamps();
        });

        Schema::create('kassa_employees', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('role', 10); // owner | seller
            $table->string('phone', 20)->nullable();
            $table->string('pin');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('kassa_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('kassa_employees')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('kassa_products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('category', 20)->index();
            $table->char('barcode', 13)->unique();
            $table->unsignedBigInteger('buy_price')->default(0);
            $table->unsignedBigInteger('sell_price');
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('min_stock')->default(0);
            $table->timestamps();
        });

        Schema::create('kassa_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->foreignId('employee_id')->nullable()->constrained('kassa_employees')->nullOnDelete();
            $table->string('customer', 60)->nullable();
            $table->string('payment', 10); // cash | card | qr
            $table->unsignedBigInteger('total');
            $table->unsignedBigInteger('cost_total');
            $table->unsignedBigInteger('received')->nullable();
            $table->unsignedBigInteger('change_amount')->default(0);
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('kassa_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('kassa_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('kassa_products')->nullOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('cost');
        });

        Schema::create('kassa_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->foreignId('sale_id')->constrained('kassa_sales')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('kassa_employees')->nullOnDelete();
            $table->string('payment', 10);
            $table->string('reason', 40);
            $table->unsignedBigInteger('total');
            $table->unsignedBigInteger('cost_total');
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('kassa_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('kassa_returns')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained('kassa_sale_items')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('kassa_products')->nullOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('cost');
        });

        Schema::create('kassa_incomes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->foreignId('employee_id')->nullable()->constrained('kassa_employees')->nullOnDelete();
            $table->string('supplier', 60)->nullable();
            $table->unsignedBigInteger('total');
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('kassa_income_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('income_id')->constrained('kassa_incomes')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('kassa_products')->nullOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('cost');
        });

        Schema::create('kassa_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->foreignId('employee_id')->nullable()->constrained('kassa_employees')->nullOnDelete();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('kassa_audit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained('kassa_audits')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('kassa_products')->nullOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('expected');
            $table->unsignedInteger('actual');
            $table->unsignedBigInteger('cost');
        });
    }

    public function down(): void
    {
        foreach ([
            'kassa_audit_items', 'kassa_audits', 'kassa_income_items', 'kassa_incomes',
            'kassa_return_items', 'kassa_returns', 'kassa_sale_items', 'kassa_sales',
            'kassa_products', 'kassa_tokens', 'kassa_employees', 'kassa_stores',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
