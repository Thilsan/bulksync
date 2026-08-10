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

    /**
     * Publishing ends a request. There is no separate completion step: the person
     * who writes the content reviews it and puts it live, and once it is live
     * there is nothing left to sign off.
     *
     * COMPLETED and READY_FOR_UPLOAD are retired rather than deleted — requests
     * created under the old flow still hold those statuses and have to keep
     * rendering. See stageApplies().
     */
    public const CLOSED_STATUSES = [self::PUBLISHED, self::COMPLETED, self::CANCELLED];

    /** Stages no longer part of the flow, kept only so historic requests work. */
    public const RETIRED_STAGES = [self::READY_FOR_UPLOAD, self::COMPLETED];

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

    /**
     * Plain-English "who does what" for every stage.
     *
     * The workflow is obvious to the people who designed it and opaque to
     * everyone else. This is the single source for the guidance shown on the
     * request page, the stepper tooltips and the dashboard explainer, so the
     * answer to "what am I meant to do?" is never buried in someone's head.
     */
    public const STAGE_GUIDE = [
        self::SUBMITTED => [
            'role'  => 'E-Commerce Team',
            'role_key' => 'ecommerce',
            'field' => 'assigned_to',
            'what'  => 'Review the request, check the SKU validation result, and assign the people who will work on it.',
        ],
        self::WAITING_MAPPING => [
            'role'  => 'Supply Chain Team',
            'role_key' => 'supply_chain',
            'field' => 'supply_chain_id',
            'what'  => 'Map the outstanding SKUs in Cegid, then record the result on the SKUs tab. The request moves on by itself once every SKU is mapped — nobody needs to re-submit it.',
        ],
        self::SKU_VERIFIED => [
            'role'  => 'E-Commerce Team',
            'role_key' => 'ecommerce',
            'field' => 'assigned_to',
            'what'  => 'Every SKU is mapped. Confirm where the images are coming from — supplier or photoshoot — and move the request to the next stage.',
        ],
        self::WAITING_IMAGES => [
            'role'  => 'Photographer',
            'role_key' => 'photographer',
            'field' => 'photographer_id',
            'what'  => 'Gather the product images. If a photoshoot is needed, book it and set the shoot date when you move the request on.',
        ],
        self::PHOTOSHOOT_SCHEDULED => [
            'role'  => 'Photographer',
            'role_key' => 'photographer',
            'field' => 'photographer_id',
            'what'  => 'Shoot the products on the scheduled date, then mark the photoshoot completed.',
        ],
        self::PHOTOSHOOT_COMPLETED => [
            'role'  => 'Photographer',
            'role_key' => 'photographer',
            'field' => 'photographer_id',
            'what'  => 'Hand the raw images over to the E-Commerce team for editing.',
        ],
        self::IMAGE_EDITING => [
            'role'  => 'Photo Editor',
            'role_key' => 'image_editor',
            'field' => 'image_editor_id',
            'what'  => 'Edit, crop and optimise the images so they are ready for the website.',
        ],
        self::AI_CONTENT => [
            'role'  => 'Content Team',
            'role_key' => 'content',
            'field' => 'content_owner_id',
            'what'  => 'Generate the product copy — descriptions, meta titles and meta descriptions — using the AI Content Generator.',
        ],
        self::QA_REVIEW => [
            'role'  => 'Content Team',
            'role_key' => 'content',
            'field' => 'content_owner_id',
            'what'  => 'Check your own images, copy and product data before it goes live. If something needs rework, move the request back a stage with a remark explaining why.',
        ],
        // Retired stage, kept for requests still sitting on it.
        self::READY_FOR_UPLOAD => [
            'role'  => 'Content Team',
            'role_key' => 'content',
            'field' => 'content_owner_id',
            'what'  => 'Upload the products so they go live, then mark the request as Published.',
        ],
        self::PUBLISHED => [
            'role'  => 'Content Team',
            'role_key' => 'content',
            'field' => 'content_owner_id',
            'what'  => 'Products are live on the website. Nothing further is needed — publishing closes the request.',
        ],
        self::COMPLETED => [
            'role'  => null,
            'role_key' => null,
            'field' => null,
            'what'  => 'This request is finished. Nothing further to do.',
        ],
        self::CANCELLED => [
            'role'  => null,
            'role_key' => null,
            'field' => null,
            'what'  => 'This request was cancelled and is no longer being worked on.',
        ],
    ];

    /** Which relation holds the owner for each assignment column. */
    private const OWNER_RELATIONS = [
        'brand_manager_id' => 'brandManager',
        'assigned_to'      => 'assignee',
        'supply_chain_id'  => 'supplyChainOwner',
        'photographer_id'  => 'photographer',
        'image_editor_id'  => 'imageEditor',
        'content_owner_id' => 'contentOwner',
        'qa_owner_id'      => 'qaOwner',
    ];

    /**
     * The standing job description for each role.
     *
     * The task is dictated by the workflow, not written by whoever raises the
     * request — so it reads the same on every request and nobody has to invent
     * wording for work the system already understands.
     */
    public const ROLE_TASKS = [
        'brand_manager_id' => 'Supply the product information and samples, answer queries from the teams, and approve the content before launch.',
        'assigned_to'      => 'Own this request end to end: check the SKU validation, move it through each stage, then upload and publish the products for the launch date.',
        'supply_chain_id'  => 'Map the SKUs in Cegid, then record the outcome on the SKUs tab so the request can continue.',
        'photographer_id'  => 'Photograph the products once the samples arrive, then hand the images over for editing.',
        'image_editor_id'  => 'Edit, crop and optimise the product images so they are ready for the website.',
        'content_owner_id' => 'Produce the product copy — descriptions, meta titles and meta descriptions — and apply it to the products.',
        'qa_owner_id'      => 'Review the images, copy and product data before anything goes live, and send it back a stage if something needs rework.',
    ];

    /**
     * Which roles to offer for this request.
     *
     * Photography roles are pointless without a shoot, and Supply Chain without
     * a mapping step — but a role is always kept when somebody already holds it,
     * or when it owns the stage the request is sitting at, so an assignment can
     * never become stranded and un-editable.
     *
     * @return array<string, string>  field => label
     */
    public function visibleAssignmentRoles(): array
    {
        $currentField = $this->currentGuide()['field'] ?? null;

        return collect(self::ASSIGNMENT_ROLES)
            ->filter(function ($label, $field) use ($currentField) {
                if ($this->{$field} || $field === $currentField) {
                    return true;
                }

                return match ($field) {
                    // No shoot means no photographs, so nothing to shoot or edit.
                    'photographer_id', 'image_editor_id' => (bool) $this->photoshoot_required,
                    'supply_chain_id'                    => $this->requiresMapping(),
                    default                              => true,
                };
            })
            ->all();
    }

    /** What we are asking of someone in this role. */
    public static function taskForRole(string $field): ?string
    {
        return self::ROLE_TASKS[$field] ?? null;
    }

    /** The people a request can be assigned to, and what to call each. */
    public const ASSIGNMENT_ROLES = [
        'brand_manager_id' => 'Brand Manager',
        'assigned_to'      => 'E-Commerce Team',
        'supply_chain_id'  => 'Supply Chain',
        'photographer_id'  => 'Photographer',
        'image_editor_id'  => 'Photo Editor',
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
        'name',
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
        'ai_content_session_id',
        'notes',
        'validation_status',
        'total_skus',
        'mapped_skus',
        'pending_skus',
        'not_mapped_skus',
        'validated_at',
        'validation_error',
        'brand_manager_id',
        'assigned_to',
        'supply_chain_id',
        'photographer_id',
        'image_editor_id',
        'content_owner_id',
        'qa_owner_id',
        'on_hold',
        'hold_reason',
        'hold_since',
        'hold_by',
        'published_at',
        'completed_at',
        'cancelled_at',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'store_launch_date'         => 'date',    // legacy: no longer collected
            'online_launch_date'        => 'datetime',
            'photoshoot_scheduled_at'   => 'date',
            'supplier_images_available' => 'boolean',
            'photoshoot_required'       => 'boolean',
            'use_ai_content'            => 'boolean',
            'on_hold'                   => 'boolean',
            'hold_since'                => 'datetime',
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

    public function brandManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'brand_manager_id');
    }

    public function supplyChainOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supply_chain_id');
    }

    public function photographer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }

    public function imageEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'image_editor_id');
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

    /** The AI Content Generator run this request kicked off, if any. */
    public function aiContentSession(): BelongsTo
    {
        return $this->belongsTo(\App\Models\AiContentSession::class, 'ai_content_session_id');
    }

    /**
     * AI generation needs products that already exist in Shopify — it reads
     * their images. For brand-new products that is only true once they have
     * been uploaded, so the button is offered from the content stage onward and
     * only when something is actually there to work with.
     */
    public function canGenerateAiContent(): bool
    {
        if (!$this->use_ai_content || $this->isClosed()) {
            return false;
        }

        return $this->skus()->where('in_shopify', true)->exists();
    }

    /** Per-person brief and deadline, one row per assigned role. */
    public function assignments(): HasMany
    {
        return $this->hasMany(ProductRequestAssignment::class);
    }

    /** The brief for one role, if the requester gave one. */
    public function assignmentFor(string $role): ?ProductRequestAssignment
    {
        return $this->assignments->firstWhere('role', $role);
    }

    /** The brief attached to the stage the request is sitting at. */
    public function currentAssignment(): ?ProductRequestAssignment
    {
        $field = $this->currentGuide()['field'];

        return $field ? $this->assignmentFor($field) : null;
    }

    /** Assignments this user holds that are past their own deadline. */
    public function overdueAssignmentsFor(User $user)
    {
        return $this->assignments
            ->where('user_id', $user->id)
            ->filter(fn ($a) => $a->isOverdue());
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

    /**
     * Common reasons work stalls, offered as one-click picks so the reason is
     * consistent enough to report on. Free text is always allowed as well.
     */
    public const HOLD_REASONS = [
        'Samples not received at studio',
        'Waiting for product samples from supplier',
        'Studio or photographer unavailable',
        'Waiting on brand team for information',
        'Waiting on supplier images',
        'Launch postponed by the brand',
    ];

    public function isOnHold(): bool
    {
        return (bool) $this->on_hold && !$this->isClosed();
    }

    /** Whole days this request has been stalled — the number worth escalating on. */
    public function heldForDays(): ?int
    {
        if (!$this->isOnHold() || !$this->hold_since) {
            return null;
        }

        return (int) $this->hold_since->startOfDay()->diffInDays(now()->startOfDay());
    }

    public function holdSetter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hold_by');
    }

    public function scopeOnHold($query)
    {
        return $query->where('on_hold', true)
            ->whereNotIn('status', self::CLOSED_STATUSES);
    }

    /**
     * Who is responsible for a stage and what they have to do, adjusted for this
     * request's own settings.
     *
     * @return array{role: ?string, what: ?string, owner: ?User, field: ?string}
     */
    public function guideFor(string $stage): array
    {
        $guide = self::STAGE_GUIDE[$stage] ?? ['role' => null, 'field' => null, 'what' => null];

        // The content stage means something different when the brand team writes
        // the copy themselves.
        if ($stage === self::AI_CONTENT && !$this->use_ai_content) {
            $guide['what'] = $this->awaitingContentSheet()
                ? 'The brand team is providing the copy themselves — chase them for the content sheet, then apply it to the products.'
                : 'Apply the copy from the brand team\'s content sheet to the products.';
        }

        // Waiting for mapping is the one stage that can be blocked on someone
        // outside the request's own assignment list.
        if ($stage === self::WAITING_MAPPING && $this->total_skus > 0) {
            $outstanding = $this->pending_skus + $this->not_mapped_skus;
            $guide['what'] = "{$outstanding} of {$this->total_skus} SKUs still need mapping. " . $guide['what'];
        }

        $relation = $guide['field'] ? (self::OWNER_RELATIONS[$guide['field']] ?? null) : null;

        return [
            'role'     => $guide['role'],
            'role_key' => $guide['role_key'] ?? null,
            'what'     => $guide['what'],
            'field'    => $guide['field'],
            'owner'    => $relation ? $this->{$relation} : null,
        ];
    }

    /** Guidance for the stage the request is sitting in right now. */
    public function currentGuide(): array
    {
        return $this->guideFor($this->status);
    }

    /**
     * How this user relates to the stage the request is sitting in.
     *
     *   'mine'    — they are personally assigned to it
     *   'my_team' — nobody is assigned, but their workflow role owns this stage,
     *               so it IS their team's job even though no name is on it
     *   'other'   — someone else's
     *   'none'    — closed, or the viewer has no stake
     *
     * The 'my_team' case is the one that matters: without it, unassigned work
     * is invisible to the very people who are supposed to pick it up.
     */
    public function ownershipFor(?User $user): string
    {
        if (!$user || $this->isClosed()) {
            return 'none';
        }

        $guide = $this->currentGuide();

        if ($guide['owner'] && (int) $guide['owner']->id === $user->id) {
            return 'mine';
        }

        if (!$guide['owner'] && $guide['role_key'] && $user->pcr_role === $guide['role_key']) {
            return 'my_team';
        }

        return $guide['owner'] ? 'other' : 'none';
    }

    /** Is the logged-in user the person this stage is waiting on? */
    public function isWaitingOn(?User $user): bool
    {
        return $this->ownershipFor($user) === 'mine';
    }

    /**
     * Can this user put their own name on the current stage? Only when the
     * stage has an assignment slot and it is empty — claiming never steals
     * work from someone who already has it.
     */
    public function claimableBy(?User $user): bool
    {
        if (!$user || $this->isClosed()) {
            return false;
        }

        $guide = $this->currentGuide();

        return $guide['field'] !== null && $guide['owner'] === null;
    }

    /** The assignment column the current stage is claimed through. */
    public function claimField(): ?string
    {
        return $this->currentGuide()['field'];
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

    /**
     * What to call this request in a list. Falls back to brand + category so a
     * request raised before names existed — or one someone left blank — still
     * reads as something, never as an empty cell.
     */
    public function displayName(): string
    {
        if (filled($this->name)) {
            return $this->name;
        }

        return collect([$this->brand, $this->category, $this->collection])
            ->filter()
            ->implode(' · ') ?: $this->reference;
    }

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
        return in_array($this->status, self::CLOSED_STATUSES, true);
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

            // No shoot means no raw images of ours to edit — supplier images
            // arrive ready to use, so the editing step does not apply either.
            self::IMAGE_EDITING => (bool) $this->photoshoot_required,

            // Retired: publishing is the end, so there is no upload hand-off and
            // no separate completion. Only shown if a request is still sitting
            // on one from before the change.
            self::READY_FOR_UPLOAD, self::COMPLETED => false,

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
                'state'  => match (true) {
                    $current < 0        => 'upcoming',
                    $current > $end     => 'done',
                    // Sitting on the final stage of a closed request means the
                    // phase is finished, not still running — otherwise a
                    // published request reads "In Progress" at 100%.
                    $current >= $offset => $this->isClosed() ? 'done' : 'current',
                    default             => 'upcoming',
                },
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
     *
     * Read off displayStages() rather than hard-coded per status, so a request
     * that skips the photoshoot leg is never pointed at a stage it does not
     * have. Only the mapping gate needs special handling, because a fully
     * mapped request jumps straight past Waiting for Mapping.
     */
    public function suggestedNextStatus(): ?string
    {
        if ($this->status === self::SUBMITTED) {
            return $this->isFullyMapped() ? self::SKU_VERIFIED : self::WAITING_MAPPING;
        }

        if ($this->status === self::WAITING_MAPPING) {
            return $this->isFullyMapped() ? self::SKU_VERIFIED : null;
        }

        $stages = $this->displayStages();
        $index  = $this->displayStageIndex();

        return $index >= 0 ? ($stages[$index + 1] ?? null) : null;
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
        if (!$this->online_launch_date) {
            return false;
        }

        // Compares the actual moment now that launches carry a time: a 09:00
        // launch is late by lunchtime, not at midnight.
        return $this->online_launch_date->isPast()
            && !in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /** The launch moment, for display. */
    public function launchLabel(string $format = 'd M Y, H:i'): ?string
    {
        return $this->online_launch_date?->format($format);
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
