<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's slice of a request: what they have to do, and by when.
 */
class ProductRequestAssignment extends Model
{
    protected $fillable = [
        'product_request_id',
        'role',
        'user_id',
        'title',
        'due_date',
        'assigned_by',
        'completed_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date'     => 'date',
            'completed_at' => 'datetime',
            'ended_at'     => 'datetime',
        ];
    }

    public function productRequest(): BelongsTo
    {
        return $this->belongsTo(ProductRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** The live assignment for a role — the one that has not been handed on. */
    public function scopeCurrent($query)
    {
        return $query->whereNull('ended_at');
    }

    /** Closed rows: who used to hold this, and for how long. */
    public function scopeHistoric($query)
    {
        return $query->whereNotNull('ended_at');
    }

    public function isCurrent(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Days this person actually held the role — finished at handover, otherwise
     * still running. This is the number "how long does editing take" is built on.
     */
    public function heldForDays(): int
    {
        $until = $this->ended_at ?? now();

        return (int) $this->created_at->startOfDay()->diffInDays($until->startOfDay());
    }

    public function roleLabel(): string
    {
        return ProductRequest::ASSIGNMENT_ROLES[$this->role] ?? $this->role;
    }

    /** Falls back to the role name so a row never reads as a blank task. */
    public function taskTitle(): string
    {
        return filled($this->title) ? $this->title : $this->roleLabel();
    }

    /** Whole days until this person's own deadline; negative once passed. */
    public function daysLeft(): ?int
    {
        if (!$this->due_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->due_date->startOfDay(), false);
    }

    /**
     * Late only matters while the work is still outstanding — a deadline that
     * passed after the job was finished isn't something to chase anyone about.
     */
    public function isOverdue(): bool
    {
        if ($this->completed_at || !$this->due_date) {
            return false;
        }

        return $this->daysLeft() < 0;
    }

    public function isDueSoon(int $within = 2): bool
    {
        if ($this->completed_at || !$this->due_date) {
            return false;
        }

        $days = $this->daysLeft();

        return $days >= 0 && $days <= $within;
    }

    /** Tailwind classes for the deadline chip, escalating as it closes in. */
    public function dueTone(): string
    {
        return match (true) {
            $this->completed_at !== null => 'bg-gray-100 text-gray-500',
            $this->isOverdue()           => 'bg-red-100 text-red-800',
            $this->isDueSoon(0)          => 'bg-orange-100 text-orange-800',
            $this->isDueSoon()           => 'bg-amber-100 text-amber-800',
            default                      => 'bg-gray-100 text-gray-600',
        };
    }

    public function dueLabel(): string
    {
        if (!$this->due_date) {
            return 'No deadline';
        }

        $days = $this->daysLeft();

        return match (true) {
            $this->completed_at !== null => 'Done ' . $this->completed_at->format('d M'),
            $days < 0                    => abs($days) . 'd overdue',
            $days === 0                  => 'Due today',
            default                      => "Due in {$days}d",
        };
    }
}
