<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_sale_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 191)->unique();
            $table->text('reason');
            $table->string('status', 20)->default('queued');
            $table->string('sale_status_before', 40);
            $table->string('sale_status_after', 40)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['sale_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_sale_reviews');
    }
};
