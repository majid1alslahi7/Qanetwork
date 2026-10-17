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
        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignUlid('seller_contact_id')->nullable()->constrained('seller_contacts')->nullOnDelete();
            $table->text('customer_snapshot')->nullable();
            $table->index(['seller_contact_id', 'seller_id', 'created_at'], 'sales_contact_seller_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropForeign(['seller_contact_id']);
            $table->dropIndex('sales_contact_seller_created_idx');
            $table->dropColumn(['seller_contact_id', 'customer_snapshot']);
        });
    }
};
