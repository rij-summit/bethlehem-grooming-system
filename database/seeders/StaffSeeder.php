<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        $credentials = config('app.privileged_seed_accounts.staff');

        if (! $this->credentialsArePresent($credentials)) {
            $this->command?->warn('Staff user not seeded: set STAFF_SEED_USERNAME, STAFF_SEED_EMAIL, STAFF_SEED_PHONE, and STAFF_SEED_PASSWORD.');
            return;
        }

        $this->validateCredentials($credentials);
        $email = Str::lower(trim($credentials['email']));
        $username = Str::lower(trim($credentials['username']));
        $emailOwner = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $usernameOwner = User::query()->whereRaw('LOWER(username) = ?', [$username])->first();
        $legacyStaff = User::query()
            ->where('role', 'staff')
            ->whereRaw('LOWER(email) = ?', ['staff@bethlehem.com'])
            ->first();

        if ($emailOwner && $emailOwner->role !== 'staff') {
            throw new InvalidArgumentException('STAFF_SEED_EMAIL already belongs to a non-staff account.');
        }

        if ($usernameOwner && $usernameOwner->role !== 'staff') {
            throw new InvalidArgumentException('STAFF_SEED_USERNAME already belongs to a non-staff account.');
        }

        $candidateIds = collect([$emailOwner, $usernameOwner, $legacyStaff])
            ->filter()
            ->map(fn (User $user): int => $user->user_id)
            ->unique();
        if ($candidateIds->count() > 1) {
            throw new InvalidArgumentException('STAFF_SEED_EMAIL and STAFF_SEED_USERNAME belong to different staff accounts.');
        }

        $staff = $emailOwner ?? $usernameOwner ?? $legacyStaff ?? new User();
        $staff->fill([
            'first_name' => 'Grooming',
            'last_name' => 'Staff',
            'username' => $username,
            'email' => $email,
            'phone' => $credentials['phone'],
            'password_hash' => Hash::make($credentials['password']),
            'role' => 'staff',
            'staff_type' => 'grooming',
            'customer_tier' => 'new',
            'is_active' => 1,
            'is_archived' => 0,
            'email_verified_at' => now(),
        ])->save();
    }

    private function credentialsArePresent(array $credentials): bool
    {
        return filled($credentials['username'])
            && filled($credentials['email'])
            && filled($credentials['phone'])
            && filled($credentials['password']);
    }

    private function validateCredentials(array $credentials): void
    {
        if (! preg_match('/^[A-Za-z][A-Za-z0-9._-]{2,49}$/', trim($credentials['username']))) {
            throw new InvalidArgumentException('STAFF_SEED_USERNAME must be a valid login username.');
        }

        if (! filter_var($credentials['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('STAFF_SEED_EMAIL must be a valid email address.');
        }

        if (! preg_match('/^09\d{9}$/', $credentials['phone'])) {
            throw new InvalidArgumentException('STAFF_SEED_PHONE must be an 11-digit Philippine mobile number.');
        }

        if (strlen($credentials['password']) < 12) {
            throw new InvalidArgumentException('STAFF_SEED_PASSWORD must contain at least 12 characters.');
        }
    }
}
