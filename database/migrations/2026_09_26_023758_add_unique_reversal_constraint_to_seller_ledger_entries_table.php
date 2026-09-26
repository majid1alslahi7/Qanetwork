<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_ledger_entries', function (Blueprint $table) {
            /*
             * NULL may appear many times.
             * But once reversal_of_entry_id has a value,
             * that original ledger entry can be referenced
             * by only one reversal.
             */
            $table->unique(
                'reversal_of_entry_id',
                'seller_ledger_one_reversal_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('seller_ledger_entries', function (Blueprint $table) {
            $table->dropUnique(
                'seller_ledger_one_reversal_unique'
            );
        });
    }
};
