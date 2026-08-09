@extends('layouts.app')

@section('title', $request->reference)
@section('page-title', 'Product Creation Request')

@section('content')
@php
    $pipeline    = \App\Models\ProductRequest::PIPELINE;
    $labels      = \App\Models\ProductRequest::STATUS_LABELS;
    $currentStep = $request->stageIndex();
    $transitions = $request->allowedTransitions();
@endphp

<div class="space-y-5"
     x-data="{
        tab: 'details',
        editing: false,
        showTransition: false,
        showCancel: false,
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

                <div class="grid grid-cols-3 sm:grid-cols-5 gap-4 mt-5">
                    @foreach([
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

                @if($request->store_launch_date && $request->online_launch_date && $request->online_launch_date->lt($request->store_launch_date))
                    <div class="mt-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-4 py-2.5 text-sm">
                        Online launch is scheduled before the store launch date.
                    </div>
                @endif
            </div>

            {{-- Workflow progress --}}
            <div>
                <h3 class="text-sm font-semibold text-gray-800 mb-4">Workflow Progress</h3>
                <div class="overflow-x-auto pb-2">
                    <div class="flex items-start gap-0 min-w-max">
                        @foreach($pipeline as $i => $stage)
                            @php
                                $done    = $currentStep > $i;
                                $current = $currentStep === $i;
                            @endphp
                            <div class="flex items-start">
                                <div class="flex flex-col items-center w-[74px]">
                                    <div class="w-5 h-5 rounded-full flex items-center justify-center shrink-0
                                        {{ $done ? 'bg-green-500 text-white' : ($current ? 'bg-blue-600 text-white ring-4 ring-blue-100' : 'bg-gray-200 text-gray-400') }}">
                                        @if($done)
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        @elseif($current)
                                            <span class="w-1.5 h-1.5 rounded-full bg-white"></span>
                                        @endif
                                    </div>
                                    <p class="text-[10px] leading-tight text-center mt-1.5 px-0.5
                                        {{ $current ? 'text-gray-900 font-semibold' : ($done ? 'text-gray-600' : 'text-gray-400') }}">
                                        {{ $labels[$stage] }}
                                    </p>
                                </div>
                                @if(!$loop->last)
                                    <div class="h-0.5 w-2 mt-2.5 -mx-1 shrink-0 {{ $done ? 'bg-green-500' : 'bg-gray-200' }}"></div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="mt-3 flex items-center gap-3">
                    <div class="flex-1 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full bg-brand-600 rounded-full transition-all" style="width: {{ $request->progressPercent() }}%"></div>
                    </div>
                    <span class="text-xs text-gray-500 tabular-nums">{{ $request->progressPercent() }}%</span>
                </div>
            </div>
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
                            @unless($cegidAutomatic)
                                &middot; <span class="text-amber-600">Cegid lookup not connected — mapping is recorded manually</span>
                            @endunless
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

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="rounded-lg border border-gray-200 px-4 py-3">
                            <p class="text-xs text-gray-500">Total SKUs</p>
                            <p class="text-2xl font-semibold text-gray-900">{{ number_format($request->total_skus) }}</p>
                        </div>
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
                    </div>

                    <div class="flex flex-wrap gap-2 mt-4">
                        @foreach([
                            'all' => 'Download All (' . $request->total_skus . ')',
                            \App\Models\ProductRequest::MAP_MAPPED => 'Download Mapped (' . $request->mapped_skus . ')',
                            \App\Models\ProductRequest::MAP_PENDING => 'Download Pending (' . $request->pending_skus . ')',
                            \App\Models\ProductRequest::MAP_NOT_MAPPED => 'Download Not Mapped (' . $request->not_mapped_skus . ')',
                        ] as $filter => $label)
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
                        'attachments' => 'Attachments (' . $request->attachments->count() . ')',
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

                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Photoshoot Scheduled On</label>
                                <template x-if="!editing">
                                    <p class="text-sm text-gray-800 py-2">{{ $request->photoshoot_scheduled_at?->format('d M Y') ?? '—' }}</p>
                                </template>
                                <input x-show="editing" x-cloak type="date" name="photoshoot_scheduled_at"
                                       value="{{ old('photoshoot_scheduled_at', $request->photoshoot_scheduled_at?->format('Y-m-d')) }}"
                                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
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

                    {{-- Supply Chain: record Cegid mapping --}}
                    <form method="POST" action="{{ route('product-requests.skus.cegid', $request) }}" class="mb-4">
                        @csrf
                        <template x-for="id in selected" :key="id">
                            <input type="hidden" name="sku_ids[]" :value="id">
                        </template>
                        <div class="flex flex-wrap items-center gap-2 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5">
                            <span class="text-xs font-medium text-gray-600">Supply Chain —</span>
                            <span class="text-xs text-gray-500"><span x-text="selected.length"></span> selected</span>
                            <div class="flex-1"></div>
                            <button type="submit" name="in_cegid" value="1" :disabled="!selected.length"
                                    class="text-xs font-medium px-3 py-1.5 rounded-lg transition-colors bg-green-600 hover:bg-green-700 text-white disabled:opacity-40 disabled:cursor-not-allowed">
                                Mark selected as mapped in Cegid
                            </button>
                            <button type="submit" name="in_cegid" value="0" :disabled="!selected.length"
                                    class="text-xs font-medium px-3 py-1.5 rounded-lg transition-colors border border-gray-300 text-gray-700 hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed">
                                Mark not mapped
                            </button>
                        </div>
                        <p class="text-xs text-gray-400 mt-1.5">
                            Marking the last outstanding SKU releases the request to <span class="font-medium">SKU Verified</span> automatically.
                        </p>
                    </form>
                    @endunless

                    @if($skus->isEmpty())
                        <p class="py-10 text-sm text-gray-400 text-center">No SKUs on this request.</p>
                    @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 border-b border-gray-100">
                                    @unless($request->isClosed())
                                    <th class="py-2 pr-3 w-8">
                                        <input type="checkbox" x-model="selectAll"
                                               @change="selected = selectAll ? Array.from(document.querySelectorAll('[data-sku-id]')).map(el => el.dataset.skuId) : []"
                                               class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                    </th>
                                    @endunless
                                    <th class="py-2 pr-3 font-medium">SKU</th>
                                    <th class="py-2 pr-3 font-medium">Mapping Status</th>
                                    <th class="py-2 pr-3 font-medium">Cegid</th>
                                    <th class="py-2 pr-3 font-medium">Shopify</th>
                                    <th class="py-2 pr-3 font-medium">Product</th>
                                    <th class="py-2 font-medium">Last Checked</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                @foreach($skus as $sku)
                                <tr class="hover:bg-gray-50/70 transition-colors">
                                    @unless($request->isClosed())
                                    <td class="py-2.5 pr-3">
                                        <input type="checkbox" data-sku-id="{{ $sku->id }}" value="{{ $sku->id }}" x-model="selected"
                                               class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                    </td>
                                    @endunless
                                    <td class="py-2.5 pr-3 font-mono text-xs text-gray-800">{{ $sku->sku }}</td>
                                    <td class="py-2.5 pr-3">
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-medium border {{ $sku->color() }}">
                                            <span class="w-1.5 h-1.5 rounded-full {{ $sku->dot() }}"></span>
                                            {{ $sku->label() }}
                                        </span>
                                    </td>
                                    <td class="py-2.5 pr-3 text-xs text-gray-600">
                                        {{ $sku->in_cegid === null ? 'Unknown' : ($sku->in_cegid ? 'Yes' : 'No') }}
                                    </td>
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

                    @forelse($request->attachments as $file)
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
                        <p class="py-10 text-sm text-gray-400 text-center">No attachments on this request.</p>
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
                    @foreach([
                        'assigned_to'      => 'E-Commerce Owner',
                        'photographer_id'  => 'Photographer',
                        'content_owner_id' => 'Content Team',
                        'qa_owner_id'      => 'QA Team',
                    ] as $field => $label)
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
                                    {{ $labels[$status] ?? $status }}@if($status === $request->suggestedNextStatus()) (suggested)@endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Photoshoot Date <span class="text-gray-400 font-normal">(if scheduling)</span></label>
                        <input type="date" name="photoshoot_scheduled_at"
                               value="{{ $request->photoshoot_scheduled_at?->format('Y-m-d') }}"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
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
