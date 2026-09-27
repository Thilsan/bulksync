@extends('layouts.app')
@section('title', 'SKU Check Results')
@section('page-title', 'SKU Check Results')

@section('content')
<div class="max-w-5xl mx-auto space-y-6"
     x-data="skuCheckPage({{ $skuCheckSession->id }}, '{{ $skuCheckSession->status }}')"
     x-init="init()">

    {{-- Back --}}
    <a href="{{ route('sku-checker.history') }}" class="text-sm text-gray-500 hover:text-gray-700">← Back to History</a>

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Status</p>
            <p class="text-sm font-semibold capitalize"
               :class="{'text-green-600': status==='completed','text-brand-600': status==='running','text-red-500': status==='failed','text-gray-500': status==='pending'}"
               x-text="status"></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Total SKUs</p>
            <p class="text-2xl font-bold text-gray-800" x-text="totalSkus.toLocaleString()">{{ $skuCheckSession->total_skus }}</p>
        </div>
        <div class="bg-white rounded-xl border border-green-100 p-5">
            <p class="text-xs text-gray-500 mb-1">Mapped</p>
            <p class="text-2xl font-bold text-green-600" x-text="available.toLocaleString()">{{ $skuCheckSession->available_count }}</p>
        </div>
        <div class="bg-white rounded-xl border border-red-100 p-5">
            <p class="text-xs text-gray-500 mb-1">Not Mapped</p>
            <p class="text-2xl font-bold text-red-500" x-text="notAvailable.toLocaleString()">{{ $skuCheckSession->not_available_count }}</p>
        </div>
    </div>

    {{-- Progress bar --}}
    <div x-show="status === 'running' || status === 'pending'" class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-3">
            <p class="text-sm font-medium text-gray-700">Checking SKUs in background…</p>
            <p class="text-sm text-gray-500"><span x-text="scanned.toLocaleString()"></span> / <span x-text="totalSkus.toLocaleString()"></span></p>
        </div>
        <div class="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
            <div class="bg-brand-600 h-3 rounded-full transition-all duration-500" :style="'width: ' + progress + '%'"></div>
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
            <p x-show="exportStatus === 'completed' && exportFailed > 0" x-cloak class="text-xs text-amber-700">
                <span x-text="exportFailed.toLocaleString()"></span> SKU(s) could not be read from Shopify and are marked
                <span class="font-mono">Lookup Failed</span> in the file — rebuild to try those again.
            </p>

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
                    {{-- The SKU row --}}
                    <div class="px-6 py-3 flex items-center gap-3">
                        <button type="button"
                                @click="toggle(row)"
                                :disabled="row.status !== 'Available'"
                                class="w-6 h-6 flex items-center justify-center rounded text-gray-400 hover:text-gray-700 disabled:opacity-30 disabled:cursor-not-allowed">
                            <svg class="w-4 h-4 transition-transform" :class="open === row.sku ? 'rotate-90' : ''"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </button>

                        <div class="flex-1 min-w-0">
                            <p class="font-mono text-sm text-gray-800" x-text="row.sku"></p>
                            <p class="text-xs text-gray-500 truncate" x-text="row.product_title"></p>
                        </div>

                        <span class="text-xs px-2 py-1 rounded-full font-medium"
                              :class="row.status === 'Available' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-600'"
                              x-text="row.status === 'Available' ? 'Mapped' : 'Not mapped'"></span>
                    </div>

                    {{-- Its variants --}}
                    <div x-show="open === row.sku" x-cloak class="px-6 pb-5 pl-14 bg-gray-50/60">
                        <template x-if="breakdownLoading">
                            <p class="text-sm text-gray-500 py-3">Reading variants from Shopify…</p>
                        </template>

                        <template x-if="!breakdownLoading && breakdownError">
                            <p class="text-sm text-red-600 py-3" x-text="breakdownError"></p>
                        </template>

                        <template x-if="!breakdownLoading && breakdown">
                            <div class="space-y-3 pt-3">
                                <p class="text-xs text-gray-500">
                                    <span x-text="breakdown.colours.length"></span> colour(s),
                                    <span x-text="breakdown.variant_count"></span> variant(s) —
                                    <span x-text="breakdown.with_image_count"></span> with a photo of their own,
                                    <span x-text="breakdown.gallery_count"></span> image(s) in the product gallery.
                                    <span x-show="!breakdown.stock_known" class="text-amber-700">
                                        Stock is not readable for this store.
                                    </span>
                                </p>

                                <template x-for="colour in breakdown.colours" :key="colour.colour">
                                    <div class="bg-white rounded-lg border border-gray-200 p-4">
                                        <div class="flex items-center gap-3 mb-3">
                                            <template x-if="colour.preview">
                                                <img :src="colour.preview" alt=""
                                                     class="w-10 h-10 rounded object-cover border border-gray-200">
                                            </template>
                                            <template x-if="!colour.preview">
                                                <div class="w-10 h-10 rounded border border-dashed border-gray-300"></div>
                                            </template>

                                            <div class="flex-1">
                                                <p class="text-sm font-medium text-gray-800" x-text="colour.colour"></p>
                                                <p class="text-xs text-gray-500">
                                                    <span x-text="colour.with_image_count"></span> of
                                                    <span x-text="colour.variant_count"></span> size(s) have a photo
                                                    <span x-show="colour.stock !== null">
                                                        · <span x-text="colour.stock"></span> in stock
                                                    </span>
                                                </p>
                                            </div>

                                            <span class="text-xs px-2 py-1 rounded-full font-medium"
                                                  :class="colour.with_image_count === 0
                                                      ? 'bg-red-50 text-red-600'
                                                      : (colour.with_image_count === colour.variant_count
                                                          ? 'bg-green-50 text-green-700'
                                                          : 'bg-amber-50 text-amber-700')"
                                                  x-text="colour.with_image_count === 0
                                                      ? 'No image'
                                                      : (colour.with_image_count === colour.variant_count ? 'All sizes covered' : 'Partly covered')"></span>
                                        </div>

                                        <div class="flex flex-wrap gap-2">
                                            <template x-for="size in colour.sizes" :key="size.variant_id">
                                                <span class="inline-flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-lg border"
                                                      :class="size.has_image
                                                          ? 'bg-green-50 border-green-200 text-green-800'
                                                          : 'bg-white border-gray-200 text-gray-500'"
                                                      :title="size.sku + (size.stock_by_location && Object.keys(size.stock_by_location).length
                                                          ? ' — ' + Object.entries(size.stock_by_location).map(([l, q]) => l + ': ' + q).join(' | ')
                                                          : '')">
                                                    <svg x-show="size.has_image" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                    </svg>
                                                    <span x-text="size.size || '—'"></span>
                                                    <span x-show="size.stock !== null"
                                                          class="text-[10px] opacity-70"
                                                          x-text="'· ' + size.stock"></span>
                                                    <span x-show="size.image_count > 1" class="text-[10px] opacity-70"
                                                          x-text="'×' + size.image_count"></span>
                                                    <span x-show="size.is_match" class="text-[10px] font-semibold uppercase tracking-wide opacity-70">searched</span>
                                                </span>
                                            </template>
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

        init() {
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
            this.open        = null;

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
