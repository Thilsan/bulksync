@extends('layouts.app')
@section('title', 'Product Migration - Image')
@section('page-title', 'Product Migration - Image')

@section('content')
<div class="space-y-6" x-data="{
        loading: false, mode: 'text', migrationType: 'images_only', overwrite: false,
        confirmOpen: false, confirmed: false, fromName: '', toName: '', skuCount: 0,
        /* Read off the form as it stands, so the dialog names what is about to happen. */
        openConfirm() {
            this.fromName = this.$refs.fromStore.selectedOptions[0]?.text.trim() || 'the source store';
            this.toName   = this.$refs.toStore.selectedOptions[0]?.text.trim() || 'the target store';
            this.skuCount = this.mode === 'text'
                ? this.$refs.form.querySelector('textarea[name=skus]').value.split(/[\r\n,]+/).filter(s => s.trim()).length
                : 0;
            this.confirmOpen = true;
        },
        proceed() {
            this.confirmed   = true;
            this.confirmOpen = false;
            this.$nextTick(() => this.$refs.form.requestSubmit());
        },
    }">

    {{--
        Confirm before overwriting. Says which store loses what, in the order
        it matters, and what is kept — the browser's own confirm() could only
        carry one grey paragraph and an "OK".
    --}}
    <div x-show="confirmOpen" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="confirmOpen = false"
         role="dialog" aria-modal="true" aria-labelledby="overwrite-confirm-title">

        <div class="absolute inset-0 bg-gray-900/50" @click="confirmOpen = false"></div>

        <div class="relative w-full max-w-md overflow-hidden rounded-xl bg-white shadow-xl"
             x-show="confirmOpen" x-transition.opacity.scale.95>
            <div class="px-5 pt-5 pb-4">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <h2 id="overwrite-confirm-title" class="text-sm font-semibold text-gray-900">
                            Update existing products in <span x-text="toName"></span>?
                        </h2>
                        <p class="mt-1 text-xs text-gray-500">
                            <span x-show="skuCount" x-text="skuCount + (skuCount === 1 ? ' SKU' : ' SKUs') + ' from '"></span><span x-show="!skuCount">SKUs from </span><span class="font-medium text-gray-700" x-text="fromName"></span>.
                            Products <span x-text="toName"></span> doesn't have yet are created as drafts.
                        </p>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg bg-amber-50 px-3 py-2.5">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-700">Replaced</p>
                        <ul class="mt-1.5 space-y-1 text-xs text-amber-900">
                            <li>Title &amp; description</li>
                            <li>Tags, vendor &amp; type</li>
                            <li>SEO title &amp; description</li>
                            <li>Prices &amp; variants</li>
                            <li>All photos</li>
                        </ul>
                    </div>
                    <div class="rounded-lg bg-gray-50 px-3 py-2.5">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Kept as is</p>
                        <ul class="mt-1.5 space-y-1 text-xs text-gray-700">
                            <li>Stock quantities</li>
                            <li>Live / draft status</li>
                            <li>Order history</li>
                        </ul>
                    </div>
                </div>

                <p class="mt-3 flex gap-2 rounded-lg border border-red-100 bg-red-50 px-3 py-2 text-xs text-red-700">
                    <span aria-hidden="true">&bull;</span>
                    <span>
                        Variants <span x-text="fromName"></span> doesn't have are <strong class="font-semibold">deleted</strong>.
                        This can't be undone — try one SKU first if you haven't yet.
                    </span>
                </p>
            </div>

            <div class="flex justify-end gap-2 border-t border-gray-100 bg-gray-50 px-5 py-3">
                <button type="button" @click="confirmOpen = false"
                        class="rounded-lg border border-gray-300 bg-white px-4 py-1.5 text-xs font-medium text-gray-700 transition-colors hover:border-gray-400">
                    Cancel
                </button>
                <button type="button" @click="proceed()"
                        class="rounded-lg bg-amber-600 px-4 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-amber-700">
                    Yes, update products
                </button>
            </div>
        </div>
    </div>

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
                      class="space-y-5 p-5"
                      x-ref="form"
                      @submit="if (migrationType === 'full_product' && overwrite && !confirmed) { $event.preventDefault(); openConfirm(); return; } loading = true">
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

                        {{--
                            Off by default: it overwrites live products. The
                            warning names what is lost, since none of it can
                            be undone from here.
                        --}}
                        <label x-show="migrationType === 'full_product'" x-cloak
                               class="mt-3 flex items-start gap-2 rounded-lg border border-gray-200 px-3 py-2.5 text-xs text-gray-600"
                               :class="overwrite ? 'border-amber-300 bg-amber-50' : ''">
                            <input type="checkbox" name="overwrite_existing" value="1" x-model="overwrite"
                                   class="mt-0.5 rounded border-gray-300">
                            <span>
                                <strong class="font-semibold text-gray-800">Update products the target store already has</strong>
                                <span class="block mt-0.5">Title, description, tags, SEO, variants and photos are replaced with the source store's.</span>
                                <span x-show="overwrite" class="block mt-1 text-amber-700">
                                    Variants the source doesn't have are deleted, and the current photos are removed. Stock and live/draft status are kept. This can't be undone.
                                </span>
                            </span>
                        </label>
                    </div>

                    {{-- Source → target, drawn --}}
                    <div class="grid items-end gap-3 sm:grid-cols-[1fr_auto_1fr]">
                        <div>
                            <label class="mb-1.5 block text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">From</label>
                            <select name="from_store" required x-ref="fromStore"
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
                            <select name="to_store" required x-ref="toStore"
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
