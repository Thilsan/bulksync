<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $fillable = ['name', 'shopify_domain', 'shopify_client_id', 'shopify_client_secret', 'shopify_access_token', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public static function getActive(): ?static
    {
        return static::where('is_active', true)->first();
    }

    public static function switchTo(int $id): void
    {
        static::query()->update(['is_active' => false]);
        static::where('id', $id)->update(['is_active' => true]);
    }
}
