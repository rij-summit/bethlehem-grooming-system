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
            $this->command?->warn('Staff user not seeded: set STAFF_SEED_EMAIL, STAFF_SEED_PHONE, and STAFF_SEED_PASSWORD.');
            return;
        }

        $this->validateCredentials($credentials);
        $email = Str::lower($credentials['email']);
        $existing = User::where('email', $email)->first();
        if ($existing && $existing->role !== 'staff') {
            throw new InvalidArgumentException('STAFF_SEED_EMAIL already belongs to a non-staff account.');
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'first_name' => 'Staff',
                'last_name' => 'Bethlehem',
                'phone' => $credentials['phone'],
                'password_hash' => Hash::make($credentials['password']),
                'role' => 'staff',
                'customer_tier' => 'new',
                'is_active' => 1,
                'is_archived' => 0,
                'email_verified_at' => now(),
            ],
        );
    }

    private function credentialsArePresent(array $credentials): bool
    {
        return filled($credentials['email'])
            && filled($credentials['phone'])
            && filled($credentials['password']);
    }

    private function validateCredentials(array $credentials): void
    {
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
