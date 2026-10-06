@extends('layouts.app')

@section('title', $request->reference)
@section('page-title', 'Product Creation')

{{-- The few facts everyone looks for, on the dark band beside its title --}}
@section('page-hero-aside')
    @php
        $heroFacts = array_filter([
            ['Website',         $request->store?->name ?? '—',                        'M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9'],
            ['Website go-live', $request->launchLabel('d M Y') ?? '—',                'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
            ['Showroom launch', $request->store_launch_date?->format('d M Y') ?? '—', 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6'],
            ['Requested by',    $request->requesterName(),                            'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
            $request->published_at ? ['Published', $request->published_at->format('d M Y'), 'M5 13l4 4L19 7'] : null,
        ]);
    @endphp
    <div class="flex flex-wrap gap-2">
        @foreach($heroFacts as [$label, $value, $icon])
            <div class="flex items-center gap-2.5 rounded-xl bg-white/10 ring-1 ring-white/15 backdrop-blur-sm px-3 py-2 min-w-0">
                <svg class="w-4 h-4 text-white/60 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $icon }}"/></svg>
                <div class="min-w-0 leading-tight">
                    <p class="text-[10px] uppercase tracking-wider text-white/55">{{ $label }}</p>
                    <p class="text-sm font-medium text-white truncate max-w-[11rem]">{{ $value }}</p>
                </div>
            </div>
        @endforeach
    </div>
@endsection

@section('content')
@php
    // Not allowedTransitions(): the two photoshoot stages stay permitted, because
    // the Photoshoot Schedule performs exactly those moves — they are simply not
    // offered here, where there is no calendar to set a date on.
    $transitions = $request->manualTransitions();
    $usesMapping = $request->requiresMapping();

    $me        = auth()->user();
    $guide     = $request->currentGuide();
    $ownership = $request->ownershipFor($me);   // mine | my_team | other | none
    $claimable = $request->claimableBy($me);
    $closed    = $request->isClosed();

    $onHold = $request->isOnHold();
    $held   = $request->heldForDays();

    // One colour per state. Being blocked outranks whose task it is —
    // nothing can move until the blocker is cleared.
    [$panelTone, $heading] = match (true) {
        $closed                  => ['border-gray-200',                 'This request is closed'],
        $onHold                  => ['border-red-300 ring-1 ring-red-100',     'On hold — work is blocked'],
        $ownership === 'mine'    => ['border-brand-300 ring-1 ring-brand-100', 'This is your task'],
        $ownership === 'my_team' => ['border-amber-300 ring-1 ring-amber-100', 'Waiting on your team'],
        default                  => ['border-gray-200',                 'Right now'],
    };

    // Countdown to the online launch — the number that decides how loudly a
    // stalled request should shout.
    $daysLeft = $request->daysToOnlineLaunch();
    [$dueTone, $dueText] = match (true) {
        $daysLeft === null => ['bg-gray-100 text-gray-500',     'No launch date'],
        $daysLeft < 0      => ['bg-red-100 text-red-700',       'Overdue by ' . abs($daysLeft) . ' ' . \Illuminate\Support\Str::plural('day', abs($daysLeft))],
        $daysLeft === 0    => ['bg-orange-100 text-orange-700', 'Launches today'],
        $daysLeft <= 7     => ['bg-amber-100 text-amber-700',   $daysLeft . ' ' . \Illuminate\Support\Str::plural('day', $daysLeft) . ' to launch'],
        default            => ['bg-gray-100 text-gray-600',     $daysLeft . ' days to launch'],
    };

    $needsCopy = $request->needsContentCount();
    $unchecked = $request->sheetUncheckedCount();
    $notOnSheet = $request->skusNotOnSheet()->count();

    $card    = 'bg-white rounded-2xl border border-gray-200/80 shadow-sm';
    $btn     = 'inline-flex items-center justify-center gap-1.5 rounded-lg text-sm font-medium px-3.5 py-2 transition-colors';
    $btnMain = $btn . ' bg-brand-600 hover:bg-brand-700 text-white shadow-sm';
    $btnAlt  = $btn . ' border border-gray-300 bg-white text-gray-700 hover:bg-gray-50';
    $small   = 'inline-flex items-center gap-1.5 rounded-lg text-xs font-medium px-3 py-1.5 transition-colors';
@endphp

<div class="space-y-5"
     x-data="{
        tab: 'skus',
        editing: false,
        showTransition: false,
        showCancel: false,
        showHold: false,
        showHandover: false,
        validating: {{ in_array($request->validation_status, ['pending', 'running'], true) ? 'true' : 'false' }},
        poll() {
            if (!this.validating) return;
            fetch('{{ route('product-requests.status', $request) }}')
                .then(r => r.json())
                .then(d => {
                    if (d.validation_status === 'completed' || d.validation_status === 'failed') {
                        window.location.reload();
                    }
                })
                .catch(() => {});
        }
     }"
     x-init="setInterval(() => poll(), 4000)">

    {{-- ── Header ─────────────────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex items-start gap-3 min-w-0">
            <a href="{{ route('product-requests.list') }}" title="All requests"
               class="mt-1 w-9 h-9 rounded-full border border-gray-200 bg-white text-gray-500 hover:text-gray-800 hover:bg-gray-50 flex items-center justify-center shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <div class="min-w-0">
                <h2 class="font-display text-2xl leading-tight text-gray-900 truncate">{{ $request->displayName() }}</h2>
                <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border {{ $request->statusColor() }}">{{ $request->statusLabel() }}</span>
                    @unless($closed)
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium {{ $dueTone }}">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            {{ $dueText }}
                        </span>
                    @endunless
                    @if($request->priority === 'high')
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border {{ $request->priorityColor() }}">{{ $request->priorityLabel() }} priority</span>
                    @endif
                    <span class="text-xs text-gray-400 ml-1">{{ $request->reference }} &middot; {{ $request->store?->name ?? '—' }}</span>
                </div>
            </div>
        </div>

        @unless($closed && !$me->is_super_admin)
        <div class="flex items-center gap-2">
        {{-- Publishing by hand, for when the products are live and nobody wants
             to wait for the hourly check. Same rules as before: never while the
             photoshoot is outstanding, and anything going live incomplete is
             said out loud first. --}}
        @unless($closed)
            @php
                $canPublish = $request->canTransitionTo(\App\Models\ProductRequest::PUBLISHED);
                $publishWarn = collect($request->publishGaps())
                    ->when(!$request->isLiveOnShopify(), fn ($c) => $c->push('not every product shows as live on Shopify yet'))
                    ->map(fn ($g) => '• ' . $g)->implode("\n");
            @endphp
            @if($canPublish)
                <form method="POST" action="{{ route('product-requests.transition', $request) }}"
                      onsubmit="return confirm(@js('Mark ' . $request->reference . ' as Published? This closes the request.' . ($publishWarn ? "\n\nGoing live without:\n" . $publishWarn : '')))">
                    @csrf
                    <input type="hidden" name="to_status" value="{{ \App\Models\ProductRequest::PUBLISHED }}">
                    <button type="submit" class="{{ $btnMain }}">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Publish
                    </button>
                </form>
            @else
                <span class="{{ $btn }} bg-gray-100 text-gray-400 cursor-not-allowed"
                      title="{{ $request->publishBlockedBecause() ?? 'Not available from ' . $request->statusLabel() }}">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Publish
                </span>
            @endif
        @endunless

        {{-- Everything rarely needed lives behind one button. Stages move on
             their own, so there is no "next" button — only a correction tool
             for super admins. --}}
        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
            <button type="button" @click="open = !open" class="{{ $btnAlt }}">
                More
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="open" x-cloak x-transition.origin.top.right
                 class="absolute right-0 mt-1.5 w-56 bg-white rounded-xl border border-gray-200 shadow-lg py-1.5 z-30 text-sm">
                @unless($closed)
                    <button type="button" @click="open = false; tab = 'details'; editing = true; $nextTick(() => document.getElementById('tabs').scrollIntoView({ behavior: 'smooth' }))" class="w-full text-left px-3.5 py-2 text-gray-700 hover:bg-gray-50">Edit details</button>
                    @unless($onHold)
                        <button type="button" @click="open = false; showHold = true" class="w-full text-left px-3.5 py-2 text-gray-700 hover:bg-gray-50">Report a blocker</button>
                    @endunless
                    @if($guide['field'])
                        <button type="button" @click="open = false; showHandover = true" class="w-full text-left px-3.5 py-2 text-gray-700 hover:bg-gray-50">Hand over</button>
                    @endif
                    @if($me->is_super_admin && !empty($transitions))
                        <button type="button" @click="open = false; showTransition = true" class="w-full text-left px-3.5 py-2 text-gray-700 hover:bg-gray-50">
                            Change stage <span class="text-xs text-gray-400">· correction</span>
                        </button>
                    @endif
                    <div class="my-1 border-t border-gray-100"></div>
                    <button type="button" @click="open = false; showCancel = true" class="w-full text-left px-3.5 py-2 text-red-600 hover:bg-red-50">Cancel request</button>
                @endunless
                {{-- Cancelling keeps the record; deleting is for requests that
                     should never have existed, so it is a super admin's call. --}}
                @if($me->is_super_admin)
                    <form method="POST" action="{{ route('product-requests.destroy', $request) }}"
                          onsubmit="return confirm('Delete {{ addslashes($request->reference) }} permanently?\n\nIts {{ $request->total_skus }} SKU(s), activity trail, assignments and attachments go with it. Cancel the request instead if you want to keep the record.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="w-full text-left px-3.5 py-2 text-red-600 hover:bg-red-50">Delete permanently</button>
                    </form>
                @endif
            </div>
        </div>
        </div>
        @endunless
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 2xl:grid-cols-4 gap-5 items-start">

        {{-- ── Main column ────────────────────────────────────────────────── --}}
        <div class="lg:col-span-2 2xl:col-span-3 space-y-4 min-w-0">

            @php
                // One look for every "please answer" card: an icon, a line, buttons.
                $ask      = 'rounded-xl border px-4 py-3 flex flex-wrap items-center gap-3';
                $askIcon  = 'w-8 h-8 rounded-full flex items-center justify-center shrink-0';
                $askText  = 'flex-1 min-w-[12rem] text-sm';
                $qIcon    = 'M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
                $warnIcon = 'M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0l-7.1 12.25A2 2 0 004.99 19z';
                $docIcon  = 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z';
            @endphp

            @if($request->status === \App\Models\ProductRequest::CANCELLED && $request->cancel_reason)
                <div class="{{ $ask }} bg-red-50 border-red-200 text-red-800">
                    <span class="{{ $askIcon }} bg-red-100 text-red-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></span>
                    <p class="{{ $askText }}"><span class="font-medium">Cancelled:</span> {{ $request->cancel_reason }}</p>
                </div>
            @endif

            @if($request->awaitingImageLocation())
                <div class="{{ $ask }} bg-amber-50 border-amber-200 text-amber-900">
                    <span class="{{ $askIcon }} bg-amber-100 text-amber-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $warnIcon }}"/></svg></span>
                    <p class="{{ $askText }}">Add the link to the supplier's images.</p>
                    <button type="button" @click="tab = 'details'; editing = true" class="{{ $small }} bg-white border border-amber-300 hover:bg-amber-100">Add link</button>
                </div>
            @endif

            {{-- Asked once, plainly. Until it is answered the request is neither
                 bound for the studio nor excused from it. --}}
            @if($request->needsPhotoshootDecision())
                <div class="{{ $ask }} bg-blue-50 border-blue-200 text-blue-900">
                    <span class="{{ $askIcon }} bg-blue-100 text-blue-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $qIcon }}"/></svg></span>
                    <p class="{{ $askText }} font-medium">Does this need a photoshoot?</p>
                    <form method="POST" action="{{ route('product-requests.photoshoot-decision', $request) }}">
                        @csrf
                        <input type="hidden" name="needed" value="yes">
                        <button type="submit" class="{{ $small }} bg-brand-600 hover:bg-brand-700 text-white">Yes — we need photos</button>
                    </form>
                    <form method="POST" action="{{ route('product-requests.photoshoot-decision', $request) }}">
                        @csrf
                        <input type="hidden" name="needed" value="no">
                        <button type="submit" class="{{ $small }} bg-white border border-blue-300 hover:bg-blue-100">No</button>
                    </form>
                </div>
            @endif

            @if($request->needsImageSourceDecision())
                <div class="{{ $ask }} bg-blue-50 border-blue-200 text-blue-900">
                    <span class="{{ $askIcon }} bg-blue-100 text-blue-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $qIcon }}"/></svg></span>
                    <p class="{{ $askText }} font-medium">Where are the images coming from?</p>
                    <form method="POST" action="{{ route('product-requests.image-request-decision', $request) }}">
                        @csrf
                        <input type="hidden" name="ask" value="yes">
                        <button type="submit" class="{{ $small }} bg-brand-600 hover:bg-brand-700 text-white">Ask the brand manager</button>
                    </form>
                    <form method="POST" action="{{ route('product-requests.image-request-decision', $request) }}">
                        @csrf
                        <input type="hidden" name="ask" value="no">
                        <button type="submit" class="{{ $small }} bg-white border border-blue-300 hover:bg-blue-100">We already have them</button>
                    </form>
                </div>
            @endif

            {{-- Only once the request has gone past the photoshoot without the
                 pictures. Before that the status card says what is happening. --}}
            @php
                $pipeline   = \App\Models\ProductRequest::PIPELINE;
                $pastShoots = array_search($request->status, $pipeline, true)
                    > array_search(\App\Models\ProductRequest::IMAGE_EDITING, $pipeline, true);
            @endphp
            @if($request->isWaitingOnPhotoshoot() && $pastShoots)
                <div class="{{ $ask }} bg-amber-50 border-amber-200 text-amber-900">
                    <span class="{{ $askIcon }} bg-amber-100 text-amber-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $warnIcon }}"/></svg></span>
                    <p class="{{ $askText }}">
                        <span class="font-medium">Waiting on the photoshoot</span>
                        ({{ strtolower(\App\Models\ProductRequest::SHOOT_STATUSES[$request->photoshoot_status] ?? 'not started') }}{{ $request->photoshoot_scheduled_at ? ', ' . $request->photoshoot_scheduled_at->format('d M, H:i') : '' }}).
                        @if($closed) This was published before the images were delivered. @endif
                    </p>
                    <a href="{{ route('product-requests.photoshoot-room') }}" class="{{ $small }} bg-white border border-amber-300 hover:bg-amber-100">Photoshoot Schedule</a>
                </div>
            @endif

            @if($needsCopy > 0)
                <div class="{{ $ask }} bg-amber-50 border-amber-200 text-amber-900">
                    <span class="{{ $askIcon }} bg-amber-100 text-amber-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $docIcon }}"/></svg></span>
                    <p class="{{ $askText }}"><span class="font-medium">Awaiting content.</span> {{ number_format($needsCopy) }} product(s) have no description in the uploaded file or the sheet.</p>
                    <form method="POST" action="{{ route('product-requests.check-sheet-copy', $request) }}">
                        @csrf
                        <button type="submit" class="{{ $small }} bg-white border border-amber-300 hover:bg-amber-100">Check again</button>
                    </form>
                    <form method="POST" action="{{ route('product-requests.ai-content', $request) }}">
                        @csrf
                        <input type="hidden" name="scope" value="missing_description">
                        <input type="hidden" name="answer" value="generate">
                        <button type="submit" class="{{ $small }} bg-brand-600 hover:bg-brand-700 text-white">Generate AI content for {{ number_format($needsCopy) }}</button>
                    </form>
                    <form method="POST" action="{{ route('product-requests.ai-content', $request) }}">
                        @csrf
                        <input type="hidden" name="scope" value="missing_description">
                        <input type="hidden" name="answer" value="skip">
                        <button type="submit" class="{{ $small }} bg-white border border-amber-300 hover:bg-amber-100">Skip</button>
                    </form>
                </div>
            @endif

            {{-- Looked for and not found: whether copy exists is unknown, so the
                 person decides — look again, or confirm none is coming. --}}
            @if($notOnSheet > 0)
                <div class="{{ $ask }} bg-amber-50 border-amber-200 text-amber-900">
                    <span class="{{ $askIcon }} bg-amber-100 text-amber-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $docIcon }}"/></svg></span>
                    <p class="{{ $askText }}"><span class="font-medium">Not on the sheet.</span> {{ number_format($notOnSheet) }} product(s) weren't found on the {{ $request->category }} tab.</p>
                    <form method="POST" action="{{ route('product-requests.check-sheet-copy', $request) }}">
                        @csrf
                        <button type="submit" class="{{ $small }} bg-white border border-amber-300 hover:bg-amber-100">Check again</button>
                    </form>
                    <form method="POST" action="{{ route('product-requests.ai-content', $request) }}">
                        @csrf
                        <input type="hidden" name="scope" value="not_on_sheet">
                        <input type="hidden" name="answer" value="generate">
                        <button type="submit" class="{{ $small }} bg-brand-600 hover:bg-brand-700 text-white"
                                onclick="return confirm('Generate descriptions for {{ $notOnSheet }} product(s) that are not on the sheet?')">Generate anyway</button>
                    </form>
                    <form method="POST" action="{{ route('product-requests.ai-content', $request) }}">
                        @csrf
                        <input type="hidden" name="scope" value="not_on_sheet">
                        <input type="hidden" name="answer" value="skip">
                        <button type="submit" class="{{ $small }} bg-white border border-amber-300 hover:bg-amber-100">Skip</button>
                    </form>
                </div>
            @endif

            @if($needsCopy === 0 && $notOnSheet === 0 && $unchecked > 0)
                {{-- Null is "nobody read the sheet", not "the sheet is blank".
                     Offering to generate on that risks writing over copy the
                     brand team did supply. --}}
                <div class="{{ $ask }} bg-amber-50 border-amber-200 text-amber-900">
                    <span class="{{ $askIcon }} bg-amber-100 text-amber-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $docIcon }}"/></svg></span>
                    <p class="{{ $askText }}"><span class="font-medium">Awaiting content.</span> The sheet has not been read for {{ number_format($unchecked) }} product(s).</p>
                    <form method="POST" action="{{ route('product-requests.check-sheet-copy', $request) }}">
                        @csrf
                        <button type="submit" class="{{ $small }} bg-brand-600 hover:bg-brand-700 text-white">Check the sheet</button>
                    </form>
                </div>
            @endif

            {{-- Status: where it is, in pictures and one plain sentence ──────── --}}
            @php
                $shoot = $request->needsPhotoshoot();
                $stageShort = [
                    \App\Models\ProductRequest::SUBMITTED            => 'Received',
                    \App\Models\ProductRequest::WAITING_MAPPING      => 'Mapping',
                    \App\Models\ProductRequest::SKU_VERIFIED         => 'SKUs verified',
                    \App\Models\ProductRequest::AI_CONTENT           => 'Descriptions',
                    \App\Models\ProductRequest::WAITING_IMAGES       => $shoot ? 'Book shoot' : 'Images',
                    \App\Models\ProductRequest::PHOTOSHOOT_SCHEDULED => 'Photoshoot',
                    \App\Models\ProductRequest::PHOTOSHOOT_COMPLETED => 'Photos done',
                    \App\Models\ProductRequest::IMAGE_EDITING        => 'Editing',
                    \App\Models\ProductRequest::QA_REVIEW            => 'Review',
                    \App\Models\ProductRequest::READY_FOR_UPLOAD     => 'Upload',
                    \App\Models\ProductRequest::PUBLISHED            => 'Live',
                    \App\Models\ProductRequest::COMPLETED            => 'Done',
                ];
                $outstanding = $request->pending_skus + $request->not_mapped_skus;
                $now = match ($request->status) {
                    \App\Models\ProductRequest::SUBMITTED            => "We're checking the SKUs.",
                    \App\Models\ProductRequest::WAITING_MAPPING      => "{$outstanding} SKU(s) are waiting to be mapped in Cegid.",
                    \App\Models\ProductRequest::SKU_VERIFIED         => 'All SKUs are verified.',
                    \App\Models\ProductRequest::AI_CONTENT           => 'Product descriptions are being prepared.',
                    \App\Models\ProductRequest::WAITING_IMAGES       => $shoot ? 'Waiting for the photoshoot to be booked.' : 'Waiting for the product images.',
                    \App\Models\ProductRequest::PHOTOSHOOT_SCHEDULED => 'Photoshoot booked' . ($request->photoshoot_scheduled_at ? ' for ' . $request->photoshoot_scheduled_at->format('D d M, H:i') : '') . '.',
                    \App\Models\ProductRequest::PHOTOSHOOT_COMPLETED, \App\Models\ProductRequest::IMAGE_EDITING => 'The photos are done.',
                    \App\Models\ProductRequest::PUBLISHED, \App\Models\ProductRequest::COMPLETED => 'Live on the website.',
                    \App\Models\ProductRequest::CANCELLED            => 'This request was cancelled.',
                    default                     => $request->statusLabel() . '.',
                };
                $next = match (true) {
                    $closed => null,
                    in_array($request->status, [\App\Models\ProductRequest::SUBMITTED, \App\Models\ProductRequest::WAITING_MAPPING], true)
                        => 'Moves on by itself once every SKU is mapped.',
                    $request->status === \App\Models\ProductRequest::SKU_VERIFIED && ($request->needsPhotoshootDecision() || $request->needsImageSourceDecision())
                        => 'Moves on once the question above is answered.',
                    $request->status === \App\Models\ProductRequest::SKU_VERIFIED && $request->suggestedNextStatus() === \App\Models\ProductRequest::PUBLISHED
                        => 'Goes live by itself once the products are published on Shopify.',
                    $request->status === \App\Models\ProductRequest::SKU_VERIFIED
                        => 'Moves on by itself at the next SKU check.',
                    $request->status === \App\Models\ProductRequest::AI_CONTENT
                        => 'Moves on by itself once every product has a description.',
                    $request->status === \App\Models\ProductRequest::WAITING_IMAGES && $shoot
                        => 'Moves on when the shoot is booked in the Photoshoot Schedule.',
                    $request->status === \App\Models\ProductRequest::WAITING_IMAGES
                        => 'Click "Images received" when they arrive.',
                    $request->status === \App\Models\ProductRequest::PHOTOSHOOT_SCHEDULED
                        => 'Moves on when the shoot is marked done in the Photoshoot Schedule.',
                    default
                        => 'Goes live by itself once the products are published on Shopify.',
                };
                // What comes after this, in plain words — the card's job is to
                // say what is next, not to repeat what just finished.
                $upcoming  = $closed ? null : $request->suggestedNextStatus();
                $upNext = $upcoming ? match ($upcoming) {
                    \App\Models\ProductRequest::WAITING_MAPPING      => ['SKU mapping', 'The brand manager maps the SKUs in Cegid.'],
                    \App\Models\ProductRequest::SKU_VERIFIED         => ['SKUs verified', 'Every SKU is checked against the website.'],
                    \App\Models\ProductRequest::AI_CONTENT           => ['Descriptions (AI content)', $request->needsContentCount() > 0
                        ? 'AI writes the descriptions for the ' . $request->needsContentCount() . ' product(s) that have none.'
                        : 'AI writes the descriptions for any product that has none.'],
                    \App\Models\ProductRequest::WAITING_IMAGES       => $shoot
                        ? ['Photoshoot', 'The products go to the studio and the shoot gets booked.']
                        : ['Images', 'The product images are collected.'],
                    \App\Models\ProductRequest::PHOTOSHOOT_SCHEDULED => ['Photoshoot', 'The products are photographed on the booked date.'],
                    \App\Models\ProductRequest::PHOTOSHOOT_COMPLETED => ['Photos done', 'The finished photos are ready for the website.'],
                    \App\Models\ProductRequest::PUBLISHED            => ['Live', 'The products go live on the website.'],
                    default                     => [$request->stageLabel($upcoming), null],
                } : null;

                $stages  = $request->displayStages();
                $current = $request->displayStageIndex();
                $tint    = $onHold && !$closed ? 'border-red-200' : ($ownership === 'mine' ? 'border-brand-200' : 'border-gray-200');
            @endphp
            <div class="bg-white rounded-2xl border shadow-sm overflow-hidden {{ $tint }}">

                @if($onHold && !$closed)
                    <div class="px-5 py-2.5 bg-red-50 border-b border-red-100 flex flex-wrap items-center gap-2 text-sm text-red-800">
                        <span class="font-semibold">{{ $heading }}:</span> {{ $request->hold_reason }}
                        <span class="text-xs text-red-600">· {{ $request->holdSetter?->name ?? 'someone' }}{{ $held ? ", {$held}d" : '' }}</span>
                    </div>
                @endif

                {{-- Progress: a ring for the whole job and a tile per part of it,
                     in the same look as the Products card --}}
                @php
                    $phases      = $request->phaseProgress();
                    $currentStep = $request->displayStageIndex();
                    $overall     = $closed && $request->status !== \App\Models\ProductRequest::CANCELLED ? 100 : $request->progressPercent();
                    $C           = 2 * M_PI * 42;
                    $phaseIcons  = [
                        'intake'     => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
                        'content'    => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
                        'photoshoot' => 'M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9zM15 13a3 3 0 11-6 0 3 3 0 016 0z',
                        'launch'     => 'M13 10V3L4 14h7v7l9-11h-7z',
                    ];
                    $firstUpcoming = collect($phases)->firstWhere('state', 'upcoming')['key'] ?? null;
                @endphp
                <div class="px-6 pt-5 flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">Progress</h3>
                        <p class="text-xs text-gray-400">
                            @if($closed) {{ $request->statusLabel() }}
                            @elseif($currentStep >= 0) Step {{ $currentStep + 1 }} of {{ count($request->displayStages()) }} · {{ $request->statusLabel() }}
                            @endif
                        </p>
                    </div>
                </div>

                <div class="px-6 pt-4 pb-5 flex flex-col sm:flex-row items-center gap-5">
                    {{-- The ring --}}
                    <div class="relative w-28 h-28 shrink-0">
                        <svg class="w-28 h-28 -rotate-90" viewBox="0 0 100 100">
                            <circle cx="50" cy="50" r="42" fill="none" stroke-width="9" class="text-gray-100" stroke="currentColor"/>
                            <circle cx="50" cy="50" r="42" fill="none" stroke-width="9" stroke-linecap="round" stroke="currentColor"
                                    class="{{ $overall >= 100 ? 'text-green-500' : 'text-sky-500' }} transition-all duration-700"
                                    stroke-dasharray="{{ $C }}" stroke-dashoffset="{{ $C * (1 - $overall / 100) }}"/>
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-2xl font-bold text-gray-900 tabular-nums">{{ $overall }}%</span>
                            <span class="text-[11px] text-gray-500">{{ $overall >= 100 ? 'done' : 'complete' }}</span>
                        </div>
                    </div>

                    {{-- A tile per part --}}
                    <div class="flex-1 w-full grid grid-cols-2 {{ count($phases) > 3 ? '2xl:grid-cols-4' : 'xl:grid-cols-3' }} gap-3">
                        @foreach($phases as $phase)
                            @php
                                $done = $phase['state'] === 'done' || ($closed && $phase['state'] !== 'upcoming');
                                $here = !$closed && $phase['state'] === 'current';
                                // Moved past the copy is not the same as having it.
                                $gap  = $phase['key'] === 'content' && $done && ($needsCopy + $notOnSheet + $unchecked) > 0;
                                [$bg, $iconTone, $titleTone, $stateText, $stateTone] = match (true) {
                                    $gap  => ['bg-amber-50 border-amber-100', 'bg-amber-100 text-amber-600', 'text-gray-800', 'No descriptions yet', 'text-amber-700'],
                                    $done => ['bg-green-50 border-green-100', 'bg-green-100 text-green-600', 'text-gray-800', 'Done', 'text-green-700'],
                                    $here => ['bg-sky-50 border-sky-200 ring-2 ring-sky-100', 'bg-sky-100 text-sky-600', 'text-gray-900', $request->statusLabel(), 'text-sky-700'],
                                    default => ['bg-gray-50 border-gray-100', 'bg-white text-gray-400', 'text-gray-500', $phase['key'] === $firstUpcoming ? 'Up next' : 'Later', 'text-gray-400'],
                                };
                            @endphp
                            <div class="rounded-2xl border px-4 py-3 {{ $bg }}" title="{{ collect($phase['stages'])->map(fn ($st) => $request->stageLabel($st))->implode(' → ') }}">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-xs font-medium leading-tight {{ $titleTone }}">{{ $phase['label'] }}</span>
                                    <span class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0 {{ $iconTone }}">
                                        @if($done && !$gap)
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                        @else
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $gap ? 'M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0l-7.1 12.25A2 2 0 004.99 19z' : ($phaseIcons[$phase['key']] ?? $phaseIcons['launch']) }}"/></svg>
                                        @endif
                                    </span>
                                </div>
                                <p class="mt-2 text-sm font-semibold leading-snug {{ $stateTone }}">
                                    @if($here)<span class="inline-block w-2 h-2 rounded-full bg-sky-500 animate-pulse mr-1 align-middle"></span>@endif{{ $stateText }}
                                </p>
                                <p class="text-[11px] text-gray-500">{{ count($phase['stages']) }} {{ \Illuminate\Support\Str::plural('step', count($phase['stages'])) }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- What is happening, who has it, what moves it on --}}
                <div class="px-6 py-4 border-t border-gray-100 bg-gray-50/60 flex flex-wrap items-center justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        @unless($closed)
                            <p class="text-[11px] font-medium uppercase tracking-wide {{ $ownership === 'mine' ? 'text-brand-700' : 'text-gray-400' }}">{{ $onHold ? 'Right now' : $heading }}</p>
                        @endunless
                        <p class="text-lg font-semibold text-gray-900 mt-0.5" title="{{ $guide['what'] }}">{{ $now }}</p>
                        @if($upNext)
                            <div class="mt-2 inline-flex items-start gap-2 rounded-xl bg-white border border-gray-200 px-3 py-2 text-sm">
                                <svg class="w-4 h-4 mt-0.5 text-brand-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                                <span><span class="font-medium text-gray-900">Next up: {{ $upNext[0] }}</span>@if($upNext[1])<span class="text-gray-600"> — {{ $upNext[1] }}</span>@endif</span>
                            </div>
                        @endif
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1.5 text-xs text-gray-500">
                            @unless($closed)
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="w-5 h-5 rounded-full bg-gray-100 text-gray-600 text-[10px] font-semibold flex items-center justify-center">
                                        {{ $guide['owner'] ? strtoupper(substr($guide['owner']->name, 0, 1)) : '?' }}
                                    </span>
                                    @if($ownership === 'mine') You
                                    @elseif($guide['owner']) {{ $guide['owner']->name }}
                                    @else <span class="text-amber-700 font-medium">Nobody yet</span>
                                    @endif
                                    <span class="text-gray-400">· {{ $guide['role'] ?? 'Team' }}</span>
                                </span>
                            @endunless
                            @if($next)
                                <span class="inline-flex items-center gap-1 text-gray-500">
                                    <svg class="w-3.5 h-3.5 text-brand-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                    {{ $next }}
                                </span>
                            @endif
                        </div>
                    </div>

                    @unless($closed)
                        <div class="flex flex-wrap gap-2 shrink-0">
                            @if($onHold)
                                <form method="POST" action="{{ route('product-requests.resume', $request) }}">
                                    @csrf
                                    <button type="submit" class="{{ $btn }} bg-green-600 hover:bg-green-700 text-white">Unblock &amp; resume</button>
                                </form>
                            @endif
                            @if($claimable)
                                <form method="POST" action="{{ route('product-requests.claim', $request) }}">
                                    @csrf
                                    <button type="submit" class="{{ $ownership === 'my_team' ? $btn . ' bg-amber-500 hover:bg-amber-600 text-white' : $btnAlt }}">Take this task</button>
                                </form>
                            @endif
                            {{-- The one stage nothing else can see finish. --}}
                            @if($request->status === \App\Models\ProductRequest::WAITING_IMAGES && !$shoot)
                                <form method="POST" action="{{ route('product-requests.images-received', $request) }}">
                                    @csrf
                                    <button type="submit" class="{{ $btnMain }}">Images received</button>
                                </form>
                            @endif
                            @if($request->status === \App\Models\ProductRequest::WAITING_IMAGES && $shoot)
                                <a href="{{ route('product-requests.photoshoot-room') }}" class="{{ $btnAlt }}">Photoshoot Schedule</a>
                            @endif
                            @if($request->isBlockedOnMapping())
                                <button type="button" @click="tab = 'skus'; $nextTick(() => document.getElementById('tabs').scrollIntoView({ behavior: 'smooth' }))" class="{{ $btnAlt }}">View SKUs</button>
                            @endif
                        </div>
                    @endunless
                </div>
            </div>

            {{-- Products ─────────────────────────────────────────────────── --}}
            @php $inShopify = $request->skus()->where('in_shopify', true)->count(); @endphp
            <div class="{{ $card }}">
                <div class="px-5 py-3.5 flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">Products</h3>
                        <p class="text-xs text-gray-400">
                            {{ $request->validated_at ? 'Checked ' . $request->validated_at->diffForHumans() : 'Not checked yet' }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        {{-- Every download in one place, the original upload included. --}}
                        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                            <button type="button" @click="open = !open" class="{{ $small }} border border-gray-300 text-gray-700 hover:bg-gray-50">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                Download
                            </button>
                            <div x-show="open" x-cloak x-transition.origin.top.right
                                 class="absolute right-0 mt-1.5 w-64 bg-white rounded-xl border border-gray-200 shadow-lg py-1.5 z-30 text-sm">
                                @php
                                    $downloads = ['all' => 'All SKUs (' . $request->total_skus . ')'];
                                    if ($usesMapping) {
                                        $downloads[\App\Models\ProductRequest::MAP_MAPPED]     = 'Mapped (' . $request->mapped_skus . ')';
                                        $downloads[\App\Models\ProductRequest::MAP_PENDING]    = 'Pending (' . $request->pending_skus . ')';
                                        $downloads[\App\Models\ProductRequest::MAP_NOT_MAPPED] = 'Not mapped (' . $request->not_mapped_skus . ')';
                                    }
                                @endphp
                                @foreach($downloads as $filter => $label)
                                    <a href="{{ route('product-requests.skus.download', [$request, 'filter' => $filter]) }}" class="block px-3.5 py-2 text-gray-700 hover:bg-gray-50">{{ $label }}</a>
                                @endforeach
                                @if($request->skuFiles->isNotEmpty())
                                    <div class="my-1 border-t border-gray-100"></div>
                                    @foreach($request->skuFiles as $skuFile)
                                        <a href="{{ route('product-requests.attachments.download', [$request, $skuFile]) }}"
                                           title="Uploaded by {{ $skuFile->user?->name ?? 'unknown' }} on {{ $skuFile->created_at->format('d M Y') }}"
                                           class="block px-3.5 py-2 text-gray-700 hover:bg-gray-50 truncate">Original upload: {{ $skuFile->original_name }}</a>
                                    @endforeach
                                @endif
                            </div>
                        </div>

                        <form method="POST" action="{{ route('product-requests.revalidate', $request) }}">
                            @csrf
                            <button type="submit" class="{{ $small }} border border-gray-300 text-gray-700 hover:bg-gray-50">
                                <svg class="w-3.5 h-3.5" :class="validating && 'animate-spin'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                Check SKUs
                            </button>
                        </form>
                    </div>
                </div>

                <div class="px-5 pb-4 space-y-3">
                    <p x-show="validating" x-cloak class="text-xs text-blue-700">Checking… this page refreshes when it's done.</p>

                    @if($request->validation_status === 'failed')
                        <p class="text-xs text-red-700 bg-red-50 rounded-lg px-3 py-2">Check failed: {{ $request->validation_error }}</p>
                    @endif

                    @php
                        // A ring for the one number people ask for — how much is ready —
                        // and a tile per count, each with its own colour and a hint.
                        $total = (int) $request->total_skus;
                        $ready = $usesMapping ? (int) $request->mapped_skus : $inShopify;
                        $pct   = $total > 0 ? (int) round(100 * $ready / $total) : 0;
                        $ringTone = $pct >= 100 ? 'text-green-500' : ($pct > 0 ? 'text-amber-500' : 'text-gray-300');
                        $C = 2 * M_PI * 42;

                        $icons = [
                            'box'   => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
                            'check' => 'M5 13l4 4L19 7',
                            'clock' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
                            'x'     => 'M6 18L18 6M6 6l12 12',
                            'cloud' => 'M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z',
                        ];
                        $tiles = $usesMapping
                            ? [
                                ['Total',      $total,                     'box',   'bg-gray-50 border-gray-100',       'bg-white text-gray-500',     'text-gray-900',  'SKUs on this request'],
                                ['Mapped',     $request->mapped_skus,     'check', 'bg-green-50 border-green-100',     'bg-green-100 text-green-600', 'text-green-700', 'Ready on Shopify'],
                                ['Pending',    $request->pending_skus,    'clock', 'bg-amber-50 border-amber-100',     'bg-amber-100 text-amber-600', 'text-amber-700', 'Waiting for Cegid'],
                                ['Not mapped', $request->not_mapped_skus, 'x',     'bg-red-50 border-red-100',         'bg-red-100 text-red-500',     'text-red-600',   'Need attention'],
                            ]
                            : [
                                ['Total',          $total,              'box',   'bg-gray-50 border-gray-100',   'bg-white text-gray-500',     'text-gray-900',  'SKUs on this request'],
                                ['On Shopify',     $inShopify,          'cloud', 'bg-green-50 border-green-100', 'bg-green-100 text-green-600', 'text-green-700', 'Found on the website'],
                                ['Not on Shopify', $total - $inShopify, 'clock', 'bg-amber-50 border-amber-100', 'bg-amber-100 text-amber-600', 'text-amber-700', 'Not created yet'],
                            ];
                    @endphp

                    <div class="flex flex-col sm:flex-row items-center gap-5">
                        {{-- The ring --}}
                        <div class="relative w-28 h-28 shrink-0">
                            <svg class="w-28 h-28 -rotate-90" viewBox="0 0 100 100">
                                <circle cx="50" cy="50" r="42" fill="none" stroke-width="9" class="stroke-gray-100" stroke="currentColor"/>
                                <circle cx="50" cy="50" r="42" fill="none" stroke-width="9" stroke-linecap="round" stroke="currentColor"
                                        class="{{ $ringTone }} transition-all duration-700"
                                        stroke-dasharray="{{ $C }}" stroke-dashoffset="{{ $C * (1 - $pct / 100) }}"/>
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-2xl font-bold text-gray-900 tabular-nums">{{ $pct }}%</span>
                                <span class="text-[11px] text-gray-500">{{ $usesMapping ? 'mapped' : 'on Shopify' }}</span>
                            </div>
                        </div>

                        {{-- The counts --}}
                        <div class="flex-1 w-full grid grid-cols-2 {{ count($tiles) === 4 ? 'xl:grid-cols-4' : 'xl:grid-cols-3' }} gap-3">
                            @foreach($tiles as [$label, $value, $icon, $bg, $iconTone, $numTone, $hint])
                                <div class="rounded-2xl border px-4 py-3 {{ $bg }}">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium text-gray-600">{{ $label }}</span>
                                        <span class="w-7 h-7 rounded-lg flex items-center justify-center {{ $iconTone }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icons[$icon] }}"/></svg>
                                        </span>
                                    </div>
                                    <p class="mt-1 text-2xl font-bold tabular-nums {{ $value > 0 || $label === 'Total' ? $numTone : 'text-gray-300' }}">{{ number_format($value) }}</p>
                                    <p class="text-[11px] text-gray-500">{{ $hint }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @if($usesMapping && $request->total_skus > 0)
                        @if($request->hasSkuBalance())
                            <div class="flex flex-wrap items-center gap-2">
                                <form method="POST" action="{{ route('product-requests.chase-mapping', $request) }}">
                                    @csrf
                                    <button type="submit" class="{{ $small }} border border-gray-300 text-gray-700 hover:bg-gray-50">
                                        Remind brand manager ({{ number_format($request->balanceSkus()) }} unmapped)
                                    </button>
                                </form>

                                @if($request->canContinueWithMapped())
                                    <form method="POST" action="{{ route('product-requests.continue-mapped', $request) }}" x-data="{ asked: false }" class="contents">
                                        @csrf
                                        <button type="button" x-show="!asked" @click="asked = true" class="{{ $small }} bg-brand-600 hover:bg-brand-700 text-white">
                                            Continue with {{ number_format($request->mapped_skus) }} mapped
                                        </button>
                                        {{-- Asked here because this is the moment the mapped half moves
                                             on: the copy is either written now or deliberately skipped. --}}
                                        <div x-show="asked" x-cloak class="w-full flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 px-3 py-2.5">
                                            <span class="text-xs text-gray-700 font-medium mr-1">Generate AI content for these {{ number_format($request->mapped_skus) }}?</span>
                                            <button type="submit" name="ai_content" value="generate" class="{{ $small }} bg-brand-600 hover:bg-brand-700 text-white">Yes</button>
                                            <button type="submit" name="ai_content" value="skip" class="{{ $small }} border border-gray-300 text-gray-700 hover:bg-gray-50">No, brand team sends copy</button>
                                        </div>
                                    </form>
                                @endif
                            </div>
                        @endif
                    @endif

                </div>
            </div>

            {{-- AI content: only once the request reaches the content leg, or
                 when a run exists that its starter needs to watch. --}}
            @php
                $atContent = in_array($request->status, [
                    \App\Models\ProductRequest::IMAGE_EDITING,
                    \App\Models\ProductRequest::AI_CONTENT,
                    \App\Models\ProductRequest::QA_REVIEW,
                ], true);
                $aiSession = $request->aiContentSession;
            @endphp
            @if($aiSession || ($request->use_ai_content && $atContent))
            <div class="{{ $card }}">
                <div class="px-5 py-3.5 flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-gray-900">AI Content</h3>
                    @unless($closed)
                    <form method="POST" action="{{ route('product-requests.ai-content', $request) }}">
                        @csrf
                        <button type="submit" class="{{ $small }} bg-brand-600 hover:bg-brand-700 text-white">
                            {{ $aiSession ? 'Run again' : 'Generate AI content' }}
                        </button>
                    </form>
                    @endunless
                </div>

                <div class="px-5 pb-4">
                    @php $eligible = $request->skus()->where('in_shopify', true)->count(); @endphp

                    @if($aiSession)
                        {{-- Runs on the queue, so this block polls itself. --}}
                        <div x-data="{
                                status: @js($aiSession->status),
                                label: @js($aiSession->statusLabel()),
                                processed: {{ $aiSession->processed_items }},
                                total: {{ $aiSession->total_items }},
                                percent: {{ $aiSession->progressPercent() }},
                                error: @js($aiSession->error_message),
                                working: {{ $aiSession->isWorking() ? 'true' : 'false' }},
                                poll() {
                                    if (!this.working) return;
                                    fetch('{{ route('product-requests.status', $request) }}')
                                        .then(r => r.json())
                                        .then(d => {
                                            const ai = d.ai_content;
                                            if (!ai) return;
                                            const wasWorking = this.working;
                                            this.status    = ai.status;
                                            this.label     = ai.status_label;
                                            this.processed = ai.processed_items;
                                            this.total     = ai.total_items;
                                            this.percent   = ai.progress;
                                            this.error     = ai.error_message;
                                            this.working   = ['pending', 'processing', 'translating', 'pushing'].includes(ai.status);
                                            // Finishing can unlock push actions elsewhere on the page.
                                            if (wasWorking && !this.working) window.location.reload();
                                        })
                                        .catch(() => {});
                                }
                             }"
                             x-init="setInterval(() => poll(), 4000)">
                            <div class="flex items-center gap-3">
                                <span class="text-sm font-medium shrink-0"
                                      :class="status === 'failed' ? 'text-red-600' : (status === 'ready' || status === 'done' ? 'text-emerald-700' : 'text-gray-800')"
                                      x-text="label">{{ $aiSession->statusLabel() }}</span>
                                <div class="flex-1 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full transition-all duration-500"
                                         :class="status === 'failed' ? 'bg-red-500' : (status === 'ready' || status === 'done' ? 'bg-emerald-500' : 'bg-brand-600')"
                                         :style="`width: ${percent}%`"
                                         style="width: {{ $aiSession->progressPercent() }}%"></div>
                                </div>
                                <span class="text-xs text-gray-500 tabular-nums shrink-0"
                                      x-text="`${processed} / ${total}`">{{ $aiSession->processed_items }} / {{ $aiSession->total_items }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-3 mt-2">
                                <p class="text-xs text-red-600" x-show="error" x-cloak x-text="error">{{ $aiSession->error_message }}</p>
                                <a href="{{ route('ai-content.show', $aiSession) }}" class="ml-auto text-xs text-brand-600 hover:text-brand-700 font-medium">Open in AI Content Generator &rarr;</a>
                            </div>
                        </div>
                    @elseif($eligible === 0)
                        <p class="text-sm text-gray-500">No SKUs are in Shopify yet. Upload the products first, then check SKUs again.</p>
                    @else
                        <p class="text-sm text-gray-600">{{ $eligible }} of {{ $request->total_skus }} SKUs are ready for AI content.</p>
                    @endif
                </div>
            </div>
            @endif

            {{-- Tabs ─────────────────────────────────────────────────────── --}}
            <div id="tabs" class="{{ $card }}">
                <div class="px-3 border-b border-gray-100 flex gap-1 overflow-x-auto">
                    @foreach(array_filter([
                        'skus'        => 'SKUs (' . $request->total_skus . ')',
                        'details'     => 'Details',
                        // Only where SKUs are not resolved through Cegid — elsewhere
                        // an unmatched SKU is the brand manager's to map, not a product to invent.
                        'drafts'      => $usesMapping ? null : 'Shopify Drafts (' . $drafts->count() . ')',
                        'attachments' => 'Files (' . $request->attachments()->count() . ')',
                    ]) as $key => $label)
                        <button type="button" @click="tab = '{{ $key }}'"
                                :class="tab === '{{ $key }}' ? 'border-brand-600 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                class="px-3 py-3 text-sm font-medium border-b-2 whitespace-nowrap transition-colors">{{ $label }}</button>
                    @endforeach
                </div>

                {{-- Tab: details — three small groups of tiles; Edit turns the tiles into fields --}}
                <div x-show="tab === 'details'" class="px-6 py-5">
                    <form method="POST" action="{{ route('product-requests.update', $request) }}">
                        @csrf
                        @method('PUT')

                        @php
                            $input = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500';
                            $tile  = 'rounded-xl bg-gray-50/80 border border-gray-100 px-4 py-3 min-w-0';
                            $tileL = 'block text-xs text-gray-500 mb-1';
                            $tileV = 'text-sm font-medium text-gray-900 break-words';
                            $group = 'text-xs font-semibold uppercase tracking-wider text-gray-400 mb-2.5 flex items-center gap-2';
                        @endphp

                        <div class="flex items-center justify-between mb-4">
                            <p class="text-sm text-gray-500" x-show="!editing">Everything about this request.</p>
                            <p class="text-sm font-medium text-brand-700" x-show="editing" x-cloak>Editing — change what you need, then Save.</p>
                            @unless($closed)
                                <button type="button" x-show="!editing" @click="editing = true" class="{{ $small }} border border-gray-300 bg-white text-gray-700 hover:bg-gray-50">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    Edit
                                </button>
                            @endunless
                        </div>

                        <div class="space-y-6">
                            {{-- Product --}}
                            <section>
                                <h4 class="{{ $group }}">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                    Product
                                </h4>
                                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                                    @foreach([['name', 'Request name', false], ['brand', 'Brand', true]] as [$field, $label, $required])
                                        <div class="{{ $tile }}">
                                            <label class="{{ $tileL }}">{{ $label }}</label>
                                            <template x-if="!editing"><p class="{{ $tileV }}">{{ $request->{$field} ?: '—' }}</p></template>
                                            <input x-show="editing" x-cloak type="text" name="{{ $field }}" value="{{ old($field, $request->{$field}) }}" {{ $required ? 'required' : '' }} class="{{ $input }}">
                                        </div>
                                    @endforeach

                                    <div class="{{ $tile }}">
                                        <label class="{{ $tileL }}">Category</label>
                                        <template x-if="!editing"><p class="{{ $tileV }}">{{ $request->category ?: '—' }}</p></template>
                                        <select x-show="editing" x-cloak name="category" required class="{{ $input }}">
                                            @foreach($request->categoryOptions() as $category)
                                                <option value="{{ $category }}" {{ old('category', $request->category) === $category ? 'selected' : '' }}>{{ $category }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div class="{{ $tile }}">
                                        <label class="{{ $tileL }}">Priority</label>
                                        <template x-if="!editing">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border {{ $request->priorityColor() }}">{{ $request->priorityLabel() }}</span>
                                        </template>
                                        <select x-show="editing" x-cloak name="priority" class="{{ $input }}">
                                            @foreach(\App\Models\ProductRequest::PRIORITIES as $value => $label)
                                                <option value="{{ $value }}" @selected($request->priority === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </section>

                            {{-- Dates --}}
                            <section>
                                <h4 class="{{ $group }}">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    Dates
                                </h4>
                                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                                    <div class="{{ $tile }}">
                                        <label class="{{ $tileL }}">Website Go-Live Date</label>
                                        <template x-if="!editing"><p class="{{ $tileV }}">{{ $request->launchLabel() ?? '—' }}</p></template>
                                        <input x-show="editing" x-cloak type="datetime-local" name="online_launch_date" required
                                               value="{{ old('online_launch_date', $request->online_launch_date?->format('Y-m-d\TH:i')) }}" class="{{ $input }}">
                                    </div>

                                    <div class="{{ $tile }}">
                                        <label class="{{ $tileL }}">Expected showroom launch</label>
                                        <template x-if="!editing"><p class="{{ $tileV }}">{{ $request->store_launch_date?->format('d M Y') ?? '—' }}</p></template>
                                        <input x-show="editing" x-cloak type="date" name="store_launch_date"
                                               value="{{ old('store_launch_date', $request->store_launch_date?->format('Y-m-d')) }}" class="{{ $input }}">
                                    </div>

                                    <div @class([$tile, 'hidden' => !$request->needsPhotoshoot() && !$request->photoshoot_scheduled_at])>
                                        <label class="{{ $tileL }}">Photoshoot</label>
                                        <template x-if="!editing">
                                            <p class="{{ $tileV }} flex items-center gap-1.5">
                                                {{ $request->photoshoot_scheduled_at?->format('d M Y, H:i') ?? 'Not booked' }}
                                                @if($request->photoshoot_status)
                                                    <a href="{{ route('product-requests.photoshoot-room') }}"
                                                       class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium border {{ $request->shootStatusColor() }}">{{ $request->shootStatusLabel() }}</a>
                                                @endif
                                            </p>
                                        </template>
                                        <input x-show="editing" x-cloak type="datetime-local" name="photoshoot_scheduled_at"
                                               value="{{ old('photoshoot_scheduled_at', $request->photoshoot_scheduled_at?->format('Y-m-d\TH:i')) }}" class="{{ $input }}">
                                    </div>
                                </div>
                            </section>

                            {{-- Images & descriptions --}}
                            <section>
                                <h4 class="{{ $group }}">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    Images &amp; descriptions
                                </h4>
                                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                                    <div class="{{ $tile }}">
                                        <label class="{{ $tileL }}">Images</label>
                                        <template x-if="!editing"><p class="{{ $tileV }}">{{ $request->imageSourceLabel() }}</p></template>
                                        <select x-show="editing" x-cloak name="image_source" class="{{ $input }}">
                                            @foreach($request->imageSourceOptions() as $value => $meta)
                                                <option value="{{ $value }}" @selected($request->image_source === $value)>{{ $meta['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    {{-- Where the supplier images actually are. --}}
                                    <div @class([$tile, 'hidden' => !$request->needsImageLocation()])>
                                        <label class="{{ $tileL }}">Images location</label>
                                        <template x-if="!editing">
                                            <p class="{{ $tileV }}">
                                                @if($request->imagesInPim())
                                                    Already in the Brand PIM
                                                @elseif($request->images_url)
                                                    <a href="{{ $request->images_url }}" target="_blank" rel="noopener" title="{{ $request->images_url }}"
                                                       class="inline-flex items-center gap-1 text-brand-600 hover:text-brand-700">Open folder &nearr;</a>
                                                @else
                                                    <span class="text-amber-600">Not added yet</span>
                                                @endif
                                            </p>
                                        </template>
                                        <div x-show="editing" x-cloak class="space-y-2">
                                            <select name="images_location" class="{{ $input }}">
                                                <option value="">Not recorded</option>
                                                @foreach(\App\Models\ProductRequest::IMAGE_LOCATIONS as $value => $label)
                                                    <option value="{{ $value }}" @selected($request->images_location === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <input type="url" name="images_url" maxlength="2048" value="{{ old('images_url', $request->images_url) }}"
                                                   placeholder="https://… link to the folder" class="{{ $input }}">
                                        </div>
                                    </div>

                                    <div class="{{ $tile }}">
                                        <label class="{{ $tileL }}">Descriptions</label>
                                        <template x-if="!editing">
                                            <p class="{{ $tileV }} flex items-center gap-1.5">
                                                <span class="w-2 h-2 rounded-full {{ $request->use_ai_content ? 'bg-violet-500' : 'bg-sky-500' }}"></span>
                                                {{ $request->use_ai_content ? 'Written with AI' : 'From brand team' }}
                                            </p>
                                        </template>
                                        <select x-show="editing" x-cloak name="use_ai_content" class="{{ $input }}">
                                            <option value="1" @selected($request->use_ai_content)>Written with AI</option>
                                            <option value="0" @selected(!$request->use_ai_content)>From brand team</option>
                                        </select>
                                    </div>
                                </div>
                            </section>

                            {{-- Notes --}}
                            <section>
                                <h4 class="{{ $group }}">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
                                    Notes
                                </h4>
                                <template x-if="!editing">
                                    <p class="rounded-xl border border-dashed border-gray-200 px-4 py-3 text-sm whitespace-pre-line {{ $request->notes ? 'text-gray-800' : 'text-gray-400' }}">{{ $request->notes ?: 'No notes.' }}</p>
                                </template>
                                <textarea x-show="editing" x-cloak name="notes" rows="3" placeholder="Anything the team should know" class="{{ $input }} resize-y">{{ old('notes', $request->notes) }}</textarea>
                            </section>
                        </div>

                        <div x-show="editing" x-cloak class="flex justify-end gap-2 mt-6 pt-4 border-t border-gray-100">
                            <button type="button" @click="editing = false" class="{{ $btnAlt }}">Cancel</button>
                            <button type="submit" class="{{ $btnMain }}">Save changes</button>
                        </div>
                    </form>
                </div>

                {{-- Tab: SKUs — search, quick filters, and a row per SKU that opens to its colours & sizes --}}
                @php
                    $pageSkus = collect($skus->items());
                    $skuChips = array_filter([
                        'all'        => ['All', $pageSkus->count()],
                        'mapped'     => $usesMapping ? ['Mapped', $pageSkus->where('mapping_status', \App\Models\ProductRequest::MAP_MAPPED)->count()] : null,
                        'pending'    => $usesMapping ? ['Pending', $pageSkus->where('mapping_status', \App\Models\ProductRequest::MAP_PENDING)->count()] : null,
                        'not_mapped' => $usesMapping ? ['Not mapped', $pageSkus->where('mapping_status', \App\Models\ProductRequest::MAP_NOT_MAPPED)->count()] : null,
                        'published'  => ['Published', $pageSkus->where('in_shopify', true)->where('shopify_published', true)->count()],
                        'draft'      => ['Draft', $pageSkus->where('in_shopify', true)->where('shopify_published', '!==', true)->count()],
                        'missing'    => ['Not on Shopify', $pageSkus->where('in_shopify', false)->count()],
                    ]);
                @endphp
                <div x-show="tab === 'skus'" x-cloak
                     x-data="{
                        adding: {{ $errors->has('sku_csv') || $errors->has('skus') ? 'true' : 'false' }},
                        csvError: @js($errors->first('sku_csv') ?: null),
                        q: '', filter: 'all', copied: null,
                        rows: @js($pageSkus->map(fn ($k) => [$k->sku, $k->shopify_product_title, $k->mapping_status, (bool) $k->in_shopify, (bool) ($k->in_shopify && $k->shopify_published)])->values()),
                        get anyVisible() { return this.rows.some(r => this.visible(...r)); },
                        // Clicking pins a SKU open in its row; hovering peeks at it in a
                        // popup. Either way each SKU is read from Shopify once and kept.
                        open: null, peek: null, peekTimer: null, cache: {},
                        visible(sku, title, status, live, pub) {
                            const q = this.q.trim().toLowerCase();
                            if (q && !sku.toLowerCase().includes(q) && !(title || '').toLowerCase().includes(q)) return false;
                            switch (this.filter) {
                                case 'mapped': case 'pending': case 'not_mapped': return status === this.filter;
                                case 'published': return live && pub;
                                case 'draft':     return live && !pub;
                                case 'missing':   return !live;
                                default:          return true;
                            }
                        },
                        copy(sku) { navigator.clipboard?.writeText(sku); this.copied = sku; setTimeout(() => this.copied = null, 1200); },
                        async fetchOnce(sku) {
                            if (this.cache[sku]) return;
                            this.cache[sku] = { data: null, error: null, loading: true };
                            try {
                                const res  = await fetch('{{ route('product-requests.variants', $request) }}?sku=' + encodeURIComponent(sku), { headers: { Accept: 'application/json' } });
                                const body = await res.json();
                                this.cache[sku] = res.ok
                                    ? { data: body, error: null, loading: false }
                                    : { data: null, error: body.error || body.message || 'Could not read the variants for this SKU.', loading: false };
                            } catch (e) {
                                // Not kept: a dropped connection is worth trying again.
                                delete this.cache[sku];
                                this.cache[sku] = { data: null, error: 'Could not reach Shopify for this SKU.', loading: false };
                                setTimeout(() => delete this.cache[sku], 5000);
                            }
                        },
                        toggle(sku) {
                            this.peek = null;
                            this.open = this.open === sku ? null : sku;
                            if (this.open) this.fetchOnce(sku);
                        },
                        // A short pause before peeking, so sweeping the mouse down the
                        // list does not flash a popup on every row it crosses.
                        hoverIn(sku) {
                            clearTimeout(this.peekTimer);
                            this.peekTimer = setTimeout(() => { this.peek = sku; this.fetchOnce(sku); }, 350);
                        },
                        hoverOut() {
                            clearTimeout(this.peekTimer);
                            this.peekTimer = setTimeout(() => { this.peek = null; }, 200);
                        },
                     }">

                    {{-- Toolbar --}}
                    <div class="px-6 pt-5 pb-3 space-y-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="relative flex-1 min-w-[14rem]">
                                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                                <input type="text" x-model="q" placeholder="Search SKU or product"
                                       class="w-full rounded-xl border border-gray-200 bg-gray-50/60 py-2 pl-9 pr-3 text-sm focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                            </div>
                            @unless($closed)
                                <button type="button" @click="adding = !adding" class="{{ $btnAlt }}">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                                    Add SKUs
                                </button>
                            @endunless
                        </div>

                        <div class="flex flex-wrap gap-1.5">
                            @foreach($skuChips as $key => [$label, $count])
                                <button type="button" @click="filter = '{{ $key }}'"
                                        :class="filter === '{{ $key }}' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors">
                                    {{ $label }}
                                    <span class="tabular-nums opacity-70">{{ $count }}</span>
                                </button>
                            @endforeach
                            @if($skus->hasPages())
                                <span class="self-center text-[11px] text-gray-400 ml-1">· this page</span>
                            @endif
                        </div>

                        @unless($closed)
                        <form method="POST" action="{{ route('product-requests.skus.add', $request) }}" enctype="multipart/form-data"
                              x-show="adding" x-cloak x-transition class="rounded-2xl border border-gray-200 bg-gray-50/60 p-4 space-y-3">
                            @csrf
                            <div class="grid gap-3 md:grid-cols-2">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Type SKUs</label>
                                    <textarea name="skus" rows="3" placeholder="One per line"
                                              class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 resize-y"></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">…or upload a CSV</label>
                                    <input type="file" name="sku_csv" accept=".csv,.txt"
                                           @change="csvError = await window.checkSkuCsv($el.files[0]); if (csvError) $el.value = ''"
                                           class="w-full rounded-lg border border-dashed border-gray-300 bg-white p-3 text-xs text-gray-600 file:mr-2 file:py-1.5 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-gray-100 file:text-gray-700 hover:file:bg-gray-200 cursor-pointer">
                                    <p class="text-[11px] text-gray-400 mt-1">Needs a "SKU" or "Item SKU" column.</p>
                                    <p x-show="csvError" x-cloak x-text="csvError" class="text-xs text-red-600 mt-1"></p>
                                </div>
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="adding = false" class="{{ $small }} text-gray-500 hover:text-gray-700">Cancel</button>
                                <button type="submit" class="{{ $small }} bg-brand-600 hover:bg-brand-700 text-white">Add &amp; check</button>
                            </div>
                        </form>
                        @include('product-requests.partials.sku-csv-check')
                        @endunless
                    </div>

                    @if($skus->isEmpty())
                        <div class="px-6 pb-10 pt-4 text-center">
                            <p class="text-sm text-gray-400">No SKUs yet.</p>
                        </div>
                    @else
                    {{-- The rows --}}
                    <div class="px-6 pb-5 space-y-2">
                        @foreach($skus as $sku)
                            @php
                                $live = (bool) $sku->in_shopify;
                                $pub  = $live && $sku->shopify_published;
                            @endphp
                            <div x-show="visible(@js($sku->sku), @js($sku->shopify_product_title), @js($sku->mapping_status), {{ $live ? 'true' : 'false' }}, {{ $pub ? 'true' : 'false' }})"
                                 @if($live) @mouseenter="hoverIn(@js($sku->sku))" @mouseleave="hoverOut()" @endif
                                 class="relative rounded-2xl border transition-colors"
                                 :class="open === @js($sku->sku) || (peek === @js($sku->sku) && !open) ? 'border-brand-300 shadow-sm' : 'border-gray-200 hover:border-gray-300'">
                                <div class="flex items-center gap-3 px-4 py-3 {{ $live ? 'cursor-pointer' : '' }}"
                                     @if($live) role="button" tabindex="0" @click="toggle(@js($sku->sku))" @keydown.enter.prevent="toggle(@js($sku->sku))" @endif>
                                    <span class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0 {{ $live ? ($pub ? 'bg-emerald-50 text-emerald-600' : 'bg-gray-100 text-gray-500') : 'bg-amber-50 text-amber-500' }}">
                                        <svg class="w-4.5 h-4.5" style="width:1.1rem;height:1.1rem" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $live ? 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4' : 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z' }}"/></svg>
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-gray-900 truncate">{{ $sku->shopify_product_title ?: 'Not on Shopify yet' }}</p>
                                        <p class="mt-0.5 flex items-center gap-1.5 text-xs text-gray-500">
                                            <span class="font-mono">{{ $sku->sku }}</span>
                                            <button type="button" @click.stop="copy(@js($sku->sku))" title="Copy SKU"
                                                    class="text-gray-300 hover:text-gray-600">
                                                <svg x-show="copied !== @js($sku->sku)" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                                <svg x-show="copied === @js($sku->sku)" x-cloak class="w-3.5 h-3.5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                            </button>
                                        </p>
                                    </div>

                                    <div class="hidden sm:flex items-center gap-1.5 shrink-0" title="Last checked {{ $sku->last_checked_at?->format('d M, h:i A') ?? 'never' }}">
                                        @if($usesMapping)
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border {{ $sku->color() }}">
                                                <span class="w-1.5 h-1.5 rounded-full {{ $sku->dot() }}"></span>
                                                {{ $sku->label() }}
                                            </span>
                                        @endif
                                        @if($live)
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {{ $pub ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ $pub ? 'Published' : 'Draft' }}</span>
                                        @else
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-amber-50 text-amber-700">Not on Shopify</span>
                                        @endif
                                    </div>

                                    @if($live)
                                        <span class="shrink-0 inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium transition-colors"
                                              :class="open === @js($sku->sku) ? 'bg-brand-600 text-white' : 'bg-gray-50 text-brand-700 group-hover:bg-gray-100'">
                                            <span x-text="open === @js($sku->sku) ? 'Hide' : 'Colours & sizes'">Colours &amp; sizes</span>
                                            <svg class="w-3.5 h-3.5 transition-transform" :class="(open === @js($sku->sku) || (peek === @js($sku->sku) && !open)) && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                        </span>
                                    @endif
                                </div>

                                @if($live)
                                    {{-- The panel reads this SKU's cache entry under the names the shared partial expects. --}}
                                    @php $scope = "{ get breakdown() { return cache[" . \Illuminate\Support\Js::from($sku->sku) . "]?.data }, get breakdownLoading() { return cache[" . \Illuminate\Support\Js::from($sku->sku) . "]?.loading ?? true }, get breakdownError() { return cache[" . \Illuminate\Support\Js::from($sku->sku) . "]?.error } }"; @endphp

                                    {{-- Opened by a click (stays) or by hovering (closes when you move away), inside the row either way --}}
                                    <div x-show="open === @js($sku->sku) || (peek === @js($sku->sku) && !open)" x-cloak
                                         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                                         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                                         class="border-t border-gray-100 bg-gray-50/70 px-4 pb-4 rounded-b-2xl">
                                        <template x-if="open === @js($sku->sku) || peek === @js($sku->sku)">
                                            <div x-data="{!! $scope !!}">
                                                @include('partials.variant-breakdown')
                                            </div>
                                        </template>
                                    </div>
                                @endif
                            </div>
                        @endforeach

                        <p x-show="!anyVisible" x-cloak
                           class="py-8 text-center text-sm text-gray-400">No SKUs match.</p>
                    </div>

                    @if($skus->hasPages())
                        <div class="px-6 pb-5">{{ $skus->links() }}</div>
                    @endif
                    @endif
                </div>

                @unless($usesMapping)
                    {{-- Tab: Shopify drafts --}}
                    <div x-show="tab === 'drafts'" x-cloak class="px-5 py-5">
                        @include('product-requests.partials.shopify-drafts')
                    </div>
                @endunless

                {{-- Tab: files --}}
                <div x-show="tab === 'attachments'" x-cloak class="px-5 py-5 space-y-6">
                    @foreach(array_filter([
                        // The SKU CSV exactly as uploaded; only its SKU column became SKUs.
                        ['Uploaded SKU File', $request->skuFiles, false, null],
                        // Only relevant when the AI generator isn't used.
                        $request->use_ai_content ? null : ['Content Sheet', $request->contentSheets, true, 'content'],
                        ['Reference Images', $request->referenceImages, true, 'reference'],
                    ]) as [$title, $files, $removable, $upload])
                        <div>
                            <div class="flex items-center justify-between gap-2 mb-2">
                                <h4 class="text-sm font-semibold text-gray-900">{{ $title }}</h4>
                                @if($upload && !$closed)
                                    <form method="POST" action="{{ route('product-requests.attachments.store', $request) }}" enctype="multipart/form-data"
                                          x-data x-ref="form">
                                        @csrf
                                        @if($upload === 'content')
                                            <input type="hidden" name="kind" value="{{ \App\Models\ProductRequestAttachment::KIND_CONTENT }}">
                                            <input type="file" name="content_sheet" accept=".csv,.xlsx,.xls" class="hidden" x-ref="file" @change="$refs.form.submit()">
                                        @else
                                            <input type="file" name="reference_images[]" multiple accept=".jpg,.jpeg,.png,.pdf" class="hidden" x-ref="file" @change="$refs.form.submit()">
                                        @endif
                                        <button type="button" @click="$refs.file.click()" class="text-xs font-medium text-brand-600 hover:text-brand-700">+ Upload</button>
                                    </form>
                                @endif
                            </div>

                            @forelse($files as $file)
                                <div class="flex items-center gap-3 py-2 border-b border-gray-50 last:border-0">
                                    <div class="w-8 h-8 rounded-lg bg-gray-100 text-gray-500 flex items-center justify-center shrink-0">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm text-gray-800 truncate">{{ $file->original_name }}</p>
                                        <p class="text-xs text-gray-400">{{ $file->humanSize() }} &middot; {{ $file->user?->name ?? 'Unknown' }} &middot; {{ $file->created_at->format('d M Y') }}</p>
                                    </div>
                                    <a href="{{ route('product-requests.attachments.download', [$request, $file]) }}" class="text-xs text-brand-600 hover:text-brand-700 font-medium shrink-0">Download</a>
                                    @if($removable && !$closed)
                                        <form method="POST" action="{{ route('product-requests.attachments.destroy', [$request, $file]) }}" onsubmit="return confirm('Remove this file?')" class="shrink-0">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-xs text-gray-400 hover:text-red-600">Remove</button>
                                        </form>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-gray-400">None yet.</p>
                            @endforelse
                        </div>
                    @endforeach
                    <p class="text-xs text-gray-400">Max {{ \App\Models\ProductRequestAttachment::maxUploadLabel() }} per file.</p>
                </div>
            </div>

            {{-- Timeline: comments and activity in one place ──────────────── --}}
            <div class="{{ $card }}">
                <div class="px-5 py-3.5 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900">Timeline</h3>
                    <a href="{{ route('product-requests.activities', $request) }}" class="text-xs text-brand-600 hover:text-brand-700 font-medium">View all</a>
                </div>
                <form method="POST" action="{{ route('product-requests.comment', $request) }}" class="px-5 pb-4">
                    @csrf
                    <div class="flex gap-2 rounded-lg border border-gray-300 focus-within:ring-2 focus-within:ring-brand-500 p-1.5">
                        <textarea name="remarks" rows="1" required placeholder="Leave a comment…"
                                  class="flex-1 border-0 px-2 py-1.5 text-sm focus:ring-0 resize-none"></textarea>
                        <button type="submit" class="{{ $small }} bg-brand-600 hover:bg-brand-700 text-white self-end">Post</button>
                    </div>
                </form>
                <div class="px-5 pb-5 max-h-[520px] overflow-y-auto">
                    @forelse($activities as $entry)
                        @php $isComment = $entry->action === 'comment'; @endphp
                        <div class="relative flex gap-3 pb-4">
                            @unless($loop->last)
                                <span class="absolute left-[11px] top-6 bottom-0 w-px bg-gray-100"></span>
                            @endunless
                            <span class="relative w-6 h-6 rounded-full flex items-center justify-center shrink-0 text-[10px] font-semibold {{ $isComment ? 'bg-brand-50 text-brand-700' : 'bg-gray-100 text-gray-400' }}">
                                @if($isComment)
                                    {{ strtoupper(substr($entry->actorName(), 0, 1)) }}
                                @else
                                    <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                @endif
                            </span>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="text-sm text-gray-800">
                                        <span class="font-medium">{{ $entry->actorName() }}</span>
                                        @unless($isComment)
                                            <span class="text-gray-600">{{ \Illuminate\Support\Str::lcfirst($entry->description) }}</span>
                                        @endunless
                                    </p>
                                    <span class="text-xs text-gray-400 whitespace-nowrap shrink-0" title="{{ $entry->created_at->format('d M Y, h:i A') }}">{{ $entry->created_at->format('d M, h:i A') }}</span>
                                </div>
                                @if($isComment)
                                    <p class="mt-1 text-sm text-gray-700 bg-gray-50 rounded-lg px-3 py-2 whitespace-pre-line">{{ $entry->remarks }}</p>
                                @elseif($entry->remarks)
                                    <p class="text-xs text-gray-500 mt-0.5">{{ $entry->remarks }}</p>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="py-6 text-sm text-gray-400 text-center">Nothing yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- ── Sidebar ────────────────────────────────────────────────────── --}}
        <div class="space-y-5 min-w-0">

            {{-- Images: where they come from and where they are --}}
            <div class="{{ $card }}">
                <div class="px-5 py-3.5">
                    <h3 class="text-sm font-semibold text-gray-900">Images</h3>
                </div>
                <div class="px-5 pb-4 space-y-3 text-sm">
                    <div class="flex items-center gap-3">
                        <span class="w-9 h-9 rounded-xl {{ $shoot ? 'bg-violet-50 text-violet-600' : 'bg-sky-50 text-sky-600' }} flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $shoot ? 'M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9zM15 13a3 3 0 11-6 0 3 3 0 016 0z' : 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z' }}"/></svg>
                        </span>
                        <div class="min-w-0">
                            <p class="text-gray-900 font-medium">{{ $request->imageSourceLabel() }}</p>
                            @if($shoot)
                                <p class="text-xs text-gray-500">
                                    {{ $request->photoshoot_scheduled_at ? 'Shoot ' . $request->photoshoot_scheduled_at->format('D d M, H:i') : 'Shoot not booked yet' }}
                                    @if($request->photoshoot_status)
                                        · <span class="font-medium">{{ $request->shootStatusLabel() }}</span>
                                    @endif
                                </p>
                            @elseif($request->imagesInPim())
                                <p class="text-xs text-gray-500">In the Brand PIM</p>
                            @endif
                        </div>
                    </div>
                    @if($request->needsImageLocation() && $request->images_url)
                        <a href="{{ $request->images_url }}" target="_blank" rel="noopener" title="{{ $request->images_url }}"
                           class="{{ $btnAlt }} w-full">Open folder &nearr;</a>
                    @elseif($shoot)
                        <a href="{{ route('product-requests.photoshoot-room') }}" class="{{ $btnAlt }} w-full">Photoshoot Schedule</a>
                    @endif
                </div>
            </div>

            {{-- Team: names first, dropdowns only when changing them. --}}
            @php $assignmentFields = $request->visibleAssignmentRoles(); @endphp
            <div class="{{ $card }}" x-data="{ editTeam: false }">
                <div class="px-5 py-3.5 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900">Team</h3>
                    @unless($closed)
                        <button type="button" @click="editTeam = !editTeam" class="text-xs text-brand-600 hover:text-brand-700 font-medium" x-text="editTeam ? 'Done' : 'Edit'">Edit</button>
                    @endunless
                </div>

                <ul x-show="!editTeam" class="px-5 pb-4 space-y-2.5">
                    @foreach($assignmentFields as $field => $label)
                        @php
                            $owner = $request->ownerFor($field);
                            $isCurrentStageOwner = $guide['field'] === $field && !$closed;
                        @endphp
                        <li class="flex items-center gap-2.5">
                            <span class="w-7 h-7 rounded-full text-[11px] font-semibold flex items-center justify-center shrink-0 {{ $isCurrentStageOwner ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-500' }}">
                                {{ $owner ? strtoupper(substr($owner->name, 0, 1)) : '–' }}
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm truncate {{ $owner ? 'text-gray-900' : 'text-gray-400' }}">{{ $owner?->name ?? 'Unassigned' }}</p>
                                <p class="text-xs truncate {{ $isCurrentStageOwner ? 'text-brand-700 font-medium' : 'text-gray-400' }}">{{ $label }}{{ $isCurrentStageOwner ? ' · now' : '' }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div x-show="editTeam" x-cloak>
                    <form method="POST" action="{{ route('product-requests.assign', $request) }}" class="px-5 pb-4 space-y-3">
                        @csrf
                        @foreach($assignmentFields as $field => $label)
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">{{ $label }}</label>
                                <select name="{{ $field }}" {{ $closed ? 'disabled' : '' }}
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 disabled:bg-gray-50 disabled:text-gray-500">
                                    <option value="">Unassigned</option>
                                    @foreach($teamPool as $member)
                                        <option value="{{ $member->id }}" @selected($request->ownerFor($field)?->id === $member->id)>{{ $member->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                        @unless($closed)
                            <button type="submit" class="{{ $btnMain }} w-full">Save team</button>
                        @endunless
                    </form>

                    {{-- Roles nobody was configured for fall back to the category
                         owner; this re-reads the settings for exactly those. --}}
                    @unless($closed)
                        <form method="POST" action="{{ route('product-requests.restaff', $request) }}" class="px-5 pb-4 -mt-1">
                            @csrf
                            <button type="submit" class="text-xs text-gray-500 hover:text-gray-700" title="Only changes roles nobody picked by hand">Reset to category defaults</button>
                        </form>
                    @endunless
                </div>

                {{-- Who held what, and for how long. Only once a role has changed hands. --}}
                @if($request->assignments->whereNotNull('ended_at')->isNotEmpty())
                    <details class="border-t border-gray-100">
                        <summary class="px-5 py-3 text-xs text-gray-500 cursor-pointer hover:text-gray-700">Ownership History</summary>
                        <div class="pb-2">
                            @foreach($request->ownershipHistory() as $entry)
                                <div class="flex items-center gap-3 px-5 py-1.5">
                                    <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $entry['current'] ? 'bg-green-500' : 'bg-gray-300' }}"></span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm text-gray-800 truncate">{{ $entry['user'] ?? 'Unassigned' }}</p>
                                        <p class="text-xs text-gray-400">{{ $entry['role'] }}</p>
                                    </div>
                                    <span class="text-xs shrink-0 {{ $entry['current'] ? 'text-green-700 font-medium' : 'text-gray-500' }}">{{ $entry['days'] }}d</span>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Modals ─────────────────────────────────────────────────────────── --}}

    {{-- Move stage --}}
    <div x-show="showTransition" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-gray-900/40" @click="showTransition = false"></div>
        <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-md">
            <form method="POST" action="{{ route('product-requests.transition', $request) }}">
                @csrf
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="text-base font-semibold text-gray-900">Move to next stage</h3>
                    <p class="text-sm text-gray-500 mt-0.5">Now: {{ $request->statusLabel() }}</p>
                </div>
                <div class="px-5 py-4 space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Move to</label>
                        <select name="to_status" required
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                            @foreach($transitions as $status)
                                <option value="{{ $status }}" @selected($status === $request->suggestedNextStatus())>
                                    {{ $request->stageLabel($status) }}@if($status === $request->suggestedNextStatus()) (suggested)@endif
                                </option>
                            @endforeach
                        </select>
                    </div>

                    @if($request->hasSkuBalance())
                        <p class="bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-xs text-amber-900">
                            Only {{ number_format($request->mapped_skus) }} of {{ number_format($request->total_skus) }} SKUs are mapped.
                        </p>
                    @endif

                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Note <span class="text-gray-400 font-normal">(optional)</span></label>
                        <textarea name="remarks" rows="2"
                                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 resize-y"></textarea>
                    </div>
                </div>
                <div class="px-5 py-4 bg-gray-50 border-t border-gray-100 rounded-b-xl flex gap-2 justify-end">
                    <button type="button" @click="showTransition = false" class="{{ $btnAlt }}">Cancel</button>
                    <button type="submit" class="{{ $btnMain }}">Move</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Report a blocker --}}
    <div x-show="showHold" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-gray-900/40" @click="showHold = false"></div>
        <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-md">
            <form method="POST" action="{{ route('product-requests.hold', $request) }}">
                @csrf
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="text-base font-semibold text-gray-900">Report a blocker</h3>
                    <p class="text-sm text-gray-500 mt-0.5">Everyone on the request is told.</p>
                </div>
                <div class="px-5 py-4 space-y-2.5">
                    @foreach(\App\Models\ProductRequest::HOLD_REASONS as $reason)
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="hold_reason" value="{{ $reason }}" class="text-brand-600 focus:ring-brand-500">
                            <span class="text-sm text-gray-700">{{ $reason }}</span>
                        </label>
                    @endforeach
                    <input type="text" name="hold_reason_other" maxlength="255" placeholder="Something else…"
                           class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                </div>
                <div class="px-5 py-4 bg-gray-50 border-t border-gray-100 rounded-b-xl flex gap-2 justify-end">
                    <button type="button" @click="showHold = false" class="{{ $btnAlt }}">Cancel</button>
                    <button type="submit" class="{{ $btn }} bg-red-600 hover:bg-red-700 text-white">Put on hold</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Hand the current stage to someone else --}}
    <div x-show="showHandover" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-gray-900/40" @click="showHandover = false"></div>
        <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-md">
            <form method="POST" action="{{ route('product-requests.reassign', $request) }}">
                @csrf
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="text-base font-semibold text-gray-900">Hand over</h3>
                    <p class="text-sm text-gray-500 mt-0.5">New {{ $guide['role'] }}</p>
                </div>
                <div class="px-5 py-4">
                    <select name="user_id" required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                        <option value="">Select a person…</option>
                        @foreach($teamPool as $member)
                            @continue($guide['owner'] && $member->id === $guide['owner']->id)
                            <option value="{{ $member->id }}">{{ $member->name }}@if($member->pcr_role) — {{ $member->pcrRoleLabel() }}@endif</option>
                        @endforeach
                    </select>
                </div>
                <div class="px-5 py-4 bg-gray-50 border-t border-gray-100 rounded-b-xl flex gap-2 justify-end">
                    <button type="button" @click="showHandover = false" class="{{ $btnAlt }}">Cancel</button>
                    <button type="submit" class="{{ $btnMain }}">Hand over</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Cancel request --}}
    <div x-show="showCancel" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-gray-900/40" @click="showCancel = false"></div>
        <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-md">
            <form method="POST" action="{{ route('product-requests.cancel', $request) }}">
                @csrf
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="text-base font-semibold text-gray-900">Cancel request</h3>
                    <p class="text-sm text-gray-500 mt-0.5">This can't be undone.</p>
                </div>
                <div class="px-5 py-4">
                    <input type="text" name="cancel_reason" required maxlength="255" placeholder="Reason"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                </div>
                <div class="px-5 py-4 bg-gray-50 border-t border-gray-100 rounded-b-xl flex gap-2 justify-end">
                    <button type="button" @click="showCancel = false" class="{{ $btnAlt }}">Keep</button>
                    <button type="submit" class="{{ $btn }} bg-red-600 hover:bg-red-700 text-white">Cancel request</button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
