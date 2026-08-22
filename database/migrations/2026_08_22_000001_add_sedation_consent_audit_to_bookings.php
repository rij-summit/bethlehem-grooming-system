<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('sedation_consent')->default(false)->after('special_notes');
            $table->string('sedation_consent_source', 30)->nullable()->after('sedation_consent');
            $table->unsignedInteger('sedation_consent_recorded_by')->nullable()->after('sedation_consent_source');
            $table->timestamp('sedation_consent_recorded_at')->nullable()->after('sedation_consent_recorded_by');

            $table->foreign('sedation_consent_recorded_by', 'bookings_sedation_consent_staff_fk')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign('bookings_sedation_consent_staff_fk');
            $table->dropColumn([
                'sedation_consent',
                'sedation_consent_source',
                'sedation_consent_recorded_by',
                'sedation_consent_recorded_at',
            ]);
        });
    }
};
