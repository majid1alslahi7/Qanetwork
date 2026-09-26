<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('network_id')
                ->constrained('networks')
                ->restrictOnDelete();

            // اسم الاتصال داخل النظام.
            $table->string('name', 100)->default('Primary');

            // نوع التكامل:
            // custom_api, mikrotik, radius, agent, ...
            $table->string('driver', 40);

            // عنوان API أو الخدمة الخارجية.
            $table->string('base_url', 2048)->nullable();

            // إعدادات غير حساسة بصيغة JSON.
            $table->json('config')->nullable();

            // بيانات الاعتماد المشفرة.
            // لا تحفظ Token/Password كنص مكشوف.
            $table->text('credentials_encrypted')->nullable();

            // معرف اختياري لدى مزود الشبكة.
            $table->string('external_account_id', 191)->nullable();

            $table->boolean('is_primary')->default(false);
            $table->boolean('is_enabled')->default(false);

            $table->string('health_status', 20)->default('unknown');

            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();

            $table->unsignedInteger('consecutive_failures')->default(0);

            // مهلة الاتصال بالثواني.
            $table->unsignedSmallInteger('connect_timeout')->default(10);

            // مهلة الطلب بالكامل بالثواني.
            $table->unsignedSmallInteger('request_timeout')->default(30);

            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->index(
                ['network_id', 'is_enabled'],
                'netconn_network_enabled_idx'
            );

            $table->index(
                ['network_id', 'is_primary'],
                'netconn_network_primary_idx'
            );

            $table->index(
                ['health_status', 'last_checked_at'],
                'netconn_health_idx'
            );

            $table->index(
                ['driver', 'is_enabled'],
                'netconn_driver_enabled_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_connections');
    }
};
