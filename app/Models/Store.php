<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $fillable = ['name', 'shopify_domain', 'shopify_client_id', 'shopify_client_secret', 'shopify_access_token', 'requires_sku_mapping', 'user_id'];

    protected $casts = ['requires_sku_mapping' => 'boolean'];

    /** Websites this user may raise a product creation request against. */
    public static function selectableFor(User $user)
    {
        return static::accessibleBy($user)->orderBy('name')->get();
    }

    /** Every store this user is allowed to work in — super admins see them all. */
    public static function accessibleBy(User $user): Builder
    {
        $query = static::query();

        if (!$user->is_super_admin) {
            $query->whereHas('users', fn ($q) => $q->where('user_id', $user->id));
        }

        return $query;
    }

    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * The store this person is currently working in. Their own choice, never
     * anyone else's — two people can sit on different websites at once.
     */
    public static function getActive(?int $userId = null): ?static
    {
        $user = $userId ? User::find($userId) : auth()->user();

        if (!$user) {
            // Console / queue work with no owner attached. Only meaningful on a
            // single-store install; anything user-facing passes a user id.
            return static::orderBy('name')->first();
        }

        // Access can be taken away after someone picked a store, so the saved
        // choice is re-checked rather than trusted.
        if ($user->active_store_id) {
            $store = static::accessibleBy($user)->whereKey($user->active_store_id)->first();

            if ($store) {
                return $store;
            }
        }

        return static::accessibleBy($user)->orderBy('name')->first();
    }

    /** Returns false when the user has no business in that store. */
    public static function switchTo(int $id, ?User $user = null): bool
    {
        $user ??= auth()->user();

        if (!$user || !static::accessibleBy($user)->whereKey($id)->exists()) {
            return false;
        }

        $user->forceFill(['active_store_id' => $id])->save();

        return true;
    }
}
