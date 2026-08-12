@extends('layouts.app')

@section('title', 'AI Content')
@section('page-title', 'New AI Content')

@section('content')
{{-- The source radios are visually hidden, so the card itself has to show keyboard focus. --}}
<style>
    .mode-card:focus-within { outline: 2px solid #439fc1; outline-offset: 2px; }
</style>

{{-- Full-bleed, matching the AI content dashboard --}}
<div x-data="{
        inputType: '{{ old('input_type', 'sku_list') }}',
        skus: @js(old('sku_raw', '')),
        csvName: '',
        get skuCount() {
            return this.skus.split('\n').map(s => s.trim()).filter(s => s.length).length;
        },
     }">

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] xl:grid-cols-[minmax(0,1fr)_23rem]">

        {{-- ─────────────────────────── Main column ─────────────────────────── --}}
        <form method="POST" action="{{ route('ai-content.store') }}" enctype="multipart/form-data"
              class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            @csrf

            {{-- Source --}}
            <fieldset class="px-6 py-5">
                <legend class="text-sm font-semibold text-gray-800">Which products should be written?</legend>
                <p class="mt-1 text-sm text-gray-500">The generator reads each product's images from Shopify, then writes from what it sees.</p>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <label class="mode-card cursor-pointer rounded-xl border p-4 transition-colors"
                           :class="inputType === 'sku_list'
                               ? 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500'
                               : 'border-gray-200 hover:border-gray-300'">
                        <input type="radio" name="input_type" value="sku_list" x-model="inputType" class="sr-only"
                               @checked(old('input_type', 'sku_list') === 'sku_list')>
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-sm font-semibold text-gray-800">Paste SKUs</span>
                            <svg class="h-4 w-4 shrink-0 text-brand-600" x-show="inputType === 'sku_list'" x-cloak
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                        <p class="mt-1 text-xs leading-relaxed text-gray-500">One SKU per line. Best for a handful of products.</p>
                    </label>

                    <label class="mode-card cursor-pointer rounded-xl border p-4 transition-colors"
                           :class="inputType === 'csv_upload'
                               ? 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500'
                               : 'border-gray-200 hover:border-gray-300'">
                        <input type="radio" name="input_type" value="csv_upload" x-model="inputType" class="sr-only"
                               @checked(old('input_type') === 'csv_upload')>
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-sm font-semibold text-gray-800">Upload a CSV</span>
                            <svg class="h-4 w-4 shrink-0 text-brand-600" x-show="inputType === 'csv_upload'" x-cloak
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                        <p class="mt-1 text-xs leading-relaxed text-gray-500">SKUs in the first column. Best for a whole collection.</p>
                    </label>
                </div>
            </fieldset>

            {{-- SKU list --}}
            <div class="border-t border-gray-100 px-6 py-5" x-show="inputType === 'sku_list'">
                <div class="flex items-baseline justify-between gap-2">
                    <label for="sku_raw" class="text-sm font-medium text-gray-700">
                        SKU list <span class="font-normal text-gray-400">(one per line)</span>
                    </label>
                    {{-- Counting as you type beats discovering the number after submitting --}}
                    <span class="text-xs tabular-nums text-gray-400" x-show="skuCount > 0" x-cloak>
                        <span class="font-semibold text-brand-700" x-text="skuCount"></span>
                        <span x-text="skuCount === 1 ? 'SKU' : 'SKUs'"></span>
                    </span>
                </div>
                {{-- Old input is echoed inline as well as bound, so a validation
                     error never loses the list if Alpine is slow to boot. --}}
                <textarea id="sku_raw" name="sku_raw" rows="10" x-model="skus"
                          placeholder="SKU001&#10;SKU002&#10;SKU003"
                          class="mt-1.5 w-full resize-y rounded-lg border px-3.5 py-2.5 font-mono text-sm transition focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15 {{ $errors->has('sku_raw') ? 'border-red-400' : 'border-gray-300' }}">{{ old('sku_raw') }}</textarea>
                @error('sku_raw')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
                <p class="mt-1.5 text-xs text-gray-400">Duplicates are removed and everything is upper-cased before the lookup.</p>
            </div>

            {{-- CSV --}}
            <div class="border-t border-gray-100 px-6 py-5" x-show="inputType === 'csv_upload'" x-cloak>
                <label for="csv_file" class="text-sm font-medium text-gray-700">CSV file</label>
                <label for="csv_file"
                       class="mt-1.5 flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed border-gray-300 px-6 py-8 text-center transition-colors hover:border-brand-400 hover:bg-brand-50/40">
                    <svg class="h-7 w-7 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    <span class="text-sm font-medium text-gray-700" x-show="!csvName">Choose a CSV file</span>
                    <span class="text-sm font-semibold text-brand-700" x-show="csvName" x-cloak x-text="csvName"></span>
                    <span class="text-xs text-gray-400">.csv or .txt, up to 10 MB</span>
                    <input id="csv_file" type="file" name="csv_file" accept=".csv,.txt" class="sr-only"
                           @change="csvName = $event.target.files[0] ? $event.target.files[0].name : ''">
                </label>
                @error('csv_file')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
                <p class="mt-1.5 text-xs text-gray-400">
                    The first column should hold the SKUs. A header row such as
                    <code class="rounded bg-gray-100 px-1 py-0.5">SKU</code> or
                    <code class="rounded bg-gray-100 px-1 py-0.5">Variant SKU</code> is optional.
                </p>
            </div>

            {{-- Actions --}}
            <div class="flex items-center justify-between gap-3 border-t border-gray-100 bg-gray-50 px-6 py-4">
                <a href="{{ route('ai-content.dashboard') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                <button type="submit"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    Generate content
                </button>
            </div>
        </form>

        {{-- ─────────────────────────── Helper column ─────────────────────────── --}}
        <aside class="space-y-4 lg:sticky lg:top-6">

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-800">What you get</h2>
                </div>
                <ul class="divide-y divide-gray-100 text-xs">
                    <li class="px-4 py-3">
                        <p class="font-medium text-gray-800">Product description</p>
                        <p class="mt-0.5 text-gray-500">Written from the product's own photos.</p>
                    </li>
                    <li class="px-4 py-3">
                        <p class="font-medium text-gray-800">Title, tags and collections</p>
                        <p class="mt-0.5 text-gray-500">Suggested, for you to accept or edit.</p>
                    </li>
                    <li class="px-4 py-3">
                        <p class="font-medium text-gray-800">Meta title and description</p>
                        <p class="mt-0.5 text-gray-500">Search metadata, sized for Google.</p>
                    </li>
                    <li class="px-4 py-3">
                        <p class="font-medium text-gray-800">Arabic translation</p>
                        <p class="mt-0.5 text-gray-500">Run as a second pass, on request.</p>
                    </li>
                </ul>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-800">Before you start</h2>
                </div>
                <ul class="divide-y divide-gray-100 text-xs">
                    <li class="flex gap-2.5 px-4 py-3">
                        <svg class="mt-px h-3.5 w-3.5 shrink-0 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        <span class="text-gray-600">
                            Reading from
                            <strong class="font-semibold text-gray-800">{{ $activeStore?->name ?? 'no store selected' }}</strong>
                            — switch stores from the top bar.
                        </span>
                    </li>
                    <li class="flex gap-2.5 px-4 py-3">
                        <svg class="mt-px h-3.5 w-3.5 shrink-0 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        <span class="text-gray-600">Nothing reaches Shopify until you review it and press push.</span>
                    </li>
                    <li class="flex gap-2.5 px-4 py-3">
                        <svg class="mt-px h-3.5 w-3.5 shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.25h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                        </svg>
                        <span class="text-gray-600">Products with no images can't be written — they come back as errors.</span>
                    </li>
                    <li class="flex gap-2.5 px-4 py-3">
                        <svg class="mt-px h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span class="text-gray-600">
                            Testing? Use SKU
                            <code class="rounded bg-gray-100 px-1 py-0.5 font-mono text-[11px] text-gray-700">GAT207LUG00139</code>
                        </span>
                    </li>
                </ul>
            </div>
        </aside>
    </div>
</div>
@endsection
