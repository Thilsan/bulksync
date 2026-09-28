@extends('layouts.app')
@section('title', 'Image grab')
@section('page-title', $session->name ?: 'Image grab')

@section('content')
<div class="space-y-5"
     x-data="barcodeGrab({{ $session->id }}, @js($session->status))"
     x-init="init()">

    {{-- ── Header ──────────────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-gray-200 bg-white p-5">
        <div class="min-w-0">
            <h2 class="text-lg font-semibold text-gray-900">{{ $session->name ?: 'Image grab' }}</h2>
            <p class="mt-1 truncate text-sm text-gray-500">
                <a href="{{ $session->site_url }}" target="_blank" rel="noopener noreferrer"
                   class="text-brand-600 hover:text-brand-800">{{ $session->site_url }}</a>
                · started {{ $session->created_at->format('d M Y, h:i A') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a x-show="images > 0" x-cloak href="{{ route('barcode-images.download', $session) }}"
               class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700">
                Download ZIP
            </a>
            <form method="POST" action="{{ route('barcode-images.destroy', $session) }}"
                  onsubmit="return confirm('Delete this run and its downloaded images?')">
                @csrf @method('DELETE')
                <button type="submit" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-500 transition-colors hover:border-red-200 hover:text-red-600">
                    Delete
                </button>
            </form>
        </div>
    </div>

    {{-- ── Progress ────────────────────────────────────────────────────── --}}
    <div class="rounded-xl border border-gray-200 bg-white p-5">
        <div class="mb-3 flex items-center justify-between text-sm">
            <span class="font-medium text-gray-700">
                <span x-text="status === 'completed' ? 'Finished' : (status === 'failed' ? 'Failed' : 'Working through the list')"></span>
            </span>
            <span class="figure text-gray-500">
                <span x-text="processed"></span> of <span x-text="total"></span>
            </span>
        </div>

        <div class="h-2 overflow-hidden rounded-full bg-gray-100">
            <div class="h-full rounded-full bg-brand-500 transition-all duration-500" :style="`width: ${progress}%`"></div>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <div>
                <p class="text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">With pictures</p>
                <p class="figure mt-1 text-2xl text-green-600" x-text="found"></p>
            </div>
            <div>
                <p class="text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">Nothing found</p>
                <p class="figure mt-1 text-2xl text-gray-500" x-text="missing"></p>
            </div>
            <div>
                <p class="text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">Images downloaded</p>
                <p class="figure mt-1 text-2xl text-gray-900" x-text="images"></p>
            </div>
        </div>

        <p x-show="error" x-cloak class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700" x-text="error"></p>
    </div>

    {{-- ── The barcodes ────────────────────────────────────────────────── --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
            <div class="inline-flex rounded-lg bg-gray-100 p-1">
                <template x-for="option in [['all','All'],['found','With pictures'],['missing','Nothing found']]" :key="option[0]">
                    <button type="button" @click="setFilter(option[0])"
                            :class="filter === option[0] ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                            class="rounded-md px-3 py-1.5 text-xs font-semibold transition-all"
                            x-text="option[1]"></button>
                </template>
            </div>

            <input type="search" x-model.debounce.400ms="search" @input="loadItems(1)"
                   placeholder="Find a barcode"
                   class="w-56 rounded-lg border border-gray-300 px-3 py-1.5 text-sm transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500">
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-5 py-3 text-left">Barcode</th>
                        <th class="px-5 py-3 text-left">Product</th>
                        <th class="px-5 py-3 text-center">Images</th>
                        <th class="px-5 py-3 text-left">Result</th>
                        <th class="px-5 py-3 text-right">Folder</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <template x-for="item in items" :key="item.id">
                        <tr class="transition-colors hover:bg-gray-50">
                            <td class="px-5 py-3 font-mono text-gray-800" x-text="item.barcode"></td>
                            <td class="px-5 py-3 max-w-xs">
                                <a x-show="item.product_url" :href="item.product_url" target="_blank" rel="noopener noreferrer"
                                   class="block truncate text-brand-600 hover:text-brand-800"
                                   x-text="item.product_title || item.product_url"></a>
                                <span x-show="!item.product_url" class="text-gray-400">—</span>
                            </td>
                            <td class="px-5 py-3 text-center figure"
                                :class="item.image_count > 0 ? 'text-gray-900' : 'text-gray-300'"
                                x-text="item.image_count"></td>
                            <td class="px-5 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium"
                                      :class="item.status === 'found'
                                          ? 'bg-green-100 text-green-700'
                                          : (item.status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600')"
                                      x-text="item.status === 'found' ? 'Downloaded' : (item.status === 'failed' ? 'Failed' : 'Not found')"></span>
                                <p x-show="item.message" class="mt-1 text-xs text-gray-400" x-text="item.message"></p>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <a x-show="item.image_count > 0"
                                   :href="`/barcode-images/{{ $session->id }}/download/${encodeURIComponent(item.barcode)}`"
                                   class="text-xs font-medium text-gray-500 hover:text-gray-800">Download</a>
                            </td>
                        </tr>
                    </template>

                    <tr x-show="items.length === 0">
                        <td colspan="5" class="px-5 py-12 text-center text-sm text-gray-400">
                            <span x-text="status === 'pending' ? 'Waiting for a worker to pick this up.' : 'Nothing here yet.'"></span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div x-show="lastPage > 1" class="flex items-center justify-between border-t border-gray-100 px-5 py-4">
            <button @click="loadItems(page - 1)" :disabled="page === 1"
                    class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors hover:bg-gray-50 disabled:opacity-40">
                ← Previous
            </button>
            <p class="text-xs text-gray-500">Page <span x-text="page"></span> of <span x-text="lastPage"></span></p>
            <button @click="loadItems(page + 1)" :disabled="page === lastPage"
                    class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors hover:bg-gray-50 disabled:opacity-40">
                Next →
            </button>
        </div>
    </div>
</div>

<script>
function barcodeGrab(sessionId, initialStatus) {
    return {
        status:    initialStatus,
        progress:  {{ $session->progressPercent() }},
        processed: {{ $session->processed }},
        total:     {{ $session->total_barcodes }},
        found:     {{ $session->found_count }},
        missing:   {{ $session->missing_count }},
        images:    {{ $session->images_downloaded }},
        error:     @js($session->error_message),
        filter:    'all',
        search:    '',
        items:     [],
        page:      1,
        lastPage:  1,
        pollTimer: null,

        init() {
            this.loadItems(1);

            // A run in flight fills the table as it goes, so the rows are
            // reloaded on the same beat as the counters.
            if (this.status !== 'completed' && this.status !== 'failed') {
                this.pollTimer = setInterval(() => this.poll(), 3000);
            }
        },

        async poll() {
            const response = await fetch(`/barcode-images/${sessionId}/status`);
            const data     = await response.json();

            this.status    = data.status;
            this.progress  = data.progress;
            this.processed = data.processed;
            this.total     = data.total;
            this.found     = data.found;
            this.missing   = data.missing;
            this.images    = data.images;
            this.error     = data.error;

            this.loadItems(this.page);

            if (data.status === 'completed' || data.status === 'failed') {
                clearInterval(this.pollTimer);
            }
        },

        setFilter(filter) {
            this.filter = filter;
            this.loadItems(1);
        },

        async loadItems(page) {
            const url = `/barcode-images/${sessionId}/items`
                + `?filter=${this.filter}&q=${encodeURIComponent(this.search)}&page=${Math.max(1, page)}`;

            const response = await fetch(url);
            const data     = await response.json();

            this.items    = data.items;
            this.page     = data.page;
            this.lastPage = data.last_page;
        },
    };
}
</script>
@endsection
