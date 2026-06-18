@extends('layouts.app')
@section('title', 'Upload Progress')
@section('page-title', $session->name)

@section('content')
<div
    x-data="uploadProgress({{ $session->id }})"
    x-init="init()"
    class="space-y-5">

    {{-- Status header --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-800">{{ $session->name }}</h2>
                <p class="text-sm text-gray-500">
                    Size: <span class="capitalize font-medium">{{ $session->image_size }}</span>
                    &nbsp;·&nbsp; Started: {{ $session->created_at->format('d M Y H:i') }}
                </p>
            </div>
            <div class="flex items-center gap-3">
                {{-- Scan phase indicator --}}
                <span x-show="scanStatus === 'scanning'" x-cloak
                      class="inline-flex items-center gap-1.5 bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-semibold">
                    <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
                    Scanning OneDrive…
                </span>
                <span
                    :class="{
                        'bg-brand-100 text-brand-700': mainStatus === 'processing',
                        'bg-green-100  text-green-700' : mainStatus === 'completed',
                        'bg-red-100    text-red-700'   : mainStatus === 'failed',
                        'bg-gray-100   text-gray-600'  : mainStatus === 'pending',
                    }"
                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-semibold">
                    <span x-show="mainStatus === 'processing'" class="w-2 h-2 rounded-full bg-brand-500 animate-pulse"></span>
                    <span x-text="mainStatus.charAt(0).toUpperCase() + mainStatus.slice(1)"></span>
                </span>
            </div>
        </div>

        {{-- Scan progress (shown during scanning phase) --}}
        <div x-show="scanStatus === 'scanning'" x-cloak class="mb-4">
            <div class="flex justify-between text-xs text-gray-500 mb-1">
                <span>Scanning folder…</span>
                <span x-text="scanned.toLocaleString() + ' files found so far'"></span>
            </div>
            <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                <div class="h-2 rounded-full bg-blue-400 animate-pulse" style="width: 100%"></div>
            </div>
        </div>

        {{-- Upload progress bar --}}
        <div x-show="total > 0" class="mb-4">
            <div class="flex justify-between text-xs text-gray-500 mb-1">
                <span x-text="progress + '% complete'"></span>
                <span x-text="uploaded.toLocaleString() + ' / ' + total.toLocaleString() + ' images uploaded'"></span>
            </div>
            <div class="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
                <div class="h-3 rounded-full bg-brand-500 transition-all duration-1000"
                     :style="'width: ' + progress + '%'"></div>
            </div>
        </div>

        {{-- Stats grid --}}
        <div class="grid grid-cols-3 sm:grid-cols-6 gap-3">
            <template x-for="[key, label, color] in statCards" :key="key">
                <div :class="`bg-${color}-50 rounded-lg p-3 text-center`">
                    <p :class="`text-xl font-bold text-${color}-700`" x-text="stats[key]?.toLocaleString() ?? '—'"></p>
                    <p :class="`text-xs text-${color}-600 mt-0.5`" x-text="label"></p>
                </div>
            </template>
        </div>

        {{-- Finished banners --}}
        <div x-show="isFinished && mainStatus === 'completed'" x-cloak
             class="mt-4 bg-green-50 border border-green-200 rounded-lg px-4 py-3 flex items-center gap-3">
            <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            <p class="text-green-800 text-sm font-medium">
                All done! <span x-text="uploaded.toLocaleString()"></span> images uploaded to Shopify.
                <span x-show="stats.skipped > 0"> <span x-text="stats.skipped.toLocaleString()"></span> skipped (no SKU match).</span>
            </p>
        </div>
        <div x-show="isFinished && mainStatus === 'failed'" x-cloak
             class="mt-4 bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-red-800 text-sm">
            Session failed.
            @if($session->error_message)
            <div class="mt-1 font-mono text-xs break-all">{{ $session->error_message }}</div>
            @else
            Check the items table below for details.
            @endif
        </div>

    </div>

    {{-- Items table --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-700">
                Items
                <span x-show="!isFinished" class="text-xs font-normal text-gray-400 ml-1">(live — latest activity first)</span>
                <span x-show="isFinished" class="text-xs font-normal text-gray-400 ml-1"
                      x-text="'(' + pagination.total.toLocaleString() + ' total)'"></span>
            </h3>
            <span class="text-xs text-gray-400" x-show="!isFinished">
                <span x-text="stats.processing"></span> processing
                &nbsp;·&nbsp;
                <span x-text="stats.pending?.toLocaleString()"></span> queued
            </span>
            <span class="text-xs text-gray-400" x-show="isFinished && pagination.last_page > 1"
                  x-text="'Page ' + pagination.current_page + ' of ' + pagination.last_page"></span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-5 py-3 text-left">Filename</th>
                        <th class="px-5 py-3 text-left">SKU</th>
                        <th class="px-5 py-3 text-left">Product</th>
                        <th class="px-5 py-3 text-center">Original</th>
                        <th class="px-5 py-3 text-center">Output</th>
                        <th class="px-5 py-3 text-center">Status</th>
                        <th class="px-5 py-3 text-left">Note</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <template x-for="item in items" :key="item.id">
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-5 py-2.5 font-mono text-xs text-gray-700 max-w-[180px] truncate" x-text="item.filename"></td>
                            <td class="px-5 py-2.5 font-mono text-xs text-brand-600" x-text="item.sku || '—'"></td>
                            <td class="px-5 py-2.5 text-gray-700 max-w-[200px] truncate text-xs" x-text="item.product_title || '—'"></td>
                            <td class="px-5 py-2.5 text-center text-gray-400 text-xs" x-text="item.original_kb ? item.original_kb + ' KB' : '—'"></td>
                            <td class="px-5 py-2.5 text-center text-xs"
                                :class="item.processed_kb ? 'text-green-600 font-medium' : 'text-gray-400'"
                                x-text="item.processed_kb ? item.processed_kb + ' KB' : '—'"></td>
                            <td class="px-5 py-2.5 text-center">
                                <span :class="{
                                    'bg-green-100  text-green-700' : item.status === 'uploaded',
                                    'bg-blue-100   text-blue-700'  : item.status === 'matched',
                                    'bg-red-100    text-red-700'   : item.status === 'failed',
                                    'bg-yellow-100 text-yellow-700': item.status === 'skipped',
                                    'bg-brand-100 text-brand-700': item.status === 'processing',
                                    'bg-gray-100   text-gray-500'  : item.status === 'pending',
                                }" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap">
                                    <span x-show="item.status === 'processing'" class="w-1.5 h-1.5 rounded-full bg-brand-500 animate-pulse mr-1"></span>
                                    <span x-text="item.status_label"></span>
                                </span>
                            </td>
                            <td class="px-5 py-2.5 text-xs text-gray-400 max-w-[200px] truncate" x-text="item.error || ''"></td>
                        </tr>
                    </template>
                    <template x-if="items.length === 0">
                        <tr>
                            <td colspan="7" class="px-5 py-10 text-center text-gray-400">
                                <span x-text="scanStatus === 'scanning' ? 'Scanning OneDrive folder…' : 'Waiting for queue workers to start…'"></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        {{-- Pagination controls (only when finished and more than 1 page) --}}
        <div x-show="isFinished && pagination.last_page > 1"
             class="px-5 py-3 border-t border-gray-100 flex items-center justify-between">
            <span class="text-xs text-gray-500"
                  x-text="'Showing ' + (((pagination.current_page - 1) * pagination.per_page) + 1).toLocaleString()
                          + '–' + Math.min(pagination.current_page * pagination.per_page, pagination.total).toLocaleString()
                          + ' of ' + pagination.total.toLocaleString() + ' items'"></span>
            <div class="flex items-center gap-1">
                <button @click="goToPage(1)" :disabled="pagination.current_page <= 1"
                    class="px-2.5 py-1.5 rounded border border-gray-200 text-xs text-gray-600 hover:bg-gray-50 disabled:opacity-30 disabled:cursor-not-allowed transition-colors">
                    «
                </button>
                <button @click="goToPage(pagination.current_page - 1)" :disabled="pagination.current_page <= 1"
                    class="px-3 py-1.5 rounded border border-gray-200 text-xs text-gray-600 hover:bg-gray-50 disabled:opacity-30 disabled:cursor-not-allowed transition-colors">
                    ← Prev
                </button>
                <span class="px-3 py-1.5 text-xs text-gray-600 font-medium"
                      x-text="pagination.current_page + ' / ' + pagination.last_page"></span>
                <button @click="goToPage(pagination.current_page + 1)" :disabled="pagination.current_page >= pagination.last_page"
                    class="px-3 py-1.5 rounded border border-gray-200 text-xs text-gray-600 hover:bg-gray-50 disabled:opacity-30 disabled:cursor-not-allowed transition-colors">
                    Next →
                </button>
                <button @click="goToPage(pagination.last_page)" :disabled="pagination.current_page >= pagination.last_page"
                    class="px-2.5 py-1.5 rounded border border-gray-200 text-xs text-gray-600 hover:bg-gray-50 disabled:opacity-30 disabled:cursor-not-allowed transition-colors">
                    »
                </button>
            </div>
        </div>
    </div>

    {{-- Actions --}}
    <div class="flex flex-wrap gap-3 items-center" x-show="isFinished" x-cloak>
        <a href="{{ route('upload.create') }}"
            class="bg-brand-600 hover:bg-brand-700 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition-colors">
            New Upload
        </a>
        <a href="{{ route('upload.history') }}"
            class="border border-gray-300 text-gray-700 hover:bg-gray-50 px-5 py-2.5 rounded-lg text-sm font-medium transition-colors">
            View History
        </a>
        {{-- Re-run variant image linking in case Shopify didn't honour variant_ids on first upload --}}
        <div x-data="{ syncing: false, syncData: null }">
            <button type="button"
                @click="syncing = true; syncData = null;
                    fetch('{{ route('upload.sync-variant-images', $session) }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
                    })
                    .then(r => {
                        if (r.status === 419) throw new Error('Session expired — please refresh the page and try again.');
                        if (r.status === 401 || r.status === 403) throw new Error('Not authenticated — please refresh and log in again.');
                        if (!r.headers.get('content-type')?.includes('application/json')) throw new Error('Server error — please refresh the page and try again.');
                        return r.json();
                    })
                    .then(d => { syncData = d; })
                    .catch(e => { syncData = { error: e.message || e.toString() }; })
                    .finally(() => { syncing = false; })"
                :disabled="syncing"
                class="border border-blue-300 text-blue-700 hover:bg-blue-50 disabled:opacity-50 px-5 py-2.5 rounded-lg text-sm font-medium transition-colors">
                <span x-show="!syncing">Sync Variant Images</span>
                <span x-show="syncing">Syncing&hellip;</span>
            </button>

            {{-- Result card --}}
            <div x-show="syncData" class="mt-3 rounded-lg border p-4 max-w-sm text-sm"
                 :class="syncData && !syncData.error && syncData.errors === 0
                     ? 'bg-green-50 border-green-200'
                     : 'bg-red-50 border-red-200'">

                {{-- Request-level error --}}
                <template x-if="syncData && syncData.error">
                    <p class="text-red-700 font-medium" x-text="syncData.error"></p>
                </template>

                {{-- Normal response --}}
                <template x-if="syncData && !syncData.error">
                    <div>
                        <p class="font-semibold mb-2"
                           :class="syncData.errors > 0 ? 'text-red-700' : 'text-green-700'"
                           x-text="syncData.errors > 0
                               ? syncData.synced + ' synced, ' + syncData.errors + ' failed'
                               : syncData.synced + ' variant' + (syncData.synced === 1 ? '' : 's') + ' linked successfully'">
                        </p>
                        <ul class="space-y-1">
                            <template x-for="r in syncData.results" :key="r.variant_id">
                                <li class="flex items-center gap-2">
                                    <span x-text="r.status === 'ok' ? '✓' : r.status === 'skip' ? '–' : '✗'"
                                          :class="r.status === 'ok' ? 'text-green-600' : r.status === 'skip' ? 'text-gray-400' : 'text-red-600'"
                                          class="font-bold w-4 shrink-0"></span>
                                    <span class="text-gray-700 font-mono text-xs" x-text="r.sku"></span>
                                    <span x-show="r.error" class="text-red-500 text-xs truncate" x-text="r.error"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>
            </div>
        </div>
    </div>

</div>

<script>
function uploadProgress(sessionId) {
    return {
        mainStatus:  '{{ $session->status }}',
        scanStatus:  '{{ $session->scan_status }}',
        isFinished:  {{ in_array($session->status, ['completed', 'failed']) ? 'true' : 'false' }},
        progress:    0,
        scanned:     {{ $session->scanned_files }},
        total:       {{ $session->total_files }},
        stats: {
            total:      {{ $session->total_files }},
            uploaded:   0,
            failed:     0,
            skipped:    0,
            processing: 0,
            pending:    0,
        },
        uploaded:   0,
        items:      [],
        timer:      null,
        pagination: { current_page: 1, last_page: 1, per_page: 50, total: 0 },

        statCards: [
            ['total',      'Total',       'gray'],
            ['uploaded',   'Uploaded',    'green'],
            ['skipped',    'No Match',    'yellow'],
            ['failed',     'Failed',      'red'],
            ['processing', 'Processing',  'brand'],
            ['pending',    'Queued',      'slate'],
        ],

        init() {
            if (!this.isFinished) {
                this.pollStatus();
                this.timer = setInterval(() => this.pollStatus(), 4000);
            } else {
                this.pollStatus(); // one-shot to populate the table
            }
        },

        async pollStatus() {
            try {
                const page = this.isFinished ? this.pagination.current_page : 1;
                const res  = await fetch(`/upload/${sessionId}/status?page=${page}`, {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await res.json();
                const s    = data.session;

                this.mainStatus  = s.status;
                this.scanStatus  = s.scan_status;
                this.isFinished  = s.is_finished;
                this.progress    = s.progress;
                this.total       = s.total;
                this.scanned     = s.scanned;
                this.uploaded    = s.uploaded;
                this.stats       = {
                    total:      s.total,
                    uploaded:   s.uploaded,
                    failed:     s.failed,
                    skipped:    s.skipped,
                    processing: s.processing,
                    pending:    s.pending,
                };

                this.items      = data.items;
                this.pagination = data.pagination ?? this.pagination;

                if (s.is_finished) {
                    clearInterval(this.timer);
                }
            } catch (e) {
                console.error('Poll error', e);
            }
        },

        async goToPage(p) {
            const clamped = Math.max(1, Math.min(p, this.pagination.last_page));
            this.pagination = { ...this.pagination, current_page: clamped };
            await this.pollStatus();
        },
    };
}
</script>
@endsection
