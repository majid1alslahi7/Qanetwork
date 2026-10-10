<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_cards', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('network_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('network_product_id')->constrained()->restrictOnDelete();
            $table->string('fingerprint', 64);
            $table->text('credentials_encrypted');
            $table->string('status', 20)->default('available');
            $table->foreignUlid('sale_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('internal_transaction_id', 26)->nullable()->unique();
            $table->timestamp('allocated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['network_id', 'fingerprint']);
            $table->index(['network_product_id', 'status', 'expires_at', 'id'], 'inventory_available_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_cards');
    }
};
