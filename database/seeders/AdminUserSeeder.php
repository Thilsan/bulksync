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
            ['email' => 'admin@bulksync.local'],
            [
                'name'     => 'Admin',
                'email'    => 'admin@bulksync.local',
                'password' => Hash::make('password'),
            ]
        );

        $this->command->info('Admin user created: admin@bulksync.local / password');
    }
}
