<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->json('clinic_verified_fields')
                ->nullable()
                ->after('fur_type');
        });

        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->unsignedInteger('pet_id')
                ->nullable()
                ->after('booking_id');
            $table->index('pet_id', 'cn_pet_idx');
            $table->foreign('pet_id', 'cn_pet_fk')
                ->references('pet_id')
                ->on('pets')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->dropForeign(['pet_id']);
            } else {
                $table->dropForeign('cn_pet_fk');
            }

            $table->dropIndex('cn_pet_idx');
            $table->dropColumn('pet_id');
        });

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('clinic_verified_fields');
        });
    }
};
