@extends('layouts.app')

@section('title', $request->reference)
@section('page-title', 'Product Creation')

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
        default                  => ['border-gray-200',                 'Next step'],
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

    $card    = 'bg-white rounded-xl border border-gray-200 shadow-sm';
    $btn     = 'inline-flex items-center justify-center gap-1.5 rounded-lg text-sm font-medium px-3.5 py-2 transition-colors';
    $btnMain = $btn . ' bg-brand-600 hover:bg-brand-700 text-white shadow-sm';
    $btnAlt  = $btn . ' border border-gray-300 bg-white text-gray-700 hover:bg-gray-50';
    $small   = 'inline-flex items-center gap-1.5 rounded-lg text-xs font-medium px-3 py-1.5 transition-colors';
@endphp

<div class="space-y-5"
     x-data="{
        tab: '{{ request()->has('skus') ? 'skus' : 'details' }}',
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
               class="mt-1 w-8 h-8 rounded-lg border border-gray-200 bg-white text-gray-500 hover:text-gray-800 hover:bg-gray-50 flex items-center justify-center shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="font-display text-2xl leading-tight text-gray-900 truncate">{{ $request->displayName() }}</h2>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border {{ $request->statusColor() }}">{{ $request->statusLabel() }}</span>
                    @if($request->priority === 'high')
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border {{ $request->priorityColor() }}">{{ $request->priorityLabel() }}</span>
                    @endif
                </div>
                <p class="text-sm text-gray-500 mt-0.5">
                    {{ $request->reference }} &middot; {{ $request->store?->name ?? '—' }} &middot; {{ $request->created_at->format('d M Y') }}
                </p>
            </div>
        </div>

        @unless($closed && !$me->is_super_admin)
        <div class="flex items-center gap-2">
            {{-- Rarely used actions live behind one button. --}}
            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" @click="open = !open" class="{{ $btnAlt }}">
                    More
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div x-show="open" x-cloak x-transition.origin.top.right
                     class="absolute right-0 mt-1.5 w-52 bg-white rounded-xl border border-gray-200 shadow-lg py-1.5 z-30 text-sm">
                    @unless($closed)
                        <button type="button" @click="open = false; tab = 'details'; editing = true" class="w-full text-left px-3.5 py-2 text-gray-700 hover:bg-gray-50">Edit details</button>
                        @unless($onHold)
                            <button type="button" @click="open = false; showHold = true" class="w-full text-left px-3.5 py-2 text-gray-700 hover:bg-gray-50">Report a blocker</button>
                        @endunless
                        @if($guide['field'])
                            <button type="button" @click="open = false; showHandover = true" class="w-full text-left px-3.5 py-2 text-gray-700 hover:bg-gray-50">Hand over</button>
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

            @if(!$closed && !empty($transitions))
                <button type="button" @click="showTransition = true" class="{{ $btnMain }}">
                    Move stage
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/></svg>
                </button>
            @endif
        </div>
        @endunless
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 2xl:grid-cols-4 gap-5 items-start">

        {{-- ── Main column ────────────────────────────────────────────────── --}}
        <div class="lg:col-span-2 2xl:col-span-3 space-y-5 min-w-0">

            {{-- Things that need an answer. One line each, only when relevant. --}}
            @if($request->status === \App\Models\ProductRequest::CANCELLED && $request->cancel_reason)
                <div class="rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                    <span class="font-medium">Cancelled:</span> {{ $request->cancel_reason }}
                </div>
            @endif

            @if($request->awaitingImageLocation())
                <div class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-900 flex flex-wrap items-center justify-between gap-2">
                    <span>Where are the supplier images? No folder link yet.</span>
                    <button type="button" @click="tab = 'details'; editing = true" class="{{ $small }} bg-white border border-amber-300 hover:bg-amber-100">Add link</button>
                </div>
            @endif

            {{-- Asked once, plainly. Until it is answered the request is neither
                 bound for the studio nor excused from it. --}}
            @if($request->needsPhotoshootDecision())
                <div class="rounded-xl bg-blue-50 border border-blue-200 px-4 py-3 text-sm text-blue-900 flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">Does this need a photoshoot?</span>
                    <div class="flex gap-2">
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
                </div>
            @endif

            @if($request->needsImageSourceDecision())
                <div class="rounded-xl bg-blue-50 border border-blue-200 px-4 py-3 text-sm text-blue-900 flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">Where are the images coming from?</span>
                    <div class="flex gap-2">
                        <form method="POST" action="{{ route('product-requests.image-request-decision', $request) }}">
                            @csrf
                            <input type="hidden" name="ask" value="yes">
                            <button type="submit" class="{{ $small }} bg-brand-600 hover:bg-brand-700 text-white">Request images from the brand manager</button>
                        </form>
                        <form method="POST" action="{{ route('product-requests.image-request-decision', $request) }}">
                            @csrf
                            <input type="hidden" name="ask" value="no">
                            <button type="submit" class="{{ $small }} bg-white border border-blue-300 hover:bg-blue-100">We already have them</button>
                        </form>
                    </div>
                </div>
            @endif

            {{-- Only once the request has moved on without the pictures. While it
                 sits in the photoshoot stage the next-step card already says so. --}}
            @if($request->isWaitingOnPhotoshoot() && !in_array($request->status, [
                    \App\Models\ProductRequest::WAITING_IMAGES,
                    \App\Models\ProductRequest::PHOTOSHOOT_SCHEDULED,
                ], true))
                <div class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-900 flex flex-wrap items-center justify-between gap-2">
                    <span>
                        <span class="font-medium">Waiting on the photoshoot</span>
                        ({{ strtolower(\App\Models\ProductRequest::SHOOT_STATUSES[$request->photoshoot_status] ?? 'not started') }}{{ $request->photoshoot_scheduled_at ? ', ' . $request->photoshoot_scheduled_at->format('d M, H:i') : '' }}).
                        @if($closed) This was published before the images were delivered. @endif
                    </span>
                    <a href="{{ route('product-requests.photoshoot-room') }}" class="{{ $small }} bg-white border border-amber-300 hover:bg-amber-100">Photoshoot Schedule</a>
                </div>
            @endif

            @if($needsCopy > 0)
                <div class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-900 flex flex-wrap items-center justify-between gap-2">
                    <span><span class="font-medium">Awaiting content.</span> The sheet has no description for {{ number_format($needsCopy) }} product(s).</span>
                    <div class="flex gap-2">
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
                </div>
            @elseif($unchecked > 0)
                {{-- Null is "nobody read the sheet", not "the sheet is blank".
                     Offering to generate on that risks writing over copy the
                     brand team did supply. --}}
                <div class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-900 flex flex-wrap items-center justify-between gap-2">
                    <span><span class="font-medium">Awaiting content.</span> The sheet has not been read for {{ number_format($unchecked) }} product(s).</span>
                    <form method="POST" action="{{ route('product-requests.check-sheet-copy', $request) }}">
                        @csrf
                        <button type="submit" class="{{ $small }} bg-brand-600 hover:bg-brand-700 text-white">Check the sheet</button>
                    </form>
                </div>
            @endif

            {{-- Next step + progress ──────────────────────────────────────── --}}
            <div class="bg-white rounded-xl border shadow-sm {{ $panelTone }}">
                <div class="px-5 py-4 flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-medium uppercase tracking-wide {{ $onHold && !$closed ? 'text-red-600' : ($ownership === 'mine' ? 'text-brand-700' : 'text-gray-400') }}">{{ $heading }}</p>

                        @if($onHold && !$closed)
                            <p class="text-base font-semibold text-red-900 mt-1">{{ $request->hold_reason }}</p>
                            <p class="text-xs text-red-700 mt-0.5">
                                By {{ $request->holdSetter?->name ?? 'someone' }}
                                {{ $request->hold_since ? $request->hold_since->diffForHumans() : '' }}@if($held) &middot; {{ $held }}d blocked @endif
                            </p>
                        @elseif(!$closed)
                            <p class="text-base font-semibold text-gray-900 mt-1">{{ $guide['what'] }}</p>
                        @endif

                        @unless($closed)
                            <div class="flex flex-wrap items-center gap-2 mt-2.5 text-xs">
                                <span class="inline-flex items-center gap-1.5 text-gray-600">
                                    <span class="w-5 h-5 rounded-full bg-gray-100 text-gray-500 text-[10px] font-semibold flex items-center justify-center">
                                        {{ $guide['owner'] ? strtoupper(substr($guide['owner']->name, 0, 1)) : '?' }}
                                    </span>
                                    @if($ownership === 'mine')
                                        You
                                    @elseif($guide['owner'])
                                        {{ $guide['owner']->name }}
                                    @else
                                        <span class="text-amber-700 font-medium">Nobody yet</span>
                                    @endif
                                    <span class="text-gray-400">&middot; {{ $guide['role'] ?? 'Team' }}</span>
                                </span>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full font-medium {{ $dueTone }}">{{ $dueText }}</span>
                                @if($request->photoshoot_scheduled_at && $request->isWaitingOnPhotoshoot())
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full font-medium bg-blue-50 text-blue-700">Shoot {{ $request->photoshoot_scheduled_at->format('d M, H:i') }}</span>
                                @endif
                            </div>
                        @endunless
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
                            @if(!empty($transitions))
                                <button type="button" @click="showTransition = true" class="{{ $btnMain }}">Move to next stage</button>
                            @elseif($request->isBlockedOnMapping())
                                <button type="button" @click="tab = 'skus'; $nextTick(() => document.getElementById('tabs').scrollIntoView({ behavior: 'smooth' }))" class="{{ $btnAlt }}">View SKUs</button>
                            @endif
                        </div>
                    @endunless
                </div>

                {{-- Progress, three phases. Hover a phase to see its steps. --}}
                @php $currentStep = $request->displayStageIndex(); @endphp
                <div class="px-5 pb-4 pt-1 flex gap-2">
                    @foreach($request->phaseProgress() as $phase)
                        @php
                            $count = count($phase['stages']);
                            $fill  = match ($phase['state']) {
                                'done'    => 100,
                                'current' => (int) round(100 * max(0.5, $currentStep - $phase['start'] + 0.5) / $count),
                                default   => 0,
                            };
                            $steps = collect($phase['stages'])->map(fn ($s) => $request->stageLabel($s))->implode(' → ');
                        @endphp
                        <div class="flex-1 min-w-0" title="{{ $steps }}">
                            <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden">
                                <div class="h-full rounded-full {{ $phase['state'] === 'done' ? 'bg-green-500' : 'bg-brand-600' }}" style="width: {{ $fill }}%"></div>
                            </div>
                            <p class="text-xs mt-1.5 truncate {{ $phase['state'] === 'current' ? 'text-gray-900 font-medium' : ($phase['state'] === 'done' ? 'text-gray-600' : 'text-gray-400') }}">
                                {{ $phase['label'] }}
                                @if($phase['state'] === 'done')
                                    <span class="text-green-600">&check;</span>
                                @endif
                            </p>
                            @if($phase['state'] === 'current')
                                <p class="text-[11px] text-brand-700 truncate">{{ $request->statusLabel() }}</p>
                            @endif
                        </div>
                    @endforeach
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
                        $stats = $usesMapping
                            ? [
                                ['Total',       $request->total_skus,      'text-gray-900'],
                                ['Mapped',      $request->mapped_skus,     'text-green-700'],
                                ['Pending',     $request->pending_skus,    'text-amber-600'],
                                ['Not mapped',  $request->not_mapped_skus, 'text-red-600'],
                            ]
                            : [
                                ['Total',            $request->total_skus,              'text-gray-900'],
                                ['In Shopify',       $inShopify,                        'text-green-700'],
                                ['Not in Shopify',   $request->total_skus - $inShopify, 'text-gray-500'],
                            ];
                    @endphp
                    <div class="grid {{ $usesMapping ? 'grid-cols-4' : 'grid-cols-3' }} divide-x divide-gray-100 rounded-lg border border-gray-100 bg-gray-50/50">
                        @foreach($stats as [$label, $value, $tone])
                            <div class="px-3 py-2.5">
                                <p class="text-[11px] text-gray-500">{{ $label }}</p>
                                <p class="text-xl font-semibold tabular-nums {{ $tone }}">{{ number_format($value) }}</p>
                            </div>
                        @endforeach
                    </div>

                    @if($usesMapping && $request->total_skus > 0)
                        <div class="flex items-center gap-3">
                            <div class="flex-1 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                <div class="h-full rounded-full {{ $request->hasSkuBalance() ? 'bg-amber-500' : 'bg-green-500' }}" style="width: {{ $request->skuCompletionPercent() }}%"></div>
                            </div>
                            <span class="text-xs font-medium tabular-nums {{ $request->hasSkuBalance() ? 'text-amber-700' : 'text-green-700' }}">{{ $request->skuCompletionPercent() }}% mapped</span>
                        </div>

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

                    {{-- Copy for the SKUs that have none — regenerating over copy that
                         is already written is worse than doing nothing. --}}
                    @if($request->canOfferContentForMissing())
                        @php
                            $blank   = $request->needsContentCount();
                            $handled = $request->contentHandledCount();
                        @endphp
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2.5">
                            <span class="text-sm text-gray-700">{{ number_format($blank) }} of {{ number_format($blank + $handled) }} live SKUs have no description</span>
                            <div class="flex gap-2">
                                <form method="POST" action="{{ route('product-requests.ai-content', $request) }}">
                                    @csrf
                                    <input type="hidden" name="scope" value="missing_description">
                                    <input type="hidden" name="answer" value="generate">
                                    <button type="submit" class="{{ $small }} bg-brand-600 hover:bg-brand-700 text-white">Generate AI content for these {{ number_format($blank) }}</button>
                                </form>
                                <form method="POST" action="{{ route('product-requests.ai-content', $request) }}">
                                    @csrf
                                    <input type="hidden" name="scope" value="missing_description">
                                    <input type="hidden" name="answer" value="skip">
                                    <button type="submit" class="{{ $small }} border border-gray-300 text-gray-700 hover:bg-gray-50">Leave as is</button>
                                </form>
                            </div>
                        </div>
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
                        'details'     => 'Details',
                        'skus'        => 'SKUs (' . $request->total_skus . ')',
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

                {{-- Tab: details --}}
                <div x-show="tab === 'details'" class="px-5 py-5">
                    <form method="POST" action="{{ route('product-requests.update', $request) }}">
                        @csrf
                        @method('PUT')

                        @php $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500'; @endphp

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                            @foreach([
                                ['name', 'Request name', 'text', false],
                                ['brand', 'Brand', 'text', true],
                            ] as [$field, $label, $type, $required])
                                <div>
                                    <label class="block text-xs text-gray-500 mb-1">{{ $label }}</label>
                                    <template x-if="!editing">
                                        <p class="text-sm text-gray-900">{{ $request->{$field} ?: '—' }}</p>
                                    </template>
                                    <input x-show="editing" x-cloak type="{{ $type }}" name="{{ $field }}"
                                           value="{{ old($field, $request->{$field}) }}" {{ $required ? 'required' : '' }} class="{{ $input }}">
                                </div>
                            @endforeach

                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Category</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-900">{{ $request->category ?: '—' }}</p>
                                </template>
                                <select x-show="editing" x-cloak name="category" required class="{{ $input }}">
                                    @foreach($request->categoryOptions() as $category)
                                        <option value="{{ $category }}" {{ old('category', $request->category) === $category ? 'selected' : '' }}>{{ $category }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Priority</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-900">{{ $request->priorityLabel() }}</p>
                                </template>
                                <select x-show="editing" x-cloak name="priority" class="{{ $input }}">
                                    @foreach(\App\Models\ProductRequest::PRIORITIES as $value => $label)
                                        <option value="{{ $value }}" @selected($request->priority === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Website Go-Live Date</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-900">{{ $request->launchLabel() ?? '—' }}</p>
                                </template>
                                <input x-show="editing" x-cloak type="datetime-local" name="online_launch_date" required
                                       value="{{ old('online_launch_date', $request->online_launch_date?->format('Y-m-d\TH:i')) }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Expected showroom launch</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-900">{{ $request->store_launch_date?->format('d M Y') ?? '—' }}</p>
                                </template>
                                <input x-show="editing" x-cloak type="date" name="store_launch_date"
                                       value="{{ old('store_launch_date', $request->store_launch_date?->format('Y-m-d')) }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Images</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-900">{{ $request->imageSourceLabel() }}</p>
                                </template>
                                <select x-show="editing" x-cloak name="image_source" class="{{ $input }}">
                                    @foreach($request->imageSourceOptions() as $value => $meta)
                                        <option value="{{ $value }}" @selected($request->image_source === $value)>{{ $meta['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Where the supplier images actually are. --}}
                            <div @class(['hidden' => !$request->needsImageLocation()])>
                                <label class="block text-xs text-gray-500 mb-1">Images location</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-900 break-all">
                                        @if($request->imagesInPim())
                                            Already in the Brand PIM
                                        @elseif($request->images_url)
                                            <a href="{{ $request->images_url }}" target="_blank" rel="noopener" class="text-brand-600 hover:text-brand-700 underline">{{ $request->images_url }}</a>
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

                            <div @class(['hidden' => !$request->needsPhotoshoot() && !$request->photoshoot_scheduled_at])>
                                <label class="block text-xs text-gray-500 mb-1">Photoshoot</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-900">
                                        {{ $request->photoshoot_scheduled_at?->format('d M Y, H:i') ?? '—' }}
                                        @if($request->photoshoot_status)
                                            <a href="{{ route('product-requests.photoshoot-room') }}"
                                               class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border {{ $request->shootStatusColor() }}">{{ $request->shootStatusLabel() }}</a>
                                        @endif
                                    </p>
                                </template>
                                <input x-show="editing" x-cloak type="datetime-local" name="photoshoot_scheduled_at"
                                       value="{{ old('photoshoot_scheduled_at', $request->photoshoot_scheduled_at?->format('Y-m-d\TH:i')) }}" class="{{ $input }}">
                            </div>

                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Descriptions</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-900">{{ $request->use_ai_content ? 'Written with AI' : 'From brand team' }}</p>
                                </template>
                                <select x-show="editing" x-cloak name="use_ai_content" class="{{ $input }}">
                                    <option value="1" @selected($request->use_ai_content)>Written with AI</option>
                                    <option value="0" @selected(!$request->use_ai_content)>From brand team</option>
                                </select>
                            </div>

                            <div class="md:col-span-2">
                                <label class="block text-xs text-gray-500 mb-1">Notes</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-900 whitespace-pre-line">{{ $request->notes ?: '—' }}</p>
                                </template>
                                <textarea x-show="editing" x-cloak name="notes" rows="3" class="{{ $input }} resize-y">{{ old('notes', $request->notes) }}</textarea>
                            </div>
                        </div>

                        <div class="flex gap-2 mt-5 pt-4 border-t border-gray-100">
                            @unless($closed)
                                <button type="button" x-show="!editing" @click="editing = true" class="{{ $small }} border border-gray-300 text-gray-700 hover:bg-gray-50">Edit</button>
                            @endunless
                            <button type="submit" x-show="editing" x-cloak class="{{ $btnMain }}">Save</button>
                            <button type="button" x-show="editing" x-cloak @click="editing = false" class="{{ $btnAlt }}">Cancel</button>
                        </div>
                    </form>
                </div>

                {{-- Tab: SKUs --}}
                <div x-show="tab === 'skus'" x-cloak class="px-5 py-5">
                    @unless($closed)
                    <form method="POST" action="{{ route('product-requests.skus.add', $request) }}" enctype="multipart/form-data"
                          x-data="{ open: {{ $errors->has('sku_csv') || $errors->has('skus') ? 'true' : 'false' }}, csvError: @js($errors->first('sku_csv') ?: null) }" class="mb-4">
                        @csrf
                        <button type="button" x-show="!open" @click="open = true" class="{{ $small }} border border-gray-300 text-gray-700 hover:bg-gray-50">+ Add SKUs</button>
                        <div x-show="open" x-cloak class="rounded-lg border border-gray-200 p-3 space-y-2">
                            <textarea name="skus" rows="2" placeholder="Type SKUs, one per line"
                                      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 resize-y"></textarea>
                            <div class="flex flex-wrap items-center gap-2">
                                <input type="file" name="sku_csv" accept=".csv,.txt"
                                       @change="csvError = await window.checkSkuCsv($el.files[0]); if (csvError) $el.value = ''"
                                       class="flex-1 text-xs text-gray-600 file:mr-2 file:py-1.5 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-gray-100 file:text-gray-700 hover:file:bg-gray-200 cursor-pointer">
                                <button type="submit" class="{{ $small }} bg-brand-600 hover:bg-brand-700 text-white">Add</button>
                                <button type="button" @click="open = false" class="{{ $small }} text-gray-500 hover:text-gray-700">Cancel</button>
                            </div>
                            <p class="text-xs text-gray-400">CSV needs a "SKU" or "Item SKU" column.</p>
                            <p x-show="csvError" x-cloak x-text="csvError" class="text-xs text-red-600"></p>
                        </div>
                    </form>
                    @include('product-requests.partials.sku-csv-check')
                    @endunless

                    @if($skus->isEmpty())
                        <p class="py-10 text-sm text-gray-400 text-center">No SKUs yet.</p>
                    @else
                    <div class="overflow-x-auto -mx-5">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 bg-gray-50 border-y border-gray-100">
                                    <th class="py-2 px-5 font-medium">SKU</th>
                                    @if($usesMapping)
                                    <th class="py-2 pr-3 font-medium">Status</th>
                                    @endif
                                    <th class="py-2 pr-3 font-medium">Product</th>
                                    <th class="py-2 pr-5 font-medium text-right">In Shopify</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                @foreach($skus as $sku)
                                <tr class="hover:bg-gray-50/70">
                                    <td class="py-2.5 px-5 font-mono text-xs text-gray-800">{{ $sku->sku }}</td>
                                    @if($usesMapping)
                                    <td class="py-2.5 pr-3">
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium border {{ $sku->color() }}">
                                            <span class="w-1.5 h-1.5 rounded-full {{ $sku->dot() }}"></span>
                                            {{ $sku->label() }}
                                        </span>
                                    </td>
                                    @endif
                                    <td class="py-2.5 pr-3 text-xs text-gray-600 max-w-xs truncate">{{ $sku->shopify_product_title ?: '—' }}</td>
                                    <td class="py-2.5 pr-5 text-right" title="Last checked {{ $sku->last_checked_at?->format('d M, h:i A') ?? 'never' }}">
                                        @if($sku->in_shopify)
                                            <span class="text-green-600">&check;</span>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if($skus->hasPages())
                        <div class="mt-4">{{ $skus->links() }}</div>
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

            <div class="{{ $card }}">
                <div class="px-5 py-3.5">
                    <h3 class="text-sm font-semibold text-gray-900">Details</h3>
                </div>
                <dl class="px-5 pb-4 space-y-3 text-sm">
                    @foreach(array_filter([
                        'Website'      => $request->store?->name ?? '—',
                        'Brand'        => $request->brand,
                        'Category'     => $request->category,
                        'Website go-live' => $request->launchLabel('d M Y') ?? '—',
                        'Showroom'     => $request->store_launch_date?->format('d M Y'),
                        'Requested by' => $request->requesterName(),
                        'Sheet'        => $request->sheetLabel(),
                        'Published'    => $request->published_at?->format('d M Y'),
                    ]) as $label => $value)
                        <div>
                            <dt class="text-xs text-gray-500">{{ $label }}</dt>
                            <dd class="text-gray-900 truncate">{{ $value }}</dd>
                        </div>
                    @endforeach
                    @if($request->needsImageLocation() && ($request->images_url || $request->imagesInPim()))
                        <div>
                            <dt class="text-xs text-gray-500">Supplier images</dt>
                            <dd class="truncate">
                                @if($request->imagesInPim())
                                    In the Brand PIM
                                @else
                                    <a href="{{ $request->images_url }}" target="_blank" rel="noopener" class="text-brand-600 hover:text-brand-700 underline">{{ $request->images_url }}</a>
                                @endif
                            </dd>
                        </div>
                    @endif
                </dl>
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
