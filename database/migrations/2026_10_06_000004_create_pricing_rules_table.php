<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('network_product_id')->constrained()->restrictOnDelete();
            $table->decimal('face_value', 20, 4);
            $table->decimal('provider_amount', 20, 4);
            $table->char('currency_code', 3);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['network_product_id', 'is_active']);
        });
        Schema::create('commission_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('pricing_rule_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('seller_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('seller_commission', 20, 4);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['pricing_rule_id', 'seller_id', 'is_active'], 'commission_rule_lookup');
        });
        Schema::table('sale_financials', function (Blueprint $table): void {
            $table->foreign('pricing_rule_id')->references('id')->on('pricing_rules')->restrictOnDelete();
            $table->foreign('commission_rule_id')->references('id')->on('commission_rules')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_financials', function (Blueprint $table): void {
            $table->dropForeign(['pricing_rule_id']);
            $table->dropForeign(['commission_rule_id']);
        });
        Schema::dropIfExists('commission_rules');
        Schema::dropIfExists('pricing_rules');
    }
};
