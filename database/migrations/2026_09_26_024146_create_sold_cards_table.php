<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sold_cards', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('sale_id')
                ->constrained('sales')
                ->restrictOnDelete();

            /*
             * مرجع/Serial غير سري إن كان المزود يقدمه.
             */
            $table->string('provider_card_reference', 191)->nullable();

            /*
             * بيانات الكرت الحساسة مشفرة.
             *
             * سنستخدم encrypted:array في Model.
             * LONGTEXT لأن النص المشفر أكبر من النص الأصلي.
             *
             * مثال للمحتوى قبل التشفير:
             * username, password, pin, serial, extra
             */
            $table->longText('credentials_encrypted');

            $table->timestamp('sold_at');

            /*
             * متى تم كشف بيانات الكرت للبائع/المستلم
             * لأول مرة، لأغراض التدقيق.
             */
            $table->timestamp('first_revealed_at')->nullable();

            $table->timestamps();

            // لا يمكن أن يكون للبيع الواحد أكثر من كرت مباع.
            $table->unique('sale_id');

            $table->index(
                ['sold_at'],
                'sold_cards_sold_at_idx'
            );

            $table->index(
                ['provider_card_reference'],
                'sold_cards_provider_reference_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sold_cards');
    }
};
