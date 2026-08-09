<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'is_super_admin', 'is_active', 'perm_bulk_upload', 'perm_sku_checker', 'perm_image_audit', 'perm_store_sync', 'perm_ai_content', 'perm_metafield_update', 'perm_product_request', 'pcr_role', 'onedrive_access_token', 'onedrive_refresh_token', 'onedrive_token_expiry'])]
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
        ];
    }

    /** Product Creation Request workflow roles — drive notification routing. */
    public const PCR_ROLES = [
        'brand_manager' => 'Brand Manager / Team',
        'supply_chain'  => 'Supply Chain Team',
        'ecommerce'     => 'E-Commerce Team',
        'photographer'  => 'Photographer',
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
