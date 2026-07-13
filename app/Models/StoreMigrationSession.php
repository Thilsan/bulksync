<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreMigrationSession extends Model
{
    protected $fillable = [
        'user_id',
        'from_store_id',
        'to_store_id',
        'migration_type',
        'token',
        'status',
        'total_skus',
        'success_count',
        'failed_count',
        'error_message',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    public function toStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }
}
