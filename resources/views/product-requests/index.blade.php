@extends('layouts.app')

@section('title', 'Product Creation Request')
@section('page-title', 'Product Creation Request')

@section('content')
<div class="space-y-5" x-data="{ newRequestOpen: {{ $errors->any() && old('brand') ? 'true' : 'false' }} }">

    {{-- Header actions --}}
    <div class="flex items-center justify-between">
        <div>
            <nav class="text-xs text-gray-400 mb-1">
                <a href="{{ route('dashboard') }}" class="hover:text-gray-600">Home</a>
                <span class="mx-1.5">&gt;</span>
                <span class="text-gray-600">Product Creation Request</span>
            </nav>
            <p class="text-sm text-gray-500">Track every new product from brand request to live launch.</p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" @click="newRequestOpen = true"
                    class="inline-flex items-center gap-2 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors"
                    style="background-color:#1d5a74" onmouseover="this.style.backgroundColor='#164659'" onmouseout="this.style.backgroundColor='#1d5a74'">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                New Request
            </button>
            <a href="{{ route('product-requests.list') }}"
               class="inline-flex items-center gap-2 border border-gray-300 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                View Requests
            </a>
        </div>
    </div>

    @include('product-requests.partials.stat-cards')

    {{-- Recent requests --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-800">Recent Requests</h2>
            <a href="{{ route('product-requests.list') }}" class="text-xs text-brand-600 hover:text-brand-700 font-medium">View All Requests &rarr;</a>
        </div>

        @if($recent->isEmpty())
            <div class="px-5 py-12 text-center">
                <p class="text-sm text-gray-500">No product creation requests yet.</p>
                <button type="button" @click="newRequestOpen = true" class="mt-3 text-sm text-brand-600 hover:text-brand-700 font-medium">
                    Create the first request &rarr;
                </button>
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 border-b border-gray-100 bg-gray-50/60">
                        <th class="px-5 py-2.5 font-medium">Request ID</th>
                        <th class="px-3 py-2.5 font-medium">Brand / Category</th>
                        <th class="px-3 py-2.5 font-medium text-right">SKUs</th>
                        <th class="px-3 py-2.5 font-medium">Store Launch</th>
                        <th class="px-3 py-2.5 font-medium">Online Launch</th>
                        <th class="px-3 py-2.5 font-medium">Photoshoot</th>
                        <th class="px-3 py-2.5 font-medium">Status</th>
                        <th class="px-3 py-2.5 font-medium">Priority</th>
                        <th class="px-5 py-2.5 font-medium">Requested By</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($recent as $item)
                    <tr class="hover:bg-gray-50/70 transition-colors">
                        <td class="px-5 py-3">
                            <a href="{{ route('product-requests.show', $item) }}" class="text-brand-600 hover:text-brand-700 font-medium">{{ $item->reference }}</a>
                        </td>
                        <td class="px-3 py-3 text-gray-700">{{ $item->brand }} / {{ $item->category }}</td>
                        <td class="px-3 py-3 text-right text-gray-700 tabular-nums">{{ number_format($item->total_skus) }}</td>
                        <td class="px-3 py-3 text-gray-600 whitespace-nowrap">{{ $item->store_launch_date?->format('d M Y') ?? '—' }}</td>
                        <td class="px-3 py-3 whitespace-nowrap {{ $item->isOverdue() ? 'text-red-600 font-medium' : 'text-gray-600' }}">
                            {{ $item->online_launch_date?->format('d M Y') ?? '—' }}
                        </td>
                        <td class="px-3 py-3 text-gray-600">{{ $item->photoshoot_required ? 'Yes' : 'No' }}</td>
                        <td class="px-3 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border {{ $item->statusColor() }} whitespace-nowrap">
                                {{ $item->statusLabel() }}
                            </span>
                        </td>
                        <td class="px-3 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border {{ $item->priorityColor() }}">
                                {{ $item->priorityLabel() }}
                            </span>
                        </td>
                        <td class="px-5 py-3">
                            <p class="text-gray-700">{{ $item->user?->name ?? '—' }}</p>
                            <p class="text-xs text-gray-400">{{ $item->created_at->format('d M Y') }}</p>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

        {{-- Status overview --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="px-5 py-3.5 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-800">Request Status Overview</h2>
            </div>
            <div class="px-5 py-5">
                @php
                    $palette = [
                        \App\Models\ProductRequest::SUBMITTED            => '#34d399',
                        \App\Models\ProductRequest::WAITING_MAPPING      => '#fbbf24',
                        \App\Models\ProductRequest::SKU_VERIFIED         => '#2dd4bf',
                        \App\Models\ProductRequest::WAITING_IMAGES       => '#fb923c',
                        \App\Models\ProductRequest::PHOTOSHOOT_SCHEDULED => '#c084fc',
                        \App\Models\ProductRequest::PHOTOSHOOT_COMPLETED => '#a78bfa',
                        \App\Models\ProductRequest::IMAGE_EDITING        => '#818cf8',
                        \App\Models\ProductRequest::AI_CONTENT           => '#f59e0b',
                        \App\Models\ProductRequest::QA_REVIEW            => '#38bdf8',
                        \App\Models\ProductRequest::READY_FOR_UPLOAD     => '#60a5fa',
                        \App\Models\ProductRequest::PUBLISHED            => '#10b981',
                        \App\Models\ProductRequest::COMPLETED            => '#9ca3af',
                        \App\Models\ProductRequest::CANCELLED            => '#f87171',
                    ];
                    $totalForChart = max(1, $breakdown->sum());
                    // Donut geometry: r=52 → circumference ≈ 326.7. Each slice consumes
                    // its share of that length and the next starts where it left off.
                    $circumference = 2 * M_PI * 52;
                    $offset        = 0.0;
                @endphp

                <div class="flex items-center gap-6">
                    <div class="relative shrink-0">
                        <svg width="140" height="140" viewBox="0 0 140 140" class="-rotate-90">
                            <circle cx="70" cy="70" r="52" fill="none" stroke="#f3f4f6" stroke-width="18"/>
                            @foreach($palette as $status => $color)
                                @php $count = (int) ($breakdown[$status] ?? 0); @endphp
                                @if($count > 0)
                                    @php
                                        $length = $count / $totalForChart * $circumference;
                                    @endphp
                                    <circle cx="70" cy="70" r="52" fill="none" stroke="{{ $color }}" stroke-width="18"
                                            stroke-dasharray="{{ round($length, 2) }} {{ round($circumference - $length, 2) }}"
                                            stroke-dashoffset="{{ round(-$offset, 2) }}"/>
                                    @php $offset += $length; @endphp
                                @endif
                            @endforeach
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-2xl font-semibold text-gray-900">{{ number_format($breakdown->sum()) }}</span>
                            <span class="text-xs text-gray-400">Total</span>
                        </div>
                    </div>

                    <div class="flex-1 min-w-0 space-y-1.5">
                        @forelse($palette as $status => $color)
                            @php $count = (int) ($breakdown[$status] ?? 0); @endphp
                            @if($count > 0)
                            <div class="flex items-center gap-2 text-xs">
                                <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $color }}"></span>
                                <span class="flex-1 text-gray-600 truncate">{{ \App\Models\ProductRequest::STATUS_LABELS[$status] }}</span>
                                <span class="text-gray-900 font-medium tabular-nums">{{ $count }}</span>
                                <span class="text-gray-400 tabular-nums w-10 text-right">{{ round($count / $totalForChart * 100) }}%</span>
                            </div>
                            @endif
                        @empty
                        @endforelse

                        @if($breakdown->sum() === 0)
                            <p class="text-sm text-gray-400">Nothing to chart yet.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Upcoming deadlines --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="px-5 py-3.5 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-800">Upcoming Deadlines</h2>
            </div>
            <div class="divide-y divide-gray-50">
                @forelse($deadlines as $item)
                    @php $days = $item->daysToOnlineLaunch(); @endphp
                    <a href="{{ route('product-requests.show', $item) }}" class="flex items-center gap-4 px-5 py-3 hover:bg-gray-50/70 transition-colors">
                        <div class="w-12 shrink-0 text-center">
                            <p class="text-lg font-semibold leading-none {{ $days < 0 ? 'text-red-600' : 'text-gray-800' }}">{{ $item->online_launch_date->format('d') }}</p>
                            <p class="text-xs uppercase text-gray-400">{{ $item->online_launch_date->format('M') }}</p>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-800 truncate">{{ $item->reference }}</p>
                            <p class="text-xs text-gray-500 truncate">
                                @if($days < 0)
                                    <span class="text-red-600 font-medium">Overdue by {{ abs($days) }} day(s)</span>
                                @elseif($days === 0)
                                    <span class="text-amber-600 font-medium">Launches today</span>
                                @else
                                    Online launch in {{ $days }} day(s)
                                @endif
                                &middot; {{ $item->brand }} / {{ $item->category }}
                            </p>
                        </div>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border shrink-0 {{ $item->priorityColor() }}">
                            {{ $item->priorityLabel() }}
                        </span>
                    </a>
                @empty
                    <p class="px-5 py-10 text-sm text-gray-400 text-center">No upcoming launches scheduled.</p>
                @endforelse
            </div>
        </div>

        {{-- Recent activity --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="px-5 py-3.5 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-800">Recent Activity</h2>
            </div>
            <div class="px-5 py-4 space-y-3.5">
                @forelse($activity as $entry)
                <div class="flex gap-3">
                    <div class="w-7 h-7 rounded-lg bg-brand-50 text-brand-600 flex items-center justify-center shrink-0 mt-0.5">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs text-gray-400">{{ $entry->created_at->format('d M Y, h:i A') }}</p>
                        <p class="text-sm text-gray-700 truncate">
                            @if($entry->productRequest)
                                <a href="{{ route('product-requests.show', $entry->productRequest) }}" class="text-brand-600 hover:text-brand-700 font-medium">{{ $entry->productRequest->reference }}</a>
                            @endif
                            — {{ $entry->description }}
                        </p>
                        <p class="text-xs text-gray-400">by {{ $entry->actorName() }}</p>
                    </div>
                </div>
                @empty
                    <p class="py-8 text-sm text-gray-400 text-center">No activity recorded yet.</p>
                @endforelse
            </div>
        </div>

        {{-- Top brands --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="px-5 py-3.5 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-800">Top Brands</h2>
            </div>
            <div class="divide-y divide-gray-50">
                @forelse($topBrands as $brand => $count)
                <a href="{{ route('product-requests.list', ['brand' => $brand]) }}"
                   class="flex items-center justify-between px-5 py-3 hover:bg-gray-50/70 transition-colors">
                    <span class="text-sm text-gray-700">{{ $brand }}</span>
                    <span class="text-sm text-gray-500">{{ $count }} {{ \Illuminate\Support\Str::plural('Request', $count) }}</span>
                </a>
                @empty
                    <p class="px-5 py-10 text-sm text-gray-400 text-center">No brands yet.</p>
                @endforelse
            </div>
        </div>

    </div>

    @include('product-requests.partials.new-request-form')

</div>
@endsection
