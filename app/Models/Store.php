<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $fillable = ['name', 'shopify_domain', 'shopify_client_id', 'shopify_client_secret', 'shopify_access_token', 'is_active', 'user_id'];

    protected $casts = ['is_active' => 'boolean'];

    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public static function getActive(?int $userId = null): ?static
    {
        $user = $userId ? User::find($userId) : auth()->user();

        $query = static::where('is_active', true);

        if ($user && !$user->is_super_admin) {
            $query->whereHas('users', fn ($q) => $q->where('user_id', $user->id));
        }

        return $query->first();
    }

    public static function switchTo(int $id): void
    {
        $user = auth()->user();

        if ($user && !$user->is_super_admin) {
            // Only deactivate stores this user has access to
            static::whereHas('users', fn ($q) => $q->where('user_id', $user->id))
                ->update(['is_active' => false]);
        } else {
            static::query()->update(['is_active' => false]);
        }

        static::where('id', $id)->update(['is_active' => true]);
    }
}
