<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_financials', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('sale_id')
                ->constrained('sales')
                ->restrictOnDelete();

            /*
             * Snapshot مالي ثابت وقت البيع.
             * تغيير الأسعار مستقبلاً لا يغير هذه القيم.
             */
            $table->decimal('face_value', 20, 4);
            $table->decimal('provider_amount', 20, 4);
            $table->decimal('seller_commission', 20, 4);
            $table->decimal('platform_commission', 20, 4);
            $table->decimal('seller_net_amount', 20, 4);

            $table->char('currency_code', 3);

            /*
             * سنربط هذه الحقول بقواعد التسعير لاحقاً.
             * ULID محفوظ الآن بدون FK حتى ننشئ جداول
             * pricing/commission rules.
             */
            $table->ulid('pricing_rule_id')->nullable();
            $table->ulid('commission_rule_id')->nullable();

            $table->timestamps();

            // كل عملية بيع لها Snapshot مالي واحد فقط.
            $table->unique('sale_id');

            $table->index(
                ['currency_code', 'created_at'],
                'sale_financial_currency_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_financials');
    }
};
