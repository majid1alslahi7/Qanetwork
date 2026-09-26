<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_owners', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('commercial_name', 150)->nullable();

            $table->string('phone', 30)->nullable();
            $table->string('email', 190)->nullable();

            $table->string('country_code', 2)->default('YE');
            $table->string('city', 100)->nullable();
            $table->text('address')->nullable();

            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_owners');
    }
};
