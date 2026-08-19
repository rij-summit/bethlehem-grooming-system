<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'account_deleted_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('account_deleted_at')->nullable()->index()->after('archived_at');
            });
        }

        if (Schema::hasTable('unregistered_customers')
            && ! Schema::hasColumn('unregistered_customers', 'account_deleted_at')) {
            Schema::table('unregistered_customers', function (Blueprint $table): void {
                $table->timestamp('account_deleted_at')->nullable()->index()->after('archived_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'account_deleted_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex('users_account_deleted_at_index');
                $table->dropColumn('account_deleted_at');
            });
        }

        if (Schema::hasTable('unregistered_customers')
            && Schema::hasColumn('unregistered_customers', 'account_deleted_at')) {
            Schema::table('unregistered_customers', function (Blueprint $table): void {
                $table->dropIndex('unregistered_customers_account_deleted_at_index');
                $table->dropColumn('account_deleted_at');
            });
        }
    }
};
