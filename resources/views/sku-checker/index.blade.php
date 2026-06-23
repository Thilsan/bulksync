@extends('layouts.app')
@section('title', 'SKU Checker')
@section('page-title', 'SKU Checker')

@section('content')
<div class="max-w-4xl mx-auto space-y-6"
     x-data="{ loading: false, mainTab: 'api' }">

    {{-- Full-page loading overlay --}}
    <div x-show="loading" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm">
        <div class="bg-white rounded-2xl shadow-2xl px-10 py-8 max-w-sm w-full mx-4 text-center space-y-5">
            <div class="flex justify-center">
                <svg class="animate-spin h-12 w-12 text-brand-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-80" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                </svg>
            </div>
            <div>
                <p class="text-gray-900 font-semibold text-lg">Submitting SKUs…</p>
                <p class="text-gray-500 text-sm mt-1">Processing will continue in the background.</p>
            </div>
        </div>
    </div>

    <div class="flex justify-end">
        <a href="{{ route('sku-checker.history') }}"
           class="text-sm text-brand-600 hover:text-brand-800 font-medium flex items-center gap-1">
            View History →
        </a>
    </div>

    {{-- Main tab switcher --}}
    <div class="flex gap-1 bg-gray-100 rounded-xl p-1 w-fit">
        <button type="button"
            @click="mainTab = 'api'"
            :class="mainTab === 'api' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700'"
            class="px-5 py-2 rounded-lg text-sm font-medium transition-all">
            SKU API Check
        </button>
        <button type="button"
            @click="mainTab = 'compare'"
            :class="mainTab === 'compare' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700'"
            class="px-5 py-2 rounded-lg text-sm font-medium transition-all">
            Compare with Shopify
        </button>
    </div>

    {{-- ── Tab 1: SKU API Check (existing) ──────────────────────────────── --}}
    <div x-show="mainTab === 'api'" x-cloak>
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100">
                <h2 class="font-semibold text-gray-800 text-lg">Check SKU Availability</h2>
                <p class="text-sm text-gray-500 mt-1">Enter SKUs or upload a CSV. Each SKU is checked live against the Shopify API. Large batches run in the background.</p>
            </div>

            <form method="POST" action="{{ route('sku-checker.check') }}" enctype="multipart/form-data"
                  class="px-6 py-5 space-y-5" x-data="{ mode: 'text' }" @submit="loading = true">
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
                        CSV File <span class="text-gray-400 font-normal">(first column = SKU, up to 20MB)</span>
                    </label>
                    <input type="file" name="csv_file" accept=".csv,.txt"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <p class="text-xs text-gray-400 mt-1.5">Header row (SKU) is automatically skipped. No limit on number of SKUs.</p>
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
    </div>

    {{-- ── Tab 2: Compare with Shopify (new) ────────────────────────────── --}}
    <div x-show="mainTab === 'compare'" x-cloak>
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100">
                <h2 class="font-semibold text-gray-800 text-lg">Compare with Shopify</h2>
                <p class="text-sm text-gray-500 mt-1">Upload your SKU list. The system will automatically fetch all SKUs from Shopify, compare them in memory, and generate a result CSV — no manual Shopify export needed.</p>
            </div>

            {{-- How it works --}}
            <div class="px-6 pt-5">
                <div class="bg-blue-50 border border-blue-100 rounded-lg px-4 py-3 flex gap-3 text-sm text-blue-800">
                    <svg class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div>
                        <strong class="font-semibold">How it works:</strong> Upload your CSV → system fetches all Shopify SKUs in bulk → compares in memory → generates result CSV with Available / Not Available for each of your SKUs.
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('sku-checker.csv-compare') }}" enctype="multipart/form-data"
                  class="px-6 py-5 space-y-5" @submit="loading = true">
                @csrf

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">
                        Your SKU List (CSV)
                        <span class="text-gray-400 font-normal">— first column must be the SKU, up to 20MB</span>
                    </label>
                    <input type="file" name="my_csv" accept=".csv,.txt" required
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <p class="text-xs text-gray-400 mt-1.5">Header row is automatically skipped. Supports 20,000+ SKUs.</p>
                </div>

                @error('my_csv')
                    <p class="text-red-600 text-xs">{{ $message }}</p>
                @enderror

                <div>
                    <button type="submit"
                        class="bg-brand-600 hover:bg-brand-700 text-white px-6 py-2.5 rounded-lg text-sm font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                        Compare with Shopify
                    </button>
                </div>
            </form>
        </div>

        {{-- Steps info card --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5 mt-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">What happens after you click Compare</p>
            <div class="space-y-3">
                <div class="flex items-start gap-3">
                    <span class="shrink-0 w-6 h-6 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center">1</span>
                    <p class="text-sm text-gray-600">Your CSV is uploaded and the job is queued in the background.</p>
                </div>
                <div class="flex items-start gap-3">
                    <span class="shrink-0 w-6 h-6 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center">2</span>
                    <p class="text-sm text-gray-600">The system fetches <strong>all SKUs</strong> from your Shopify store in a single bulk sweep.</p>
                </div>
                <div class="flex items-start gap-3">
                    <span class="shrink-0 w-6 h-6 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center">3</span>
                    <p class="text-sm text-gray-600">Your SKUs are compared against the Shopify set in memory — <strong>no individual API calls</strong> per SKU.</p>
                </div>
                <div class="flex items-start gap-3">
                    <span class="shrink-0 w-6 h-6 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center">4</span>
                    <p class="text-sm text-gray-600">Download the result CSV showing <strong>Available</strong> or <strong>Not Available</strong> for each SKU.</p>
                </div>
            </div>
        </div>
    </div>

</div>
@endsection
