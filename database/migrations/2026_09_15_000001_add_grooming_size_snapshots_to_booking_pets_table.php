<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_pets')) {
            return;
        }

        Schema::table('booking_pets', function (Blueprint $table) {
            if (! Schema::hasColumn('booking_pets', 'registered_size')) {
                $table->string('registered_size', 20)->nullable();
            }
            if (! Schema::hasColumn('booking_pets', 'confirmed_size')) {
                $table->string('confirmed_size', 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('booking_pets')) {
            return;
        }

        Schema::table('booking_pets', function (Blueprint $table) {
            if (Schema::hasColumn('booking_pets', 'confirmed_size')) {
                $table->dropColumn('confirmed_size');
            }
            if (Schema::hasColumn('booking_pets', 'registered_size')) {
                $table->dropColumn('registered_size');
            }
        });
    }
};
