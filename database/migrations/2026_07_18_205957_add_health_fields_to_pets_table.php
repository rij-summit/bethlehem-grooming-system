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
        Schema::table('pets', function (Blueprint $table) {
            $table->enum('gender', ['male', 'female'])->nullable()->after('breed');
            $table->date('birthdate')->nullable()->after('gender');
            $table->boolean('is_neutered')->default(false)->after('birthdate');
            $table->date('neutered_date')->nullable()->after('is_neutered');
            $table->boolean('is_deceased')->default(false)->after('neutered_date');
            $table->date('deceased_date')->nullable()->after('is_deceased');
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn(['gender', 'birthdate', 'is_neutered', 'neutered_date', 'is_deceased', 'deceased_date']);
        });
    }
};
