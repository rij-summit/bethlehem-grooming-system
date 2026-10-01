<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServiceLivePricingMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('services', function (Blueprint $table): void {
            $table->increments('service_id');
            $table->string('slug')->unique();
            $table->decimal('price', 8, 2)->nullable();
            $table->decimal('price_small', 8, 2)->nullable();
            $table->decimal('price_medium', 8, 2)->nullable();
            $table->decimal('price_large', 8, 2)->nullable();
        });

        DB::table('services')->insert([
            'slug' => 'regular_dog_grooming',
            'price' => 500,
            'price_small' => 600,
            'price_medium' => 700,
            'price_large' => 850,
        ]);

        $migration = require base_path(
            'database/migrations/2026_08_30_000004_add_live_pricing_fields_to_services_table.php',
        );
        $migration->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('services');

        parent::tearDown();
    }

    public function test_live_pricing_fields_and_existing_prices_are_preserved(): void
    {
        $this->assertTrue(Schema::hasColumns('services', [
            'price_extra_large',
            'price_min',
            'price_max',
            'is_starting_price',
        ]));
        $this->assertDatabaseHas('services', [
            'slug' => 'regular_dog_grooming',
            'price_extra_large' => 1050,
        ]);
    }
}
