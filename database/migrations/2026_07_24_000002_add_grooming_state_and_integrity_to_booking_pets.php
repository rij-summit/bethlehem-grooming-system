<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_pets', function (Blueprint $table) {
            $table->string('grooming_state', 20)
                ->nullable()
                ->after('grooming_end_time');
        });

        DB::table('booking_pets')
            ->whereNotNull('grooming_end_time')
            ->update(['grooming_state' => 'finished']);

        DB::table('booking_pets')
            ->whereNull('grooming_end_time')
            ->whereNotNull('grooming_start_time')
            ->update(['grooming_state' => 'in_progress']);

        DB::table('booking_pets')
            ->whereNull('grooming_start_time')
            ->whereNull('grooming_end_time')
            ->update(['grooming_state' => 'not_started']);

        Schema::table('booking_pets', function (Blueprint $table) {
            $table->string('grooming_state', 20)
                ->default('not_started')
                ->nullable(false)
                ->change();
            $table->index('grooming_state', 'bp_grooming_state_idx');
        });
    }

    public function down(): void
    {
        Schema::table('booking_pets', function (Blueprint $table) {
            $table->dropIndex('bp_grooming_state_idx');
            $table->dropColumn('grooming_state');
        });
    }
};
