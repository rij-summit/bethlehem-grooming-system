<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'first_name'    => 'Admin',
            'last_name'     => 'Bethlehem',
            'email'         => 'admin@bethlehem.com',
            'phone'         => '09000000000',
            'password_hash' => Hash::make('admin123'),
            'role'          => 'admin',
            'customer_tier' => 'new',
            'is_active'     => 1,
        ]);
    }
}
