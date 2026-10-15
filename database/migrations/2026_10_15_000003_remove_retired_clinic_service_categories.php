<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historical occurrences keep their snapshots and billing links after catalogue removal.
        // Retain RESTRICT on the foreign key to protect all remaining catalogue services.
        Schema::table('clinic_case_services', function (Blueprint $table) {
            $table->unsignedBigInteger('clinic_service_id')->nullable()->change();
        });

        DB::transaction(function () {
            $categories = DB::table('clinic_service_categories')
                ->whereIn('name', ['Procedures', 'Certificates & Forms'])->lockForUpdate()->pluck('id');
            $services = DB::table('clinic_services')->whereIn('category_id', $categories)
                ->lockForUpdate()->pluck('id');

            DB::table('clinic_charges')->whereIn('clinic_service_id', $services)->update(['clinic_service_id' => null]);
            DB::table('clinic_case_services')->whereIn('clinic_service_id', $services)->update(['clinic_service_id' => null]);
            DB::table('clinic_services')->whereIn('id', $services)->delete();
            DB::table('clinic_service_categories')->whereIn('id', $categories)->delete();
        });
    }

    public function down(): void
    {
        // Deleted catalogue data cannot be reconstructed. Keep nullable historical references.
    }
};
