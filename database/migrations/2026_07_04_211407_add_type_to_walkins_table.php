<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('walkins', function (Blueprint $table) {
            $table->enum('appointment_type', ['grooming', 'clinic'])->default('grooming')->after('user_id');
            $table->text('chief_complaint')->nullable()->after('appointment_type');
        });
    }

    public function down(): void
    {
        Schema::table('walkins', function (Blueprint $table) {
            $table->dropColumn(['appointment_type', 'chief_complaint']);
        });
    }
};
