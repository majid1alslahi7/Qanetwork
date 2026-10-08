<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_financials', function (Blueprint $table): void {
            $table->foreignUlid('network_owner_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_financials', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('network_owner_id');
        });
    }
};
