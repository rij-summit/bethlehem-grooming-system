<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'first_name'    => 'Staff',
            'last_name'     => 'Bethlehem',
            'email'         => 'staff@bethlehem.com',
            'phone'         => '09111111111',
            'password_hash' => Hash::make('staff123'),
            'role'          => 'staff',
            'customer_tier' => 'new',
            'is_active'     => 1,
        ]);
    }
}
