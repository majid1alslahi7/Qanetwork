<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('code', 30)->unique();

            $table->string('business_name', 150)->nullable();
            $table->string('phone', 30)->nullable();

            $table->string('country_code', 2)->default('YE');
            $table->string('city', 100)->nullable();
            $table->text('address')->nullable();

            /*
             * pending   = بانتظار التفعيل
             * active    = فعال
             * suspended = موقوف
             * inactive  = غير فعال
             */
            $table->string('status', 20)->default('pending');

            $table->text('notes')->nullable();

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();

            $table->timestamps();

            $table->unique('user_id');
            $table->index(['status', 'created_at'], 'sellers_status_created_idx');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};
