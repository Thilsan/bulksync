<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whether one SKU already had its photo on Shopify when a given upload
 * session first reached it. See UploadBaselineResolver for how the answer is
 * claimed and shared between the sibling files of the same SKU folder.
 */
class UploadSkuBaseline extends Model
{
    protected $fillable = [
        'upload_session_id',
        'scope',
        'scope_id',
        'has_existing_image',
        'variant_image_claimed',
    ];

    protected $casts = [
        'has_existing_image'    => 'boolean',
        'variant_image_claimed' => 'boolean',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(UploadSession::class, 'upload_session_id');
    }
}
