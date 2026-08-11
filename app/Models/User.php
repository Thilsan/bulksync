<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'is_super_admin', 'is_active', 'perm_bulk_upload', 'perm_sku_checker', 'perm_image_audit', 'perm_store_sync', 'perm_ai_content', 'perm_metafield_update', 'perm_product_request', 'pcr_role', 'pcr_categories', 'pcr_brand_categories', 'pcr_notify_all', 'onedrive_access_token', 'onedrive_refresh_token', 'onedrive_token_expiry'])]
#[Hidden(['password', 'remember_token', 'onedrive_access_token', 'onedrive_refresh_token', 'onedrive_token_expiry'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_super_admin'    => 'boolean',
            'is_active'         => 'boolean',
            'perm_bulk_upload'  => 'boolean',
            'perm_sku_checker'  => 'boolean',
            'perm_image_audit'  => 'boolean',
            'perm_store_sync'   => 'boolean',
            'perm_ai_content'        => 'boolean',
            'perm_metafield_update'  => 'boolean',
            'perm_product_request'   => 'boolean',
            'pcr_categories'         => 'array',
            'pcr_brand_categories'   => 'array',
            'pcr_notify_all'         => 'boolean',
        ];
    }

    /** Product Creation Request workflow roles — drive notification routing. */
    public const PCR_ROLES = [
        'brand_manager' => 'Brand Manager / Team',
        'supply_chain'  => 'Supply Chain Team',
        'ecommerce'     => 'E-Commerce Team',
        'photographer'  => 'Photoshoot Coordinator',
        'image_editor'  => 'Image / Photo Editor',
        'content'       => 'Content Team',
        'qa'            => 'QA Team',
    ];

    public function pcrRoleLabel(): ?string
    {
        return $this->pcr_role ? (self::PCR_ROLES[$this->pcr_role] ?? $this->pcr_role) : null;
    }

    public function hasPcrRole(string ...$roles): bool
    {
        return in_array($this->pcr_role, $roles, true);
    }

    // ── Category ownership ───────────────────────────────────────────────────

    /** The categories this person handles. */
    public function ownedCategories(): array
    {
        return array_values(array_intersect(
            ProductRequest::CATEGORIES,
            $this->pcr_categories ?? [],
        ));
    }

    public function ownsCategory(string $category): bool
    {
        return in_array($category, $this->pcr_categories ?? [], true);
    }

    /**
     * Who handles a category.
     *
     * A category is meant to belong to exactly one active person; if two records
     * claim it, the lower id wins so the answer is at least stable — the Users
     * screen is where that clash gets sorted out.
     */
    public static function ownerForCategory(?string $category): ?self
    {
        if (blank($category)) {
            return null;
        }

        return self::query()
            ->where('is_active', true)
            ->whereJsonContains('pcr_categories', $category)
            ->orderBy('id')
            ->first();
    }

    /**
     * category => owner, for every category that has one.
     *
     * One query for the whole map — the request form needs all of it at once to
     * tell the requester who will pick the work up.
     *
     * @return array<string, self>
     */
    public static function categoryOwners(): array
    {
        $owners = [];

        foreach (self::query()->where('is_active', true)->whereNotNull('pcr_categories')->orderBy('id')->get() as $member) {
            foreach ($member->ownedCategories() as $category) {
                $owners[$category] ??= $member;
            }
        }

        return $owners;
    }

    // ── Brand managers: kept informed, never given the work ──────────────────

    /** The categories this person follows as brand manager. */
    public function brandManagedCategories(): array
    {
        return array_values(array_intersect(
            ProductRequest::CATEGORIES,
            $this->pcr_brand_categories ?? [],
        ));
    }

    public function managesBrandCategory(string $category): bool
    {
        return in_array($category, $this->pcr_brand_categories ?? [], true);
    }

    /**
     * The brand managers to copy on a category's requests.
     *
     * Unlike ownership this is not exclusive — a category can be followed by
     * several people, and following one is not doing the work on it.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function brandManagersForCategory(?string $category): \Illuminate\Support\Collection
    {
        if (blank($category)) {
            return collect();
        }

        return self::query()
            ->where('is_active', true)
            ->whereJsonContains('pcr_brand_categories', $category)
            ->orderBy('id')
            ->get();
    }

    /**
     * category => brand managers, for the Users screen.
     *
     * @return array<string, \Illuminate\Support\Collection<int, self>>
     */
    public static function categoryBrandManagers(): array
    {
        $managers = [];

        foreach (self::query()->where('is_active', true)->whereNotNull('pcr_brand_categories')->orderBy('id')->get() as $member) {
            foreach ($member->brandManagedCategories() as $category) {
                $managers[$category] ??= collect();
                $managers[$category]->push($member);
            }
        }

        return $managers;
    }

    /**
     * Accounts copied on every product request message.
     *
     * The shared e-commerce inbox watches the whole process without holding a
     * role on any single request, so it cannot be reached through assignments
     * the way everyone else is.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function requestWatchers(): \Illuminate\Support\Collection
    {
        return self::query()
            ->where('is_active', true)
            ->where('pcr_notify_all', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * Who arranges the shoots.
     *
     * One person coordinates every photoshoot, so a request that needs one can be
     * handed straight to them. If two accounts hold the role there is no right
     * answer — the requester is asked instead of the system picking at random.
     *
     * The stored key is still 'photographer'; only what we call it changed.
     */
    public static function photoshootCoordinator(): ?self
    {
        $coordinators = self::query()
            ->where('is_active', true)
            ->where('pcr_role', 'photographer')
            ->orderBy('id')
            ->limit(2)
            ->get();

        return $coordinators->count() === 1 ? $coordinators->first() : null;
    }

    // ── Notifications: mine, versus the team's ───────────────────────────────

    /**
     * Notifications about this person's own work.
     *
     * Every message carries a for_me flag decided when it was written. `data` is
     * a text column rather than JSON, so this matches on the encoded payload —
     * which behaves the same on MySQL and SQLite, unlike the JSON operators.
     */
    public function ownNotifications(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->notifications()->where('data', 'like', '%"for_me":true%');
    }

    public function unreadOwnNotifications(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->unreadNotifications()->where('data', 'like', '%"for_me":true%');
    }

    public function productRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductRequest::class);
    }

    public function stores(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Store::class);
    }

    public function hasFeature(string $feature): bool
    {
        if ($this->is_super_admin) return true;
        return (bool) $this->{"perm_{$feature}"};
    }

    public function getHasOnedriveAttribute(): bool
    {
        return !empty($this->onedrive_access_token);
    }
}
