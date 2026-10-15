<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
        });
        DB::table('clinic_service_categories')->insert(array_map(fn ($name) => ['name' => $name], [
            'Consultation & Fees', 'Laboratory', 'Diagnostics', 'Diagnostic Imaging', 'Procedures',
            'Boarding & Lodging', 'Certificates & Forms',
        ]));
        Schema::create('clinic_services', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->foreignId('category_id')->constrained('clinic_service_categories')->restrictOnDelete();
            $table->string('type', 100)->nullable();
            $table->char('identity_key', 64)->unique();
            $table->string('pricing_method', 20);
            $table->string('billing_unit', 20)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('min_price', 10, 2)->nullable();
            $table->decimal('max_price', 10, 2)->nullable();
            $table->string('tier_basis', 20)->nullable();
            $table->json('tiers')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'category_id']);
        });
        Schema::table('clinic_charges', function (Blueprint $table) {
            $table->foreignId('clinic_service_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clinic_charges', fn (Blueprint $table) => $table->dropConstrainedForeignId('clinic_service_id'));
        Schema::dropIfExists('clinic_services');
        Schema::dropIfExists('clinic_service_categories');
    }
};
