@extends('layouts.app')
@section('title', 'SKU Checker')
@section('page-title', 'SKU Checker')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    {{-- Input card --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800 text-lg">Check SKU Availability</h2>
            <p class="text-sm text-gray-500 mt-1">Enter SKUs manually or upload a CSV to check if they exist in your Shopify store.</p>
        </div>

        <form method="POST" action="{{ route('sku-checker.check') }}" enctype="multipart/form-data"
              class="px-6 py-5 space-y-5" x-data="{ mode: 'text' }">
            @csrf

            {{-- Toggle --}}
            <div class="flex gap-2">
                <button type="button"
                    @click="mode = 'text'"
                    :class="mode === 'text' ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'"
                    class="px-4 py-2 rounded-lg border text-sm font-medium transition-colors">
                    Type SKUs
                </button>
                <button type="button"
                    @click="mode = 'csv'"
                    :class="mode === 'csv' ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'"
                    class="px-4 py-2 rounded-lg border text-sm font-medium transition-colors">
                    Upload CSV
                </button>
            </div>

            {{-- Text input --}}
            <div x-show="mode === 'text'">
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                    SKUs <span class="text-gray-400 font-normal">(one per line or comma separated)</span>
                </label>
                <textarea name="skus" rows="6"
                    placeholder="ABC001&#10;ABC002&#10;ABC003"
                    class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent resize-none">{{ old('skus') }}</textarea>
            </div>

            {{-- CSV upload --}}
            <div x-show="mode === 'csv'" x-cloak>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                    CSV File <span class="text-gray-400 font-normal">(first column should be SKU)</span>
                </label>
                <input type="file" name="csv_file" accept=".csv,.txt"
                    class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                <p class="text-xs text-gray-400 mt-1.5">CSV format: SKU in the first column. Header row is automatically skipped.</p>
            </div>

            @error('skus')
                <p class="text-red-600 text-xs">{{ $message }}</p>
            @enderror

            <div>
                <button type="submit"
                    class="bg-brand-600 hover:bg-brand-700 text-white px-6 py-2.5 rounded-lg text-sm font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                    Check SKUs
                </button>
            </div>
        </form>
    </div>

    {{-- Results --}}
    @isset($results)
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-gray-800 text-lg">Results</h2>
                <div class="flex items-center gap-4 mt-1">
                    <span class="text-xs text-gray-500">Total: <strong>{{ count($results) }}</strong></span>
                    <span class="text-xs text-green-600 font-medium">✓ Available: {{ $available }}</span>
                    <span class="text-xs text-red-500 font-medium">✗ Not Available: {{ $notAvailable }}</span>
                </div>
            </div>
            @if(count($results))
            <a href="{{ route('sku-checker.download') }}"
               class="flex items-center gap-2 bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Download CSV
            </a>
            @endif
        </div>

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
                    @foreach($results as $i => $row)
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-6 py-3 text-gray-400 text-xs">{{ $i + 1 }}</td>
                        <td class="px-6 py-3 font-mono text-gray-800 font-medium">{{ $row['sku'] }}</td>
                        <td class="px-6 py-3">
                            @if($row['available'])
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
                        <td class="px-6 py-3 text-gray-600">{{ $row['product_title'] ?: '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endisset

</div>
@endsection
