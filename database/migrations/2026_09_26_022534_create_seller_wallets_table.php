<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_wallets', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('seller_id')
                ->constrained('sellers')
                ->restrictOnDelete();

            $table->char('currency_code', 3)->default('YER');

            /*
             * Cached balance for fast reads.
             *
             * The ledger remains the accounting history.
             * This value must only change inside controlled
             * database transactions together with ledger entries.
             */
            $table->decimal('balance', 20, 4)->default(0);

            /*
             * Amount temporarily reserved for operations
             * that have started but are not finalized yet.
             */
            $table->decimal('reserved_balance', 20, 4)->default(0);

            $table->string('status', 20)->default('active');

            $table->timestamp('last_transaction_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['seller_id', 'currency_code'],
                'seller_wallet_currency_unique'
            );

            $table->index(
                ['status', 'currency_code'],
                'seller_wallet_status_currency_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_wallets');
    }
};
