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
        Schema::table('network_products', function (Blueprint $table) {
            $table->foreignUlid('fulfillment_connection_id')->nullable()->constrained('network_connections')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('network_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fulfillment_connection_id');
        });
    }
};
