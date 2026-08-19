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
    public const RETIRED_STAGES = [self::IMAGE_EDITING, self::READY_FOR_UPLOAD, self::COMPLETED];

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
            'role'  => 'Photoshoot Coordinator',
            'role_key' => 'photographer',
            'field' => 'photographer_id',
            'what'  => 'Gather the product images. If a photoshoot is needed, book it and set the shoot date when you move the request on.',
        ],
        self::PHOTOSHOOT_SCHEDULED => [
            'role'  => 'Photoshoot Coordinator',
            'role_key' => 'photographer',
            'field' => 'photographer_id',
            'what'  => 'Shoot the products on the scheduled date, then mark the photoshoot completed.',
        ],
        self::PHOTOSHOOT_COMPLETED => [
            'role'  => 'Photoshoot Coordinator',
            'role_key' => 'photographer',
            'field' => 'photographer_id',
            'what'  => 'Finish the images — edited, cropped and ready for the website — then move the request on to content.',
        ],
        // Retired stage: editing is part of the photoshoot now. Kept so a request
        // still sitting here reads sensibly and can be moved on.
        self::IMAGE_EDITING => [
            'role'  => 'E-Commerce Team',
            'role_key' => 'ecommerce',
            'field' => 'assigned_to',
            'what'  => 'Editing is done as part of the photoshoot now. Check the images are on the products, then move the request on to content.',
        ],
        self::AI_CONTENT => [
            'role'  => 'E-Commerce Team',
            'role_key' => 'ecommerce',
            'field' => 'assigned_to',
            'what'  => 'Generate the product copy — descriptions, meta titles and meta descriptions — using the AI Content Generator.',
        ],
        self::QA_REVIEW => [
            'role'  => 'E-Commerce Team',
            'role_key' => 'ecommerce',
            'field' => 'assigned_to',
            'what'  => 'Check your own images, copy and product data before it goes live. If something needs rework, move the request back a stage with a remark explaining why.',
        ],
        // Retired stage, kept for requests still sitting on it.
        self::READY_FOR_UPLOAD => [
            'role'  => 'E-Commerce Team',
            'role_key' => 'ecommerce',
            'field' => 'assigned_to',
            'what'  => 'Upload the products so they go live, then mark the request as Published.',
        ],
        self::PUBLISHED => [
            'role'  => 'E-Commerce Team',
            'role_key' => 'ecommerce',
            'field' => 'assigned_to',
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

    // ── Categories ───────────────────────────────────────────────────────────
    /**
     * The categories the business trades in. Free text let everyone spell the
     * same category their own way ("mens fashion", "Men's Fashion", "Menswear"),
     * which made the queue impossible to group or report on.
     */
    public const CATEGORIES = [
        'Beauty',
        'Food & Beverages',
        'Fashion Accessories',
        'Home',
        'Leather Goods',
        'Lingerie',
        'Linen',
        'Luggage',
        "Men's Fashion",
        "Women's Fashion",
        'Kids',
        'Watches',
        'PG Operations',
    ];

    /**
     * Who handles this category. Each category belongs to one person, set on
     * their user record, so a request lands with its owner without the requester
     * having to know who covers what.
     */
    public function categoryOwner(): ?User
    {
        return User::ownerForCategory($this->category);
    }

    /** The list, plus whatever this request was raised with before the list existed. */
    public function categoryOptions(): array
    {
        $options = self::CATEGORIES;

        if (filled($this->category) && !in_array($this->category, $options, true)) {
            array_unshift($options, $this->category);
        }

        return $options;
    }

    // ── Where the product images come from ───────────────────────────────────
    public const IMG_SUPPLIER      = 'supplier';
    public const IMG_PHOTOSHOOT    = 'photoshoot';
    public const IMG_BRAND_WEBSITE = 'brand_website';

    /**
     * One answer that decides the whole middle of the workflow.
     *
     * Each source implies a different set of stages, which is why this replaced
     * two booleans that could disagree with each other.
     */
    public const IMAGE_SOURCES = [
        self::IMG_SUPPLIER => [
            'label'  => 'Supplier has sent the images',
            'hint'   => 'Used as they are — no photoshoot and no editing stage.',
        ],
        self::IMG_PHOTOSHOOT => [
            'label'  => 'We are photographing the products',
            'hint'   => 'Waits for samples, then the photoshoot and editing stages apply.',
        ],
        self::IMG_BRAND_WEBSITE => [
            'label'  => 'Take the images from the brand website',
            'hint'   => 'Someone collects them from the brand site, then they are edited for our site.',
        ],
    ];

    /**
     * No longer offered. Kept so a request already set to it keeps its label and
     * its stages, and can be changed away from it — deleting the option outright
     * would leave such a request reading "Not specified".
     */
    public const RETIRED_IMAGE_SOURCES = [self::IMG_BRAND_WEBSITE];

    /** Options offered when raising or editing a request. */
    public static function selectableImageSources(): array
    {
        return collect(self::IMAGE_SOURCES)
            ->reject(fn ($meta, $key) => in_array($key, self::RETIRED_IMAGE_SOURCES, true))
            ->all();
    }

    /** Selectable options, plus whatever this request is already set to. */
    public function imageSourceOptions(): array
    {
        $options = self::selectableImageSources();

        if ($this->image_source && !isset($options[$this->image_source])) {
            $options[$this->image_source] = self::IMAGE_SOURCES[$this->image_source];
        }

        return $options;
    }

    // ── The shoot itself ─────────────────────────────────────────────────────

    public const SHOOT_PENDING     = 'pending';
    public const SHOOT_SCHEDULED   = 'scheduled';
    public const SHOOT_IN_PROGRESS = 'in_progress';
    public const SHOOT_COMPLETED   = 'completed';
    public const SHOOT_CANCELLED   = 'cancelled';

    /**
     * Where a shoot has got to, which is not the same question as where the
     * request has got to — a shoot can be under way, or called off and rebooked,
     * while the request sits at the same stage throughout.
     */
    public const SHOOT_STATUSES = [
        self::SHOOT_PENDING     => 'Pending',
        self::SHOOT_SCHEDULED   => 'Scheduled',
        self::SHOOT_IN_PROGRESS => 'In Progress',
        self::SHOOT_COMPLETED   => 'Completed',
        self::SHOOT_CANCELLED   => 'Cancelled',
    ];

    /** One literal class string per state — Tailwind only sees what is rendered. */
    public const SHOOT_COLORS = [
        self::SHOOT_PENDING     => 'bg-amber-50 text-amber-700 border-amber-200',
        self::SHOOT_SCHEDULED   => 'bg-blue-50 text-blue-700 border-blue-200',
        self::SHOOT_IN_PROGRESS => 'bg-violet-50 text-violet-700 border-violet-200',
        self::SHOOT_COMPLETED   => 'bg-green-50 text-green-700 border-green-200',
        self::SHOOT_CANCELLED   => 'bg-gray-100 text-gray-500 border-gray-200',
    ];

    /** The solid version, for calendar chips where the day is already busy. */
    public const SHOOT_DOT_COLORS = [
        self::SHOOT_PENDING     => 'bg-amber-400',
        self::SHOOT_SCHEDULED   => 'bg-blue-500',
        self::SHOOT_IN_PROGRESS => 'bg-violet-500',
        self::SHOOT_COMPLETED   => 'bg-green-500',
        self::SHOOT_CANCELLED   => 'bg-gray-400',
    ];

    /** States that still need someone to do something. */
    public const SHOOT_OPEN_STATUSES = [self::SHOOT_PENDING, self::SHOOT_SCHEDULED, self::SHOOT_IN_PROGRESS];

    public function shootStatusLabel(): string
    {
        return self::SHOOT_STATUSES[$this->photoshoot_status] ?? '—';
    }

    public function shootStatusColor(): string
    {
        return self::SHOOT_COLORS[$this->photoshoot_status] ?? 'bg-gray-100 text-gray-500 border-gray-200';
    }

    public function shootDotColor(): string
    {
        return self::SHOOT_DOT_COLORS[$this->photoshoot_status] ?? 'bg-gray-400';
    }

    /** Booked, but the day has come and gone with nothing marked done. */
    public function isShootOverdue(): bool
    {
        return in_array($this->photoshoot_status, [self::SHOOT_SCHEDULED, self::SHOOT_IN_PROGRESS], true)
            && $this->photoshoot_scheduled_at?->isPast() === true;
    }

    /** Needs a shoot, and it has not happened or been called off. */
    public function shootIsOpen(): bool
    {
        return in_array($this->photoshoot_status, self::SHOOT_OPEN_STATUSES, true);
    }

    /** Requests with a shoot to think about — the Photoshoot Room's whole list. */
    public function scopeWithPhotoshoot($query)
    {
        return $query->whereNotNull('photoshoot_status');
    }

    public const IMAGES_AT_URL = 'url';
    public const IMAGES_AT_PIM = 'pim';

    public const IMAGE_LOCATIONS = [
        self::IMAGES_AT_URL => 'A link to the folder',
        self::IMAGES_AT_PIM => 'Already in the PIM',
    ];

    /** Only supplier images need a location recorded — the rest we produce. */
    public function needsImageLocation(): bool
    {
        return $this->image_source === self::IMG_SUPPLIER;
    }

    /** Where the supplier images are, in words. */
    public function imageLocationLabel(): ?string
    {
        if (!$this->needsImageLocation() || !$this->images_location) {
            return null;
        }

        return $this->images_location === self::IMAGES_AT_PIM
            ? 'Already in the PIM'
            : ($this->images_url ?: 'Link not recorded');
    }

    public function imagesInPim(): bool
    {
        return $this->images_location === self::IMAGES_AT_PIM;
    }

    /** Supplier images were promised but nobody said where they are. */
    public function awaitingImageLocation(): bool
    {
        if (!$this->needsImageLocation() || $this->isClosed()) {
            return false;
        }

        return $this->images_location === null
            || ($this->images_location === self::IMAGES_AT_URL && blank($this->images_url));
    }

    public function imageSourceLabel(): string
    {
        return self::IMAGE_SOURCES[$this->image_source]['label'] ?? 'Not specified';
    }

    /** Only a studio shoot brings the photoshoot stages into play. */
    public function needsPhotoshoot(): bool
    {
        return $this->image_source === self::IMG_PHOTOSHOOT;
    }

    /**
     * Supplier images arrive ready to use. Anything we shoot or pull off the
     * brand's site has to be edited for our own storefront.
     */
    public function needsImageEditing(): bool
    {
        return $this->image_source !== self::IMG_SUPPLIER;
    }

    /** Supplier images are already in hand; everything else has to be gathered. */
    public function needsImagesGathered(): bool
    {
        return $this->image_source !== self::IMG_SUPPLIER;
    }

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
        'photographer_id'  => 'Arrange the shoot once the samples arrive — book the studio, get the products photographed, then hand the images over for editing.',
        // Retired role — the photoshoot delivers finished images.
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
                if ($this->ownerFor($field) || $field === $currentField) {
                    return true;
                }

                // Retired roles are never offered fresh.
                if (in_array($field, self::RETIRED_ROLES, true)) {
                    return false;
                }

                return match ($field) {
                    'photographer_id' => $this->needsPhotoshoot(),
                    'supply_chain_id' => $this->requiresMapping(),
                    default           => true,
                };
            })
            ->all();
    }

    /** Roles offered when raising a request — retired ones are not. */
    public static function assignableRoles(): array
    {
        return collect(self::ASSIGNMENT_ROLES)
            ->reject(fn ($label, $field) => in_array($field, self::RETIRED_ROLES, true))
            ->all();
    }

    /** What we are asking of someone in this role. */
    public static function taskForRole(string $field): ?string
    {
        return self::ROLE_TASKS[$field] ?? null;
    }

    /**
     * Roles no longer offered on new requests.
     *
     * QA Review belongs to whoever produced the work — they check their own
     * output — so a separate QA assignee has nothing to own. Content went the
     * same way: one person runs a category end to end, writing the copy as part
     * of owning the request, so a separate Content Team assignee was always the
     * same name twice. Photo Editor went the same way: the people who take the
     * pictures finish them, so the shoot delivers website-ready images and there
     * is nothing left to hand to an editor. Their stages are owned by the
     * E-Commerce Team.
     *
     * Kept in the list rather than deleted so a request that already has one
     * still shows it, and it can be cleared; hiding it outright would strand the
     * assignment.
     */
    public const RETIRED_ROLES = ['qa_owner_id', 'content_owner_id', 'image_editor_id'];

    /** The people a request can be assigned to, and what to call each. */
    public const ASSIGNMENT_ROLES = [
        'brand_manager_id' => 'Brand Manager',
        'assigned_to'      => 'E-Commerce Team',
        'supply_chain_id'  => 'Supply Chain',
        'photographer_id'  => 'Photoshoot Coordinator',
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
        'sheet_request_no',
        'sheet_requested_by',
        'name',
        'user_id',
        'store_id',
        'request_type',
        'brand',
        'category',
        'status',
        'priority',
        'store_launch_date',
        'online_launch_date',
        'supplier_images_available',
        'image_source',
        'images_location',
        'images_url',
        'photoshoot_required',
        'photoshoot_scheduled_at',
        'photoshoot_status',
        'photoshoot_studio',
        'photoshoot_notes',
        'use_ai_content',
        'ai_content_session_id',
        'ai_content_decision',
        'ai_content_decided_at',
        'notes',
        'validation_status',
        'total_skus',
        'mapped_skus',
        'pending_skus',
        'not_mapped_skus',
        'validated_at',
        'validation_error',
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
            // A booking, so it carries a time — "Tuesday" is not a slot.
            'photoshoot_scheduled_at'   => 'datetime',
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

    /**
     * Live assignments only. The single answer to "who owns this role" — the
     * owner columns that used to duplicate this are gone.
     */
    /** Shopify draft products staged from the tracking sheet, awaiting review. */
    public function draftProducts(): HasMany
    {
        return $this->hasMany(ProductRequestDraftProduct::class);
    }

    public function currentAssignments(): HasMany
    {
        return $this->hasMany(ProductRequestAssignment::class)->whereNull('ended_at');
    }

    /** Who holds a role right now, if anyone. */
    public function ownerFor(string $role): ?User
    {
        return $this->assignmentFor($role)?->user;
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

    /** Every assignment ever made on this request, current and historic. */
    public function assignments(): HasMany
    {
        return $this->hasMany(ProductRequestAssignment::class);
    }

    /** The live assignment for one role. */
    public function assignmentFor(string $role): ?ProductRequestAssignment
    {
        return $this->currentAssignments->firstWhere('role', $role);
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
        return $this->currentAssignments
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
        // Gathering images means something different per source.
        if ($stage === self::WAITING_IMAGES) {
            $guide['what'] = match ($this->image_source) {
                self::IMG_PHOTOSHOOT    => 'Get the samples into the studio, then book the shoot and set the date when you move the request on.',
                self::IMG_BRAND_WEBSITE => 'Collect the product images from the brand website, then hand them over for editing.',
                default                 => 'Chase the supplier for the product images, then hand them over for editing.',
            };
        }

        if ($stage === self::WAITING_MAPPING && $this->total_skus > 0) {
            $outstanding = $this->pending_skus + $this->not_mapped_skus;
            $guide['what'] = "{$outstanding} of {$this->total_skus} SKUs still need mapping. " . $guide['what'];
        }

        return [
            'role'     => $guide['role'],
            'role_key' => $guide['role_key'] ?? null,
            'what'     => $guide['what'],
            'field'    => $guide['field'],
            'owner'    => $guide['field'] ? $this->ownerFor($guide['field']) : null,
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

    /**
     * Whether the generator is worth offering instead of waiting for a sheet.
     *
     * "This request is not using the AI Content Generator" was a dead end: the
     * setting says where the copy was meant to come from, not whether the copy
     * exists. If products are live with nothing on them, writing it here is a
     * real option whatever the request was raised with.
     */
    public function couldGenerateInsteadOfSheet(): bool
    {
        return $this->awaitingContentSheet() && $this->needsContentCount() > 0;
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
    /**
     * Who asked for this. Sheet-synced requests carry the free-typed name from
     * the tracking sheet's "Requested By" column — that person rarely has a User
     * row, and user_id is then just whoever ran the sync, so the sheet name wins.
     */
    public function requesterName(): string
    {
        return filled($this->sheet_requested_by)
            ? $this->sheet_requested_by
            : ($this->user?->name ?? '—');
    }

    public function displayName(): string
    {
        if (filled($this->name)) {
            return $this->name;
        }

        return collect([$this->brand, $this->category])
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

    // ── The balance: SKUs still waiting on Cegid ─────────────────────────────

    /**
     * SKUs Supply Chain has not resolved yet.
     *
     * Only meaningful on a Cegid website — everywhere else a SKU that isn't in
     * Shopify is simply a product nobody has uploaded yet, which is the normal
     * state of a new brand.
     */
    public function balanceSkus(): int
    {
        if (!$this->requiresMapping()) {
            return 0;
        }

        return max(0, $this->total_skus - $this->mapped_skus);
    }

    // ── Copy: which SKUs already have a description ──────────────────────────

    /**
     * SKUs live in Shopify with no copy on them.
     *
     * The point of counting them separately: on a website with no Cegid step, a
     * request of twenty SKUs where ten already read well only needs content for
     * the other ten — and regenerating over the ten that are written is worse
     * than doing nothing.
     */
    public function skusMissingDescription(): HasMany
    {
        return $this->skus()->where('in_shopify', true)->where('has_description', false);
    }

    /**
     * SKUs still waiting on copy: live in Shopify, no description, and nobody has
     * either started writing or decided to leave them.
     *
     * Per SKU rather than per request, because the two are not the same question.
     * Thirty SKUs where twenty-eight were mapped first, generated for, and the
     * last two mapped afterwards leaves exactly those two here — which is what
     * gets offered, not the twenty-eight again and not nothing at all.
     */
    public function skusNeedingContent(): HasMany
    {
        return $this->skusMissingDescription()
            ->whereNull('content_started_at')
            ->whereNull('content_skipped_at');
    }

    /**
     * Live SKUs the check has not read a description back for.
     *
     * Null is not "has no copy" — it means nobody has looked. Generating over a
     * description somebody wrote is worse than waiting, so these are counted
     * apart and reported as needing a check rather than needing copy.
     */
    public function descriptionsUncheckedCount(): int
    {
        return $this->skus()->where('in_shopify', true)->whereNull('has_description')->count();
    }

    public function missingDescriptionCount(): int
    {
        return $this->skusMissingDescription()->count();
    }

    public function needsContentCount(): int
    {
        return $this->skusNeedingContent()->count();
    }

    public function describedCount(): int
    {
        return $this->skus()->where('in_shopify', true)->where('has_description', true)->count();
    }

    /** Live SKUs whose copy is written, being written, or deliberately left. */
    public function contentHandledCount(): int
    {
        return $this->skus()->where('in_shopify', true)->count() - $this->needsContentCount();
    }

    /**
     * Whether to offer content for the SKUs that have none.
     *
     * The offer comes back whenever new SKUs arrive without copy — a SKU mapped
     * today is a fresh question, even if the same request was asked last week.
     * What does not come back is a SKU already answered for.
     */
    public function canOfferContentForMissing(): bool
    {
        return !$this->isClosed() && $this->needsContentCount() > 0;
    }

    /**
     * What is going live incomplete, in words.
     *
     * A request can be published with SKUs still unmapped and with no copy
     * written — both are legitimate calls, and both are invisible afterwards
     * unless somebody writes them down at the moment it is published.
     *
     * @return array<int, string>
     */
    public function publishGaps(): array
    {
        $gaps = [];

        if ($balance = $this->balanceSkus()) {
            $gaps[] = "{$balance} of {$this->total_skus} SKU(s) never mapped in Cegid";
        }

        if ($this->not_mapped_skus > 0) {
            $gaps[] = "{$this->not_mapped_skus} SKU(s) marked as not mappable";
        }

        if ($this->ai_content_decision === 'skip') {
            $gaps[] = 'AI content skipped — copy supplied by the brand team';
        } elseif ($this->use_ai_content && !$this->ai_content_session_id) {
            $gaps[] = 'AI content was never generated';
        }

        if ($blank = $this->missingDescriptionCount()) {
            $gaps[] = "{$blank} SKU(s) live in Shopify with no description";
        }

        return $gaps;
    }

    public function hasSkuBalance(): bool
    {
        return $this->balanceSkus() > 0;
    }

    /**
     * How much of the request can actually go live — mapped SKUs as a share of
     * the whole. This is the number to quote at the end: "18 of 20 (90%)" says
     * far more about where a launch stands than the stage name does.
     */
    public function skuCompletionPercent(): int
    {
        if ($this->total_skus < 1) {
            return 0;
        }

        return (int) round($this->mapped_skus / $this->total_skus * 100);
    }

    /**
     * Whether this request has ever been parked with Supply Chain.
     *
     * Separates a request that was waved straight past the mapping stage
     * (submitted → SKU verified, because the website's Cegid tick was off) from
     * one the team dealt with — finished, or deliberately carried on with the
     * mapped half. Only the first kind may be pulled back when the tick changes.
     */
    public function hasBeenToMapping(): bool
    {
        return $this->activities()->where('to_status', self::WAITING_MAPPING)->exists();
    }

    /**
     * Enough mapped to get on with, but not all of it.
     *
     * The condition for carrying on with part of a request rather than holding
     * ten good SKUs hostage to ten that Supply Chain has not reached yet.
     */
    public function canContinueWithMapped(): bool
    {
        return $this->status === self::WAITING_MAPPING
            && $this->requiresMapping()
            && $this->mapped_skus > 0
            && $this->hasSkuBalance()
            && !$this->isClosed();
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
            // IMAGE_EDITING is retired but still listed, so a request parked on it
            // shows inside a phase rather than falling outside the stepper.
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

            // Supplier images are already in hand; anything else must be gathered.
            self::WAITING_IMAGES => $this->needsImagesGathered(),

            self::PHOTOSHOOT_SCHEDULED, self::PHOTOSHOOT_COMPLETED => $this->needsPhotoshoot(),

            // Retired stages. Editing is part of the photoshoot — the people who
            // take the pictures finish them — so there is no separate editing
            // step to wait on; publishing is the end, so there is no upload
            // hand-off and no separate completion. Any of these still shows on a
            // request that is sitting on it from before the change, because the
            // check above returns early for the current stage.
            self::IMAGE_EDITING, self::READY_FOR_UPLOAD, self::COMPLETED => false,

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
            // Part-mapped still points forward: the mapped SKUs are ready to work
            // on even while the balance sits with Supply Chain.
            return $this->isFullyMapped() || $this->mapped_skus > 0 ? self::SKU_VERIFIED : null;
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

        // Waiting for Mapping with nothing mapped is a genuine dead stop — there
        // is no product to work on. With some of it mapped the request may carry
        // on with that part, and the balance follows once Cegid catches up.
        if ($this->status === self::WAITING_MAPPING && !$this->isFullyMapped() && $this->mapped_skus < 1) {
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
        // Who may OPEN a request — not what fills their dashboard; see onMyDesk().
        //
        // Anyone with a workflow role can reach any request, because they have to:
        // Supply Chain records mappings on requests they hold nothing on, a team
        // has to be able to pick up unclaimed work, and every stage email links
        // to a request. The accounts copied on everything need it for the same
        // reason. Without a role, you get what you raised, hold, or brand-manage.
        if ($user->is_super_admin || $user->pcr_role || $user->pcr_notify_all) {
            return $query;
        }

        // Ownership lives in the assignments table — the owner columns these
        // checks used to read were dropped with it.
        $followed = $user->brandManagedCategories();

        return $query->where(function ($q) use ($user, $followed) {
            $q->where('user_id', $user->id)
              ->orWhereHas('assignments', fn ($a) => $a->current()->where('user_id', $user->id));

            // A brand manager gets emailed about their categories, so they have
            // to be able to open what those emails link to.
            if ($followed) {
                $q->orWhereIn('category', $followed);
            }
        });
    }

    /**
     * What belongs on this person's own dashboard and list.
     *
     * Being able to open any request is not a reason to be shown all of them. A
     * brand manager's dashboard read as the whole company's pipeline — six
     * requests, one of them theirs — which made the numbers meaningless. This is
     * the narrower question: what did I raise, what do I hold, and which
     * categories am I brand manager for.
     *
     * Super admins, and the accounts copied on every request, still see the lot:
     * that oversight is their job.
     */
    public function scopeOnMyDesk($query, User $user)
    {
        if ($user->is_super_admin || $user->pcr_notify_all) {
            return $query;
        }

        $managed = $user->brandManagedCategories();

        return $query->where(function ($q) use ($user, $managed) {
            $q->where('user_id', $user->id)
              ->orWhereHas('assignments', fn ($a) => $a->current()->where('user_id', $user->id));

            if ($managed) {
                $q->orWhereIn('category', $managed);
            }
        });
    }

    /** Requests this user currently holds a role on. */
    public function scopeAssignedTo($query, User $user)
    {
        return $query->whereHas('currentAssignments', fn ($q) => $q->where('user_id', $user->id));
    }

    /** Which hats this user is wearing on this request, e.g. ["Photographer"]. */
    public function rolesFor(User $user): array
    {
        return $this->currentAssignments
            ->where('user_id', $user->id)
            ->map(fn ($a) => $a->roleLabel())
            ->values()
            ->all();
    }

    /**
     * How long each person held each role on this request — the history the old
     * owner columns could not keep, since a handover simply overwrote them.
     *
     * @return \Illuminate\Support\Collection<int, array{role: string, user: ?string, days: int, current: bool}>
     */
    public function ownershipHistory()
    {
        return $this->assignments
            ->sortBy('created_at')
            ->map(fn (ProductRequestAssignment $a) => [
                'role'    => $a->roleLabel(),
                'user'    => $a->user?->name,
                'days'    => $a->heldForDays(),
                'current' => $a->isCurrent(),
            ])
            ->values();
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
