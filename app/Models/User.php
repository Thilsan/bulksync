<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'is_super_admin', 'is_active', 'onedrive_access_token', 'onedrive_refresh_token', 'onedrive_token_expiry'])]
#[Hidden(['password', 'remember_token', 'onedrive_access_token', 'onedrive_refresh_token', 'onedrive_token_expiry'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_super_admin'    => 'boolean',
            'is_active'         => 'boolean',
        ];
    }

    /**
     * Whether this user has a connected OneDrive account.
     */
    public function getHasOnedriveAttribute(): bool
    {
        return !empty($this->onedrive_access_token);
    }
}
