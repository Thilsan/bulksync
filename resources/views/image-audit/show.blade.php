@extends('layouts.app')
@section('title', 'Image Audit Results')
@section('page-title', 'Image Audit Results')

@section('content')
<div class="space-y-6"
     x-data="auditPage({{ $imageAuditSession->id }}, '{{ $imageAuditSession->status }}')"
     x-init="init()">

    {{-- Back + Download --}}
    <div class="flex items-center justify-between">
        <a href="{{ route('image-audit.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Back to Audits</a>
        <div class="flex items-center gap-2" x-show="status === 'completed'">
            <a :href="'{{ route('image-audit.download', $imageAuditSession) }}?filter=without'"
               class="bg-red-50 hover:bg-red-100 text-red-700 px-3 py-1.5 rounded-lg text-xs font-medium border border-red-200 transition-colors">
                Download No-Image SKUs
            </a>
            <a :href="'{{ route('image-audit.download', $imageAuditSession) }}?filter=with'"
               class="bg-green-50 hover:bg-green-100 text-green-700 px-3 py-1.5 rounded-lg text-xs font-medium border border-green-200 transition-colors">
                Download With-Image SKUs
            </a>
            <a :href="'{{ route('image-audit.download', $imageAuditSession) }}'"
               class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-1.5 rounded-lg text-xs font-medium transition-colors flex items-center gap-1">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Download All CSV
            </a>
        </div>
    </div>

    {{-- Progress / Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Status</p>
            <p class="text-sm font-semibold capitalize"
               :class="{
                   'text-green-600': status === 'completed',
                   'text-brand-600': status === 'running',
                   'text-red-500':   status === 'failed',
                   'text-gray-500':  status === 'pending'
               }" x-text="status"></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Total SKUs</p>
            <p class="text-2xl font-bold text-gray-800" x-text="totalSkus.toLocaleString()">0</p>
        </div>
        <div class="bg-white rounded-xl border border-green-100 p-5">
            <p class="text-xs text-gray-500 mb-1">With Images</p>
            <p class="text-2xl font-bold text-green-600" x-text="withImages.toLocaleString()">0</p>
        </div>
        <div class="bg-white rounded-xl border border-red-100 p-5">
            <p class="text-xs text-gray-500 mb-1">No Images</p>
            <p class="text-2xl font-bold text-red-500" x-text="withoutImages.toLocaleString()">0</p>
        </div>
    </div>

    {{-- Progress bar (shown while running) --}}
    <div x-show="status === 'running' || status === 'pending'" class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-3">
            <p class="text-sm font-medium text-gray-700">Scanning Shopify products…</p>
            <p class="text-sm text-gray-500"><span x-text="scanned"></span> / <span x-text="totalProducts"></span> products</p>
        </div>
        <div class="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
            <div class="bg-brand-600 h-3 rounded-full transition-all duration-500"
                 :style="'width: ' + progress + '%'"></div>
        </div>
        <p class="text-xs text-gray-400 mt-2 text-center" x-text="progress + '% complete'"></p>
    </div>

    {{-- Error --}}
    @if($imageAuditSession->status === 'failed')
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700">
        <strong>Audit failed:</strong> {{ $imageAuditSession->error_message }}
    </div>
    @endif

    {{-- Results table (shown when completed) --}}
    <div x-show="status === 'completed'" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap items-center gap-4">
            {{-- Filter tabs --}}
            <div class="flex gap-2">
                <button @click="setFilter('all')"
                    :class="filter === 'all' ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">
                    All (<span x-text="totalSkus.toLocaleString()"></span>)
                </button>
                <button @click="setFilter('without')"
                    :class="filter === 'without' ? 'bg-red-600 text-white' : 'bg-red-50 text-red-600 hover:bg-red-100'"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">
                    No Image (<span x-text="withoutImages.toLocaleString()"></span>)
                </button>
                <button @click="setFilter('with')"
                    :class="filter === 'with' ? 'bg-green-600 text-white' : 'bg-green-50 text-green-600 hover:bg-green-100'"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">
                    Has Image (<span x-text="withImages.toLocaleString()"></span>)
                </button>
            </div>
            {{-- Search --}}
            <div class="flex-1 max-w-xs">
                <input type="text" x-model.debounce.400ms="search" @input="loadItems(1)"
                    placeholder="Search SKU or product…"
                    class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
            <p class="text-xs text-gray-400 ml-auto"><span x-text="itemTotal.toLocaleString()"></span> results</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">#</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">SKU</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">Product Title</th>
                        <th class="text-center px-6 py-3 font-medium text-gray-600">Images</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <template x-for="(item, i) in items" :key="item.id">
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-3 text-gray-400 text-xs" x-text="(currentPage - 1) * 100 + i + 1"></td>
                            <td class="px-6 py-3 font-mono font-medium text-gray-800" x-text="item.sku"></td>
                            <td class="px-6 py-3 text-gray-600 max-w-xs truncate" x-text="item.product_title"></td>
                            <td class="px-6 py-3 text-center font-semibold text-gray-700" x-text="item.image_count"></td>
                            <td class="px-6 py-3">
                                <template x-if="item.has_image">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-green-50 text-green-700">
                                        <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                                        Has Image
                                    </span>
                                </template>
                                <template x-if="!item.has_image">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-red-50 text-red-600">
                                        <span class="w-1.5 h-1.5 rounded-full bg-red-400"></span>
                                        No Image
                                    </span>
                                </template>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="items.length === 0 && status === 'completed'">
                        <td colspan="5" class="px-6 py-8 text-center text-gray-400 text-sm">No results found.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div x-show="lastPage > 1" class="px-6 py-4 border-t border-gray-100 flex items-center justify-between">
            <button @click="loadItems(currentPage - 1)" :disabled="currentPage === 1"
                class="px-3 py-1.5 rounded-lg border text-xs font-medium disabled:opacity-40 hover:bg-gray-50 transition-colors">
                ← Previous
            </button>
            <p class="text-xs text-gray-500">Page <span x-text="currentPage"></span> of <span x-text="lastPage"></span></p>
            <button @click="loadItems(currentPage + 1)" :disabled="currentPage === lastPage"
                class="px-3 py-1.5 rounded-lg border text-xs font-medium disabled:opacity-40 hover:bg-gray-50 transition-colors">
                Next →
            </button>
        </div>
    </div>

</div>

<script>
function auditPage(sessionId, initialStatus) {
    return {
        status:        initialStatus,
        progress:      {{ $imageAuditSession->progressPercent() }},
        scanned:       {{ $imageAuditSession->scanned_products }},
        totalProducts: {{ $imageAuditSession->total_products }},
        totalSkus:     {{ $imageAuditSession->total_skus }},
        withImages:    {{ $imageAuditSession->with_images }},
        withoutImages: {{ $imageAuditSession->without_images }},
        filter:        'all',
        search:        '',
        items:         [],
        itemTotal:     0,
        currentPage:   1,
        lastPage:      1,
        pollTimer:     null,

        init() {
            if (this.status === 'completed') {
                this.loadItems(1);
            } else if (this.status !== 'failed') {
                this.startPolling();
            }
        },

        startPolling() {
            this.pollTimer = setInterval(() => this.poll(), 3000);
        },

        async poll() {
            const res  = await fetch(`/image-audit/${sessionId}/status`);
            const data = await res.json();
            this.status        = data.status;
            this.progress      = data.progress;
            this.scanned       = data.scanned_products;
            this.totalProducts = data.total_products;
            this.totalSkus     = data.total_skus;
            this.withImages    = data.with_images;
            this.withoutImages = data.without_images;

            if (data.status === 'completed' || data.status === 'failed') {
                clearInterval(this.pollTimer);
                if (data.status === 'completed') this.loadItems(1);
            }
        },

        setFilter(f) {
            this.filter = f;
            this.loadItems(1);
        },

        async loadItems(page) {
            this.currentPage = page;
            const url = `/image-audit/${sessionId}/items?filter=${this.filter}&search=${encodeURIComponent(this.search)}&page=${page}`;
            const res  = await fetch(url);
            const data = await res.json();
            this.items       = data.items;
            this.itemTotal   = data.total;
            this.lastPage    = data.last_page;
            this.currentPage = data.current_page;
        },
    };
}
</script>
@endsection
