<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_TYPES = [
        'reminder_24h',
        'reminder_3h',
        'grooming_started',
        'grooming_finished',
        'ready_for_pickup',
        'pickup_reminder',
        'picked_up',
    ];

    public function up(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->string('type', 50)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        $unsupportedTypes = DB::table('customer_notifications')
            ->whereNotIn('type', self::LEGACY_TYPES)
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->all();

        if ($unsupportedTypes !== []) {
            throw new RuntimeException(
                'Cannot restore customer_notifications.type to its legacy ENUM because unsupported values exist: '
                .implode(', ', $unsupportedTypes),
            );
        }

        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->enum('type', self::LEGACY_TYPES)->nullable(false)->change();
        });
    }
};
