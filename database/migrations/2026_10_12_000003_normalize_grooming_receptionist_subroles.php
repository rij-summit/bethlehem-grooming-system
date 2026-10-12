<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('users')
                ->where('role', 'staff')
                ->where('staff_type', 'grooming')
                ->whereNull('staff_subrole')
                ->update(['staff_subrole' => 'grooming_receptionist']);

            DB::table('pending_staff_accounts')
                ->where('staff_type', 'grooming')
                ->whereNull('staff_subrole')
                ->update(['staff_subrole' => 'grooming_receptionist']);
        });
    }

    public function down(): void
    {
        // Data normalization is forward-only; preserve explicit roles on rollback.
    }
};
