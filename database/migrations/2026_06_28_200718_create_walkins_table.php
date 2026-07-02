<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walkins', function (Blueprint $table) {
            $table->id();

            // Owner information
            $table->string('fname');
            $table->string('lname');
            $table->string('mname')->nullable();
            $table->string('email')->nullable(); // Optional — used to match returning customers
            $table->string('phone');

            // Consent confirmed by staff on-site
            $table->boolean('sedation_consent')->default(false);
            $table->boolean('terms_agreed')->default(false);

            // Nullable link to an existing registered customer
            $table->unsignedInteger('user_id')->nullable();
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walkins');
    }
};
