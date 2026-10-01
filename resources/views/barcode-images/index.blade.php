@extends('layouts.app')
@section('title', 'Barcode Image Grabber')
@section('page-title', 'Barcode Image Grabber')

@section('content')
<div class="space-y-5" x-data="{ loading: false }">

    {{-- Submitting overlay. The run continues on the queue, so this only has
         to cover the redirect. --}}
    <div x-show="loading" x-cloak
         class="fixed inset-0 z-50 grid place-items-center bg-slate-900/50 backdrop-blur-sm">
        <div class="rounded-2xl bg-white px-10 py-8 text-center shadow-2xl">
            <svg class="mx-auto h-10 w-10 animate-spin text-brand-600" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-80" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            <p class="mt-4 font-display text-lg text-gray-900">Starting the grab</p>
            <p class="mt-1 text-sm text-gray-500">It continues in the background.</p>
        </div>
    </div>

    {{-- ── Figures ─────────────────────────────────────────────────────── --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="mb-2 text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">Runs</p>
            <p class="figure text-3xl text-gray-900">{{ number_format($totals->runs) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="mb-2 text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">Barcodes looked up</p>
            <p class="figure text-3xl text-gray-900">{{ number_format($totals->barcodes) }}</p>
        </div>
        <div class="rounded-xl border border-green-100 bg-white p-5">
            <p class="mb-2 text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">With pictures</p>
            <p class="figure text-3xl text-green-600">{{ number_format($totals->found) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="mb-2 text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">Images downloaded</p>
            <p class="figure text-3xl text-gray-900">{{ number_format($totals->images) }}</p>
        </div>
    </div>

    <div class="grid gap-5 xl:grid-cols-12">

        {{-- ── The grab itself ─────────────────────────────────────────── --}}
        <div class="xl:col-span-7 2xl:col-span-8">
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-5 py-4">
                    <h2 class="font-semibold text-gray-800">Grab product images</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        Give the website and the internal barcodes. Each barcode gets its own
                        folder with that product's pictures inside, and the whole lot comes
                        back as one ZIP.
                    </p>
                </div>

                <form method="POST" action="{{ route('barcode-images.start') }}"
                      enctype="multipart/form-data" class="space-y-4 p-5"
                      x-data="{ mode: 'text', barcodes: @js(old('barcodes', '')) }" @submit="loading = true">
                    @csrf

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500">Websites</label>
                            <textarea name="site_url" rows="3"
                                      placeholder="albertoshop.de&#10;herrenausstatter.de"
                                      class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500">{{ old('site_url') }}</textarea>
                            <p class="mt-1.5 text-xs text-gray-400">
                                One per line, up to five. Each barcode tries them in order and stops at the first
                                site with pictures, so a site that is blocked or does not stock an article no
                                longer empties the whole run. Include the country if the site has one in its
                                address — <span class="font-mono text-gray-500">luisaspagnoli.com/en/qa</span> —
                                or it will answer from wherever this server happens to be.
                            </p>
                            @error('site_url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500">Name this run <span class="font-normal normal-case text-gray-400">(optional)</span></label>
                            <input type="text" name="name" value="{{ old('name') }}"
                                   placeholder="Autumn drop — Gucci"
                                   class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500">
                            <p class="mt-1.5 text-xs text-gray-400">Becomes the ZIP's filename.</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-3 border-t border-gray-100 pt-4">
                        <div class="inline-flex rounded-lg bg-gray-100 p-1">
                            <button type="button" @click="mode = 'text'"
                                    :class="mode === 'text' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                                    class="rounded-md px-3 py-1.5 text-xs font-semibold transition-all">Paste</button>
                            <button type="button" @click="mode = 'csv'"
                                    :class="mode === 'csv' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                                    class="rounded-md px-3 py-1.5 text-xs font-semibold transition-all">Upload CSV</button>
                        </div>

                        <p x-show="mode === 'text' && barcodes.trim()" x-cloak class="text-xs text-gray-400">
                            <span class="figure text-gray-700"
                                  x-text="barcodes.split(/[\r\n,;\t]+/).filter(s => s.trim()).length"></span> to look up
                        </p>
                    </div>

                    <div x-show="mode === 'text'">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500">Internal barcodes</label>
                        <textarea name="barcodes" rows="12" x-model="barcodes"
                                  placeholder="8806094582161&#10;3614273065580&#10;0888066093149"
                                  class="w-full resize-none rounded-lg border border-gray-300 px-4 py-3 font-mono text-sm leading-relaxed transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500"></textarea>
                        <p class="mt-1.5 text-xs text-gray-400">One per line. Commas, semicolons and tabs work too.</p>
                    </div>

                    <div x-show="mode === 'csv'" x-cloak>
                        <input type="file" name="csv_file" accept=".csv,.txt"
                               class="w-full rounded-lg border border-dashed border-gray-300 px-4 py-8 text-sm transition file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-brand-700 hover:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500">
                        <p class="mt-2 text-xs text-gray-400">First column, header row skipped.</p>
                    </div>

                    @error('barcodes') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                    <button type="submit"
                            class="rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700">
                        Grab the images
                    </button>
                </form>
            </div>
        </div>

        {{-- ── Recent runs ─────────────────────────────────────────────── --}}
        <div class="xl:col-span-5 2xl:col-span-4">
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                    <h2 class="font-semibold text-gray-800">Recent runs</h2>
                    <a href="{{ route('barcode-images.history') }}" class="text-xs font-medium text-brand-600 hover:text-brand-800">All runs</a>
                </div>

                @if($recent->isEmpty())
                    <p class="px-5 py-12 text-center text-sm text-gray-400">Nothing grabbed yet.</p>
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach($recent as $run)
                            @php
                                $colours = ['pending' => 'gray', 'running' => 'brand', 'completed' => 'green', 'failed' => 'red'];
                                $c = $colours[$run->status] ?? 'gray';
                            @endphp
                            <li class="px-5 py-3.5">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <a href="{{ route('barcode-images.show', $run) }}"
                                           class="block truncate text-sm font-medium text-gray-800 hover:text-brand-600">
                                            {{ $run->name ?: parse_url($run->site_url, PHP_URL_HOST) }}
                                        </a>
                                        <p class="mt-0.5 truncate text-xs text-gray-400">
                                            {{ number_format($run->total_barcodes) }} barcodes ·
                                            {{ number_format($run->images_downloaded) }} images ·
                                            {{ $run->created_at->diffForHumans() }}
                                        </p>
                                    </div>
                                    <span class="shrink-0 rounded-full bg-{{ $c }}-100 px-2 py-0.5 text-xs font-medium text-{{ $c }}-700">
                                        {{ ucfirst($run->status) }}
                                    </span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
