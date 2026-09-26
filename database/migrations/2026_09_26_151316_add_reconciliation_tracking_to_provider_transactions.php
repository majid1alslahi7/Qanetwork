<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_transactions', function (Blueprint $table) {
            $table->unsignedInteger('reconciliation_attempt_count')
                ->default(0)
                ->after('attempt_count');

            $table->timestamp('last_reconciliation_at')
                ->nullable()
                ->after('reconciliation_attempt_count');

            $table->timestamp('manual_review_required_at')
                ->nullable()
                ->after('last_reconciliation_at');
        });
    }

    public function down(): void
    {
        Schema::table('provider_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'reconciliation_attempt_count',
                'last_reconciliation_at',
                'manual_review_required_at',
            ]);
        });
    }
};
