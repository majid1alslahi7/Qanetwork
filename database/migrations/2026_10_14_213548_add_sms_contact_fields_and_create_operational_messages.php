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
        Schema::table('users', function (Blueprint $table): void {
            $table->text('sms_phone')->nullable();
        });
        Schema::table('account_registrations', function (Blueprint $table): void {
            $table->text('phone')->nullable();
        });
        Schema::create('operational_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('event_key', 64)->unique();
            $table->string('kind', 40);
            $table->text('recipient');
            $table->text('body');
            $table->string('status', 32)->default('queued');
            $table->uuid('provider_reference')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operational_messages');
        Schema::table('account_registrations', fn (Blueprint $table) => $table->dropColumn('phone'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('sms_phone'));
    }
};
