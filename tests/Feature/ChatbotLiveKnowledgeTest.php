<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Services\GroomingServicePriceResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChatbotLiveKnowledgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('clinic_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('groomers_on_duty')->default(2);
            $table->time('clinic_open_time')->default('08:00:00');
            $table->time('clinic_close_time')->default('17:00:00');
            $table->time('clinic_prereg_cutoff_time')->default('14:00:00');
            $table->time('grooming_open_time')->default('08:00:00');
            $table->time('grooming_close_time')->default('17:00:00');
            $table->time('grooming_prereg_cutoff_time')->default('14:00:00');
            $table->timestamps();
        });
        DB::table('clinic_settings')->insert(['id' => 1]);

        Schema::create('clinic_closures', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->increments('service_id');
            $table->string('service_name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('base_price', 8, 2)->default(0);
            $table->decimal('price_small', 8, 2)->nullable();
            $table->decimal('price_medium', 8, 2)->nullable();
            $table->decimal('price_large', 8, 2)->nullable();
            $table->decimal('price_extra_large', 8, 2)->nullable();
            $table->decimal('price_min', 8, 2)->nullable();
            $table->decimal('price_max', 8, 2)->nullable();
            $table->boolean('is_starting_price')->default(false);
            $table->boolean('is_active')->default(true);
        });

        DB::table('services')->insert([
            [
                'service_name' => 'Regular Dog Grooming',
                'slug' => 'regular_dog_grooming',
                'description' => 'Live database package',
                'base_price' => 650,
                'price_small' => 555,
                'price_medium' => 777,
                'price_large' => 999,
                'price_extra_large' => 1333,
                'is_active' => true,
            ],
            [
                'service_name' => 'Retired Package',
                'slug' => 'partial_grooming',
                'description' => 'Do not expose this',
                'base_price' => 1,
                'price_small' => 1,
                'price_medium' => null,
                'price_large' => null,
                'price_extra_large' => null,
                'is_active' => false,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('services');
        Schema::dropIfExists('clinic_closures');
        Schema::dropIfExists('clinic_settings');

        parent::tearDown();
    }

    public function test_groq_receives_current_active_database_service_prices(): void
    {
        config()->set('services.groq.key', 'fake-groq-key');
        config()->set('services.groq.model', 'fake-groq-model');
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'The live price was loaded.']]],
            ]),
        ]);

        $this->postJson('/api/chatbot', [
            'message' => 'What grooming services and prices are available?',
        ])->assertOk();

        Http::assertSent(function ($request) {
            $prompt = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($prompt, 'Small PHP 555')
                && str_contains($prompt, 'Medium PHP 777')
                && str_contains($prompt, 'Extra Large PHP 1,333+')
                && ! str_contains($prompt, 'Retired Package');
        });
    }

    public function test_booking_price_resolver_uses_the_live_extra_large_database_price(): void
    {
        $service = Service::query()->where('slug', 'regular_dog_grooming')->firstOrFail();

        $this->assertSame(
            '1333.00',
            app(GroomingServicePriceResolver::class)->servicePrice(
                $service,
                'extra_large'
            )
        );
    }
}
