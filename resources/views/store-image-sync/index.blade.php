@extends('layouts.app')
@section('title', 'Product Migration - Image')
@section('page-title', 'Product Migration - Image')

@section('content')
<div class="max-w-3xl mx-auto space-y-6" x-data="{ loading: false, mode: 'text', migrationType: 'images_only' }">

    {{-- Loading overlay --}}
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
                <p class="text-gray-900 font-semibold text-lg">Starting sync…</p>
                <p class="text-gray-500 text-sm mt-1">Images will be copied in the background.</p>
            </div>
        </div>
    </div>

    {{-- Info banner --}}
    <div class="bg-blue-50 border border-blue-100 rounded-xl px-5 py-4 flex gap-3 text-sm text-blue-800">
        <svg class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p x-show="migrationType === 'images_only'">Provide the SKUs you want to copy. The system will find those products in the <strong>source store</strong>, fetch their images, and upload them to the matching products in the <strong>target store</strong>. The product must already exist in both stores.</p>
        <p x-show="migrationType === 'full_product'" x-cloak>Provide the SKUs you want to migrate. For any SKU that doesn't exist yet in the <strong>target store</strong>, the system creates the full product there (title, description, variants, price, stock, images) as a <strong>draft</strong> — you review and publish it manually. SKUs that already exist in the target store just get their images synced instead.</p>
    </div>

    {{-- Form --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800 text-lg">Copy Images by SKU</h2>
            <p class="text-sm text-gray-500 mt-1">Select source and target stores, then enter the SKUs to sync.</p>
        </div>

        <form method="POST" action="{{ route('store-image-sync.start') }}" enctype="multipart/form-data"
              class="px-6 py-5 space-y-5" @submit="loading = true">
            @csrf

            {{-- Migration type --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Migration Type</label>
                <div class="flex gap-2">
                    <button type="button"
                        @click="migrationType = 'images_only'"
                        :class="migrationType === 'images_only' ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'"
                        class="px-4 py-2 rounded-lg border text-sm font-medium transition-colors">
                        Images Only
                    </button>
                    <button type="button"
                        @click="migrationType = 'full_product'"
                        :class="migrationType === 'full_product' ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'"
                        class="px-4 py-2 rounded-lg border text-sm font-medium transition-colors">
                        Full Product Migration
                    </button>
                </div>
                <input type="hidden" name="migration_type" :value="migrationType">
                <p class="text-xs text-gray-400 mt-1.5" x-show="migrationType === 'full_product'" x-cloak>
                    Creates missing products as <strong>drafts</strong> in the target store — copies price, stock, variants, images, tags, matching collections, and Material/Features. Never auto-publishes.
                </p>
            </div>

            {{-- Store selectors --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">
                        From Store <span class="text-gray-400 font-normal">(has images)</span>
                    </label>
                    <select name="from_store" required
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 bg-white">
                        <option value="">— Select source store —</option>
                        @foreach($stores as $store)
                            <option value="{{ $store->id }}" {{ old('from_store') == $store->id ? 'selected' : '' }}>
                                {{ $store->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">
                        To Store <span class="text-gray-400 font-normal">(needs images)</span>
                    </label>
                    <select name="to_store" required
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 bg-white">
                        <option value="">— Select target store —</option>
                        @foreach($stores as $store)
                            <option value="{{ $store->id }}" {{ old('to_store') == $store->id ? 'selected' : '' }}>
                                {{ $store->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            @error('to_store')
                <p class="text-red-600 text-xs">{{ $message }}</p>
            @enderror

            {{-- SKU input toggle --}}
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

            {{-- Text SKUs --}}
            <div x-show="mode === 'text'">
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                    SKUs <span class="text-gray-400 font-normal">(one per line or comma separated)</span>
                </label>
                <textarea name="skus" rows="7"
                    placeholder="CDP103TTP00273&#10;ELC103ACC00016&#10;DCO103TTP00251"
                    class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent resize-none">{{ old('skus') }}</textarea>
            </div>

            {{-- CSV upload --}}
            <div x-show="mode === 'csv'" x-cloak>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                    CSV File <span class="text-gray-400 font-normal">(first column = SKU)</span>
                </label>
                <input type="file" name="csv_file" accept=".csv,.txt"
                    class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                <p class="text-xs text-gray-400 mt-1.5">Header row is automatically skipped.</p>
            </div>

            @error('skus')
                <p class="text-red-600 text-xs">{{ $message }}</p>
            @enderror

            <div>
                <button type="submit"
                    class="bg-brand-600 hover:bg-brand-700 text-white px-6 py-2.5 rounded-lg text-sm font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                    Start Image Sync
                </button>
            </div>
        </form>
    </div>

    {{-- How it works --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">What happens</p>

        <div class="space-y-3" x-show="migrationType === 'images_only'">
            <div class="flex items-start gap-3">
                <span class="shrink-0 w-6 h-6 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center">1</span>
                <p class="text-sm text-gray-600">For each SKU, the system finds the product in the <strong>source store</strong> and fetches all its images.</p>
            </div>
            <div class="flex items-start gap-3">
                <span class="shrink-0 w-6 h-6 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center">2</span>
                <p class="text-sm text-gray-600">The same SKU is found in the <strong>target store</strong> and images are uploaded to that product.</p>
            </div>
            <div class="flex items-start gap-3">
                <span class="shrink-0 w-6 h-6 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center">3</span>
                <p class="text-sm text-gray-600">Images are permanently added to the target store — visible in Shopify admin and on the live website immediately.</p>
            </div>
            <div class="flex items-start gap-3">
                <span class="shrink-0 w-6 h-6 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center">4</span>
                <p class="text-sm text-gray-600">Download the result CSV showing how many images were copied per SKU and any errors.</p>
            </div>
        </div>

        <div class="space-y-3" x-show="migrationType === 'full_product'" x-cloak>
            <div class="flex items-start gap-3">
                <span class="shrink-0 w-6 h-6 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center">1</span>
                <p class="text-sm text-gray-600">For each SKU, the system checks the <strong>target store</strong> first — if it already exists there, only images are synced (same as Images Only mode).</p>
            </div>
            <div class="flex items-start gap-3">
                <span class="shrink-0 w-6 h-6 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center">2</span>
                <p class="text-sm text-gray-600">If the SKU doesn't exist in the target store, the full product is fetched from the <strong>source store</strong> — title, description, all variants (SKU/price/stock/options), and images.</p>
            </div>
            <div class="flex items-start gap-3">
                <span class="shrink-0 w-6 h-6 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center">3</span>
                <p class="text-sm text-gray-600">A new product is created in the target store as a <strong>draft</strong> (never live automatically) — with tags, matching collections, and Material/Features metafields also copied over.</p>
            </div>
            <div class="flex items-start gap-3">
                <span class="shrink-0 w-6 h-6 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center">4</span>
                <p class="text-sm text-gray-600">You review the new draft product in Shopify admin — check price/stock currency, then publish it manually when ready.</p>
            </div>
        </div>
    </div>

</div>
@endsection
