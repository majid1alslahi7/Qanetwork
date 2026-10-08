<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_accounting_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('sale_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('network_owner_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('entry_type', 30);
            $table->decimal('amount', 20, 4);
            $table->char('currency_code', 3);
            $table->string('idempotency_key', 191)->unique();
            $table->timestamp('posted_at');
            $table->timestamps();
            $table->unique(['sale_id', 'entry_type']);
            $table->index(['network_owner_id', 'currency_code', 'posted_at'], 'sale_accounting_owner_currency');
            $table->index(['entry_type', 'currency_code', 'posted_at'], 'sale_accounting_type_currency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_accounting_entries');
    }
};
