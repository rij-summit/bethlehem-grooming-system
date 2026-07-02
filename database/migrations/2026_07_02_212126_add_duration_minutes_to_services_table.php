<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Estimated grooming duration per service for queue ETA calculation
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('is_active');
        });

        // Seed reasonable defaults for each known service slug
        $defaults = [
            'partial_grooming'    => 45,
            'regular_dog_grooming'=> 90,
            'deluxe_dog_grooming' => 120,
            'bath_and_go'         => 60,
            'cat_full_grooming'   => 90,
            'nail_clipping'       => 15,
            'ear_cleaning'        => 15,
            'facial_trimming'     => 20,
            'anal_sac_draining'   => 15,
            'tooth_brushing'      => 15,
        ];

        foreach ($defaults as $slug => $minutes) {
            DB::table('services')
                ->where('slug', $slug)
                ->update(['duration_minutes' => $minutes]);
        }
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });
    }
};
