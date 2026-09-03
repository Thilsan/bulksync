<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'is_super_admin', 'is_active', 'active_store_id', 'perm_bulk_upload', 'perm_sku_checker', 'perm_image_audit', 'perm_store_sync', 'perm_ai_content', 'perm_metafield_update', 'perm_product_request', 'perm_photo_editor', 'perm_orders_dashboard', 'pcr_role', 'pcr_categories', 'pcr_brand_categories', 'pcr_owned_brands', 'pcr_managed_brands', 'pcr_store_categories', 'pcr_brand_store_categories', 'pcr_notify_all', 'onedrive_access_token', 'onedrive_refresh_token', 'onedrive_token_expiry'])]
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
            'perm_photo_editor'      => 'boolean',
            'perm_orders_dashboard'  => 'boolean',
            'pcr_categories'         => 'array',
            'pcr_brand_categories'   => 'array',
            'pcr_owned_brands'       => 'array',
            'pcr_store_categories'   => 'array',
            'pcr_brand_store_categories' => 'array',
            'pcr_managed_brands'     => 'array',
            'pcr_notify_all'         => 'boolean',
        ];
    }

    /** Product Creation Request workflow roles — drive notification routing. */
    public const PCR_ROLES = [
        'brand_manager' => 'Brand Manager / Team',

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
    /**
     * One spelling for a brand.
     *
     * The tracking sheet writes "COLE HAAN ", "Cole Haan" and "RAGO " for the
     * same brands, so a setting matched literally would miss most of them.
     */
    public static function normalizeBrand(?string $brand): ?string
    {
        $brand = strtoupper(trim((string) $brand));

        return $brand === '' ? null : $brand;
    }

    /** Brands this user handles end to end, overriding their categories. */
    public function ownedBrands(): array
    {
        return array_values(array_filter($this->pcr_owned_brands ?? []));
    }

    /** Brands this user is brand manager for, overriding their categories. */
    public function managedBrands(): array
    {
        return array_values(array_filter($this->pcr_managed_brands ?? []));
    }

    /**
     * Whoever a named brand belongs to, ignoring category entirely.
     *
     * @param  string  $column  pcr_owned_brands or pcr_managed_brands
     */
    private static function forBrand(string $column, ?string $brand): \Illuminate\Support\Collection
    {
        $brand = self::normalizeBrand($brand);

        if ($brand === null) {
            return collect();
        }

        return self::query()
            ->where('is_active', true)
            ->whereJsonContains($column, $brand)
            ->orderBy('id')
            ->get();
    }

    /**
     * How a category on one website is stored: "3|Watches".
     *
     * Watches on Blue Salon and Watches on PG are two jobs done by two people,
     * and a plain category list cannot say that.
     */
    public static function storeCategoryKey(?int $storeId, ?string $category): ?string
    {
        return $storeId && filled($category) ? "{$storeId}|{$category}" : null;
    }

    /**
     * Whoever handles a request, most specific answer first.
     *
     * A named brand beats a website, and a website beats the plain category —
     * each is only ever set because the broader answer is not the right one.
     */
    public static function ownerForCategory(?string $category, ?string $brand = null, ?int $storeId = null): ?self
    {
        if ($named = self::forBrand('pcr_owned_brands', $brand)->first()) {
            return $named;
        }

        if ($key = self::storeCategoryKey($storeId, $category)) {
            $onThisWebsite = self::query()
                ->where('is_active', true)
                ->whereJsonContains('pcr_store_categories', $key)
                ->orderBy('id')
                ->first();

            if ($onThisWebsite) {
                return $onThisWebsite;
            }
        }

        if (blank($category)) {
            return null;
        }

        return self::query()
            ->where('is_active', true)
            ->whereJsonContains('pcr_categories', $category)
            ->orderBy('id')
            ->first();
    }

    /** store id => [category, ...] this user handles on that website alone. */
    public function storeCategories(): array
    {
        $out = [];

        foreach ($this->pcr_store_categories ?? [] as $key) {
            [$storeId, $category] = array_pad(explode('|', (string) $key, 2), 2, null);

            if ($storeId && $category) {
                $out[(int) $storeId][] = $category;
            }
        }

        return $out;
    }

    /** "3|Watches" => the person who holds it, for the Users screen. */
    public static function storeCategoryOwners(): array
    {
        $map = [];

        foreach (self::query()->where('is_active', true)->whereNotNull('pcr_store_categories')->orderBy('id')->get() as $user) {
            foreach ($user->pcr_store_categories ?? [] as $key) {
                $map[$key] ??= $user;   // first by id, the same rule staffing uses
            }
        }

        return $map;
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

    /**
     * Someone whose whole job on a request is the brand side.
     *
     * Their screens are narrowed to it: the two things asked of them, and whether
     * their brands are live yet. A super admin is excluded — they need the whole
     * picture even if they also hold the role.
     */
    public function isBrandManagerOnly(): bool
    {
        return $this->pcr_role === 'brand_manager'
            && !$this->is_super_admin
            && !$this->pcr_notify_all;
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
    /**
     * The one brand manager a request in this category is given.
     *
     * More than one person can follow a category, but only one can hold the task
     * — and which one it is has to be the same answer everywhere, or the Users
     * screen shows a name the staffing does not use. Oldest account wins, and the
     * screen prints it.
     */
    public static function brandManagerForCategory(?string $category, ?string $brand = null, ?int $storeId = null): ?self
    {
        return self::brandManagersForCategory($category, $brand, $storeId)->first();
    }

    /** category => the brand manager it falls to, for the Users screen. */
    public static function brandManagerMap(): array
    {
        $map = [];

        foreach (self::query()->where('is_active', true)->whereNotNull('pcr_brand_categories')->orderBy('id')->get() as $user) {
            foreach ($user->pcr_brand_categories ?? [] as $category) {
                $map[$category] ??= $user;   // first by id, the same rule staffing uses
            }
        }

        return $map;
    }

    /**
     * brand => whoever handles it, for the Users screen.
     *
     * @return array<string, self>
     */
    public static function brandOwnerMap(): array
    {
        return self::mapByBrand('pcr_owned_brands');
    }

    /** brand => whoever is its brand manager. */
    public static function brandManagerByBrandMap(): array
    {
        return self::mapByBrand('pcr_managed_brands');
    }

    /** @return array<string, self> */
    private static function mapByBrand(string $column): array
    {
        $map = [];

        foreach (self::query()->where('is_active', true)->whereNotNull($column)->orderBy('id')->get() as $user) {
            foreach ($user->{$column} ?? [] as $brand) {
                $map[$brand] ??= $user;   // first by id, the same rule staffing uses
            }
        }

        return $map;
    }

    public static function brandManagersForCategory(?string $category, ?string $brand = null, ?int $storeId = null): \Illuminate\Support\Collection
    {
        // Named for this brand specifically: they are the answer, and the
        // category's people are not copied — the point of naming a brand is that
        // it is handled apart from the rest.
        if (($named = self::forBrand('pcr_managed_brands', $brand))->isNotEmpty()) {
            return $named;
        }

        // Leather Goods on Blue Salon and Leather Goods on Samsonite are two
        // brand sides followed by two people, and a plain category list cannot
        // say that. Named for this pairing, they are the answer for this website
        // alone and the category's people are not copied on it.
        if ($key = self::storeCategoryKey($storeId, $category)) {
            $onThisWebsite = self::query()
                ->where('is_active', true)
                ->whereJsonContains('pcr_brand_store_categories', $key)
                ->orderBy('id')
                ->get();

            if ($onThisWebsite->isNotEmpty()) {
                return $onThisWebsite;
            }
        }

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
     * store id => [category, ...] this person follows on that website alone.
     *
     * The mirror of storeCategories() for the brand side: same "3|Watches" keys,
     * a different column, because following a category is not doing its work.
     */
    public function brandStoreCategories(): array
    {
        $out = [];

        foreach ($this->pcr_brand_store_categories ?? [] as $key) {
            [$storeId, $category] = array_pad(explode('|', (string) $key, 2), 2, null);

            if ($storeId && $category) {
                $out[(int) $storeId][] = $category;
            }
        }

        return $out;
    }

    /** "3|Watches" => the brand manager it falls to, for the Users screen. */
    public static function storeCategoryBrandManagers(): array
    {
        $map = [];

        foreach (self::query()->where('is_active', true)->whereNotNull('pcr_brand_store_categories')->orderBy('id')->get() as $user) {
            foreach ($user->pcr_brand_store_categories ?? [] as $key) {
                $map[$key] ??= $user;   // first by id, the same rule staffing uses
            }
        }

        return $map;
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

    /** The website this person is working in — their own, not the company's. */
    public function activeStore(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Store::class, 'active_store_id');
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
