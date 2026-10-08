<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_settlements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('network_owner_id')->constrained()->restrictOnDelete();
            $table->string('reference_no', 50)->unique();
            $table->decimal('amount', 20, 4);
            $table->char('currency_code', 3);
            $table->string('payment_method', 40);
            $table->string('external_reference', 191);
            $table->string('idempotency_key', 191)->unique();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();
            $table->unique(['network_owner_id', 'currency_code', 'payment_method', 'external_reference'], 'provider_settlement_payment_unique');
            $table->index(['network_owner_id', 'paid_at'], 'provider_settlement_owner_date');
        });
        Schema::create('provider_settlement_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('provider_settlement_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('sale_accounting_entry_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->timestamps();
            $table->unique(['provider_settlement_id', 'sale_accounting_entry_id'], 'provider_settlement_allocation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_settlement_allocations');
        Schema::dropIfExists('provider_settlements');
    }
};
