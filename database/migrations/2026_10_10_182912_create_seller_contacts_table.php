<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_contacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('seller_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('phone', 30);
            $table->string('email')->nullable();
            $table->string('address', 500)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->timestamps();
            $table->unique(['seller_id', 'phone']);
            $table->index(['seller_id', 'is_favorite', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_contacts');
    }
};
