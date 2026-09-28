@extends('layouts.app')
@section('title', 'SKU Check Results')
@section('page-title', 'SKU Check Results')

@section('content')
<div class="space-y-5"
     x-data="skuCheckPage({{ $skuCheckSession->id }}, '{{ $skuCheckSession->status }}')"
     x-init="init()">

    {{-- Back --}}
    <a href="{{ route('sku-checker.history') }}" class="text-sm text-gray-500 hover:text-gray-700">← Back to History</a>

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-[11px] font-medium uppercase tracking-[.12em] text-gray-400 mb-2">Status</p>
            <p class="flex items-center gap-2 text-sm font-semibold capitalize"
               :class="{'text-green-600': status==='completed','text-brand-600': status==='running','text-red-500': status==='failed','text-gray-500': status==='pending'}">
                <span class="h-1.5 w-1.5 rounded-full bg-current"
                      :class="(status==='running' || status==='pending') && 'pulse-dot'"></span>
                <span x-text="status"></span>
            </p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-[11px] font-medium uppercase tracking-[.12em] text-gray-400 mb-2">Total SKUs</p>
            <p class="figure text-3xl text-gray-900" x-text="shown.total.toLocaleString()">{{ $skuCheckSession->total_skus }}</p>
        </div>
        <div class="bg-white rounded-xl border border-green-100 p-5">
            <p class="text-[11px] font-medium uppercase tracking-[.12em] text-gray-400 mb-2">Mapped</p>
            <p class="figure text-3xl text-green-600" x-text="shown.available.toLocaleString()">{{ $skuCheckSession->available_count }}</p>
        </div>
        <div class="bg-white rounded-xl border border-red-100 p-5">
            <p class="text-[11px] font-medium uppercase tracking-[.12em] text-gray-400 mb-2">Not Mapped</p>
            <p class="figure text-3xl text-red-500" x-text="shown.notAvailable.toLocaleString()">{{ $skuCheckSession->not_available_count }}</p>
        </div>
    </div>

    {{-- Progress bar --}}
    <div x-show="status === 'running' || status === 'pending'" class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-3">
            <p class="text-sm font-medium text-gray-700">Checking SKUs in background…</p>
            <p class="text-sm text-gray-500"><span x-text="scanned.toLocaleString()"></span> / <span x-text="totalSkus.toLocaleString()"></span></p>
        </div>
        <div class="w-full bg-gray-100 rounded-full h-2.5 overflow-hidden">
            <div class="bar-live bg-brand-600 h-2.5 rounded-full transition-all duration-700 ease-out"
                 :style="'width: ' + Math.max(progress, 2) + '%'"></div>
        </div>
        <p class="text-xs text-gray-400 mt-2 text-center" x-text="progress + '% complete'"></p>
    </div>

    {{-- Error --}}
    @if($skuCheckSession->status === 'failed')
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700">
        <strong>Check failed:</strong> {{ $skuCheckSession->error_message }}
    </div>
    @endif

    {{-- Download buttons --}}
    <div x-show="status === 'completed'" class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <p class="text-sm font-semibold text-gray-700">Download Results</p>
        <div class="flex flex-wrap gap-3">
            <a href="{{ route('sku-checker.download', $skuCheckSession) }}?filter=not_available"
               class="bg-red-50 hover:bg-red-100 text-red-700 px-4 py-2 rounded-lg text-sm font-medium border border-red-200 transition-colors flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Not Mapped (<span x-text="notAvailable.toLocaleString()"></span>)
            </a>
            <a href="{{ route('sku-checker.download', $skuCheckSession) }}?filter=available"
               class="bg-green-50 hover:bg-green-100 text-green-700 px-4 py-2 rounded-lg text-sm font-medium border border-green-200 transition-colors flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Mapped (<span x-text="available.toLocaleString()"></span>)
            </a>
            <a href="{{ route('sku-checker.download', $skuCheckSession) }}"
               class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Download All
            </a>
        </div>

        {{-- Colour/size CSV: the drill-down for the whole list at once --}}
        <div class="border-t border-gray-100 pt-4 space-y-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-gray-700">Colours &amp; Sizes CSV</p>
                    <p class="text-xs text-gray-500 mt-0.5">
                        One row per variant — colour, size, and whether it has a photo of its own.
                        Costs one Shopify lookup per mapped SKU, so it runs in the background.
                    </p>
                </div>

                <div class="flex gap-2">
                    <button type="button" @click="startVariantExport()"
                            x-show="exportStatus !== 'pending' && exportStatus !== 'running'"
                            class="bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                        <span x-text="exportStatus === 'completed' ? 'Rebuild' : 'Generate'"></span>
                    </button>

                    <a x-show="exportStatus === 'completed'"
                       href="{{ route('sku-checker.variant-export.download', $skuCheckSession) }}"
                       class="bg-green-50 hover:bg-green-100 text-green-700 px-4 py-2 rounded-lg text-sm font-medium border border-green-200 transition-colors flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Download
                    </a>
                </div>
            </div>

            {{-- Running --}}
            <div x-show="exportStatus === 'pending' || exportStatus === 'running'" x-cloak class="space-y-2">
                <div class="flex items-center justify-between text-xs text-gray-500">
                    <span x-text="exportStatus === 'pending' ? 'Queued…' : 'Reading variants from Shopify…'"></span>
                    <span><span x-text="exportScanned.toLocaleString()"></span> / <span x-text="exportTotal.toLocaleString()"></span></span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                    <div class="bg-brand-600 h-2 rounded-full transition-all duration-500" :style="'width: ' + exportProgress + '%'"></div>
                </div>
            </div>

            {{-- A lookup that failed is named, not folded into the file silently --}}
            <div x-show="exportStatus === 'completed' && exportFailed > 0" x-cloak class="text-xs text-amber-700 space-y-1">
                <p>
                    <span x-text="exportFailed.toLocaleString()"></span> SKU(s) could not be read from Shopify and are marked
                    <span class="font-mono">Lookup Failed</span> in the file — rebuild to try those again.
                </p>
                <p x-show="exportError" class="font-mono text-[11px] text-amber-800/80" x-text="exportError"></p>
            </div>

            <p x-show="exportStatus === 'failed'" x-cloak class="text-xs text-red-600" x-text="exportError"></p>
        </div>
    </div>


    {{-- Results table with per-SKU variant breakdown --}}
    <div x-show="status === 'completed'" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100 flex flex-wrap items-center gap-3 justify-between">
            <div>
                <h2 class="font-semibold text-gray-800">Results</h2>
                <p class="text-sm text-gray-500 mt-0.5">Open a mapped SKU to see its colours and sizes, and which of them already have a photo.</p>
            </div>
            <div class="flex items-center gap-2">
                <input type="search" x-model.debounce.400ms="search" @input="page = 1; loadRows()"
                       placeholder="Search SKU…"
                       class="px-3 py-2 border border-gray-300 rounded-lg text-sm w-44 focus:outline-none focus:ring-2 focus:ring-brand-500">
                <select x-model="filter" @change="page = 1; loadRows()"
                        class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="all">All</option>
                    <option value="available">Mapped</option>
                    <option value="not_available">Not mapped</option>
                </select>
            </div>
        </div>

        <div x-show="rowsLoading" class="px-6 py-8 text-center text-sm text-gray-500">Loading results…</div>

        <template x-if="!rowsLoading && rows.length === 0">
            <p class="px-6 py-8 text-center text-sm text-gray-500">No SKUs match this view.</p>
        </template>

        <div x-show="!rowsLoading && rows.length > 0" class="divide-y divide-gray-100">
            <template x-for="row in rows" :key="row.sku">
                <div>
                    {{--
                        The whole row opens it, not a 24px arrow. The arrow was
                        the only hint the row did anything, and it carried no
                        hover state — so a SKU that had colours and sizes behind
                        it looked exactly like one that did not.
                    --}}
                    <div class="flex cursor-pointer items-center gap-3 px-6 py-3 transition-colors"
                         :class="[
                            row.status === 'Available' ? 'hover:bg-brand-50/50' : 'cursor-default',
                            open === row.sku ? 'bg-brand-50/60' : ''
                         ]"
                         role="button" tabindex="0"
                         @click="toggle(row)"
                         @keydown.enter.prevent="toggle(row)"
                         @keydown.space.prevent="toggle(row)">

                        <span class="grid h-6 w-6 shrink-0 place-items-center rounded text-gray-400"
                              :class="row.status !== 'Available' && 'opacity-25'">
                            <svg class="h-4 w-4 transition-transform duration-200" :class="open === row.sku ? 'rotate-90' : ''"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="font-mono text-sm text-gray-800" x-text="row.sku"></p>
                            <p class="truncate text-xs text-gray-500" x-text="row.product_title"></p>
                        </div>

                        {{-- Says what the row does, on the row it does it to. --}}
                        <span x-show="row.status === 'Available'"
                              class="hidden text-xs font-medium text-brand-600 sm:block"
                              x-text="open === row.sku ? 'Hide colours' : 'Colours & sizes'"></span>

                        <span class="rounded-full px-2 py-1 text-xs font-medium"
                              :class="row.status === 'Available' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-600'"
                              x-text="row.status === 'Available' ? 'Mapped' : 'Not mapped'"></span>
                    </div>

                    {{-- Its variants --}}
                    <div x-show="open === row.sku" x-cloak
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0"
                         class="border-t border-brand-100 bg-gray-50/70 px-6 pb-5 pl-14">
                        <template x-if="breakdownLoading">
                            <p class="text-sm text-gray-500 py-3">Reading variants from Shopify…</p>
                        </template>

                        <template x-if="!breakdownLoading && breakdownError">
                            <p class="text-sm text-red-600 py-3" x-text="breakdownError"></p>
                        </template>

                        <template x-if="!breakdownLoading && breakdown">
                            <div class="space-y-3 pt-3">
                                {{--
                                    The same facts the CSV carries, in the same
                                    order: the product, then a row per variant.
                                    The panel used to show only what a colour
                                    covered, so anyone who wanted the variant's
                                    own SKU or id had to build the export.
                                --}}
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <span class="font-medium text-gray-700" x-text="breakdown.product_title"></span>
                                    <span class="rounded-md bg-white px-2 py-0.5 font-mono text-[11px] text-gray-500 ring-1 ring-gray-200"
                                          x-text="'ID ' + breakdown.product_id"></span>
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                          :class="breakdown.published ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500'"
                                          x-text="breakdown.published ? 'Published' : 'Draft'"></span>
                                    <span class="text-gray-400">·</span>
                                    <span class="text-gray-500">
                                        <span class="figure text-gray-800" x-text="breakdown.with_image_count"></span> of
                                        <span class="figure text-gray-800" x-text="breakdown.variant_count"></span> variants have their own photo
                                    </span>
                                    <span class="text-gray-400">·</span>
                                    <span class="text-gray-500">
                                        <span class="figure text-gray-800" x-text="breakdown.gallery_count"></span> in the gallery
                                    </span>
                                </div>

                                <template x-for="colour in breakdown.colours" :key="colour.colour">
                                    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                                        <div class="flex items-center gap-3 border-b border-gray-100 px-4 py-3">
                                            <template x-if="colour.preview">
                                                <img :src="colour.preview" alt=""
                                                     class="h-10 w-10 rounded border border-gray-200 object-cover">
                                            </template>
                                            <template x-if="!colour.preview">
                                                <div class="h-10 w-10 rounded border border-dashed border-gray-300"></div>
                                            </template>

                                            <div class="flex-1">
                                                <p class="text-sm font-medium text-gray-800" x-text="colour.colour"></p>
                                                <p class="text-xs text-gray-500">
                                                    <span x-text="colour.with_image_count"></span> of
                                                    <span x-text="colour.variant_count"></span> size(s) have a photo
                                                </p>
                                            </div>

                                            <span class="rounded-full px-2 py-1 text-xs font-medium"
                                                  :class="colour.with_image_count === 0
                                                      ? 'bg-red-50 text-red-600'
                                                      : (colour.with_image_count === colour.variant_count
                                                          ? 'bg-green-50 text-green-700'
                                                          : 'bg-amber-50 text-amber-700')"
                                                  x-text="colour.with_image_count === 0
                                                      ? 'No image'
                                                      : (colour.with_image_count === colour.variant_count ? 'All sizes covered' : 'Partly covered')"></span>
                                        </div>

                                        {{-- One row per variant, the shape the export uses. --}}
                                        <div class="overflow-x-auto">
                                            <table class="w-full text-xs">
                                                <thead>
                                                    <tr class="bg-gray-50/70 text-left text-[10px] text-gray-400">
                                                        <th class="px-4 py-2 font-semibold">Size</th>
                                                        <th class="px-4 py-2 font-semibold">Variant SKU</th>
                                                        <th class="px-4 py-2 font-semibold">Variant ID</th>
                                                        <th class="px-4 py-2 text-right font-semibold">Photos</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-50">
                                                    <template x-for="size in colour.sizes" :key="size.variant_id">
                                                        <tr :class="size.is_match && 'bg-brand-50/40'">
                                                            <td class="px-4 py-2">
                                                                <span class="inline-flex items-center gap-1.5 font-medium text-gray-800">
                                                                    <svg x-show="size.has_image" class="h-3 w-3 text-emerald-500"
                                                                         fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                                                    </svg>
                                                                    <span x-text="size.size || '—'"></span>
                                                                </span>
                                                                <span x-show="size.is_match"
                                                                      class="ml-1.5 text-[9px] font-semibold uppercase tracking-wide text-brand-600">searched</span>
                                                            </td>
                                                            <td class="px-4 py-2 font-mono text-gray-600" x-text="size.sku || '—'"></td>
                                                            <td class="px-4 py-2 font-mono text-gray-400" x-text="size.variant_id"></td>
                                                            <td class="px-4 py-2 text-right">
                                                                <span class="figure"
                                                                      :class="size.has_image ? 'text-gray-800' : 'text-red-500'"
                                                                      x-text="size.has_image ? size.image_count : 'none'"></span>
                                                            </td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        {{-- Paging --}}
        <div x-show="!rowsLoading && pages > 1" class="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm">
            <p class="text-gray-500">Page <span x-text="page"></span> of <span x-text="pages"></span> — <span x-text="total.toLocaleString()"></span> SKU(s)</p>
            <div class="flex gap-2">
                <button type="button" @click="page = page - 1; loadRows()" :disabled="page <= 1"
                        class="px-3 py-1.5 border border-gray-300 rounded-lg disabled:opacity-40">Previous</button>
                <button type="button" @click="page = page + 1; loadRows()" :disabled="page >= pages"
                        class="px-3 py-1.5 border border-gray-300 rounded-lg disabled:opacity-40">Next</button>
            </div>
        </div>
    </div>

