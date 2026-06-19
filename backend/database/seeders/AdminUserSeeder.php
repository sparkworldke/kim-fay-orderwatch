<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@fayshop.co.ke'],
            [
                'name'     => 'Admin User',
                'password' => Hash::make('password'),
                'role'     => 'Administrator',
                'email_verified_at' => now(),
                'is_active' => true,
                'is_super_admin' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'csm@fayshop.co.ke'],
            [
                'name'     => 'CS Manager',
                'password' => Hash::make('password'),
                'role'     => 'Customer Service Manager',
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );
    }
}
