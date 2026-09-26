<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_products', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('network_id')
                ->constrained('networks')
                ->restrictOnDelete();

            // معرف الفئة لدى صاحب الشبكة/API.
            $table->string('external_product_id', 191);

            // رمز داخلي ثابت داخل QaNetwork.
            $table->string('code', 50)->unique();

            $table->string('name', 150);
            $table->string('display_name', 150)->nullable();

            // القيمة الاسمية للكرت.
            // لا نستخدم float/double للأموال.
            $table->decimal('face_value', 20, 4);

            $table->char('currency_code', 3)->default('YER');

            // معلومات وصفية فقط عن الخدمة.
            // الأسعار والعمولات ستكون في جداول مستقلة.
            $table->unsignedBigInteger('data_limit_bytes')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();

            // معلومات إضافية تختلف من مزود إلى آخر.
            $table->json('metadata')->nullable();

            /*
             * active   = الفئة فعالة
             * inactive = غير مفعلة
             * archived = مؤرشفة
             */
            $table->string('status', 20)->default('active');

            // آخر حالة توفر عرفناها من المزود.
            $table->string('availability_status', 20)
                ->default('unknown');

            // عدد تقريبي فقط إن كان API يوفره.
            // NULL يعني أن المزود لا يقدم كمية.
            $table->unsignedBigInteger('available_quantity')
                ->nullable();

            $table->timestamp('availability_checked_at')
                ->nullable();

            // آخر مرة تم فيها مزامنة بيانات الفئة.
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            // لا يمكن لنفس الشبكة تسجيل نفس معرف الفئة الخارجية مرتين.
            $table->unique(
                ['network_id', 'external_product_id'],
                'netprod_network_external_unique'
            );

            $table->index(
                ['network_id', 'status'],
                'netprod_network_status_idx'
            );

            $table->index(
                ['network_id', 'availability_status'],
                'netprod_network_availability_idx'
            );

            $table->index(
                ['status', 'availability_status'],
                'netprod_sellable_lookup_idx'
            );

            $table->index(
                ['network_id', 'face_value'],
                'netprod_network_value_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_products');
    }
};
