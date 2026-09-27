@extends('layouts.app')
@section('title', 'SKU Checker')
@section('page-title', 'SKU Checker')

@section('content')
@php
    $coverage = $totals->skus > 0 ? (int) round($totals->mapped / $totals->skus * 100) : null;
@endphp

<div class="space-y-5" x-data="{ loading: false, tab: 'check' }">

    {{-- Submitting overlay --}}
    <div x-show="loading" x-cloak
         class="fixed inset-0 z-50 grid place-items-center bg-slate-900/50 backdrop-blur-sm">
        <div class="rounded-2xl bg-white px-10 py-8 text-center shadow-2xl">
            <svg class="mx-auto h-10 w-10 animate-spin text-brand-600" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-80" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            <p class="mt-4 font-display text-lg text-gray-900">Starting the check</p>
            <p class="mt-1 text-sm text-gray-500">It continues in the background.</p>
        </div>
    </div>

    {{-- ── Figures ─────────────────────────────────────────────────────── --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="mb-2 text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">Checks run</p>
            <p class="figure text-3xl text-gray-900">{{ number_format($totals->runs) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="mb-2 text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">SKUs checked</p>
            <p class="figure text-3xl text-gray-900">{{ number_format($totals->skus) }}</p>
        </div>
        <div class="rounded-xl border border-green-100 bg-white p-5">
            <p class="mb-2 text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">Mapped</p>
            <p class="figure text-3xl text-green-600">{{ number_format($totals->mapped) }}</p>
        </div>
        <div class="rounded-xl border border-red-100 bg-white p-5">
            <p class="mb-2 text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">Not mapped</p>
            <p class="figure text-3xl text-red-500">{{ number_format($totals->missing) }}</p>
        </div>
    </div>

    <div class="grid gap-5 xl:grid-cols-12">

        {{-- ── The check itself ────────────────────────────────────────── --}}
        <div class="xl:col-span-7 2xl:col-span-8">
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">

                {{-- Tabs --}}
                <div class="flex items-center gap-1 border-b border-gray-100 px-4 pt-4">
                    <button type="button" @click="tab = 'check'"
                            :class="tab === 'check'
                                ? 'border-brand-600 text-gray-900'
                                : 'border-transparent text-gray-400 hover:text-gray-600'"
                            class="border-b-2 px-3 pb-3 text-sm font-semibold transition-colors">
                        Check SKUs
                    </button>
                    <button type="button" @click="tab = 'compare'"
                            :class="tab === 'compare'
                                ? 'border-brand-600 text-gray-900'
                                : 'border-transparent text-gray-400 hover:text-gray-600'"
                            class="border-b-2 px-3 pb-3 text-sm font-semibold transition-colors">
                        Compare a Shopify export
                    </button>
                </div>

                {{-- Live check --}}
                <form x-show="tab === 'check'" method="POST" action="{{ route('sku-checker.check') }}"
                      enctype="multipart/form-data" class="space-y-4 p-5"
                      x-data="{ mode: 'text', skus: @js(old('skus', '')) }" @submit="loading = true">
                    @csrf

                    <div class="flex items-center justify-between gap-3">
                        <div class="inline-flex rounded-lg bg-gray-100 p-1">
                            <button type="button" @click="mode = 'text'"
                                    :class="mode === 'text' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                                    class="rounded-md px-3 py-1.5 text-xs font-semibold transition-all">Paste</button>
                            <button type="button" @click="mode = 'csv'"
                                    :class="mode === 'csv' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                                    class="rounded-md px-3 py-1.5 text-xs font-semibold transition-all">Upload CSV</button>
                        </div>

                        <p x-show="mode === 'text' && skus.trim()" x-cloak class="text-xs text-gray-400">
                            <span class="figure text-gray-700"
                                  x-text="skus.split(/[\r\n,]+/).filter(s => s.trim()).length"></span> to check
                        </p>
                    </div>

                    <div x-show="mode === 'text'">
                        <textarea name="skus" rows="12" x-model="skus"
                                  placeholder="ABC001&#10;ABC002&#10;ABC003"
                                  class="w-full resize-none rounded-lg border border-gray-300 px-4 py-3 font-mono text-sm leading-relaxed transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500"></textarea>
                        <button type="button" @click="skus = 'GAT207LUG00139'"
                                class="mt-2 text-xs font-medium text-brand-600 transition-colors hover:text-brand-800">
                            Use a sample SKU
                        </button>
                    </div>

                    <div x-show="mode === 'csv'" x-cloak>
                        <input type="file" name="csv_file" accept=".csv,.txt"
                               class="w-full rounded-lg border border-dashed border-gray-300 px-4 py-8 text-sm transition file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-brand-700 hover:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500">
                        <p class="mt-2 text-xs text-gray-400">First column, header skipped.</p>
                    </div>

                    @error('skus') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                    <button type="submit"
                            class="rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700">
                        Check SKUs
                    </button>
                </form>

                {{-- Offline compare --}}
                <form x-show="tab === 'compare'" x-cloak method="POST" action="{{ route('sku-checker.csv-compare') }}"
                      enctype="multipart/form-data" class="space-y-4 p-5" @submit="loading = true">
                    @csrf

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700">Your SKU list</label>
                            <input type="file" name="my_csv" accept=".csv,.txt" required
                                   class="w-full rounded-lg border border-dashed border-gray-300 px-4 py-6 text-sm transition file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-brand-700 hover:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500">
                            @error('my_csv') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700">Shopify product export</label>
                            <input type="file" name="shopify_csv" accept=".csv,.txt" required
                                   class="w-full rounded-lg border border-dashed border-gray-300 px-4 py-6 text-sm transition file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-brand-700 hover:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500">
                            @error('shopify_csv') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <p class="text-xs text-gray-400">Shopify Admin → Products → Export. Compared in memory, no API calls.</p>

                    <button type="submit"
                            class="rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700">
                        Compare files
                    </button>
                </form>
            </div>
        </div>

        {{-- ── Recent runs ─────────────────────────────────────────────── --}}
        <div class="xl:col-span-5 2xl:col-span-4">
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                    <h2 class="font-display text-lg text-gray-900">Recent checks</h2>
                    <a href="{{ route('sku-checker.history') }}"
                       class="text-xs font-semibold text-brand-600 transition-colors hover:text-brand-800">All checks →</a>
                </div>

                @forelse($recent as $session)
                    @php
                        $done  = $session->status === 'completed';
                        $pct   = $done && $session->total_skus > 0
                            ? (int) round($session->available_count / $session->total_skus * 100)
                            : null;
                        $live  = in_array($session->status, ['running', 'pending'], true);
                    @endphp
                    <a href="{{ route('sku-checker.show', $session) }}"
                       class="flex items-center gap-4 border-b border-gray-50 px-5 py-3.5 transition-colors last:border-0 hover:bg-gray-50/70">
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-2 text-sm font-medium text-gray-800">
                                <span class="h-1.5 w-1.5 shrink-0 rounded-full
                                             {{ $live ? 'pulse-dot bg-brand-500 text-brand-500' : ($session->status === 'failed' ? 'bg-red-400' : 'bg-emerald-400') }}"></span>
                                <span class="figure">{{ number_format($session->total_skus) }}</span>
                                <span class="text-gray-400">SKUs</span>
                            </p>
                            <p class="mt-0.5 truncate text-xs text-gray-400">
                                {{ $session->store?->name ?? 'No store' }} · {{ $session->created_at->diffForHumans(short: true) }}
                            </p>
                        </div>

                        @if($pct !== null)
                            <div class="w-24 shrink-0">
                                <div class="mb-1 flex justify-between text-[11px]">
                                    <span class="figure text-emerald-600">{{ $pct }}%</span>
                                    <span class="text-gray-300">mapped</span>
                                </div>
                                <div class="h-1 overflow-hidden rounded-full bg-gray-100">
                                    <div class="h-1 rounded-full bg-emerald-500" style="width: {{ $pct }}%"></div>
                                </div>
                            </div>
                        @else
                            <span class="shrink-0 text-[11px] font-medium capitalize text-gray-400">{{ $session->status }}</span>
                        @endif
                    </a>
                @empty
                    <p class="px-5 py-10 text-center text-sm text-gray-400">
                        Paste a SKU to run your first check.
                    </p>
                @endforelse
            </div>

            @if($coverage !== null)
                <div class="mt-5 rounded-xl border border-gray-200 bg-white p-5">
                    <div class="mb-3 flex items-baseline justify-between">
                        <p class="text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">Catalogue coverage</p>
                        <p class="figure text-2xl text-gray-900">{{ $coverage }}%</p>
                    </div>
                    <div class="flex h-2 overflow-hidden rounded-full bg-gray-100">
                        <div class="bg-emerald-500 transition-all duration-700" style="width: {{ $coverage }}%"></div>
                        <div class="bg-red-300" style="width: {{ 100 - $coverage }}%"></div>
                    </div>
                    <p class="mt-2.5 text-xs text-gray-400">
                        Across every check you have run.
                    </p>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
