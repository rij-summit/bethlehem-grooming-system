<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChatbotFoundationMigrationTest extends TestCase
{
    private $pricingMigration;

    private $insightsMigration;

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
        $this->insightsMigration = require base_path(
            'database/migrations/2026_08_30_000005_create_chatbot_insights_and_feedback_tables.php',
        );

        $this->pricingMigration->up();
        $this->insightsMigration->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('chatbot_feedback');
        Schema::dropIfExists('chatbot_insights');
        Schema::dropIfExists('services');

        parent::tearDown();
    }

    public function test_migrations_create_live_pricing_insight_and_feedback_storage(): void
    {
        $this->assertTrue(Schema::hasColumns('services', [
            'price_extra_large',
            'price_min',
            'price_max',
            'is_starting_price',
        ]));
        $this->assertTrue(Schema::hasColumns('chatbot_insights', [
            'question_fingerprint',
            'question_excerpt',
            'language',
            'failure_reason',
            'occurrence_count',
            'status',
            'first_seen_at',
            'last_seen_at',
        ]));
        $this->assertTrue(Schema::hasColumns('chatbot_feedback', [
            'response_id',
            'helpful',
            'question_excerpt',
            'answer_excerpt',
            'answer_source',
        ]));

        $this->assertDatabaseHas('services', [
            'slug' => 'regular_dog_grooming',
            'price_extra_large' => 1050,
        ]);
    }

    public function test_migrations_can_be_rolled_back_cleanly(): void
    {
        $this->insightsMigration->down();
        $this->pricingMigration->down();

        $this->assertFalse(Schema::hasTable('chatbot_insights'));
        $this->assertFalse(Schema::hasTable('chatbot_feedback'));
        $this->assertFalse(Schema::hasColumn('services', 'price_extra_large'));
    }
}
