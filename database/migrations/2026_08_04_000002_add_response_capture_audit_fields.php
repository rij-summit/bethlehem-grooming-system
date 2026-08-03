<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grooming_medical_concern_responses', function (Blueprint $table) {
            $table->string('response_channel', 30)
                ->default('portal')
                ->after('responded_by_name');
            $table->unsignedInteger('captured_by_user_id')
                ->nullable()
                ->after('response_channel');
            $table->string('captured_by_name', 200)
                ->nullable()
                ->after('captured_by_user_id');

            $table->unique(['id', 'concern_id'], 'gmcr_ref_context_uq');
            $table->foreign('captured_by_user_id', 'gmcr_captured_by_fk')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete();
        });

        DB::table('grooming_medical_concern_responses')
            ->whereNull('response_channel')
            ->update(['response_channel' => 'portal']);
    }

    public function down(): void
    {
        Schema::table('grooming_medical_concern_responses', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->dropForeign(['captured_by_user_id']);
            } else {
                $table->dropForeign('gmcr_captured_by_fk');
            }
            $table->dropUnique('gmcr_ref_context_uq');
            $table->dropColumn([
                'response_channel',
                'captured_by_user_id',
                'captured_by_name',
            ]);
        });
    }
};
