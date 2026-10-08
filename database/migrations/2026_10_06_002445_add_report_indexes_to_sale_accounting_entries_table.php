<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_accounting_entries', function (Blueprint $table): void {
            $table->index('posted_at', 'sale_accounting_report_date');
            $table->index(['network_owner_id', 'posted_at'], 'sale_accounting_owner_date');
        });
    }

    public function down(): void
    {
        Schema::table('sale_accounting_entries', function (Blueprint $table): void {
            $table->dropIndex('sale_accounting_report_date');
            $table->dropIndex('sale_accounting_owner_date');
        });
    }
};
