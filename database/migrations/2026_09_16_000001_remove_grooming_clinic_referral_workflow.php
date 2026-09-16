<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CUSTOMER_NOTIFICATION_TYPES = [
        'grooming_medical_concern',
        'grooming_clinic_referral_requested',
        'grooming_clinic_referral_accepted',
        'grooming_clinic_assessment_started',
        'grooming_clinic_assessment_completed',
    ];

    public function up(): void
    {
        $referralAppointmentIds = Schema::hasTable('grooming_clinic_referrals')
            && Schema::hasColumn('grooming_clinic_referrals', 'clinic_appointment_id')
                ? DB::table('grooming_clinic_referrals')
                    ->whereNotNull('clinic_appointment_id')
                    ->pluck('clinic_appointment_id')
                    ->unique()
                    ->values()
                    ->all()
                : [];

        $this->removeCustomerNotificationLinks();

        Schema::dropIfExists('grooming_stopped_payment_reviews');
        Schema::dropIfExists('grooming_clinic_referrals');
        Schema::dropIfExists('grooming_medical_concern_responses');
        Schema::dropIfExists('grooming_medical_concerns');

        if ($referralAppointmentIds !== [] && Schema::hasTable('clinic_appointments')) {
            DB::table('clinic_appointments')
                ->whereIn('id', $referralAppointmentIds)
                ->delete();
        }

        $this->normalizeGroomingStates();
        $this->dropIndexIfExists(
            'booking_pets',
            'bp_concern_identity_uq',
            unique: true,
        );
    }

    public function down(): void
    {
        // The removed workflow and its operational data are intentionally not restored.
    }

    private function removeCustomerNotificationLinks(): void
    {
        if (! Schema::hasTable('customer_notifications')) {
            return;
        }

        $notifications = DB::table('customer_notifications')
            ->whereIn('type', self::CUSTOMER_NOTIFICATION_TYPES);

        foreach ([
            'grooming_clinic_referral_id',
            'grooming_medical_concern_id',
        ] as $column) {
            if (Schema::hasColumn('customer_notifications', $column)) {
                $notifications->orWhereNotNull($column);
            }
        }

        $notifications->delete();

        $this->dropNotificationColumn(
            'grooming_clinic_referral_id',
            'cn_grooming_referral_fk',
            ['cn_referral_type_uq', 'cn_grooming_referral_idx'],
        );
        $this->dropNotificationColumn(
            'grooming_medical_concern_id',
            'cn_grooming_concern_fk',
            ['cn_grooming_concern_idx'],
        );
    }

    /** @param list<string> $indexes */
    private function dropNotificationColumn(
        string $column,
        string $foreign,
        array $indexes,
    ): void {
        if (! Schema::hasColumn('customer_notifications', $column)) {
            return;
        }

        if ($this->foreignKeyExists('customer_notifications', $foreign, $column)) {
            Schema::table('customer_notifications', function (Blueprint $table) use (
                $column,
                $foreign,
            ): void {
                if (DB::connection()->getDriverName() === 'sqlite') {
                    $table->dropForeign([$column]);
                } else {
                    $table->dropForeign($foreign);
                }
            });
        }

        foreach ($indexes as $index) {
            $this->dropIndexIfExists(
                'customer_notifications',
                $index,
                unique: $index === 'cn_referral_type_uq',
            );
        }

        Schema::table('customer_notifications', function (Blueprint $table) use ($column): void {
            $table->dropColumn($column);
        });
    }

    private function normalizeGroomingStates(): void
    {
        if (! Schema::hasTable('booking_pets')
            || ! Schema::hasColumn('booking_pets', 'grooming_state')) {
            return;
        }

        DB::table('booking_pets')
            ->whereNotNull('grooming_end_time')
            ->update(['grooming_state' => 'finished']);

        DB::table('booking_pets')
            ->whereNull('grooming_end_time')
            ->whereNotNull('grooming_start_time')
            ->update(['grooming_state' => 'in_progress']);

        DB::table('booking_pets')
            ->whereNull('grooming_start_time')
            ->whereNull('grooming_end_time')
            ->update(['grooming_state' => 'not_started']);
    }

    private function foreignKeyExists(string $table, string $name, string $column): bool
    {
        return collect(Schema::getForeignKeys($table))
            ->contains(fn (array $foreign): bool => ($foreign['name'] ?? null) === $name
                || ($foreign['columns'] ?? []) === [$column]);
    }

    private function dropIndexIfExists(
        string $tableName,
        string $indexName,
        bool $unique = false,
    ): void {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $exists = collect(Schema::getIndexes($tableName))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $indexName);

        if (! $exists) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use (
            $indexName,
            $unique,
        ): void {
            if ($unique) {
                $table->dropUnique($indexName);
            } else {
                $table->dropIndex($indexName);
            }
        });
    }
};
