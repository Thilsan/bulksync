<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class AdminSetup extends Command
{
    protected $signature   = 'admin:setup';
    protected $description = 'Create the initial super admin user from env vars if none exists';

    public function handle(): int
    {
        if (User::where('is_super_admin', true)->exists()) {
            $this->info('Super admin already exists. Skipping.');
            return 0;
        }

        $email    = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->warn('ADMIN_EMAIL or ADMIN_PASSWORD not set. Skipping admin setup.');
            return 0;
        }

        User::create([
            'name'           => 'Admin',
            'email'          => $email,
            'password'       => Hash::make($password),
            'is_super_admin' => true,
            'is_active'      => true,
        ]);

        $this->info("Super admin created: {$email}");
        return 0;
    }
}
