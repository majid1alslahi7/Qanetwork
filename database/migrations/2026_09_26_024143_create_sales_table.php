<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('seller_id')
                ->constrained('sellers')
                ->restrictOnDelete();

            $table->foreignUlid('seller_wallet_id')
                ->constrained('seller_wallets')
                ->restrictOnDelete();

            $table->foreignUlid('network_id')
                ->constrained('networks')
                ->restrictOnDelete();

            $table->foreignUlid('network_product_id')
                ->constrained('network_products')
                ->restrictOnDelete();

            // رقم مفهوم للبحث والدعم وخدمة العملاء.
            $table->string('reference_no', 50)->unique();

            /*
             * يمنع إنشاء عمليتي بيع لنفس طلب العميل
             * عند إعادة المحاولة.
             */
            $table->string('idempotency_key', 191)->unique();

            /*
             * created
             * validating
             * balance_reserved
             * processing_provider
             * provider_confirmed
             * accounting_posted
             * completed
             * failed
             * timeout
             * unknown_provider_state
             * reconciliation_required
             * reversed
             */
            $table->string('status', 40)->default('created');

            $table->char('currency_code', 3)->default('YER');

            // طريقة تسليم الكرت.
            $table->string('delivery_method', 30)->default('screen');

            // رقم المستلم عند اختيار SMS.
            $table->string('customer_phone', 30)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('provider_confirmed_at')->nullable();
            $table->timestamp('accounting_posted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();

            $table->timestamps();

            $table->index(
                ['seller_id', 'created_at'],
                'sales_seller_created_idx'
            );

            $table->index(
                ['network_id', 'created_at'],
                'sales_network_created_idx'
            );

            $table->index(
                ['network_product_id', 'created_at'],
                'sales_product_created_idx'
            );

            $table->index(
                ['status', 'created_at'],
                'sales_status_created_idx'
            );

            $table->index(
                ['network_id', 'status', 'created_at'],
                'sales_network_status_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
