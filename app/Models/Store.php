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

        return static::where('is_active', true)->first();
    }

    public static function switchTo(int $id): void
    {
        $user = auth()->user();

        static::query()->update(['is_active' => false]);

        static::where('id', $id)->update(['is_active' => true]);
    }
}
