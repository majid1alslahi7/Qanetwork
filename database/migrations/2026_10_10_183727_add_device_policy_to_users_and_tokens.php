<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('require_device_approval')->default(false);
        });
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignUlid('account_device_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('account_device_id');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('require_device_approval');
        });
    }
};
