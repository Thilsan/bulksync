<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionContentItem extends Model
{
    protected $fillable = [
        'session_id', 'collection_id', 'title', 'handle',
        'existing_description', 'existing_meta_title', 'existing_meta_description',
        'ai_description', 'ai_meta_title', 'ai_meta_description',
        'status', 'is_confirmed', 'error_message',
    ];

    protected $casts = ['is_confirmed' => 'boolean'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CollectionContentSession::class, 'session_id');
    }

    /** The public URL path, for the review screen and for Search Console. */
    public function path(): string
    {
        return '/collections/' . $this->handle;
    }
}
