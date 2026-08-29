<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_EMAIL = 'staff@bethlehem.com';

    private const TARGET_EMAIL = 'bethlehem.staff.test@gmail.com';

    private const TARGET_USERNAME = 'groomingstaff';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('staff_type', 20)->nullable()->after('role');
            $table->string('phone', 20)->nullable()->change();
        });

        DB::transaction(function (): void {
            $emailOwner = DB::table('users')
                ->whereRaw('LOWER(email) = ?', [self::TARGET_EMAIL])
                ->lockForUpdate()
                ->first();
            $usernameOwner = DB::table('users')
                ->whereRaw('LOWER(username) = ?', [self::TARGET_USERNAME])
                ->lockForUpdate()
                ->first();
            $legacyStaff = DB::table('users')
                ->where('role', 'staff')
                ->whereRaw('LOWER(email) = ?', [self::LEGACY_EMAIL])
                ->lockForUpdate()
                ->first();

            foreach ([$emailOwner, $usernameOwner] as $owner) {
                if ($owner && $owner->role !== 'staff') {
                    throw new \RuntimeException('The configured staff identity belongs to a non-staff account.');
                }
            }

            $candidateIds = collect([$emailOwner, $usernameOwner, $legacyStaff])
                ->filter()
                ->pluck('user_id')
                ->unique()
                ->values();
            if ($candidateIds->count() > 1) {
                throw new \RuntimeException('The legacy and configured staff identities belong to different accounts.');
            }

            $staffId = $candidateIds->first();
            if (! $staffId) {
                return;
            }

            DB::table('users')->where('user_id', $staffId)->update([
                'first_name' => 'Grooming',
                'last_name' => 'Staff',
                'username' => self::TARGET_USERNAME,
                'email' => self::TARGET_EMAIL,
                'staff_type' => 'grooming',
                'email_verified_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('users')
            ->where('role', 'staff')
            ->whereRaw('LOWER(email) = ?', [self::TARGET_EMAIL])
            ->whereRaw('LOWER(username) = ?', [self::TARGET_USERNAME])
            ->update([
                'first_name' => 'Staff',
                'last_name' => 'Bethlehem',
                'username' => null,
                'email' => self::LEGACY_EMAIL,
            ]);

        DB::table('users')
            ->where('role', 'staff')
            ->whereNull('phone')
            ->orderBy('user_id')
            ->eachById(function (object $staff): void {
                DB::table('users')->where('user_id', $staff->user_id)->update([
                    'phone' => 'staff-'.$staff->user_id,
                ]);
            }, column: 'user_id');

        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable(false)->change();
            $table->dropColumn('staff_type');
        });
    }
};
