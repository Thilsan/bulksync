@extends('layouts.app')
@section('title', 'Product Migration - Image')
@section('page-title', 'Product Migration - Image')

@section('content')
<div class="space-y-6" x-data="{ loading: false, mode: 'text', migrationType: 'images_only' }">

    {{-- Loading overlay --}}
    <div x-show="loading" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm">
        <div class="bg-white rounded-2xl shadow-2xl px-10 py-8 max-w-sm w-full mx-4 text-center space-y-5">
            <div class="flex justify-center">
                <div class="spinner h-12 w-12"></div>
            </div>
            <div>
                <p class="font-display text-lg text-gray-900">Starting the migration</p>
                <p class="mt-1 text-sm text-gray-500">It continues in the background.</p>
            </div>
        </div>
    </div>

    {{-- Figures --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
        @foreach ([
            ['Runs',        $stats['total_runs'],      'text-gray-900'],
            ['Full product', $stats['full_product'],   'text-brand-600'],
            ['Images only', $stats['images_only'],     'text-gray-900'],
            ['Migrated',    $stats['total_migrated'],  'text-emerald-600'],
            ['Failed',      $stats['total_failed'],    'text-red-500'],
        ] as [$label, $value, $tone])
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <p class="mb-1.5 text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">{{ $label }}</p>
                <p class="figure text-3xl leading-none {{ $tone }}">{{ number_format($value) }}</p>
            </div>
        @endforeach
    </div>

    {{-- Two-column layout: form on the left, Recent Migrations on the right --}}
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 items-start">

        {{-- Left column --}}
        <div class="lg:col-span-3 space-y-6">

            {{--
                The form draws the migration instead of describing it: source
                store on the left, target on the right, the SKUs between them.
                It replaced an info banner and a three-step "What happens"
                list, each written twice — once per mode — which is four
                paragraphs explaining an arrow.
            --}}
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <form method="POST" action="{{ route('store-image-sync.start') }}" enctype="multipart/form-data"
                      class="space-y-5 p-5" @submit="loading = true">
                    @csrf

                    {{-- What is being moved --}}
                    <div>
                        <div class="inline-flex gap-1 rounded-xl bg-gray-100 p-1">
                            <button type="button" @click="migrationType = 'images_only'"
                                :class="migrationType === 'images_only' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
                                class="rounded-lg px-4 py-1.5 text-xs font-semibold transition-all">Images only</button>
                            <button type="button" @click="migrationType = 'full_product'"
                                :class="migrationType === 'full_product' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
                                class="rounded-lg px-4 py-1.5 text-xs font-semibold transition-all">Full product</button>
                        </div>
                        <input type="hidden" name="migration_type" :value="migrationType">

                        {{-- The one consequence that differs between the two. --}}
                        <p class="mt-2 text-xs text-gray-500">
                            <span x-show="migrationType === 'images_only'">The product must already exist in both stores.</span>
                            <span x-show="migrationType === 'full_product'" x-cloak>
                                Missing products are created in the target store as <strong class="font-semibold text-gray-700">drafts</strong>. Never published automatically.
                            </span>
                        </p>
                    </div>

                    {{-- Source → target, drawn --}}
                    <div class="grid items-end gap-3 sm:grid-cols-[1fr_auto_1fr]">
                        <div>
                            <label class="mb-1.5 block text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">From</label>
                            <select name="from_store" required
                                class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                                <option value="">Source store</option>
                                @foreach($stores as $store)
                                    <option value="{{ $store->id }}" {{ old('from_store') == $store->id ? 'selected' : '' }}>{{ $store->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="hidden pb-2.5 text-gray-300 sm:block">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                            </svg>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">To</label>
                            <select name="to_store" required
                                class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                                <option value="">Target store</option>
                                @foreach($stores as $store)
                                    <option value="{{ $store->id }}" {{ old('to_store') == $store->id ? 'selected' : '' }}>{{ $store->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    @error('from_store') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    @error('to_store')   <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                    {{-- The SKUs that travel between them --}}
                    <div x-data="{ skus: @js(old('skus', '')) }">
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <div class="inline-flex gap-1 rounded-xl bg-gray-100 p-1">
                                <button type="button" @click="mode = 'text'"
                                    :class="mode === 'text' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
                                    class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-all">Paste</button>
                                <button type="button" @click="mode = 'csv'"
                                    :class="mode === 'csv' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
                                    class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-all">Upload CSV</button>
                            </div>
                            <p x-show="mode === 'text' && skus.trim()" x-cloak class="text-xs text-gray-400">
                                <span class="figure text-gray-700" x-text="skus.split(/[\r\n,]+/).filter(s => s.trim()).length"></span> to migrate
                            </p>
                        </div>

                        <div x-show="mode === 'text'">
                            <textarea name="skus" rows="8" x-model="skus"
                                placeholder="CDP103TTP00273&#10;ELC103ACC00016&#10;DCO103TTP00251"
                                class="w-full resize-none rounded-lg border border-gray-300 px-4 py-3 font-mono text-sm leading-relaxed transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500"></textarea>
                        </div>

                        <div x-show="mode === 'csv'" x-cloak>
                            <input type="file" name="csv_file" accept=".csv,.txt"
                                class="w-full rounded-lg border border-dashed border-gray-300 px-4 py-8 text-sm transition file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-brand-700 hover:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500">
                            <p class="mt-2 text-xs text-gray-400">First column, header skipped.</p>
                        </div>
                    </div>

                    @error('skus') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                    <button type="submit"
                        class="rounded-lg bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700"
                        x-text="migrationType === 'full_product' ? 'Start product migration' : 'Start image sync'"></button>
                </form>
            </div>
        </div>

        {{-- Right column: Recent Migrations --}}
        <div class="lg:col-span-2">
            @if($recentSessions->isNotEmpty())
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-base font-semibold text-gray-800">Recent Migrations</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-b border-gray-100">
                            <tr>
                                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Date</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">User</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">From → To</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Type</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">SKUs</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($recentSessions as $session)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $session->created_at->format('d M H:i') }}</td>
                                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $session->user?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $session->fromStore?->name ?? '—' }} → {{ $session->toStore?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $session->migration_type === 'full_product' ? 'Full Product' : 'Images Only' }}</td>
                                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $session->success_count }}/{{ $session->total_skus }} ok
                                    @if($session->failed_count > 0)<span class="text-red-500">, {{ $session->failed_count }} failed</span>@endif
                                </td>
                                <td class="px-4 py-3">
                                    @php
                                        $colors = ['running' => 'bg-blue-100 text-blue-700', 'completed' => 'bg-emerald-100 text-emerald-700', 'failed' => 'bg-red-100 text-red-700'];
                                    @endphp
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $colors[$session->status] ?? 'bg-gray-100 text-gray-600' }}">
                                        {{ ucfirst($session->status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('store-image-sync.show', $session->token) }}" class="text-brand-600 hover:text-brand-800 font-medium text-xs">View →</a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($recentSessions->hasPages())
                <div class="px-6 py-4 border-t border-gray-100">{{ $recentSessions->links() }}</div>
                @endif
            </div>
            @else
            <div class="bg-white rounded-xl border border-gray-200 p-8 text-center text-sm text-gray-400">
                No migrations yet. Your first run will show up here.
            </div>
            @endif
        </div>

    </div>

</div>
@endsection
