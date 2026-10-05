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

    @include('product-performance._filters', ['mode' => 'products'])

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
            <div class="pb-3 flex items-center gap-3">
                @if($filters['division'] !== '')
                    <a href="{{ $link(['division' => null]) }}" title="Show every division"
                       class="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-medium text-brand-700 hover:bg-brand-100">
                        <span class="font-mono">{{ $filters['division'] === \App\Http\Controllers\ProductPerformanceController::NO_DIVISION ? 'No division' : $filters['division'] }}</span>
                        <span aria-hidden="true">×</span>
                    </a>
                @endif
                <span class="text-xs text-gray-400 tabular-nums">{{ number_format($rows->total()) }} products</span>
            </div>
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
