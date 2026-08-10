<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unregistered_customers', function (Blueprint $table) {
            $table->boolean('is_archived')->default(false)->after('created_by_user_id');
            $table->timestamp('archived_at')->nullable()->after('is_archived');
        });

        Schema::table('pets', function (Blueprint $table) {
            $table->foreignId('unregistered_customer_id')
                ->nullable()
                ->after('user_id')
                ->constrained('unregistered_customers')
                ->nullOnDelete();
        });

        Schema::table('walkins', function (Blueprint $table) {
            $table->foreignId('unregistered_customer_id')
                ->nullable()
                ->after('user_id')
                ->constrained('unregistered_customers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('walkins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unregistered_customer_id');
        });

        Schema::table('pets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unregistered_customer_id');
        });

        Schema::table('unregistered_customers', function (Blueprint $table) {
            $table->dropColumn(['is_archived', 'archived_at']);
        });
    }
};
