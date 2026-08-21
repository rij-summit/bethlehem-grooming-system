<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_TYPES = [
        'booked',
        'cancelled',
        'rescheduled',
        'payment_due',
    ];

    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('type', 50)->nullable(false)->change();
            $table->unsignedInteger('booking_id')->nullable()->change();
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('clinic_appointment_id')->nullable()->after('booking_id');
            $table->foreign('clinic_appointment_id', 'admin_notif_clinic_appt_fk')
                ->references('id')
                ->on('clinic_appointments')
                ->cascadeOnDelete();
            $table->index(
                ['type', 'is_read', 'created_at'],
                'admin_notif_type_read_created_idx',
            );
            $table->index(
                ['is_read', 'created_at'],
                'admin_notif_read_created_idx',
            );
        });
    }

    public function down(): void
    {
        $clinicNotificationCount = DB::table('notifications')
            ->whereNotNull('clinic_appointment_id')
            ->count();
        $unsupportedTypes = DB::table('notifications')
            ->whereNotIn('type', self::LEGACY_TYPES)
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->all();

        if ($clinicNotificationCount > 0 || $unsupportedTypes !== []) {
            throw new RuntimeException(
                'Cannot restore the legacy admin notification schema while clinic or unsupported notification types exist.',
            );
        }

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('admin_notif_type_read_created_idx');
            $table->dropIndex('admin_notif_read_created_idx');
            $table->dropForeign('admin_notif_clinic_appt_fk');
            $table->dropColumn('clinic_appointment_id');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedInteger('booking_id')->nullable(false)->change();
            $table->enum('type', self::LEGACY_TYPES)->nullable(false)->change();
        });
    }
};
