<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PromoteToSuperAdmin extends Command
{
    protected $signature   = 'admin:promote {email}';
    protected $description = 'Promote a user to super admin by email';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user  = User::where('email', $email)->first();

        if (!$user) {
            $this->error("No user found with email: {$email}");
            return 1;
        }

        $user->update(['is_super_admin' => true, 'is_active' => true]);

        $this->info("✓ {$user->name} ({$email}) is now a super admin.");

        return 0;
    }
}
