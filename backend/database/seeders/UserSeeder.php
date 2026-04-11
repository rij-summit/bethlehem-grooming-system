<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'first_name'    => 'Juan',
            'last_name'     => 'Dela Cruz',
            'email'         => 'juan23@test.com',
            'phone'         => '09000000000',
            'password' => Hash::make('juan2345'),
            'role'          => 'customer',
            'customer_tier' => 'new',
            'is_active'     => 1,
        ]);
    }
}
