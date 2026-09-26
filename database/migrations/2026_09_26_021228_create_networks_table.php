<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('networks', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('network_owner_id')
                ->constrained('network_owners')
                ->restrictOnDelete();

            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('display_name', 150)->nullable();

            $table->string('country_code', 2)->default('YE');
            $table->string('city', 100)->nullable();
            $table->string('timezone', 64)->default('Asia/Aden');

            $table->char('currency_code', 3)->default('YER');

            $table->string('status', 20)->default('inactive');
            $table->string('health_status', 20)->default('unknown');

            $table->timestamp('last_health_check_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();

            $table->boolean('sales_enabled')->default(false);

            $table->text('notes')->nullable();

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();

            $table->timestamps();

            $table->index(
                ['network_owner_id', 'status'],
                'networks_owner_status_idx'
            );

            $table->index(
                ['sales_enabled', 'status'],
                'networks_sales_status_idx'
            );

            $table->index(
                ['health_status', 'last_health_check_at'],
                'networks_health_check_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('networks');
    }
};
