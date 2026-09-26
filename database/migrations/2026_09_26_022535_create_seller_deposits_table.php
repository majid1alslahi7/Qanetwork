<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_deposits', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('seller_id')
                ->constrained('sellers')
                ->restrictOnDelete();

            $table->foreignUlid('seller_wallet_id')
                ->constrained('seller_wallets')
                ->restrictOnDelete();

            $table->string('reference_no', 50)->unique();

            $table->decimal('amount', 20, 4);
            $table->char('currency_code', 3)->default('YER');

            /*
             * cash
             * bank_transfer
             * mobile_wallet
             * payment_gateway
             * other
             */
            $table->string('payment_method', 40);

            // مرجع التحويل الخارجي إن وجد.
            $table->string('external_reference', 191)->nullable();

            // مسار صورة/ملف سند الإيداع، وليس الملف نفسه.
            $table->string('receipt_path', 500)->nullable();

            /*
             * pending
             * approved
             * rejected
             * cancelled
             */
            $table->string('status', 20)->default('pending');

            $table->text('seller_notes')->nullable();
            $table->text('review_notes')->nullable();

            // مستخدم الإدارة الذي راجع الإيداع.
            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();

            $table->timestamps();

            $table->index(
                ['seller_id', 'status', 'created_at'],
                'seller_deposit_seller_status_idx'
            );

            $table->index(
                ['status', 'created_at'],
                'seller_deposit_review_queue_idx'
            );

            $table->index(
                ['payment_method', 'created_at'],
                'seller_deposit_method_idx'
            );

            $table->index('external_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_deposits');
    }
};
