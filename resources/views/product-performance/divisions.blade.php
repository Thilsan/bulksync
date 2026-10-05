@extends('layouts.app')
@section('title', 'Division Performance')
@section('page-title', 'Product Performance')

@php
    $isAll   = $filters['store'] === 'all';
    $noDiv   = \App\Http\Controllers\ProductPerformanceController::NO_DIVISION;
@endphp

@section('content')
<div class="space-y-6">

    @include('product-performance._filters', ['mode' => 'divisions'])

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-3.5 border-b border-gray-100 flex items-baseline justify-between gap-4">
            <h3 class="text-sm font-semibold text-gray-800">Divisions</h3>
            <span class="text-xs text-gray-400 tabular-nums">
                {{ number_format($totals['divisions']) }} selling · {{ number_format($totals['units']) }} units
            </span>
        </div>

        @if($rows->isEmpty())
            <div class="px-6 py-12 text-center text-gray-400 text-sm">
                {{ $filters['search'] !== '' || $filters['brand'] !== '' ? 'Nothing matches these filters.' : 'No division data yet.' }}
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3 text-left">Division</th>
                            @if($isAll)<th class="px-4 py-3 text-left">Website</th>@endif
                            <th class="px-4 py-3 text-left w-1/3">Units sold</th>
                            @unless($isAll)<th class="px-4 py-3 text-right">Revenue</th>@endunless
                            <th class="px-4 py-3 text-right">Products sold</th>
                            <th class="px-4 py-3 text-right">Stock</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($rows as $row)
                            @php
                                $store = $storeById[$row['store_id']] ?? null;
                                $open  = route('product-performance.index', [
                                    'store' => $row['store_id'], 'days' => $filters['days'],
                                    'brand' => $filters['brand'] ?: null, 'division' => $row['division'],
                                    'tab'   => $row['units'] > 0 ? 'best' : 'none',
                                ]);
                                $width = $topUnits > 0 ? max(1, round($row['units'] / $topUnits * 100)) : 0;
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <a href="{{ $open }}" class="font-mono font-semibold {{ $row['division'] === $noDiv ? 'text-gray-400' : 'text-gray-800 hover:text-brand-700' }}">
                                        {{ $row['division'] === $noDiv ? 'No division' : $row['division'] }}
                                    </a>
                                </td>
                                @if($isAll)<td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $store?->name }}</td>@endif
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <span class="flex-1 h-2 rounded-full bg-gray-100 overflow-hidden" role="presentation">
                                            <span class="block h-full rounded-full bg-brand-500" style="width: {{ $row['units'] ? $width : 0 }}%"></span>
                                        </span>
                                        <span class="w-14 text-right font-semibold tabular-nums {{ $row['units'] ? 'text-gray-800' : 'text-red-600' }}">{{ number_format($row['units']) }}</span>
                                    </div>
                                </td>
                                @unless($isAll)
                                    <td class="px-4 py-3 text-right text-gray-600 tabular-nums whitespace-nowrap">
                                        {{ number_format($row['revenue'], 0) }} <span class="text-xs text-gray-400">{{ $store?->sales_currency }}</span>
                                    </td>
                                @endunless
                                <td class="px-4 py-3 text-right text-gray-600 tabular-nums">
                                    {{ number_format($row['sold']) }}<span class="text-gray-400"> / {{ number_format($row['products']) }}</span>
                                </td>
                                <td class="px-4 py-3 text-right text-gray-600 tabular-nums">{{ number_format($row['stock']) }}</td>
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
