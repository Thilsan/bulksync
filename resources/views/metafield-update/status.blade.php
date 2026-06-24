@extends('layouts.app')

@section('title', 'Metafield Update — Status')
@section('page-title', 'Metafield Update — Status')

@section('content')
<div class="max-w-4xl mx-auto space-y-5"
     x-data="metafieldStatus('{{ $key }}', '{{ $data['status'] }}')"
     x-init="init()">

    <div class="flex items-center justify-between">
        <a href="{{ route('metafield-update.index') }}"
           class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            New Upload
        </a>
    </div>

    {{-- Processing --}}
    <div x-show="status === 'pending' || status === 'processing'" x-cloak
         class="bg-white rounded-xl border border-gray-200 shadow-sm p-8 text-center">
        <div class="w-12 h-12 rounded-full border-4 border-brand-200 border-t-brand-600 animate-spin mx-auto mb-4"></div>
        <p class="text-gray-700 font-medium mb-1">Updating metafields…</p>
        <p class="text-sm text-gray-400 mb-4">Processing SKUs from your CSV file.</p>
        <div class="max-w-xs mx-auto">
            <div class="flex justify-between text-xs text-gray-500 mb-1">
                <span>Progress</span>
                <span x-text="processed + ' / ' + total"></span>
            </div>
            <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                <div class="h-full bg-brand-500 rounded-full transition-all duration-500"
                     :style="`width: ${total > 0 ? Math.round(processed/total*100) : 0}%`"></div>
            </div>
        </div>
    </div>

    {{-- Done --}}
    <div x-show="status === 'done'" x-cloak class="space-y-4">

        {{-- Summary --}}
        <div class="grid grid-cols-3 gap-4">
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 text-center">
                <p class="text-2xl font-bold text-green-600" x-text="countByStatus('updated')"></p>
                <p class="text-sm text-gray-500 mt-1">Updated</p>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 text-center">
                <p class="text-2xl font-bold text-red-500" x-text="countByStatus('not_found')"></p>
                <p class="text-sm text-gray-500 mt-1">Not Found</p>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 text-center">
                <p class="text-2xl font-bold text-yellow-500" x-text="countByStatus('failed')"></p>
                <p class="text-sm text-gray-500 mt-1">Failed</p>
            </div>
        </div>

        {{-- Results table --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-base font-semibold text-gray-800">Results</h2>
                <div class="flex gap-2">
                    <button @click="filter = 'all'" :class="filter==='all' ? 'bg-gray-200 text-gray-800' : 'text-gray-500 hover:bg-gray-100'"
                        class="text-xs px-3 py-1 rounded-full">All</button>
                    <button @click="filter = 'updated'" :class="filter==='updated' ? 'bg-green-100 text-green-700' : 'text-gray-500 hover:bg-gray-100'"
                        class="text-xs px-3 py-1 rounded-full">Updated</button>
                    <button @click="filter = 'not_found'" :class="filter==='not_found' ? 'bg-red-100 text-red-700' : 'text-gray-500 hover:bg-gray-100'"
                        class="text-xs px-3 py-1 rounded-full">Not Found</button>
                    <button @click="filter = 'failed'" :class="filter==='failed' ? 'bg-yellow-100 text-yellow-700' : 'text-gray-500 hover:bg-gray-100'"
                        class="text-xs px-3 py-1 rounded-full">Failed</button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">SKU</th>
                            <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                            <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Message</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="item in filteredResults" :key="item.sku">
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 font-mono text-xs text-gray-800" x-text="item.sku"></td>
                                <td class="px-6 py-3">
                                    <span :class="{
                                        'bg-green-100 text-green-700':  item.status === 'updated',
                                        'bg-red-100 text-red-700':      item.status === 'not_found',
                                        'bg-yellow-100 text-yellow-700': item.status === 'failed',
                                    }" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                    x-text="item.status === 'not_found' ? 'Not Found' : (item.status.charAt(0).toUpperCase() + item.status.slice(1))">
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-gray-500 text-xs" x-text="item.message"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Failed state --}}
    <div x-show="status === 'failed'" x-cloak
         class="bg-red-50 border border-red-200 rounded-xl p-6 text-center">
        <p class="text-red-700 font-medium">Update failed</p>
        <p class="text-sm text-red-500 mt-1" x-text="error"></p>
    </div>

</div>

<script>
function metafieldStatus(key, initialStatus) {
    return {
        key,
        status: initialStatus,
        total: {{ $data['total'] ?? 0 }},
        processed: {{ $data['processed'] ?? 0 }},
        results: @json($data['results'] ?? []),
        filter: 'all',
        error: '',
        pollTimer: null,

        init() {
            if (this.status === 'pending' || this.status === 'processing') {
                this.poll();
            }
        },

        poll() {
            this.pollTimer = setInterval(async () => {
                const res  = await fetch(`/metafield-update/poll?key=${this.key}`);
                const data = await res.json();

                this.status    = data.status;
                this.total     = data.total || 0;
                this.processed = data.processed || 0;
                this.results   = data.results || [];
                this.error     = data.error || '';

                if (this.status === 'done' || this.status === 'failed' || this.status === 'expired') {
                    clearInterval(this.pollTimer);
                }
            }, 2000);
        },

        countByStatus(s) {
            return this.results.filter(r => r.status === s).length;
        },

        get filteredResults() {
            if (this.filter === 'all') return this.results;
            return this.results.filter(r => r.status === this.filter);
        },
    };
}
</script>
@endsection
