@extends('layouts.app')

@section('title', $config['title'])
@section('page-title', 'Product Creation Request')

@section('content')
@php
    $labels       = \App\Models\ProductRequest::STATUS_LABELS;
    $ownerField   = $config['owner_field'];
    $ownerRelation = $config['owner_relation'];
    // One literal class per case — the Tailwind CDN can only see classes that
    // actually appear in the rendered HTML.
    $boardCols    = count($config['stages']) === 2 ? 'md:grid-cols-2' : 'md:grid-cols-3';
@endphp

<div class="space-y-5">

    {{-- Header --}}
    <div class="flex items-start justify-between">
        <div>
            <nav class="text-xs text-gray-400 mb-1">
                <a href="{{ route('product-requests.index') }}" class="hover:text-gray-600">Product Creation Request</a>
                <span class="mx-1.5">&gt;</span>
                <span class="text-gray-600">{{ $config['title'] }}</span>
            </nav>
            <h2 class="text-lg font-semibold text-gray-800">{{ $config['title'] }} Queue</h2>
            <p class="text-sm text-gray-500">{{ $config['description'] }}</p>
        </div>
        <a href="{{ route('product-requests.index') }}"
           class="inline-flex items-center gap-2 border border-gray-300 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Back to Dashboard
        </a>
    </div>

    {{-- Summary + filters --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm px-5 py-4 flex flex-wrap items-center gap-x-8 gap-y-3">
        <div>
            <p class="text-xs text-gray-500">In this queue</p>
            <p class="text-2xl font-semibold text-gray-900 leading-tight">{{ $total }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-500">Overdue</p>
            <p class="text-2xl font-semibold leading-tight {{ $overdue > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $overdue }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-500">Blocked</p>
            <p class="text-2xl font-semibold leading-tight {{ $blocked > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $blocked }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-500">Unassigned</p>
            <p class="text-2xl font-semibold leading-tight {{ $unassigned > 0 ? 'text-amber-600' : 'text-gray-900' }}">{{ $unassigned }}</p>
        </div>

        <div class="flex-1"></div>

        <div class="flex items-center gap-2">
            @php
                $activeFilter = request()->boolean('mine') ? 'mine' : (request()->boolean('unassigned') ? 'unassigned' : 'all');
            @endphp
            @foreach(['all' => 'All', 'mine' => 'Assigned to me', 'unassigned' => 'Unassigned'] as $key => $label)
                <a href="{{ route('product-requests.queue', array_merge(['queue' => $queueKey], $key === 'all' ? [] : [$key => 1])) }}"
                   class="text-xs font-medium px-3 py-1.5 rounded-lg border transition-colors
                          {{ $activeFilter === $key ? 'bg-brand-600 border-brand-600 text-white' : 'border-gray-300 text-gray-600 hover:bg-gray-50' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    {{-- Stage board --}}
    <div class="grid grid-cols-1 {{ $boardCols }} gap-4 items-start">
        @foreach($config['stages'] as $stage)
            @php $items = $columns[$stage]; @endphp
            <div class="bg-gray-100/70 rounded-xl border border-gray-200">
                <div class="px-4 py-3 flex items-center justify-between border-b border-gray-200">
                    <h3 class="text-sm font-semibold text-gray-700">{{ $labels[$stage] }}</h3>
                    <span class="text-xs font-medium text-gray-500 bg-white border border-gray-200 rounded-full px-2 py-0.5">{{ $items->count() }}</span>
                </div>

                <div class="p-3 space-y-2.5 min-h-[6rem]">
                    @forelse($items as $item)
                        @php $days = $item->daysToOnlineLaunch(); @endphp
                        <a href="{{ route('product-requests.show', $item) }}"
                           class="block rounded-lg border px-3.5 py-3 hover:shadow-sm transition-all
                                  {{ $item->isOnHold() ? 'bg-red-50 border-red-300 hover:border-red-400' : 'bg-white border-gray-200 hover:border-brand-300' }}">

                            <div class="flex items-start justify-between gap-2">
                                <span class="text-sm font-semibold text-brand-600">{{ $item->reference }}</span>
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium border shrink-0 {{ $item->priorityColor() }}">
                                    {{ $item->priorityLabel() }}
                                </span>
                            </div>

                            <p class="text-sm text-gray-800 mt-0.5 truncate">{{ $item->brand }} / {{ $item->category }}</p>
                            <p class="text-xs text-gray-400 truncate">{{ $item->store?->name }} &middot; {{ number_format($item->total_skus) }} SKUs</p>

                            @if($item->isOnHold())
                                <p class="text-xs text-red-700 font-medium mt-1.5 flex items-start gap-1">
                                    <svg class="w-3 h-3 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    <span>Blocked: {{ $item->hold_reason }}</span>
                                </p>
                            @endif

                            @if($queueKey === 'photoshoot')
                                <p class="text-xs text-gray-500 mt-1.5">
                                    @if($item->photoshoot_scheduled_at)
                                        Shoot: <span class="font-medium text-gray-700">{{ $item->photoshoot_scheduled_at->format('d M Y') }}</span>
                                    @else
                                        <span class="text-amber-600 font-medium">Shoot not scheduled</span>
                                    @endif
                                </p>
                                @if($item->supplier_images_available)
                                    <p class="text-xs text-gray-400">Supplier images provided</p>
                                @endif
                            @endif

                            <div class="flex items-center justify-between gap-2 mt-2 pt-2 border-t border-gray-50">
                                @php $cardOwner = $item->currentGuide()['owner']; @endphp
                                <span class="text-xs {{ $cardOwner ? 'text-gray-600' : 'text-amber-600 font-medium' }} truncate">
                                    {{ $cardOwner?->name ?? 'Unassigned' }}
                                </span>
                                <span class="text-xs shrink-0 whitespace-nowrap
                                    {{ $days !== null && $days < 0 ? 'text-red-600 font-medium' : ($days !== null && $days <= 3 ? 'text-amber-600 font-medium' : 'text-gray-400') }}">
                                    @if($days === null)
                                        No launch date
                                    @elseif($days < 0)
                                        {{ abs($days) }}d overdue
                                    @elseif($days === 0)
                                        Launches today
                                    @else
                                        {{ $days }}d to launch
                                    @endif
                                </span>
                            </div>
                        </a>
                    @empty
                        <p class="text-xs text-gray-400 text-center py-6">Nothing at this stage.</p>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    @if($total === 0)
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm px-5 py-12 text-center">
            <p class="text-sm text-gray-500">Nothing in the {{ strtolower($config['title']) }} queue right now.</p>
            <a href="{{ route('product-requests.list') }}" class="text-sm text-brand-600 hover:text-brand-700 font-medium mt-2 inline-block">
                View all requests &rarr;
            </a>
        </div>
    @endif

</div>
@endsection
