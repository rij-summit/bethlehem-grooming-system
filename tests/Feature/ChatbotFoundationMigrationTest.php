<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChatbotFoundationMigrationTest extends TestCase
{
    private $pricingMigration;

    private $legacyInsightsMigration;

    private $retirementMigration;

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

        $this->pricingMigration = require base_path(
            'database/migrations/2026_08_30_000004_add_live_pricing_fields_to_services_table.php',
        );
        $this->legacyInsightsMigration = require base_path(
            'database/migrations/2026_08_30_000005_create_chatbot_insights_and_feedback_tables.php',
        );

        $this->retirementMigration = require base_path(
            'database/migrations/2026_09_28_000001_drop_chatbot_insights_and_feedback_tables.php',
        );

        $this->pricingMigration->up();
        $this->legacyInsightsMigration->up();
        $this->retirementMigration->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('chatbot_feedback');
        Schema::dropIfExists('chatbot_insights');
        Schema::dropIfExists('services');

        parent::tearDown();
    }

    public function test_pricing_remains_and_chatbot_insight_storage_is_retired(): void
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
        $this->assertFalse(Schema::hasTable('chatbot_insights'));
        $this->assertFalse(Schema::hasTable('chatbot_feedback'));
    }

    public function test_retirement_migration_can_be_rolled_back_to_empty_tables(): void
    {
        $this->retirementMigration->down();

        $this->assertTrue(Schema::hasTable('chatbot_insights'));
        $this->assertTrue(Schema::hasTable('chatbot_feedback'));
        $this->assertDatabaseCount('chatbot_insights', 0);
        $this->assertDatabaseCount('chatbot_feedback', 0);

        $this->retirementMigration->up();
        $this->assertFalse(Schema::hasTable('chatbot_insights'));
        $this->assertFalse(Schema::hasTable('chatbot_feedback'));
    }
}
