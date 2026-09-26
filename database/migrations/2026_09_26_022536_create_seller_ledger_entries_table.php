<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_ledger_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('seller_id')
                ->constrained('sellers')
                ->restrictOnDelete();

            $table->foreignUlid('seller_wallet_id')
                ->constrained('seller_wallets')
                ->restrictOnDelete();

            /*
             * deposit
             * sale
             * seller_commission
             * refund
             * adjustment
             * reversal
             * reservation
             * reservation_release
             */
            $table->string('entry_type', 40);

            /*
             * credit = يزيد رصيد البائع
             * debit  = يخفض رصيد البائع
             */
            $table->string('direction', 10);

            // نخزن القيمة موجبة دائماً؛ direction يحدد أثرها.
            $table->decimal('amount', 20, 4);

            $table->char('currency_code', 3)->default('YER');

            /*
             * Snapshot للرصيد بعد ترحيل هذا القيد.
             * مفيد جداً لكشوف الحساب والمراجعة.
             */
            $table->decimal('balance_after', 20, 4);

            /*
             * ربط القيد بمصدره:
             * seller_deposit, sale, adjustment, ...
             */
            $table->string('reference_type', 50);
            $table->string('reference_id', 64);

            /*
             * مفتاح يمنع ترحيل نفس الأثر المالي مرتين.
             * مثال:
             * deposit:{deposit_id}:approved
             * sale:{sale_id}:commission
             */
            $table->string('idempotency_key', 191)->unique();

            $table->string('description', 255)->nullable();

            // المستخدم الذي تسبب بالقيد عند وجود مستخدم مباشر.
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * reversal_of_entry_id يشير إلى القيد الأصلي
             * عند تنفيذ عكس محاسبي.
             *
             * لا نحذف القيد الأصلي.
             */
            $table->ulid('reversal_of_entry_id')->nullable();

            $table->timestamp('posted_at');

            $table->timestamps();

            $table->foreign('reversal_of_entry_id')
                ->references('id')
                ->on('seller_ledger_entries')
                ->restrictOnDelete();

            $table->index(
                ['seller_wallet_id', 'posted_at'],
                'seller_ledger_wallet_posted_idx'
            );

            $table->index(
                ['seller_id', 'posted_at'],
                'seller_ledger_seller_posted_idx'
            );

            $table->index(
                ['reference_type', 'reference_id'],
                'seller_ledger_reference_idx'
            );

            $table->index(
                ['entry_type', 'posted_at'],
                'seller_ledger_type_posted_idx'
            );

            $table->index(
                ['currency_code', 'posted_at'],
                'seller_ledger_currency_posted_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_ledger_entries');
    }
};
