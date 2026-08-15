<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('grooming_medical_concern_id')->nullable();
            $table->index(
                'grooming_medical_concern_id',
                'cn_grooming_concern_idx',
            );
            $table->foreign(
                'grooming_medical_concern_id',
                'cn_grooming_concern_fk',
            )
                ->references('id')
                ->on('grooming_medical_concerns')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->dropForeign('cn_grooming_concern_fk');
            $table->dropIndex('cn_grooming_concern_idx');
            $table->dropColumn('grooming_medical_concern_id');
        });
    }
};
