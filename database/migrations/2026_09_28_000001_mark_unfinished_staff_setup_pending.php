<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('role', 'staff')
            ->where('password_hash', 'like', '!staff-password-setup-required!%')
            ->update(['is_active' => false, 'email_verified_at' => null]);
    }

    public function down(): void
    {
        // Verification cannot be restored without proof that setup was completed.
    }
};
