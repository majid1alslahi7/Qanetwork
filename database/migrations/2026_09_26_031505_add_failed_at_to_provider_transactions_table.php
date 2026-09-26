<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('provider_transactions', 'failed_at')) {
            Schema::table('provider_transactions', function (Blueprint $table) {
                $table->timestamp('failed_at')
                    ->nullable()
                    ->after('confirmed_at');

                $table->index(
                    ['status', 'failed_at'],
                    'provider_transactions_status_failed_idx'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('provider_transactions', 'failed_at')) {
            Schema::table('provider_transactions', function (Blueprint $table) {
                $table->dropIndex(
                    'provider_transactions_status_failed_idx'
                );

                $table->dropColumn('failed_at');
            });
        }
    }
};
