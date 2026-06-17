<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $fillable = ['name', 'shopify_domain', 'shopify_client_id', 'shopify_client_secret', 'shopify_access_token', 'is_active', 'user_id'];

    protected $casts = ['is_active' => 'boolean'];

    public static function getActive(?int $userId = null): ?static
    {
        $user = $userId ? \App\Models\User::find($userId) : auth()->user();

        $query = static::where('is_active', true);

        if ($user && !$user->is_super_admin) {
            $query->where('user_id', $user->id);
        }

        return $query->first();
    }

    public static function switchTo(int $id): void
    {
        $user = auth()->user();

        if ($user && !$user->is_super_admin) {
            // Only deactivate stores belonging to this user
            static::where('user_id', $user->id)->update(['is_active' => false]);
        } else {
            static::query()->update(['is_active' => false]);
        }

        static::where('id', $id)->update(['is_active' => true]);
    }
}
