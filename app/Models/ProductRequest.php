<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ProductRequest extends Model
{
    // ── Workflow statuses ────────────────────────────────────────────────────
    public const SUBMITTED            = 'submitted';
    public const WAITING_MAPPING      = 'waiting_for_mapping';
    public const SKU_VERIFIED         = 'sku_verified';
    public const WAITING_IMAGES       = 'waiting_for_images';
    public const PHOTOSHOOT_SCHEDULED = 'photoshoot_scheduled';
    public const PHOTOSHOOT_COMPLETED = 'photoshoot_completed';
    public const IMAGE_EDITING        = 'image_editing';
    public const AI_CONTENT           = 'ai_content_generation';
    public const QA_REVIEW            = 'qa_review';
    public const READY_FOR_UPLOAD     = 'ready_for_upload';
    public const PUBLISHED            = 'published';
    public const COMPLETED            = 'completed';
    public const CANCELLED            = 'cancelled';

    /** Ordered pipeline — drives the progress stepper and "is this a step forward?" checks. */
    public const PIPELINE = [
        self::SUBMITTED,
        self::WAITING_MAPPING,
        self::SKU_VERIFIED,
        self::WAITING_IMAGES,
        self::PHOTOSHOOT_SCHEDULED,
        self::PHOTOSHOOT_COMPLETED,
        self::IMAGE_EDITING,
        self::AI_CONTENT,
        self::QA_REVIEW,
        self::READY_FOR_UPLOAD,
        self::PUBLISHED,
        self::COMPLETED,
    ];

    public const STATUS_LABELS = [
        self::SUBMITTED            => 'Submitted',
        self::WAITING_MAPPING      => 'Waiting for Mapping',
        self::SKU_VERIFIED         => 'SKU Verified',
        self::WAITING_IMAGES       => 'Waiting for Images',
        self::PHOTOSHOOT_SCHEDULED => 'Photoshoot Scheduled',
        self::PHOTOSHOOT_COMPLETED => 'Photoshoot Completed',
        self::IMAGE_EDITING        => 'Image Editing',
        self::AI_CONTENT           => 'AI Content Generation',
        self::QA_REVIEW            => 'QA Review',
        self::READY_FOR_UPLOAD     => 'Ready for Upload',
        self::PUBLISHED            => 'Published',
        self::COMPLETED            => 'Completed',
        self::CANCELLED            => 'Cancelled',
    ];

    /** Tailwind classes per status — one source of truth for every badge in the module. */
    public const STATUS_COLORS = [
        self::SUBMITTED            => 'bg-green-50 text-green-700 border-green-200',
        self::WAITING_MAPPING      => 'bg-amber-50 text-amber-700 border-amber-200',
        self::SKU_VERIFIED         => 'bg-teal-50 text-teal-700 border-teal-200',
        self::WAITING_IMAGES       => 'bg-orange-50 text-orange-700 border-orange-200',
        self::PHOTOSHOOT_SCHEDULED => 'bg-purple-50 text-purple-700 border-purple-200',
        self::PHOTOSHOOT_COMPLETED => 'bg-purple-50 text-purple-700 border-purple-200',
        self::IMAGE_EDITING        => 'bg-indigo-50 text-indigo-700 border-indigo-200',
        self::AI_CONTENT           => 'bg-orange-50 text-orange-700 border-orange-200',
        self::QA_REVIEW            => 'bg-sky-50 text-sky-700 border-sky-200',
        self::READY_FOR_UPLOAD     => 'bg-blue-50 text-blue-700 border-blue-200',
        self::PUBLISHED            => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        self::COMPLETED            => 'bg-gray-100 text-gray-700 border-gray-200',
        self::CANCELLED            => 'bg-red-50 text-red-700 border-red-200',
    ];

    public const PRIORITIES = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];

    public const PRIORITY_COLORS = [
        'high'   => 'bg-red-50 text-red-700 border-red-200',
        'medium' => 'bg-amber-50 text-amber-700 border-amber-200',
        'low'    => 'bg-gray-100 text-gray-600 border-gray-200',
    ];

    // ── SKU mapping states ───────────────────────────────────────────────────
    public const MAP_MAPPED     = 'mapped';
    public const MAP_PENDING    = 'pending';
    public const MAP_NOT_MAPPED = 'not_mapped';

    protected $fillable = [
        'reference',
        'user_id',
        'store_id',
        'request_type',
        'brand',
        'category',
        'sub_category',
        'department',
        'collection',
        'status',
        'priority',
        'store_launch_date',
        'online_launch_date',
        'supplier_images_available',
        'photoshoot_required',
        'photoshoot_scheduled_at',
        'notes',
        'validation_status',
        'total_skus',
        'mapped_skus',
        'pending_skus',
        'not_mapped_skus',
        'validated_at',
        'validation_error',
        'assigned_to',
        'photographer_id',
        'content_owner_id',
        'qa_owner_id',
        'published_at',
        'completed_at',
        'cancelled_at',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'store_launch_date'         => 'date',
            'online_launch_date'        => 'date',
            'photoshoot_scheduled_at'   => 'date',
            'supplier_images_available' => 'boolean',
            'photoshoot_required'       => 'boolean',
            'validated_at'              => 'datetime',
            'published_at'              => 'datetime',
            'completed_at'              => 'datetime',
            'cancelled_at'              => 'datetime',
        ];
    }

    // ── Relations ────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function photographer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }

    public function contentOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'content_owner_id');
    }

    public function qaOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qa_owner_id');
    }

    public function skus(): HasMany
    {
        return $this->hasMany(ProductRequestSku::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ProductRequestActivity::class)->latest('created_at')->latest('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ProductRequestAttachment::class);
    }

    // ── Reference generation ─────────────────────────────────────────────────

    /**
     * PCR-<year>-<5 digit sequence>. Locks the year's rows so two concurrent
     * submissions can't claim the same number — the unique index would reject
     * the loser and lose the user's whole form otherwise.
     */
    public static function nextReference(): string
    {
        $year = now()->format('Y');

        return DB::transaction(function () use ($year) {
            $last = static::where('reference', 'like', "PCR-{$year}-%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('reference');

            $seq = $last ? ((int) substr($last, -5)) + 1 : 1;

            return sprintf('PCR-%s-%05d', $year, $seq);
        });
    }

    // ── Workflow helpers ─────────────────────────────────────────────────────

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function statusColor(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'bg-gray-100 text-gray-700 border-gray-200';
    }

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->priority] ?? $this->priority;
    }

    public function priorityColor(): string
    {
        return self::PRIORITY_COLORS[$this->priority] ?? 'bg-gray-100 text-gray-600 border-gray-200';
    }

    public function stageIndex(): int
    {
        $i = array_search($this->status, self::PIPELINE, true);
        return $i === false ? -1 : $i;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::CANCELLED], true);
    }

    public function isBlockedOnMapping(): bool
    {
        return $this->status === self::WAITING_MAPPING;
    }

    /** Every SKU mapped — the gate for leaving "Waiting for Mapping". */
    public function isFullyMapped(): bool
    {
        return $this->total_skus > 0 && $this->mapped_skus === $this->total_skus;
    }

    public function progressPercent(): int
    {
        if ($this->status === self::CANCELLED) return 0;
        $i = $this->stageIndex();
        if ($i < 0) return 0;

        return (int) round(($i + 1) / count(self::PIPELINE) * 100);
    }

    /**
     * The stage that naturally follows the current one for THIS request.
     * Branches on the two questions the form asks: is mapping outstanding, and
     * do we need a photoshoot at all.
     */
    public function suggestedNextStatus(): ?string
    {
        return match ($this->status) {
            self::SUBMITTED => $this->isFullyMapped() ? self::SKU_VERIFIED : self::WAITING_MAPPING,

            self::WAITING_MAPPING => $this->isFullyMapped() ? self::SKU_VERIFIED : null,

            // Supplier already sent images → straight to editing, no photoshoot leg.
            self::SKU_VERIFIED => $this->photoshoot_required
                ? self::WAITING_IMAGES
                : ($this->supplier_images_available ? self::IMAGE_EDITING : self::WAITING_IMAGES),

            self::WAITING_IMAGES       => $this->photoshoot_required ? self::PHOTOSHOOT_SCHEDULED : self::IMAGE_EDITING,
            self::PHOTOSHOOT_SCHEDULED => self::PHOTOSHOOT_COMPLETED,
            self::PHOTOSHOOT_COMPLETED => self::IMAGE_EDITING,
            self::IMAGE_EDITING        => self::AI_CONTENT,
            self::AI_CONTENT           => self::QA_REVIEW,
            self::QA_REVIEW            => self::READY_FOR_UPLOAD,
            self::READY_FOR_UPLOAD     => self::PUBLISHED,
            self::PUBLISHED            => self::COMPLETED,

            default => null,
        };
    }

    /**
     * Statuses a user may move this request to. The suggested next stage plus
     * any later stage (skipping is legitimate — e.g. no photoshoot needed) and
     * one step back, so a QA rejection can bounce the request without an admin.
     */
    public function allowedTransitions(): array
    {
        if ($this->isClosed()) {
            return [];
        }

        $i = $this->stageIndex();
        if ($i < 0) {
            return [];
        }

        $allowed = [];

        // Can't leave "Waiting for Mapping" until every SKU resolves.
        if ($this->status === self::WAITING_MAPPING && !$this->isFullyMapped()) {
            return [self::CANCELLED];
        }

        foreach (self::PIPELINE as $j => $status) {
            if ($j > $i) {
                $allowed[] = $status;
            }
        }

        // One step back for rework (QA bounce, re-shoot).
        if ($i > 0) {
            array_unshift($allowed, self::PIPELINE[$i - 1]);
        }

        $allowed[] = self::CANCELLED;

        return $allowed;
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    public function daysToOnlineLaunch(): ?int
    {
        if (!$this->online_launch_date) return null;

        return (int) now()->startOfDay()->diffInDays($this->online_launch_date->startOfDay(), false);
    }

    public function isOverdue(): bool
    {
        $days = $this->daysToOnlineLaunch();

        return $days !== null && $days < 0 && !in_array($this->status, [self::PUBLISHED, self::COMPLETED, self::CANCELLED], true);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeVisibleTo($query, User $user)
    {
        // Super admins and anyone with a workflow role see the whole pipeline —
        // the module's whole point is shared visibility. Everyone else sees
        // only what they raised or were assigned.
        if ($user->is_super_admin || $user->pcr_role) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->where('user_id', $user->id)
              ->orWhere('assigned_to', $user->id)
              ->orWhere('photographer_id', $user->id)
              ->orWhere('content_owner_id', $user->id)
              ->orWhere('qa_owner_id', $user->id);
        });
    }

    /** "In Progress" for the dashboard tile — everything actively being worked. */
    public function scopeInProgress($query)
    {
        return $query->whereIn('status', [
            self::SKU_VERIFIED,
            self::WAITING_IMAGES,
            self::PHOTOSHOOT_SCHEDULED,
            self::PHOTOSHOOT_COMPLETED,
            self::IMAGE_EDITING,
            self::AI_CONTENT,
            self::QA_REVIEW,
        ]);
    }
}
