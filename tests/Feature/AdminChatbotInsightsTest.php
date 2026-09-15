<?php

namespace Tests\Feature;

use App\Models\ChatbotInsight;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminChatbotInsightsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->string('password_hash');
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('account_deleted_at')->nullable();
        });
        Schema::create('chatbot_insights', function (Blueprint $table) {
            $table->id();
            $table->char('question_fingerprint', 64)->unique();
            $table->string('question_excerpt', 500);
            $table->string('language', 16);
            $table->string('failure_reason', 50);
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->string('status', 20)->default('new');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('chatbot_feedback', function (Blueprint $table) {
            $table->id();
            $table->uuid('response_id')->unique();
            $table->boolean('helpful');
            $table->string('question_excerpt', 500);
            $table->string('answer_excerpt', 800);
            $table->string('answer_source', 40);
            $table->timestamps();
        });

        DB::table('users')->insert([
            [
                'user_id' => 1,
                'first_name' => 'Admin',
                'last_name' => 'User',
                'email' => 'admin@example.com',
                'phone' => '09170000001',
                'password_hash' => 'hash',
                'role' => 'admin',
                'email_verified_at' => now(),
            ],
            [
                'user_id' => 2,
                'first_name' => 'Staff',
                'last_name' => 'User',
                'email' => 'staff@example.com',
                'phone' => '09170000002',
                'password_hash' => 'hash',
                'role' => 'staff',
                'email_verified_at' => now(),
            ],
        ]);
        DB::table('chatbot_insights')->insert([
            'question_fingerprint' => hash('sha256', 'question'),
            'question_excerpt' => 'Can you explain this clinic service?',
            'language' => 'english',
            'failure_reason' => 'needs_human_handoff',
            'occurrence_count' => 3,
            'status' => 'new',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('chatbot_feedback')->insert([
            'response_id' => '123e4567-e89b-12d3-a456-426614174000',
            'helpful' => false,
            'question_excerpt' => 'Safe question excerpt',
            'answer_excerpt' => 'Safe answer excerpt',
            'answer_source' => 'groq',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('chatbot_feedback');
        Schema::dropIfExists('chatbot_insights');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_admin_can_view_summaries_and_update_insight_status(): void
    {
        Sanctum::actingAs(User::query()->findOrFail(1));

        $this->getJson('/api/admin/chatbot-insights')
            ->assertOk()
            ->assertJsonPath('summary.new_questions', 1)
            ->assertJsonPath('summary.total_occurrences', 3)
            ->assertJsonPath('summary.unhelpful_feedback', 1)
            ->assertJsonPath('insights.0.question_excerpt', 'Can you explain this clinic service?')
            ->assertJsonPath('recent_unhelpful.0.question_excerpt', 'Safe question excerpt');

        $insight = ChatbotInsight::query()->firstOrFail();
        $this->patchJson("/api/admin/chatbot-insights/{$insight->id}/status", [
            'status' => 'resolved',
        ])
            ->assertOk()
            ->assertJsonPath('insight.status', 'resolved');

        $this->assertDatabaseHas('chatbot_insights', [
            'id' => $insight->id,
            'status' => 'resolved',
            'reviewed_by_user_id' => 1,
        ]);
    }

    public function test_staff_cannot_access_admin_chatbot_insights(): void
    {
        Sanctum::actingAs(User::query()->findOrFail(2));

        $this->getJson('/api/admin/chatbot-insights')->assertForbidden();
    }
}
