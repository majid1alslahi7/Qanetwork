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
        Schema::create('card_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('sale_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 191)->unique();
            $table->string('channel', 10)->default('sms');
            $table->text('recipient_encrypted');
            $table->string('recipient_hint', 8);
            $table->string('status', 30)->default('queued');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('provider_reference', 191)->nullable();
            $table->string('failure_code', 40)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_retry_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_deliveries');
    }
};
