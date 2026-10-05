@extends('layouts.app')
@section('title', 'Product Performance')
@section('page-title', 'Product Performance')

@php
    $isAll   = $filters['store'] === 'all';
    $link    = fn (array $change) => route('product-performance.index', array_merge(request()->except('page'), $change));
    $thumb   = fn (?string $url) => $url ? $url . (str_contains($url, '?') ? '&' : '?') . 'width=96' : null;
    $tabs    = [
        'best' => ['Best sellers', 'Most units sold in the range.'],
        'low'  => ['Low sellers',  "Live, in stock, and sold {$filters['max']} or fewer units."],
        'none' => ['No sales',     'Live and in stock, but not one unit sold in the range.'],
    ];
@endphp

@section('content')
<div class="space-y-6">

    {{-- Filters ─────────────────────────────────────────────────────────── --}}
    @php $field = 'h-9 rounded-lg border border-gray-200 bg-gray-50 px-3 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:bg-white'; @endphp
    <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="tab" value="{{ $filters['tab'] }}">

            <select name="store" onchange="this.form.submit()" aria-label="Website" class="{{ $field }}">
                <option value="all" @selected($isAll)>All websites</option>
                @foreach($choices as $choice)
                    <option value="{{ $choice->id }}" @selected((string) $choice->id === $filters['store'])>{{ $choice->name }}</option>
                @endforeach
            </select>
            <select name="days" onchange="this.form.submit()" aria-label="Period" class="{{ $field }}">
                @foreach($ranges as $range)
                    <option value="{{ $range }}" @selected($range === $filters['days'])>Last {{ $range }} days</option>
                @endforeach
            </select>
            <select name="brand" onchange="this.form.submit()" aria-label="Brand" class="{{ $field }} max-w-[12rem]">
                <option value="">All brands</option>
                @foreach($brands as $brand)
                    <option value="{{ $brand }}" @selected($brand === $filters['brand'])>{{ $brand }}</option>
                @endforeach
            </select>
            @if($filters['tab'] === 'low')
                <label class="flex items-center gap-1.5 text-xs text-gray-500">
                    ≤
                    <input type="number" name="max" min="1" max="50" value="{{ $filters['max'] }}" aria-label="Sold at most"
                           onchange="this.form.submit()" class="{{ $field }} w-16">
                    units
                </label>
            @endif
            <div class="relative flex-1 min-w-[10rem] max-w-xs">
                <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                <input type="search" name="q" value="{{ $filters['search'] }}" placeholder="Search product or SKU" aria-label="Search"
                       class="{{ $field }} w-full pl-8">
            </div>

            <div class="ml-auto flex items-center gap-2">
                @if($summary['syncedAt'])
                    <span class="hidden md:inline text-xs text-gray-400 mr-1">Updated {{ $summary['syncedAt']->diffForHumans() }}</span>
                @endif
                <a href="{{ route('product-performance.download', request()->except('page')) }}" title="Download CSV"
                   class="h-9 inline-flex items-center gap-1.5 px-3 rounded-lg border border-gray-200 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    CSV
                </a>
                @if(auth()->user()->is_super_admin)
                    <button form="pp-sync" class="h-9 inline-flex items-center px-3 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">Sync now</button>
                @endif
            </div>
        </form>
        @if(auth()->user()->is_super_admin)
            <form id="pp-sync" method="POST" action="{{ route('product-performance.refresh') }}" class="hidden">@csrf</form>
        @endif

        {{-- Why a number might be missing, kept to one line each; the detail sits in the tooltip. --}}
        @if($summary['never']->isNotEmpty())
            <p class="mt-3 text-xs text-amber-700 flex items-center gap-1.5" title="{{ $summary['never']->pluck('name')->join(', ') }}">
                <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                {{ $summary['never']->count() }} {{ \Illuminate\Support\Str::plural('website', $summary['never']->count()) }} waiting for first sync
            </p>
        @endif
        @foreach($summary['failing'] as $failing)
            <p class="mt-2 text-xs text-red-700 flex items-center gap-1.5" title="{{ $failing->sales_sync_error }}">
                <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>
                {{ $failing->name }}: sync failed — {{ \Illuminate\Support\Str::limit($failing->sales_sync_error, 90) }}
            </p>
        @endforeach
        @foreach($summary['partial'] as $partial)
            <p class="mt-2 text-xs text-amber-700 flex items-center gap-1.5" title="Shopify gives 60 days of orders unless the app has read_all_orders.">
                <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                {{ $partial->name }}: data from {{ $partial->sales_covered_from->format('j M Y') }} only
            </p>
        @endforeach
    </div>

    {{-- Headline numbers ─────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Units sold</p>
            <p class="text-2xl font-bold text-gray-800 tabular-nums">{{ number_format($summary['units']) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Products that sold</p>
            <p class="text-2xl font-bold text-gray-800 tabular-nums">{{ number_format($summary['products']) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Revenue</p>
            @if($summary['revenue'] !== null)
                <p class="text-2xl font-bold text-gray-800 tabular-nums">
                    <span class="text-sm font-medium text-gray-400">{{ $summary['currency'] }}</span>
                    {{ number_format($summary['revenue'], 0) }}
                </p>
            @else
                <p class="text-2xl font-bold text-gray-300">—</p>
                <p class="text-xs text-gray-400 mt-1">Pick one website to see revenue</p>
            @endif
        </div>
        <a href="{{ $link(['tab' => 'none']) }}" class="bg-white rounded-xl border border-gray-200 p-5 hover:border-red-200">
            <p class="text-xs text-gray-500 mb-1">In stock, no sales</p>
            <p class="text-2xl font-bold {{ $summary['idle'] ? 'text-red-600' : 'text-gray-800' }} tabular-nums">{{ number_format($summary['idle']) }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ number_format($summary['idleStock']) }} units of stock sitting</p>
        </a>
    </div>

    {{-- The list ─────────────────────────────────────────────────────────── --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 pt-4 border-b border-gray-100 flex items-end justify-between gap-4">
            <nav class="flex gap-6 -mb-px">
                @foreach($tabs as $key => [$label, $hint])
                    <a href="{{ $link(['tab' => $key]) }}" title="{{ $hint }}"
                       class="pb-3 text-sm font-medium border-b-2 {{ $filters['tab'] === $key ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-500 hover:text-gray-800' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </nav>
            <span class="pb-3 text-xs text-gray-400 tabular-nums">{{ number_format($rows->total()) }} products</span>
        </div>

        @if($rows->isEmpty())
            <div class="px-6 py-12 text-center text-gray-400 text-sm">
                @if($filters['search'] !== '' || $filters['brand'] !== '')
                    Nothing matches these filters.
                @elseif($filters['tab'] === 'best')
                    No sales recorded in this range yet.
                @else
                    Nothing here: every live, in-stock product sold more than that.
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                        <tr>
                            @if($filters['tab'] === 'best')<th class="px-4 py-3 text-right w-12">#</th>@endif
                            <th class="px-4 py-3 text-left">Product</th>
                            @if($isAll)<th class="px-4 py-3 text-left">Website</th>@endif
                            <th class="px-4 py-3 text-left">Brand</th>
                            <th class="px-4 py-3 text-right">Units sold</th>
                            @unless($isAll)<th class="px-4 py-3 text-right">Revenue</th>@endunless
                            <th class="px-4 py-3 text-right">Stock</th>
                            <th class="px-4 py-3 text-left">Last sold</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($rows as $i => $row)
                            @php
                                $store = $storeById[$row->store_id] ?? null;
                                $last  = $row->last_sold ?? ($lastSold["{$row->store_id}|{$row->product_id}"] ?? null);
                            @endphp
                            <tr class="hover:bg-gray-50">
                                @if($filters['tab'] === 'best')
                                    <td class="px-4 py-3 text-right text-gray-400 tabular-nums">{{ $rows->firstItem() + $i }}</td>
                                @endif
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3 min-w-[16rem]">
                                        @if($thumb($row->image_url))
                                            <img src="{{ $thumb($row->image_url) }}" alt="" loading="lazy" class="h-10 w-10 rounded object-cover bg-gray-100 shrink-0">
                                        @else
                                            <span class="h-10 w-10 rounded bg-gray-100 shrink-0"></span>
                                        @endif
                                        <div class="min-w-0">
                                            @if($store && $row->title)
                                                <a href="https://{{ $store->shopify_domain }}/admin/products/{{ $row->product_id }}" target="_blank" rel="noopener"
                                                   class="font-medium text-gray-800 hover:text-brand-700 line-clamp-2">{{ $row->title }}</a>
                                            @else
                                                <span class="font-medium text-gray-400">Removed from Shopify</span>
                                            @endif
                                            <p class="text-xs text-gray-400 font-mono">
                                                {{ $row->sku ?: '—' }}
                                                @if($row->status && $row->status !== 'active')
                                                    <span class="ml-1 font-sans text-amber-600">{{ $row->status }}</span>
                                                @endif
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                @if($isAll)<td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $store?->name }}</td>@endif
                                <td class="px-4 py-3 text-gray-600">{{ $row->vendor ?: '—' }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-800 tabular-nums">{{ number_format((int) $row->units) }}</td>
                                @unless($isAll)
                                    <td class="px-4 py-3 text-right text-gray-600 tabular-nums whitespace-nowrap">
                                        {{ number_format((float) $row->revenue, 0) }} <span class="text-xs text-gray-400">{{ $store?->sales_currency }}</span>
                                    </td>
                                @endunless
                                <td class="px-4 py-3 text-right tabular-nums {{ $row->total_inventory !== null && $row->total_inventory <= 0 ? 'text-red-600' : 'text-gray-600' }}">
                                    {{ $row->total_inventory === null ? '—' : number_format((int) $row->total_inventory) }}
                                </td>
                                <td class="px-4 py-3 text-gray-500 whitespace-nowrap">
                                    {{ $last ? \Illuminate\Support\Carbon::parse($last)->format('j M Y') : 'No sales on record' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($rows->hasPages())
                <div class="px-6 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
            @endif
        @endif
    </div>

</div>
@endsection
