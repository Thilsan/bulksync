@extends('layouts.app')

@section('title', $request->reference)
@section('page-title', 'Product Creation Request')

@section('content')
@php
    // Stages this request actually passes through — websites without Cegid
    // mapping never show "Waiting for Mapping".
    $pipeline    = $request->displayStages();
    $labels      = \App\Models\ProductRequest::STATUS_LABELS;
    $currentStep = $request->displayStageIndex();
    $transitions = $request->allowedTransitions();
    $usesMapping = $request->requiresMapping();
@endphp

<div class="space-y-5"
     x-data="{
        tab: 'details',
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

    {{-- Breadcrumb + actions --}}
    <div class="flex items-start justify-between">
        <nav class="text-xs text-gray-400">
            <a href="{{ route('dashboard') }}" class="hover:text-gray-600">Home</a>
            <span class="mx-1.5">&gt;</span>
            <a href="{{ route('product-requests.index') }}" class="hover:text-gray-600">Product Creation Request</a>
            <span class="mx-1.5">&gt;</span>
            <span class="text-gray-600">{{ $request->reference }}</span>
        </nav>

        <div class="flex items-center gap-2">
            <a href="{{ route('product-requests.list') }}"
               class="inline-flex items-center gap-2 border border-gray-300 text-gray-700 text-sm font-medium px-3.5 py-1.5 rounded-lg hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Requests
            </a>

            @unless($request->isClosed())
                <button type="button" @click="editing = !editing"
                        class="inline-flex items-center gap-2 border border-gray-300 text-gray-700 text-sm font-medium px-3.5 py-1.5 rounded-lg hover:bg-gray-50 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    <span x-text="editing ? 'Cancel Edit' : 'Edit Request'"></span>
                </button>

                @if(!empty($transitions))
                <button type="button" @click="showTransition = true"
                        class="inline-flex items-center gap-2 text-white text-sm font-medium px-3.5 py-1.5 rounded-lg transition-colors"
                        style="background-color:#1d5a74" onmouseover="this.style.backgroundColor='#164659'" onmouseout="this.style.backgroundColor='#1d5a74'">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/>
                    </svg>
                    Move Stage
                </button>
                @endif
            @endunless
        </div>
    </div>

    {{-- Header card + workflow progress --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-6 py-5 grid grid-cols-1 xl:grid-cols-2 gap-6">

            <div>
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-xl bg-brand-50 text-brand-600 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2.5">
                            <h2 class="text-xl font-semibold text-gray-900">{{ $request->reference }}</h2>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border {{ $request->statusColor() }}">
                                {{ $request->statusLabel() }}
                            </span>
                        </div>
                        <p class="text-sm text-gray-500 mt-0.5">
                            Request created on {{ $request->created_at->format('d M Y, h:i A') }} by {{ $request->user?->name ?? 'Unknown' }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-3 sm:grid-cols-6 gap-4 mt-5">
                    @foreach([
                        'Website'    => $request->store?->name ?? '—',
                        'Brand'      => $request->brand,
                        'Category'   => $request->category,
                        'Department' => $request->department ?: '—',
                        'Collection' => $request->collection ?: '—',
                    ] as $label => $value)
                        <div>
                            <p class="text-xs text-gray-400">{{ $label }}</p>
                            <p class="text-sm font-medium text-gray-800 truncate">{{ $value }}</p>
                        </div>
                    @endforeach
                    <div>
                        <p class="text-xs text-gray-400">Priority</p>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border mt-0.5 {{ $request->priorityColor() }}">
                            {{ $request->priorityLabel() }}
                        </span>
                    </div>
                </div>

                @if($request->status === \App\Models\ProductRequest::CANCELLED && $request->cancel_reason)
                    <div class="mt-4 bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-2.5 text-sm">
                        <span class="font-medium">Cancelled:</span> {{ $request->cancel_reason }}
                    </div>
                @endif

                @if($request->isOverdue())
                    <div class="mt-4 bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-2.5 text-sm">
                        Online launch date has passed and the request is not published yet.
                    </div>
                @endif

                @if($request->awaitingContentSheet())
                    <div class="mt-4 bg-amber-50 border border-amber-200 text-amber-900 rounded-lg px-4 py-2.5 text-sm">
                        <span class="font-medium">Awaiting content sheet.</span>
                        This request is not using the AI Content Generator — the brand team needs to upload the copy as an Excel or CSV file.
                        <button type="button" @click="tab = 'attachments'" class="underline font-medium">Upload it now</button>
                    </div>
                @endif

                @if($request->store_launch_date && $request->online_launch_date && $request->online_launch_date->lt($request->store_launch_date))
                    <div class="mt-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-4 py-2.5 text-sm">
                        Online launch is scheduled before the store launch date.
                    </div>
                @endif
            </div>

            {{-- Workflow progress, grouped into phases --}}
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-800">Workflow Progress</h3>
                    <div class="flex items-center gap-2 w-40">
                        <div class="flex-1 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full bg-brand-600 rounded-full transition-all" style="width: {{ $request->progressPercent() }}%"></div>
                        </div>
                        <span class="text-xs text-gray-500 tabular-nums shrink-0">{{ $request->progressPercent() }}%</span>
                    </div>
                </div>

                <div class="space-y-2">
                    @foreach($request->phaseProgress() as $phase)
                        @php
                            $tone = match ($phase['state']) {
                                'done'    => ['border-green-200 bg-green-50/50', 'bg-green-500 text-white',  'text-green-700',  'Done'],
                                'current' => ['border-blue-200 bg-blue-50/50',   'bg-blue-600 text-white',   'text-blue-700',   'In Progress'],
                                default   => ['border-gray-200 bg-white',        'bg-gray-200 text-gray-500','text-gray-400',   'Pending'],
                            };
                        @endphp
                        <div class="rounded-lg border px-3 py-2.5 {{ $tone[0] }}">
                            <div class="flex items-center justify-between gap-2 mb-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="w-5 h-5 rounded-full flex items-center justify-center shrink-0 text-[10px] font-bold {{ $tone[1] }}">
                                        @if($phase['state'] === 'done')
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        @else
                                            {{ $loop->iteration }}
                                        @endif
                                    </span>
                                    <span class="text-xs font-semibold text-gray-800 truncate">{{ $phase['label'] }}</span>
                                </div>
                                <span class="text-[10px] font-medium shrink-0 {{ $tone[2] }}">{{ $tone[3] }}</span>
                            </div>

                            {{-- Steps within the phase --}}
                            <div class="flex flex-wrap items-center gap-x-1.5 gap-y-1 pl-7">
                                @foreach($phase['stages'] as $k => $stage)
                                    @php
                                        $globalIndex = $phase['start'] + $k;
                                        $stepDone    = $currentStep > $globalIndex;
                                        $stepCurrent = $currentStep === $globalIndex;
                                    @endphp
                                    <span class="inline-flex items-center gap-1.5" title="{{ $request->guideFor($stage)['what'] }}">
                                        <span class="w-1.5 h-1.5 rounded-full shrink-0
                                            {{ $stepDone ? 'bg-green-500' : ($stepCurrent ? 'bg-blue-600 ring-2 ring-blue-200' : 'bg-gray-300') }}"></span>
                                        <span class="text-[11px] leading-tight
                                            {{ $stepCurrent ? 'text-gray-900 font-semibold' : ($stepDone ? 'text-gray-600' : 'text-gray-400') }}">
                                            {{ $request->stageLabel($stage) }}
                                        </span>
                                    </span>
                                    @unless($loop->last)
                                        <span class="text-gray-300 text-[10px]">&rsaquo;</span>
                                    @endunless
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- What has to happen next, and whose job it is. --}}
    @php
        $me        = auth()->user();
        $guide     = $request->currentGuide();
        $ownership = $request->ownershipFor($me);   // mine | my_team | other | none
        $claimable = $request->claimableBy($me);
        $closed    = $request->isClosed();

        $onHold = $request->isOnHold();
        $held   = $request->heldForDays();

        // One colour per state. Being blocked outranks whose task it is —
        // nothing can move until the blocker is cleared.
        [$panelBg, $iconBg, $heading] = match (true) {
            $closed                  => ['bg-gray-50 border-gray-200',    'bg-gray-200 text-gray-500',   'This request is closed'],
            $onHold                  => ['bg-red-50 border-red-300',      'bg-red-600 text-white',       'On hold — work is blocked'],
            $ownership === 'mine'    => ['bg-brand-50 border-brand-300',  'bg-brand-600 text-white',     'This is your task'],
            $ownership === 'my_team' => ['bg-amber-50 border-amber-300',  'bg-amber-500 text-white',     'Waiting on your team'],
            default                  => ['bg-white border-gray-200',      'bg-gray-100 text-gray-500',   'What needs to happen next'],
        };
    @endphp
    <div class="rounded-xl border shadow-sm px-5 py-4 {{ $panelBg }}">
        <div class="flex items-start gap-4">
            <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 {{ $iconBg }}">
                <svg class="w-4.5 h-4.5" style="width:1.125rem;height:1.125rem" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="{{ $onHold && !$closed
                            ? 'M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z'
                            : ($closed
                            ? 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'
                            : ($ownership === 'mine' || $ownership === 'my_team'
                                ? 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4'
                                : 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z')) }}"/>
                </svg>
            </div>

            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-sm font-semibold text-gray-900">{{ $heading }}</h3>

                    @if($onHold && !$closed)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wide bg-red-600 text-white">
                            Blocked{{ $held !== null && $held > 0 ? " · {$held}d" : '' }}
                        </span>
                    @elseif($ownership === 'mine')
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wide bg-brand-600 text-white">
                            Assigned to you
                        </span>
                    @elseif($ownership === 'my_team')
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wide bg-amber-500 text-white">
                            Unclaimed
                        </span>
                    @endif
                </div>

                @if($onHold && !$closed)
                    <p class="text-sm text-red-900 mt-1">
                        <span class="font-semibold">{{ $request->hold_reason }}</span>
                    </p>
                    <p class="text-xs text-red-700 mt-0.5">
                        Flagged by {{ $request->holdSetter?->name ?? 'someone' }}
                        {{ $request->hold_since ? $request->hold_since->diffForHumans() : '' }}.
                        Nothing moves until this is resolved.
                    </p>
                    <p class="text-xs text-gray-600 mt-2">
                        When unblocked: {{ $guide['what'] }}
                    </p>
                @else
                    <p class="text-sm text-gray-700 mt-1">{{ $guide['what'] }}</p>
                @endif

                @unless($closed)
                    {{-- Say plainly whose court the ball is in. --}}
                    <p class="text-xs text-gray-600 mt-2">
                        @if($ownership === 'mine')
                            <span class="font-medium">You</span> are the {{ $guide['role'] }} on this request.
                        @elseif($ownership === 'my_team')
                            This stage belongs to the <span class="font-medium">{{ $guide['role'] }}</span> — your team — but
                            <span class="font-medium text-amber-700">nobody has taken it yet</span>.
                        @elseif($guide['owner'])
                            Waiting on <span class="font-medium">{{ $guide['owner']->name }}</span> ({{ $guide['role'] }}).
                        @else
                            Waiting on the <span class="font-medium">{{ $guide['role'] ?? 'team' }}</span> —
                            <span class="font-medium text-amber-700">nobody assigned yet</span>.
                        @endif
                        <span class="mx-1 text-gray-300">|</span>
                        Current stage: <span class="font-medium">{{ $request->statusLabel() }}</span>
                    </p>
                @endunless
            </div>

            @unless($closed)
            <div class="shrink-0 flex flex-col gap-2 self-center">
                @if($onHold)
                    <form method="POST" action="{{ route('product-requests.resume', $request) }}">
                        @csrf
                        <button type="submit"
                                class="w-full inline-flex items-center justify-center gap-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Unblock &amp; resume
                        </button>
                    </form>
                @endif

                @if($claimable)
                    <form method="POST" action="{{ route('product-requests.claim', $request) }}">
                        @csrf
                        <button type="submit"
                                class="w-full inline-flex items-center justify-center gap-2 text-sm font-medium px-4 py-2 rounded-lg transition-colors
                                       {{ $ownership === 'my_team' ? 'bg-amber-500 hover:bg-amber-600 text-white' : 'border border-gray-300 text-gray-700 hover:bg-gray-50' }}">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Take this task
                        </button>
                    </form>
                @endif

                @if(!empty($transitions))
                <button type="button" @click="showTransition = true"
                        class="inline-flex items-center justify-center gap-2 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors"
                        style="background-color:#1d5a74" onmouseover="this.style.backgroundColor='#164659'" onmouseout="this.style.backgroundColor='#1d5a74'">
                    Move to next stage
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/>
                    </svg>
                </button>
                @elseif($request->isBlockedOnMapping())
                <button type="button" @click="tab = 'skus'"
                        class="inline-flex items-center justify-center gap-2 border border-amber-300 bg-white text-amber-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-amber-50 transition-colors">
                    Go to SKUs
                </button>
                @endif

                {{-- Secondary actions: report a blocker, or pass the task on. --}}
                <div class="flex gap-2">
                    @unless($onHold)
                        <button type="button" @click="showHold = true"
                                class="flex-1 text-xs font-medium px-3 py-1.5 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors whitespace-nowrap">
                            Report a blocker
                        </button>
                    @endunless
                    @if($guide['field'])
                        <button type="button" @click="showHandover = true"
                                class="flex-1 text-xs font-medium px-3 py-1.5 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors whitespace-nowrap">
                            Hand over
                        </button>
                    @endif
                </div>
            </div>
            @endunless
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

        {{-- Left / middle: SKU + validation --}}
        <div class="xl:col-span-2 space-y-5">

            {{-- Validation results --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
                <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-800">Validation Results</h2>
                        <p class="text-xs text-gray-400 mt-0.5">
                            @if($request->validated_at)
                                Last checked {{ $request->validated_at->format('d M Y, h:i A') }}
                            @else
                                Not validated yet
                            @endif
                            @if($usesMapping)
                                &middot; <span class="text-gray-500">Supply Chain records mapping on the SKUs tab</span>
                            @else
                                &middot; <span class="text-gray-500">{{ $request->store?->name }} has no Cegid mapping step</span>
                            @endif
                        </p>
                    </div>
                    <form method="POST" action="{{ route('product-requests.revalidate', $request) }}">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center gap-1.5 bg-brand-600 hover:bg-brand-700 text-white text-xs font-medium px-3 py-1.5 rounded-lg transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            Validate SKUs
                        </button>
                    </form>
                </div>

                <div class="px-5 py-4">
                    <div x-show="validating" class="mb-4 flex items-center gap-2.5 bg-blue-50 border border-blue-200 text-blue-800 rounded-lg px-4 py-2.5 text-sm">
                        <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Validation in progress — this page refreshes automatically when it finishes.
                    </div>

                    @if($request->validation_status === 'failed')
                        <div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-2.5 text-sm">
                            Validation failed: {{ $request->validation_error }}
                        </div>
                    @endif

                    @php $inShopify = $request->skus()->where('in_shopify', true)->count(); @endphp

                    <div class="grid grid-cols-2 {{ $usesMapping ? 'md:grid-cols-4' : 'md:grid-cols-3' }} gap-3">
                        <div class="rounded-lg border border-gray-200 px-4 py-3">
                            <p class="text-xs text-gray-500">Total SKUs</p>
                            <p class="text-2xl font-semibold text-gray-900">{{ number_format($request->total_skus) }}</p>
                        </div>

                        @if($usesMapping)
                        <div class="rounded-lg border border-green-200 bg-green-50/60 px-4 py-3">
                            <p class="text-xs text-green-700 flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-green-500"></span> Mapped</p>
                            <p class="text-2xl font-semibold text-green-800">{{ number_format($request->mapped_skus) }}</p>
                        </div>
                        <div class="rounded-lg border border-amber-200 bg-amber-50/60 px-4 py-3">
                            <p class="text-xs text-amber-700 flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-500"></span> Pending Mapping</p>
                            <p class="text-2xl font-semibold text-amber-800">{{ number_format($request->pending_skus) }}</p>
                        </div>
                        <div class="rounded-lg border border-red-200 bg-red-50/60 px-4 py-3">
                            <p class="text-xs text-red-700 flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-red-500"></span> Not Mapped</p>
                            <p class="text-2xl font-semibold text-red-800">{{ number_format($request->not_mapped_skus) }}</p>
                        </div>
                        @else
                        {{-- No mapping step for this website — show what the SKU Checker found instead. --}}
                        <div class="rounded-lg border border-teal-200 bg-teal-50/60 px-4 py-3">
                            <p class="text-xs text-teal-700">Already in Shopify</p>
                            <p class="text-2xl font-semibold text-teal-800">{{ number_format($inShopify) }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 px-4 py-3">
                            <p class="text-xs text-gray-500">Not yet in Shopify</p>
                            <p class="text-2xl font-semibold text-gray-700">{{ number_format($request->total_skus - $inShopify) }}</p>
                        </div>
                        @endif
                    </div>

                    <div class="flex flex-wrap gap-2 mt-4">
                        @php
                            $downloads = ['all' => 'Download All (' . $request->total_skus . ')'];
                            if ($usesMapping) {
                                $downloads[\App\Models\ProductRequest::MAP_MAPPED]     = 'Download Mapped (' . $request->mapped_skus . ')';
                                $downloads[\App\Models\ProductRequest::MAP_PENDING]    = 'Download Pending (' . $request->pending_skus . ')';
                                $downloads[\App\Models\ProductRequest::MAP_NOT_MAPPED] = 'Download Not Mapped (' . $request->not_mapped_skus . ')';
                            }
                        @endphp
                        @foreach($downloads as $filter => $label)
                            <a href="{{ route('product-requests.skus.download', [$request, 'filter' => $filter]) }}"
                               class="inline-flex items-center gap-1.5 border border-gray-300 text-gray-700 text-xs font-medium px-3 py-1.5 rounded-lg hover:bg-gray-50 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>

                    @if($request->isBlockedOnMapping())
                        <div class="mt-4 bg-amber-50 border border-amber-200 text-amber-900 rounded-lg px-4 py-3 text-sm">
                            <p class="font-medium">Waiting for Supply Chain to complete mapping.</p>
                            <p class="text-xs mt-0.5">
                                The request stays here until every SKU is mapped. Once mapping is done it moves to
                                <span class="font-medium">SKU Verified</span> automatically — no re-submission needed.
                            </p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Tabs --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
                <div class="px-5 border-b border-gray-100 flex gap-1">
                    @foreach([
                        'details'     => 'Request Details',
                        'skus'        => 'SKUs (' . $request->total_skus . ')',
                        'attachments' => 'Attachments (' . $request->attachments()->count() . ')',
                        'comments'    => 'Comments',
                    ] as $key => $label)
                        <button type="button" @click="tab = '{{ $key }}'"
                                :class="tab === '{{ $key }}' ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                class="px-3.5 py-3 text-sm font-medium border-b-2 transition-colors">{{ $label }}</button>
                    @endforeach
                </div>

                {{-- Tab: details --}}
                <div x-show="tab === 'details'" class="px-5 py-5">
                    <form method="POST" action="{{ route('product-requests.update', $request) }}">
                        @csrf
                        @method('PUT')

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                            @foreach([
                                ['brand', 'Brand', 'text', true],
                                ['category', 'Category', 'text', true],
                                ['sub_category', 'Sub Category', 'text', false],
                                ['department', 'Department', 'text', false],
                                ['collection', 'Collection / Season', 'text', false],
                            ] as [$field, $label, $type, $required])
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">{{ $label }}</label>
                                    <template x-if="!editing">
                                        <p class="text-sm text-gray-800 py-2">{{ $request->{$field} ?: '—' }}</p>
                                    </template>
                                    <input x-show="editing" x-cloak type="{{ $type }}" name="{{ $field }}"
                                           value="{{ old($field, $request->{$field}) }}" {{ $required ? 'required' : '' }}
                                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                                </div>
                            @endforeach

                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Priority</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-800 py-2">{{ $request->priorityLabel() }}</p>
                                </template>
                                <select x-show="editing" x-cloak name="priority"
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                                    @foreach(\App\Models\ProductRequest::PRIORITIES as $value => $label)
                                        <option value="{{ $value }}" @selected($request->priority === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Expected Store Launch Date</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-800 py-2">{{ $request->store_launch_date?->format('d M Y') ?? '—' }}</p>
                                </template>
                                <input x-show="editing" x-cloak type="date" name="store_launch_date" required
                                       value="{{ old('store_launch_date', $request->store_launch_date?->format('Y-m-d')) }}"
                                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Expected Online Launch Date</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-800 py-2">{{ $request->online_launch_date?->format('d M Y') ?? '—' }}</p>
                                </template>
                                <input x-show="editing" x-cloak type="date" name="online_launch_date" required
                                       value="{{ old('online_launch_date', $request->online_launch_date?->format('Y-m-d')) }}"
                                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Supplier Images Available?</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-800 py-2">{{ $request->supplier_images_available ? 'Yes, provided by supplier' : 'No, require photoshoot' }}</p>
                                </template>
                                <select x-show="editing" x-cloak name="supplier_images_available"
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                                    <option value="1" @selected($request->supplier_images_available)>Yes, provided by supplier</option>
                                    <option value="0" @selected(!$request->supplier_images_available)>No, require photoshoot</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Photoshoot Required?</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-800 py-2">{{ $request->photoshoot_required ? 'Yes' : 'No' }}</p>
                                </template>
                                <select x-show="editing" x-cloak name="photoshoot_required"
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                                    <option value="1" @selected($request->photoshoot_required)>Yes</option>
                                    <option value="0" @selected(!$request->photoshoot_required)>No</option>
                                </select>
                            </div>

                            <div @class(['hidden' => !$request->photoshoot_required && !$request->photoshoot_scheduled_at])>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Photoshoot Scheduled On</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-800 py-2">{{ $request->photoshoot_scheduled_at?->format('d M Y') ?? '—' }}</p>
                                </template>
                                <input x-show="editing" x-cloak type="date" name="photoshoot_scheduled_at"
                                       value="{{ old('photoshoot_scheduled_at', $request->photoshoot_scheduled_at?->format('Y-m-d')) }}"
                                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Product Content</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-800 py-2">{{ $request->use_ai_content ? 'AI Content Generator' : 'Provided by brand team' }}</p>
                                </template>
                                <select x-show="editing" x-cloak name="use_ai_content"
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                                    <option value="1" @selected($request->use_ai_content)>AI Content Generator</option>
                                    <option value="0" @selected(!$request->use_ai_content)>Provided by brand team</option>
                                </select>
                            </div>

                            <div class="md:col-span-2">
                                <label class="block text-xs font-medium text-gray-500 mb-1">Notes / Special Instructions</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-800 py-2 whitespace-pre-line">{{ $request->notes ?: '—' }}</p>
                                </template>
                                <textarea x-show="editing" x-cloak name="notes" rows="3"
                                          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 resize-y">{{ old('notes', $request->notes) }}</textarea>
                            </div>
                        </div>

                        <div x-show="editing" x-cloak class="flex gap-2 mt-5 pt-4 border-t border-gray-100">
                            <button type="submit" class="bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">Save Changes</button>
                            <button type="button" @click="editing = false" class="border border-gray-300 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors">Cancel</button>
                        </div>
                    </form>

                    @unless($request->isClosed())
                    <div class="mt-5 pt-4 border-t border-gray-100">
                        <button type="button" @click="showCancel = true"
                                class="inline-flex items-center gap-2 text-red-600 hover:text-red-700 text-sm font-medium">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                            Cancel this request
                        </button>
                    </div>
                    @endunless
                </div>

                {{-- Tab: SKUs --}}
                <div x-show="tab === 'skus'" x-cloak class="px-5 py-5" x-data="{ selected: [], selectAll: false }">

                    @unless($request->isClosed())
                    <form method="POST" action="{{ route('product-requests.skus.add', $request) }}" enctype="multipart/form-data" class="mb-5 pb-5 border-b border-gray-100">
                        @csrf
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Add more SKUs</label>
                        <div class="flex gap-2">
                            <textarea name="skus" rows="2" placeholder="One per line, or comma separated"
                                      class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 resize-y"></textarea>
                            <div class="flex flex-col gap-2 shrink-0">
                                <input type="file" name="sku_csv" accept=".csv,.txt"
                                       class="text-xs text-gray-600 file:mr-2 file:py-1.5 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 cursor-pointer w-52">
                                <button type="submit" class="bg-brand-600 hover:bg-brand-700 text-white text-xs font-medium px-4 py-2 rounded-lg transition-colors">Add &amp; Revalidate</button>
                            </div>
                        </div>
                    </form>

                    {{-- Supply Chain records the mapping outcome here. --}}
                    @if($usesMapping)
                    <form method="POST" action="{{ route('product-requests.skus.mapping', $request) }}" class="mb-4">
                        @csrf
                        <template x-for="id in selected" :key="id">
                            <input type="hidden" name="sku_ids[]" :value="id">
                        </template>
                        <div class="bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs font-medium text-gray-600">Supply Chain — update mapping status</span>
                                <span class="text-xs text-gray-500">(<span x-text="selected.length"></span> selected)</span>
                                <div class="flex-1"></div>
                                <button type="submit" name="mapping_status" value="{{ \App\Models\ProductRequest::MAP_MAPPED }}" :disabled="!selected.length"
                                        class="inline-flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-lg transition-colors bg-green-600 hover:bg-green-700 text-white disabled:opacity-40 disabled:cursor-not-allowed">
                                    <span class="w-1.5 h-1.5 rounded-full bg-white"></span> Mapped
                                </button>
                                <button type="submit" name="mapping_status" value="{{ \App\Models\ProductRequest::MAP_PENDING }}" :disabled="!selected.length"
                                        class="inline-flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-lg transition-colors bg-amber-500 hover:bg-amber-600 text-white disabled:opacity-40 disabled:cursor-not-allowed">
                                    <span class="w-1.5 h-1.5 rounded-full bg-white"></span> Pending
                                </button>
                                <button type="submit" name="mapping_status" value="{{ \App\Models\ProductRequest::MAP_NOT_MAPPED }}" :disabled="!selected.length"
                                        class="inline-flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-lg transition-colors bg-red-600 hover:bg-red-700 text-white disabled:opacity-40 disabled:cursor-not-allowed">
                                    <span class="w-1.5 h-1.5 rounded-full bg-white"></span> Not Mapped
                                </button>
                            </div>
                            <input type="text" name="mapping_note" maxlength="255" placeholder="Optional note — e.g. awaiting supplier article code"
                                   class="w-full mt-2 rounded-lg border border-gray-300 px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500">
                        </div>
                        <p class="text-xs text-gray-400 mt-1.5">
                            Mapping is done in Cegid by Supply Chain and recorded here. Marking the last outstanding SKU as
                            <span class="font-medium">Mapped</span> releases the request to <span class="font-medium">SKU Verified</span> automatically.
                        </p>
                    </form>
                    @endif
                    @endunless

                    @if($skus->isEmpty())
                        <p class="py-10 text-sm text-gray-400 text-center">No SKUs on this request.</p>
                    @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 border-b border-gray-100">
                                    @if(!$request->isClosed() && $usesMapping)
                                    <th class="py-2 pr-3 w-8">
                                        <input type="checkbox" x-model="selectAll"
                                               @change="selected = selectAll ? Array.from(document.querySelectorAll('[data-sku-id]')).map(el => el.dataset.skuId) : []"
                                               class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                    </th>
                                    @endif
                                    <th class="py-2 pr-3 font-medium">SKU</th>
                                    @if($usesMapping)
                                    <th class="py-2 pr-3 font-medium">Mapping Status</th>
                                    <th class="py-2 pr-3 font-medium">Recorded By</th>
                                    @endif
                                    <th class="py-2 pr-3 font-medium">In Shopify</th>
                                    <th class="py-2 pr-3 font-medium">Product</th>
                                    <th class="py-2 font-medium">Last Checked</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                @foreach($skus as $sku)
                                <tr class="hover:bg-gray-50/70 transition-colors">
                                    @if(!$request->isClosed() && $usesMapping)
                                    <td class="py-2.5 pr-3">
                                        <input type="checkbox" data-sku-id="{{ $sku->id }}" value="{{ $sku->id }}" x-model="selected"
                                               class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                    </td>
                                    @endif
                                    <td class="py-2.5 pr-3 font-mono text-xs text-gray-800">{{ $sku->sku }}</td>
                                    @if($usesMapping)
                                    <td class="py-2.5 pr-3">
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-medium border {{ $sku->color() }}">
                                            <span class="w-1.5 h-1.5 rounded-full {{ $sku->dot() }}"></span>
                                            {{ $sku->label() }}
                                        </span>
                                    </td>
                                    <td class="py-2.5 pr-3 text-xs text-gray-600">
                                        <span class="{{ $sku->isManuallySet() ? '' : 'text-gray-400 italic' }}">{{ $sku->sourceLabel() }}</span>
                                        @if($sku->mapping_note)
                                            <p class="text-gray-400">{{ $sku->mapping_note }}</p>
                                        @endif
                                    </td>
                                    @endif
                                    <td class="py-2.5 pr-3 text-xs text-gray-600">{{ $sku->in_shopify ? 'Yes' : 'No' }}</td>
                                    <td class="py-2.5 pr-3 text-xs text-gray-600 max-w-xs truncate">{{ $sku->shopify_product_title ?: '—' }}</td>
                                    <td class="py-2.5 text-xs text-gray-400 whitespace-nowrap">{{ $sku->last_checked_at?->format('d M, h:i A') ?? '—' }}</td>
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

                {{-- Tab: attachments --}}
                <div x-show="tab === 'attachments'" x-cloak class="px-5 py-5">

                    {{-- Content sheet: only relevant when the AI generator isn't used --}}
                    @unless($request->use_ai_content)
                    <div class="mb-5 pb-5 border-b border-gray-100">
                        <h4 class="text-sm font-semibold text-gray-800 mb-1">Content Sheet</h4>
                        <p class="text-xs text-gray-500 mb-3">
                            This request is not using the AI Content Generator, so the brand team supplies the copy as an Excel or CSV file.
                        </p>

                        @forelse($request->contentSheets as $sheet)
                            <div class="flex items-center gap-3 py-2.5 border-b border-gray-50 last:border-0">
                                <div class="w-8 h-8 rounded-lg bg-green-50 text-green-600 flex items-center justify-center shrink-0">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-gray-800 truncate">{{ $sheet->original_name }}</p>
                                    <p class="text-xs text-gray-400">{{ $sheet->humanSize() }} &middot; {{ $sheet->user?->name ?? 'Unknown' }} &middot; {{ $sheet->created_at->format('d M Y') }}</p>
                                </div>
                                <a href="{{ route('product-requests.attachments.download', [$request, $sheet]) }}"
                                   class="text-xs text-brand-600 hover:text-brand-700 font-medium shrink-0">Download</a>
                                @unless($request->isClosed())
                                <form method="POST" action="{{ route('product-requests.attachments.destroy', [$request, $sheet]) }}"
                                      onsubmit="return confirm('Remove this content sheet?')" class="shrink-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs text-red-500 hover:text-red-700 font-medium">Remove</button>
                                </form>
                                @endunless
                            </div>
                        @empty
                            <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-3">
                                No content sheet uploaded yet.
                            </p>
                        @endforelse

                        @unless($request->isClosed())
                        <form method="POST" action="{{ route('product-requests.attachments.store', $request) }}" enctype="multipart/form-data" class="flex gap-2 mt-3">
                            @csrf
                            <input type="hidden" name="kind" value="{{ \App\Models\ProductRequestAttachment::KIND_CONTENT }}">
                            <input type="file" name="content_sheet" accept=".csv,.xlsx,.xls" required
                                   class="flex-1 text-sm text-gray-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 cursor-pointer">
                            <button type="submit" class="bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors shrink-0">
                                Upload Sheet
                            </button>
                        </form>
                        @endunless
                    </div>
                    @endunless

                    @unless($request->isClosed())
                    <form method="POST" action="{{ route('product-requests.attachments.store', $request) }}" enctype="multipart/form-data" class="mb-5 pb-5 border-b border-gray-100">
                        @csrf
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Upload reference images or documents</label>
                        <div class="flex gap-2">
                            <input type="file" name="reference_images[]" multiple accept=".jpg,.jpeg,.png,.pdf" required
                                   class="flex-1 text-sm text-gray-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 cursor-pointer">
                            <button type="submit" class="bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors shrink-0">Upload</button>
                        </div>
                        <p class="text-xs text-gray-400 mt-1">JPG, PNG, PDF up to 10MB each (max 10 files per upload).</p>
                    </form>
                    @endunless

                    <h4 class="text-sm font-semibold text-gray-800 mb-2">Reference Images</h4>
                    @forelse($request->referenceImages as $file)
                        <div class="flex items-center gap-3 py-2.5 border-b border-gray-50 last:border-0">
                            <div class="w-8 h-8 rounded-lg bg-gray-100 text-gray-500 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $file->isImage() ? 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z' : 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z' }}"/>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-gray-800 truncate">{{ $file->original_name }}</p>
                                <p class="text-xs text-gray-400">{{ $file->humanSize() }} &middot; {{ $file->user?->name ?? 'Unknown' }} &middot; {{ $file->created_at->format('d M Y') }}</p>
                            </div>
                            <a href="{{ route('product-requests.attachments.download', [$request, $file]) }}"
                               class="text-xs text-brand-600 hover:text-brand-700 font-medium shrink-0">Download</a>
                            @unless($request->isClosed())
                            <form method="POST" action="{{ route('product-requests.attachments.destroy', [$request, $file]) }}"
                                  onsubmit="return confirm('Remove this attachment?')" class="shrink-0">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs text-red-500 hover:text-red-700 font-medium">Remove</button>
                            </form>
                            @endunless
                        </div>
                    @empty
                        <p class="py-10 text-sm text-gray-400 text-center">No reference images on this request.</p>
                    @endforelse
                </div>

                {{-- Tab: comments --}}
                <div x-show="tab === 'comments'" x-cloak class="px-5 py-5">
                    <form method="POST" action="{{ route('product-requests.comment', $request) }}" class="mb-5">
                        @csrf
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Add a comment</label>
                        <textarea name="remarks" rows="3" required placeholder="Share an update with everyone following this request…"
                                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 resize-y"></textarea>
                        <button type="submit" class="mt-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">Post Comment</button>
                    </form>

                    @php $comments = $activities->where('action', 'comment'); @endphp

                    @forelse($comments as $comment)
                        <div class="flex gap-3 py-3 border-t border-gray-50">
                            <div class="w-7 h-7 rounded-full bg-brand-50 text-brand-700 text-xs font-semibold flex items-center justify-center shrink-0">
                                {{ strtoupper(substr($comment->actorName(), 0, 2)) }}
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm"><span class="font-medium text-gray-800">{{ $comment->actorName() }}</span>
                                    <span class="text-xs text-gray-400 ml-1.5">{{ $comment->created_at->format('d M Y, h:i A') }}</span></p>
                                <p class="text-sm text-gray-600 mt-0.5 whitespace-pre-line">{{ $comment->remarks }}</p>
                            </div>
                        </div>
                    @empty
                        <p class="py-10 text-sm text-gray-400 text-center">No comments yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Right column --}}
        <div class="space-y-5">

            {{-- Activity log --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
                <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-800">Activity Log</h2>
                    <a href="{{ route('product-requests.activities', $request) }}" class="text-xs text-brand-600 hover:text-brand-700 font-medium">View All &rarr;</a>
                </div>
                <div class="px-5 py-4 space-y-4 max-h-[520px] overflow-y-auto">
                    @forelse($activities as $entry)
                    <div class="flex gap-3">
                        <div class="flex flex-col items-center shrink-0">
                            <div class="w-6 h-6 rounded-lg bg-brand-50 text-brand-600 flex items-center justify-center">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            @unless($loop->last)
                                <div class="w-px flex-1 bg-gray-100 mt-1"></div>
                            @endunless
                        </div>
                        <div class="flex-1 min-w-0 pb-1">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-xs text-gray-400">{{ $entry->created_at->format('d M Y, h:i A') }}</p>
                                @if($entry->to_status)
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium border shrink-0 {{ $entry->statusColor() }}">
                                        {{ $entry->statusLabel() }}
                                    </span>
                                @endif
                            </div>
                            <p class="text-sm font-medium text-gray-800 mt-0.5">
                                {{ $entry->actorName() }}
                                @if($entry->actorRole())
                                    <span class="text-xs font-normal text-gray-400">({{ $entry->actorRole() }})</span>
                                @endif
                            </p>
                            <p class="text-sm text-gray-600">{{ $entry->description }}</p>
                            @if($entry->remarks)
                                <p class="text-xs text-gray-500 mt-0.5">{{ $entry->remarks }}</p>
                            @endif
                        </div>
                    </div>
                    @empty
                        <p class="py-8 text-sm text-gray-400 text-center">No activity yet.</p>
                    @endforelse
                </div>
            </div>

            {{-- Team assignments --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
                <div class="px-5 py-3.5 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-800">Team Assignments</h2>
                </div>
                <form method="POST" action="{{ route('product-requests.assign', $request) }}" class="px-5 py-4 space-y-3">
                    @csrf
                    @php
                        // Only ask for a photographer when there is actually a shoot —
                        // an empty field for work nobody is doing is just noise.
                        // Kept visible if someone is already assigned, so an existing
                        // assignment can never be stranded and un-editable.
                        $assignmentFields = ['assigned_to' => 'E-Commerce Owner'];

                        if ($request->photoshoot_required || $request->photographer_id) {
                            $assignmentFields['photographer_id'] = 'Photographer';
                        }

                        $assignmentFields['content_owner_id'] = 'Content Team';
                        $assignmentFields['qa_owner_id']      = 'QA Team';
                    @endphp
                    @foreach($assignmentFields as $field => $label)
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">{{ $label }}</label>
                            <select name="{{ $field }}" {{ $request->isClosed() ? 'disabled' : '' }}
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 disabled:bg-gray-50 disabled:text-gray-500">
                                <option value="">Unassigned</option>
                                @foreach($teamPool as $member)
                                    <option value="{{ $member->id }}" @selected($request->{$field} === $member->id)>
                                        {{ $member->name }}@if($member->pcr_role) — {{ $member->pcrRoleLabel() }}@endif
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach

                    @unless($request->isClosed())
                    <button type="submit" class="w-full bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
                        Save Assignments
                    </button>
                    @endunless
                </form>
            </div>

            {{-- Summary --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
                <div class="px-5 py-3.5 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-800">Summary</h2>
                </div>
                <dl class="px-5 py-4 space-y-2.5 text-sm">
                    @foreach([
                        'Request ID'    => $request->reference,
                        'Requested By'  => $request->user?->name ?? '—',
                        'Request Type'  => $request->request_type === 'new_brand' ? 'New Brand' : 'Existing Brand / New Category',
                        'Store'         => $request->store?->name ?? '—',
                        'Created On'    => $request->created_at->format('d M Y, h:i A'),
                        'Last Updated'  => $request->updated_at->format('d M Y, h:i A'),
                        'Published On'  => $request->published_at?->format('d M Y, h:i A') ?? '—',
                    ] as $label => $value)
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500 shrink-0">{{ $label }}</dt>
                            <dd class="text-gray-800 text-right truncate">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>
    </div>

    {{-- Modal: move stage --}}
    <div x-show="showTransition" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-gray-900/40" @click="showTransition = false"></div>
        <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-md">
            <form method="POST" action="{{ route('product-requests.transition', $request) }}">
                @csrf
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="text-base font-semibold text-gray-900">Move to next stage</h3>
                    <p class="text-sm text-gray-500 mt-0.5">Currently <span class="font-medium">{{ $request->statusLabel() }}</span>. Everyone involved is notified.</p>
                </div>
                <div class="px-5 py-4 space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">New Status <span class="text-red-500">*</span></label>
                        <select name="to_status" required
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                            @foreach($transitions as $status)
                                <option value="{{ $status }}" @selected($status === $request->suggestedNextStatus())>
                                    {{ $request->stageLabel($status) }}@if($status === $request->suggestedNextStatus()) (suggested)@endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div @class(['hidden' => !$request->photoshoot_required])>
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Photoshoot Date <span class="text-gray-400 font-normal">(if scheduling)</span></label>
                        <input type="date" name="photoshoot_scheduled_at"
                               value="{{ $request->photoshoot_scheduled_at?->format('Y-m-d') }}"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    </div>
                    <div class="bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 text-xs text-gray-600">
                        <span class="font-medium text-gray-700">Suggested next:</span>
                        {{ $request->suggestedNextStatus() ? $request->stageLabel($request->suggestedNextStatus()) : '—' }}
                        @if($request->suggestedNextStatus())
                            <p class="mt-1">{{ $request->guideFor($request->suggestedNextStatus())['what'] }}</p>
                        @endif
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Remarks</label>
                        <textarea name="remarks" rows="3" placeholder="Optional note recorded in the activity log"
                                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 resize-y"></textarea>
                    </div>
                </div>
                <div class="px-5 py-4 bg-gray-50 border-t border-gray-100 flex gap-2 justify-end">
                    <button type="button" @click="showTransition = false"
                            class="border border-gray-300 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-100 transition-colors">Cancel</button>
                    <button type="submit" class="bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">Update Status</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal: report a blocker --}}
    <div x-show="showHold" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-gray-900/40" @click="showHold = false"></div>
        <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-md">
            <form method="POST" action="{{ route('product-requests.hold', $request) }}">
                @csrf
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="text-base font-semibold text-gray-900">Report a blocker</h3>
                    <p class="text-sm text-gray-500 mt-0.5">
                        The request stays at {{ $request->statusLabel() }} and is flagged as blocked. Everyone involved is notified.
                    </p>
                </div>
                <div class="px-5 py-4 space-y-3">
                    <label class="block text-xs font-medium text-gray-600">What is blocking this? <span class="text-red-500">*</span></label>
                    @foreach(\App\Models\ProductRequest::HOLD_REASONS as $reason)
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="hold_reason" value="{{ $reason }}" class="text-brand-600 focus:ring-brand-500">
                            <span class="text-sm text-gray-700">{{ $reason }}</span>
                        </label>
                    @endforeach
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1.5 mt-2">Or describe it</label>
                        <input type="text" name="hold_reason_other" maxlength="255" placeholder="e.g. Only 12 of 45 samples arrived"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    </div>
                </div>
                <div class="px-5 py-4 bg-gray-50 border-t border-gray-100 flex gap-2 justify-end">
                    <button type="button" @click="showHold = false"
                            class="border border-gray-300 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-100 transition-colors">Cancel</button>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">Put on hold</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal: hand the current stage to someone else --}}
    <div x-show="showHandover" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-gray-900/40" @click="showHandover = false"></div>
        <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-md">
            <form method="POST" action="{{ route('product-requests.reassign', $request) }}">
                @csrf
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="text-base font-semibold text-gray-900">Hand this task over</h3>
                    <p class="text-sm text-gray-500 mt-0.5">
                        Choose who takes over as <span class="font-medium">{{ $guide['role'] }}</span>. They are notified straight away.
                    </p>
                </div>
                <div class="px-5 py-4">
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Hand over to <span class="text-red-500">*</span></label>
                    <select name="user_id" required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                        <option value="">Select a person…</option>
                        @foreach($teamPool as $member)
                            @continue($guide['owner'] && $member->id === $guide['owner']->id)
                            <option value="{{ $member->id }}">
                                {{ $member->name }}@if($member->pcr_role) — {{ $member->pcrRoleLabel() }}@endif
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-400 mt-1.5">The hand-over is recorded in the activity log.</p>
                </div>
                <div class="px-5 py-4 bg-gray-50 border-t border-gray-100 flex gap-2 justify-end">
                    <button type="button" @click="showHandover = false"
                            class="border border-gray-300 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-100 transition-colors">Cancel</button>
                    <button type="submit" class="bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">Hand over</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal: cancel request --}}
    <div x-show="showCancel" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-gray-900/40" @click="showCancel = false"></div>
        <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-md">
            <form method="POST" action="{{ route('product-requests.cancel', $request) }}">
                @csrf
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="text-base font-semibold text-gray-900">Cancel request</h3>
                    <p class="text-sm text-gray-500 mt-0.5">{{ $request->reference }} will be closed. This cannot be undone.</p>
                </div>
                <div class="px-5 py-4">
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Reason <span class="text-red-500">*</span></label>
                    <input type="text" name="cancel_reason" required maxlength="255" placeholder="Why is this request being cancelled?"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                </div>
                <div class="px-5 py-4 bg-gray-50 border-t border-gray-100 flex gap-2 justify-end">
                    <button type="button" @click="showCancel = false"
                            class="border border-gray-300 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-100 transition-colors">Keep Request</button>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">Cancel Request</button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
