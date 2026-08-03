<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('grooming_clinic_referral_id')
                ->nullable()
                ->after('grooming_medical_concern_id');
            $table->index(
                'grooming_clinic_referral_id',
                'cn_grooming_referral_idx',
            );
            $table->unique(
                ['grooming_clinic_referral_id', 'type'],
                'cn_referral_type_uq',
            );
            $table->foreign(
                'grooming_clinic_referral_id',
                'cn_grooming_referral_fk',
            )
                ->references('id')
                ->on('grooming_clinic_referrals')
                ->restrictOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->dropForeign(['grooming_clinic_referral_id']);
            } else {
                $table->dropForeign('cn_grooming_referral_fk');
            }
            $table->dropUnique('cn_referral_type_uq');
            $table->dropIndex('cn_grooming_referral_idx');
            $table->dropColumn('grooming_clinic_referral_id');
        });
    }
};
