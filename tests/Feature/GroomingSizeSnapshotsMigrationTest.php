<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GroomingSizeSnapshotsMigrationTest extends TestCase
{
    public function test_size_snapshots_can_be_added_and_removed(): void
    {
        Schema::dropIfExists('booking_pets');
        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('pet_id')->nullable();
        });

        $migration = require base_path(
            'database/migrations/2026_09_15_000001_add_grooming_size_snapshots_to_booking_pets_table.php',
        );

        try {
            $migration->up();
            $migration->up();
            $this->assertTrue(Schema::hasColumn('booking_pets', 'registered_size'));
            $this->assertTrue(Schema::hasColumn('booking_pets', 'confirmed_size'));

            $migration->down();
            $this->assertFalse(Schema::hasColumn('booking_pets', 'registered_size'));
            $this->assertFalse(Schema::hasColumn('booking_pets', 'confirmed_size'));
        } finally {
            Schema::dropIfExists('booking_pets');
        }
    }
}
