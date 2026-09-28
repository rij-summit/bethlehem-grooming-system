<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('chatbot_feedback');
        Schema::dropIfExists('chatbot_insights');
    }

    public function down(): void
    {
        // Rollback restores empty tables; removed insight and feedback data cannot be recovered.
        $legacyMigration = require __DIR__.'/2026_08_30_000005_create_chatbot_insights_and_feedback_tables.php';
        $legacyMigration->up();
    }
};
