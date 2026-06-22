@extends('layouts.app')
@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
<div class="space-y-6">

    {{-- Stats cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @php
            $cards = [
                ['label' => 'Total Sessions',    'value' => $stats['total_sessions'],  'color' => 'brand', 'icon' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'],
                ['label' => 'Images Uploaded',  'value' => $stats['total_uploaded'],   'color' => 'green',  'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                ['label' => 'No SKU Match',     'value' => $stats['total_skipped'],    'color' => 'yellow', 'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'],
                ['label' => 'Failed',           'value' => $stats['total_failed'],     'color' => 'red',    'icon' => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z'],
            ];
        @endphp

        @foreach ($cards as $card)
        <div class="bg-white rounded-xl border border-gray-200 p-5 flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-{{ $card['color'] }}-100 flex items-center justify-center flex-shrink-0">
                <svg class="w-6 h-6 text-{{ $card['color'] }}-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $card['icon'] }}"/>
                </svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-gray-900">{{ number_format($card['value']) }}</p>
                <p class="text-sm text-gray-500">{{ $card['label'] }}</p>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Quick action --}}
    <div class="bg-brand-50 border border-brand-200 rounded-xl p-6 flex items-center justify-between">
        <div>
            <h3 class="font-semibold text-brand-900 text-lg">Ready to upload images?</h3>
            <p class="text-brand-700 text-sm mt-1">Paste a OneDrive folder link and we'll match images to Shopify products by SKU.</p>
        </div>
        <a href="{{ route('upload.create') }}"
            class="flex-shrink-0 bg-brand-600 hover:bg-brand-700 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition-colors">
            New Upload
        </a>
    </div>

    {{-- SKU Checker section --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <h2 class="font-semibold text-gray-800">SKU Checker</h2>
                <div class="flex items-center gap-3 text-xs">
                    <span class="text-gray-400">Total Checks: <strong class="text-gray-700">{{ $skuStats['total_checks'] }}</strong></span>
                    <span class="text-gray-400">SKUs Checked: <strong class="text-gray-700">{{ number_format($skuStats['total_skus']) }}</strong></span>
                    <span class="text-green-600 font-medium">✓ {{ number_format($skuStats['total_available']) }} Available</span>
                    <span class="text-red-500 font-medium">✗ {{ number_format($skuStats['total_not_found']) }} Not Found</span>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('sku-checker.history') }}" class="text-sm text-brand-600 hover:underline">View all</a>
                <a href="{{ route('sku-checker.index') }}"
                   class="bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors">
                    + New Check
                </a>
            </div>
        </div>

        @if($recentSkuChecks->isEmpty())
        <div class="px-6 py-8 text-center text-gray-400 text-sm">
            No SKU checks yet. <a href="{{ route('sku-checker.index') }}" class="text-brand-600 hover:underline">Run your first check</a>
        </div>
        @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-6 py-3 text-left">Date</th>
                    <th class="px-6 py-3 text-left">Store</th>
                    <th class="px-6 py-3 text-center">Total SKUs</th>
                    <th class="px-6 py-3 text-center">Available</th>
                    <th class="px-6 py-3 text-center">Not Found</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($recentSkuChecks as $check)
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-6 py-3 text-gray-600">{{ $check->created_at->format('d M Y, h:i A') }}</td>
                    <td class="px-6 py-3 text-gray-600">{{ $check->store?->name ?? '—' }}</td>
                    <td class="px-6 py-3 text-center font-semibold text-gray-800">{{ $check->total_skus }}</td>
                    <td class="px-6 py-3 text-center text-green-600 font-medium">{{ $check->available_count }}</td>
                    <td class="px-6 py-3 text-center text-red-500 font-medium">{{ $check->not_available_count }}</td>
                    <td class="px-6 py-3 text-right">
                        <a href="{{ route('sku-checker.show', $check) }}" class="text-brand-600 hover:underline text-xs">View</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>

    {{-- Recent sessions --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h2 class="font-semibold text-gray-800">Recent Upload Sessions</h2>
            <a href="{{ route('upload.history') }}" class="text-sm text-brand-600 hover:underline">View all</a>
        </div>

        @if ($recentSessions->isEmpty())
        <div class="px-6 py-12 text-center text-gray-400">
            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
            </svg>
            <p>No uploads yet. <a href="{{ route('upload.create') }}" class="text-brand-600 hover:underline">Start your first upload</a></p>
        </div>
        @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-6 py-3 text-left">Session</th>
                    <th class="px-6 py-3 text-left">Size</th>
                    <th class="px-6 py-3 text-center">Total</th>
                    <th class="px-6 py-3 text-center">Uploaded</th>
                    <th class="px-6 py-3 text-center">Skipped</th>
                    <th class="px-6 py-3 text-left">Status</th>
                    <th class="px-6 py-3 text-left">Date</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($recentSessions as $session)
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-6 py-3 font-medium text-gray-800">{{ $session->name }}</td>
                    <td class="px-6 py-3 text-gray-500 capitalize">{{ $session->image_size }}</td>
                    <td class="px-6 py-3 text-center text-gray-700">{{ $session->total_files }}</td>
                    <td class="px-6 py-3 text-center text-green-700 font-medium">{{ $session->uploaded_files }}</td>
                    <td class="px-6 py-3 text-center text-yellow-600">{{ $session->skipped_files }}</td>
                    <td class="px-6 py-3">
                        @php
                            $colors = ['pending' => 'gray', 'processing' => 'brand', 'completed' => 'green', 'failed' => 'red'];
                            $c = $colors[$session->status] ?? 'gray';
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-{{ $c }}-100 text-{{ $c }}-700">
                            {{ ucfirst($session->status) }}
                        </span>
                    </td>
                    <td class="px-6 py-3 text-gray-500">{{ $session->created_at->format('d M Y H:i') }}</td>
                    <td class="px-6 py-3">
                        <a href="{{ route('upload.show', $session) }}" class="text-brand-600 hover:underline text-xs">View</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>

</div>
@endsection