</div>

<script>
function skuCheckPage(sessionId, initialStatus) {
    return {
        status:      initialStatus,
        totalSkus:   {{ $skuCheckSession->total_skus }},
        scanned:     {{ $skuCheckSession->scanned_skus }},
        available:   {{ $skuCheckSession->available_count }},
        notAvailable:{{ $skuCheckSession->not_available_count }},
        progress:    {{ $skuCheckSession->progressPercent() }},
        pollTimer:   null,

        // What the tiles display, walked up to the real figures above.
        shown: {
            total:        {{ $skuCheckSession->total_skus }},
            available:    {{ $skuCheckSession->available_count }},
            notAvailable: {{ $skuCheckSession->not_available_count }},
        },

        // Results table
        rows:        [],
        rowsLoading: false,
        filter:      'all',
        search:      '',
        page:        1,
        pages:       0,
        total:       0,

        // Colours & sizes CSV
        exportStatus:   @json($skuCheckSession->variant_export_status),
        exportTotal:    {{ $skuCheckSession->variant_export_total }},
        exportScanned:  {{ $skuCheckSession->variant_export_scanned }},
        exportFailed:   {{ $skuCheckSession->variant_export_failed }},
        exportProgress: {{ $skuCheckSession->variantExportProgressPercent() }},
        exportError:    @json($skuCheckSession->variant_export_error),
        exportTimer:    null,

        // The open row's colour/size breakdown
        open:             null,
        breakdown:        null,
        breakdownLoading: false,
        breakdownError:   null,

        // One place that moves a tile's figure, so a poll cannot leave two of
        // them counting from different starting points.
        settle(key, to) {
            window.countUp(this.shown[key], to, v => this.shown[key] = v);
        },

        init() {
            this.shown = { total: 0, available: 0, notAvailable: 0 };
            this.settle('total', this.totalSkus);
            this.settle('available', this.available);
            this.settle('notAvailable', this.notAvailable);

            if (this.status !== 'completed' && this.status !== 'failed') {
                this.startPolling();
            } else if (this.status === 'completed') {
                this.loadRows();
            }

            if (this.exportStatus === 'pending' || this.exportStatus === 'running') {
                this.watchVariantExport();
            }
        },

        async startVariantExport() {
            this.exportError   = null;
            this.exportStatus  = 'pending';
            this.exportScanned = 0;

            const res = await fetch(`/sku-checker/${sessionId}/variant-export`, {
                method:  'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=\"csrf-token\"]').content },
            });

            if (!res.ok) {
                const data        = await res.json();
                this.exportStatus = 'failed';
                this.exportError  = data.error || 'Could not start the export.';
                return;
            }

            this.watchVariantExport();
        },

        watchVariantExport() {
            // The check's own poller stops once the check is done, so the export
            // needs its own — it outlives the check by minutes on a long list.
            clearInterval(this.exportTimer);
            this.exportTimer = setInterval(() => this.pollVariantExport(), 3000);
        },

        async pollVariantExport() {
            const res  = await fetch(`/sku-checker/${sessionId}/status`);
            const data = await res.json();
            const e    = data.variant_export;

            this.exportStatus   = e.status;
            this.exportTotal    = e.total;
            this.exportScanned  = e.scanned;
            this.exportFailed   = e.failed;
            this.exportProgress = e.progress;
            this.exportError    = e.error;

            if (e.status === 'completed' || e.status === 'failed') {
                clearInterval(this.exportTimer);
            }
        },

        async loadRows() {
            this.rowsLoading = true;

            // The open row survives a reload if it is still in the list. It
            // used to be closed unconditionally, so anything that refetched
            // rows — a filter, a search, a check finishing — shut the panel
            // somebody was reading.
            const wasOpen = this.open;

            const params = new URLSearchParams({ filter: this.filter, q: this.search, page: this.page });

            try {
                const res  = await fetch(`/sku-checker/${sessionId}/results?${params}`);
                const data = await res.json();
                this.rows  = data.rows;
                this.total = data.total;
                this.pages = data.pages;
                // A filter or search can leave the current page past the end.
                if (this.page > this.pages && this.pages > 0) {
                    this.page = this.pages;
                    this.rowsLoading = false;
                    return this.loadRows();
                }
            } catch (e) {
                this.rows = [];
            }

            if (wasOpen && !this.rows.some(r => r.sku === wasOpen)) {
                this.open      = null;
                this.breakdown = null;
            }

            this.rowsLoading = false;
        },

        toggle(row) {
            if (row.status !== 'Available') return;

            if (this.open === row.sku) {
                this.open = null;
                return;
            }

            this.open = row.sku;
            this.loadBreakdown(row.sku);
        },

        async loadBreakdown(sku) {
            this.breakdown        = null;
            this.breakdownError   = null;
            this.breakdownLoading = true;

            try {
                const res  = await fetch(`/sku-checker/${sessionId}/variants?sku=${encodeURIComponent(sku)}`);
                const data = await res.json();

                // A slow lookup must not paint into a row the user has since closed.
                if (this.open !== sku) return;

                if (res.ok) {
                    this.breakdown = data;
                } else {
                    this.breakdownError = data.error || 'Could not read the variants for this SKU.';
                }
            } catch (e) {
                if (this.open === sku) {
                    this.breakdownError = 'Could not reach Shopify for this SKU.';
                }
            }

            if (this.open === sku) {
                this.breakdownLoading = false;
            }
        },

        startPolling() {
            this.pollTimer = setInterval(() => this.poll(), 3000);
        },

        async poll() {
            const res  = await fetch(`/sku-checker/${sessionId}/status`);
            const data = await res.json();
            this.status       = data.status;
            this.totalSkus    = data.total_skus;
            this.scanned      = data.scanned_skus;
            this.available    = data.available;
            this.notAvailable = data.not_available;
            this.progress     = data.progress;

            this.settle('total', data.total_skus);
            this.settle('available', data.available);
            this.settle('notAvailable', data.not_available);
            if (data.status === 'completed' || data.status === 'failed') {
                clearInterval(this.pollTimer);
                if (data.status === 'completed') {
                    this.loadRows();
                }
            }
        },
    };
}
</script>
@endsection
