<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Some MySQL configurations silently attach ON UPDATE behavior to the
        // first TIMESTAMP column. Re-declaring the default explicitly removes
        // that behavior without rewriting the existing historical values.
        DB::statement(
            'ALTER TABLE grooming_medical_concerns '
            .'MODIFY reported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        );
        DB::statement(
            'ALTER TABLE grooming_medical_concern_responses '
            .'MODIFY responded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        );
    }

    public function down(): void
    {
        // Intentionally retain the safe timestamp definition. Restoring the
        // implicit MySQL ON UPDATE behavior would corrupt historical audits.
    }
};
