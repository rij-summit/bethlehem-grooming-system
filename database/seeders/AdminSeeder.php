<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $credentials = config('app.privileged_seed_accounts.admin');

        if (! $this->credentialsArePresent($credentials)) {
            $this->command?->warn('Admin user not seeded: set ADMIN_SEED_USERNAME, ADMIN_SEED_EMAIL, ADMIN_SEED_PHONE, and ADMIN_SEED_PASSWORD.');
            return;
        }

        $this->validateCredentials($credentials);
        $email = Str::lower(trim($credentials['email']));
        $username = trim($credentials['username']);
        $emailOwner = User::where('email', $email)->first();
        $usernameOwner = User::where('username', $username)->first();

        if ($emailOwner && $emailOwner->role !== 'admin') {
            throw new InvalidArgumentException('ADMIN_SEED_EMAIL already belongs to a non-admin account.');
        }

        if ($usernameOwner && $usernameOwner->role !== 'admin') {
            throw new InvalidArgumentException('ADMIN_SEED_USERNAME already belongs to a non-admin account.');
        }

        if ($emailOwner && $usernameOwner && ! $emailOwner->is($usernameOwner)) {
            throw new InvalidArgumentException('ADMIN_SEED_EMAIL and ADMIN_SEED_USERNAME belong to different admin accounts.');
        }

        $admin = $emailOwner ?? $usernameOwner ?? new User();
        $admin->fill([
            'username' => $username,
            'email' => $email,
            'first_name' => 'Admin',
            'last_name' => 'Bethlehem',
            'phone' => $credentials['phone'],
            'password_hash' => Hash::make($credentials['password']),
            'role' => 'admin',
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
        $usernameLength = mb_strlen(trim($credentials['username']));
        if ($usernameLength === 0 || $usernameLength > 50) {
            throw new InvalidArgumentException('ADMIN_SEED_USERNAME must contain between 1 and 50 characters.');
        }

        if (! filter_var($credentials['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('ADMIN_SEED_EMAIL must be a valid email address.');
        }

        if (! preg_match('/^09\d{9}$/', $credentials['phone'])) {
            throw new InvalidArgumentException('ADMIN_SEED_PHONE must be an 11-digit Philippine mobile number.');
        }

        if (strlen($credentials['password']) < 12) {
            throw new InvalidArgumentException('ADMIN_SEED_PASSWORD must contain at least 12 characters.');
        }
    }
}
