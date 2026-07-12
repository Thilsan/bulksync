<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

#[Fillable(['user_id', 'action', 'description', 'ip_address', 'user_agent', 'created_at'])]
class ActivityLog extends Model
{
    public const ACTION_LOGIN        = 'login';
    public const ACTION_LOGIN_FAILED = 'login_failed';
    public const ACTION_LOGOUT       = 'logout';
    public const ACTION_PAGE_VIEW    = 'page_view';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $action, ?string $description = null, ?int $userId = null): void
    {
        try {
            static::create([
                'user_id'     => $userId ?? Auth::id(),
                'action'      => $action,
                'description' => $description,
                'ip_address'  => request()->ip(),
                'user_agent'  => substr((string) request()->userAgent(), 0, 512),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            report($e); // logging must never break the request
        }
    }
}
