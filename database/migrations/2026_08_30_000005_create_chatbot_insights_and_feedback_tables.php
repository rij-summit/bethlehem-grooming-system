<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chatbot_insights')) {
            Schema::create('chatbot_insights', function (Blueprint $table) {
                $table->id();
                $table->char('question_fingerprint', 64)->unique();
                $table->string('question_excerpt', 500);
                $table->string('language', 16)->default('unknown');
                $table->string('failure_reason', 50);
                $table->unsignedInteger('occurrence_count')->default(1);
                $table->string('status', 20)->default('new')->index();
                $table->dateTime('first_seen_at');
                $table->dateTime('last_seen_at')->index();
                $table->unsignedInteger('reviewed_by_user_id')->nullable();
                $table->dateTime('resolved_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('chatbot_feedback')) {
            Schema::create('chatbot_feedback', function (Blueprint $table) {
                $table->id();
                $table->uuid('response_id')->unique();
                $table->boolean('helpful');
                $table->string('question_excerpt', 500);
                $table->string('answer_excerpt', 800);
                $table->string('answer_source', 40);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_feedback');
        Schema::dropIfExists('chatbot_insights');
    }
};
