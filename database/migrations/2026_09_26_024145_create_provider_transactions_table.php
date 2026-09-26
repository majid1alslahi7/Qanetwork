<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('sale_id')
                ->constrained('sales')
                ->restrictOnDelete();

            $table->foreignUlid('network_connection_id')
                ->constrained('network_connections')
                ->restrictOnDelete();

            /*
             * معرف QaNetwork الذي نرسله للمزود متى كان
             * المزود يدعم client transaction reference.
             */
            $table->string('internal_transaction_id', 100)->unique();

            /*
             * مفتاح ثابت لنفس محاولة الشراء.
             * Retry لا يولد عملية شراء جديدة.
             */
            $table->string('idempotency_key', 191)->unique();

            // معرف العملية الذي يعيده المزود.
            $table->string('provider_transaction_id', 191)->nullable();

            /*
             * created
             * processing
             * confirmed
             * failed
             * timeout
             * unknown
             * reconciliation_required
             */
            $table->string('status', 40)->default('created');

            $table->unsignedInteger('attempt_count')->default(0);

            $table->timestamp('request_started_at')->nullable();
            $table->timestamp('response_received_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();

            $table->string('provider_status', 100)->nullable();
            $table->string('error_code', 100)->nullable();

            /*
             * رسالة مختصرة منقحة فقط.
             * لا نخزن response الخام هنا لأن الرد قد
             * يحتوي username/password/token للكرت.
             */
            $table->text('error_message')->nullable();

            $table->timestamps();

            /*
             * في المرحلة الحالية: محاولة Provider واحدة
             * فعالة لكل Sale.
             * إذا أضفنا failover لاحقاً نعيد تصميم هذا
             * القيد مع مفهوم attempt sequence.
             */
            $table->unique(
                'sale_id',
                'provider_tx_sale_unique'
            );

            /*
             * معرف المزود قد يتكرر بين مزودين مختلفين،
             * لذلك uniqueness مرتبط بالاتصال.
             */
            $table->unique(
                ['network_connection_id', 'provider_transaction_id'],
                'provider_tx_connection_external_unique'
            );

            $table->index(
                ['status', 'created_at'],
                'provider_tx_status_created_idx'
            );

            $table->index(
                ['network_connection_id', 'status'],
                'provider_tx_connection_status_idx'
            );

            $table->index(
                ['status', 'last_checked_at'],
                'provider_tx_reconciliation_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_transactions');
    }
};
