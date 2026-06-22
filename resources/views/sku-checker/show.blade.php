@extends('layouts.app')
@section('title', 'SKU Check Results')
@section('page-title', 'SKU Check Results')

@section('content')
<div class="space-y-6">

    <div class="flex items-center justify-between">
        <a href="{{ route('sku-checker.history') }}" class="text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1">
            ← Back to History
        </a>
        <a href="{{ route('sku-checker.download', $skuCheckSession) }}"
           class="flex items-center gap-2 bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Download CSV
        </a>
    </div>

    {{-- Summary --}}
    <div class="grid grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 px-6 py-4">
            <p class="text-xs text-gray-500 mb-1">Total SKUs</p>
            <p class="text-2xl font-bold text-gray-800">{{ $skuCheckSession->total_skus }}</p>
        </div>
        <div class="bg-white rounded-xl border border-green-100 px-6 py-4">
            <p class="text-xs text-gray-500 mb-1">Available</p>
            <p class="text-2xl font-bold text-green-600">{{ $skuCheckSession->available_count }}</p>
        </div>
        <div class="bg-white rounded-xl border border-red-100 px-6 py-4">
            <p class="text-xs text-gray-500 mb-1">Not Available</p>
            <p class="text-2xl font-bold text-red-500">{{ $skuCheckSession->not_available_count }}</p>
        </div>
    </div>

    {{-- Info --}}
    <div class="text-xs text-gray-400 flex items-center gap-4">
        <span>Checked: {{ $skuCheckSession->created_at->format('d M Y, h:i A') }}</span>
        @if($skuCheckSession->store)
            <span>Store: {{ $skuCheckSession->store->name }}</span>
        @endif
    </div>

    {{-- Results table --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="text-left px-6 py-3 font-medium text-gray-600">#</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">SKU</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">Status</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">Product Title</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($items as $i => $item)
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-6 py-3 text-gray-400 text-xs">{{ $i + 1 }}</td>
                        <td class="px-6 py-3 font-mono text-gray-800 font-medium">{{ $item->sku }}</td>
                        <td class="px-6 py-3">
                            @if($item->available)
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-green-50 text-green-700">
                                    <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                                    Available
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-red-50 text-red-600">
                                    <span class="w-1.5 h-1.5 rounded-full bg-red-400"></span>
                                    Not Available
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-3 text-gray-600">{{ $item->product_title ?: '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
