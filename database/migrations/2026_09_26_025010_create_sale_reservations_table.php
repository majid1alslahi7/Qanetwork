<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_reservations', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('sale_id')
                ->constrained('sales')
                ->restrictOnDelete();

            $table->foreignUlid('seller_wallet_id')
                ->constrained('seller_wallets')
                ->restrictOnDelete();

            $table->decimal('amount', 20, 4);
            $table->char('currency_code', 3);

            /*
             * reserved  = money is locked for this sale
             * captured  = reservation became a real debit
             * released  = provider failed; money returned
             */
            $table->string('status', 20)->default('reserved');

            $table->string('idempotency_key', 191)->unique();

            $table->timestamp('reserved_at');
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            /*
             * A sale can own only one wallet reservation.
             */
            $table->unique(
                'sale_id',
                'sale_reservations_sale_unique'
            );

            $table->index(
                ['seller_wallet_id', 'status'],
                'sale_reservations_wallet_status_idx'
            );

            $table->index(
                ['status', 'reserved_at'],
                'sale_reservations_status_time_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_reservations');
    }
};
