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

    /** The four people a request can be assigned to, and what to call each. */
    public const ASSIGNMENT_ROLES = [
        'assigned_to'      => 'E-Commerce Owner',
        'photographer_id'  => 'Photographer',
        'content_owner_id' => 'Content Team',
        'qa_owner_id'      => 'QA Team',
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
        'use_ai_content',
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
            'use_ai_content'            => 'boolean',
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

    /** Mood boards and reference shots supplied with the request. */
    public function referenceImages(): HasMany
    {
        return $this->attachments()->where('kind', ProductRequestAttachment::KIND_REFERENCE);
    }

    /** The brand team's written content, when they aren't using the AI generator. */
    public function contentSheets(): HasMany
    {
        return $this->attachments()->where('kind', ProductRequestAttachment::KIND_CONTENT);
    }

    /** Brand team owes us a content sheet and hasn't sent one yet. */
    public function awaitingContentSheet(): bool
    {
        return !$this->use_ai_content && $this->contentSheets()->doesntExist();
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
        return $this->stageLabel($this->status);
    }

    /**
     * Stage name as it applies to THIS request. "AI Content Generation" is a lie
     * when the brand team is writing the copy themselves, and the content team
     * needs to know which it is at a glance.
     */
    public function stageLabel(string $stage): string
    {
        if ($stage === self::AI_CONTENT && !$this->use_ai_content) {
            return 'Content from Brand Team';
        }

        return self::STATUS_LABELS[$stage] ?? $stage;
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

    /**
     * Whether this request's website goes through Cegid mapping at all.
     * Only websites flagged in Stores (Blue Salon) do — everywhere else there is
     * no mapping step, so the request must not wait on Supply Chain.
     */
    public function requiresMapping(): bool
    {
        return (bool) $this->store?->requires_sku_mapping;
    }

    /** Every SKU mapped — the gate for leaving "Waiting for Mapping". */
    public function isFullyMapped(): bool
    {
        // No mapping step for this website, so there is nothing to wait for.
        if (!$this->requiresMapping()) {
            return true;
        }

        return $this->total_skus > 0 && $this->mapped_skus === $this->total_skus;
    }

    /**
     * The pipeline grouped into the four things people actually talk about.
     * Twelve stages in one strip is unreadable — and it hides the fact that
     * Photoshoot and Content are separate pieces of work owned by different
     * teams. Grouping keeps the same stages while making that obvious.
     */
    public const PHASES = [
        'intake' => [
            'label'  => 'Intake & Verification',
            'stages' => [self::SUBMITTED, self::WAITING_MAPPING, self::SKU_VERIFIED],
        ],
        'photoshoot' => [
            'label'  => 'Photoshoot',
            'stages' => [self::WAITING_IMAGES, self::PHOTOSHOOT_SCHEDULED, self::PHOTOSHOOT_COMPLETED],
        ],
        'content' => [
            'label'  => 'Content Creation',
            'stages' => [self::IMAGE_EDITING, self::AI_CONTENT],
        ],
        'launch' => [
            'label'  => 'Review & Launch',
            'stages' => [self::QA_REVIEW, self::READY_FOR_UPLOAD, self::PUBLISHED, self::COMPLETED],
        ],
    ];

    /**
     * Does this stage exist for this request at all?
     *
     * The stage the request is currently sitting in always counts, whatever the
     * flags say — a request can be parked somewhere and then have the flag that
     * created that stage switched off, and a stepper missing the live status
     * would render as no progress at all.
     */
    public function stageApplies(string $stage): bool
    {
        if ($stage === $this->status) {
            return true;
        }

        return match ($stage) {
            self::WAITING_MAPPING => $this->requiresMapping(),

            // Still needed when nobody has the images yet, even without a shoot.
            self::WAITING_IMAGES => $this->photoshoot_required || !$this->supplier_images_available,

            self::PHOTOSHOOT_SCHEDULED, self::PHOTOSHOOT_COMPLETED => (bool) $this->photoshoot_required,

            default => true,
        };
    }

    /** Phases that apply to this request, each with its state for the stepper. */
    public function phaseProgress(): array
    {
        $current = $this->displayStageIndex();
        $offset  = 0;
        $out     = [];

        foreach (self::PHASES as $key => $phase) {
            $stages = array_values(array_filter($phase['stages'], fn ($s) => $this->stageApplies($s)));

            if (empty($stages)) {
                continue; // e.g. no photoshoot on this request
            }

            $end = $offset + count($stages) - 1;

            // Without a shoot this phase is just "we're waiting on images" —
            // calling it Photoshoot would be misleading.
            $label = ($key === 'photoshoot' && !$this->photoshoot_required)
                ? 'Product Images'
                : $phase['label'];

            $out[] = [
                'key'    => $key,
                'label'  => $label,
                'stages' => $stages,
                'start'  => $offset,
                'state'  => $current < 0 ? 'upcoming'
                    : ($current > $end ? 'done' : ($current >= $offset ? 'current' : 'upcoming')),
            ];

            $offset += count($stages);
        }

        return $out;
    }

    /** The stages this particular request actually passes through, in order. */
    public function displayStages(): array
    {
        return array_values(array_filter(self::PIPELINE, fn ($s) => $this->stageApplies($s)));
    }

    /** Position within displayStages() — drives the stepper and the progress bar. */
    public function displayStageIndex(): int
    {
        $i = array_search($this->status, $this->displayStages(), true);

        return $i === false ? -1 : $i;
    }

    public function progressPercent(): int
    {
        if ($this->status === self::CANCELLED) return 0;
        $i = $this->displayStageIndex();
        if ($i < 0) return 0;

        return (int) round(($i + 1) / count($this->displayStages()) * 100);
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

        // Work off the stages that apply to THIS request, so a request with no
        // photoshoot is never offered a photoshoot stage — forward or backward.
        $stages = $this->displayStages();
        $i      = $this->displayStageIndex();

        if ($i < 0) {
            return [];
        }

        // Can't leave "Waiting for Mapping" until every SKU resolves.
        if ($this->status === self::WAITING_MAPPING && !$this->isFullyMapped()) {
            return [self::CANCELLED];
        }

        $allowed = array_slice($stages, $i + 1);

        // One applicable step back for rework (QA bounce, re-shoot).
        if ($i > 0) {
            array_unshift($allowed, $stages[$i - 1]);
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

    /** Requests where this user holds any of the four assignment roles. */
    public function scopeAssignedTo($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            foreach (array_keys(self::ASSIGNMENT_ROLES) as $field) {
                $q->orWhere($field, $user->id);
            }
        });
    }

    /** Which hats this user is wearing on this request, e.g. ["Photographer"]. */
    public function rolesFor(User $user): array
    {
        $roles = [];

        foreach (self::ASSIGNMENT_ROLES as $field => $label) {
            if ((int) $this->{$field} === $user->id) {
                $roles[] = $label;
            }
        }

        return $roles;
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
