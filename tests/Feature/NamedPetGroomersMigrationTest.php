<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NamedPetGroomersMigrationTest extends TestCase
{
    public function test_migration_preserves_old_user_assignments_and_enforces_dedicated_relationship(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name');
        });
        Schema::create('clinic_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('groomers_on_duty');
        });
        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('groomer_id')->nullable();
            $table->foreign('groomer_id')->references('user_id')->on('users')->nullOnDelete();
        });
        DB::table('users')->insert(['user_id' => 42, 'first_name' => 'Anna']);
        DB::table('clinic_settings')->insert(['groomers_on_duty' => 2]);
        DB::table('booking_pets')->insert(['groomer_id' => 42]);
        $migration = require base_path('database/migrations/2026_10_16_000004_create_named_pet_groomers.php');
        $migration->up();
        $this->assertFalse(Schema::hasColumn('clinic_settings', 'groomers_on_duty'));
        $this->assertDatabaseHas('pet_groomers', ['id' => 42, 'first_name' => 'Anna', 'on_duty' => false]);
        $this->assertDatabaseHas('booking_pets', ['groomer_id' => 42]);
        DB::table('users')->where('user_id', 42)->delete();
        $this->assertDatabaseHas('booking_pets', ['groomer_id' => 42]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('pet_groomers')->where('id', 42)->delete();
    }
}
