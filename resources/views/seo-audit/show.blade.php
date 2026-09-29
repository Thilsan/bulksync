@extends('layouts.app')
@section('title', 'SEO Audit Results')
@section('page-title', 'SEO Audit Results')

@section('content')
<div class="space-y-6"
     x-data="seoAuditPage({{ $session->id }}, '{{ $session->status }}')"
     x-init="init()">

    {{-- Back + Download --}}
    <div class="flex items-center justify-between">
        <a href="{{ route('seo-audit.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Back to Audits</a>
        <div class="flex items-center gap-2" x-show="status === 'completed'">
            {{-- Sends the rows currently filtered on screen for generation. The
                 hidden fields mirror the Alpine state so the server filters the
                 same set the table is showing. --}}
            <form method="POST" action="{{ route('seo-audit.fix', $session) }}" @submit="return confirmFix($event)">
                @csrf
                <input type="hidden" name="filter" :value="filter">
                <input type="hidden" name="search" :value="search">
                <button type="submit"
                    class="bg-brand-600 hover:bg-brand-700 text-white px-4 py-1.5 rounded-lg text-xs font-semibold transition-colors flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
                    </svg>
                    Fix <span x-text="itemTotal.toLocaleString()"></span> with AI
                </button>
            </form>

            {{-- Exports the rows currently filtered on screen, so the fix list
                 and the CSV can never disagree. --}}
            <a :href="downloadUrl()"
               class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-1.5 rounded-lg text-xs font-medium transition-colors flex items-center gap-1">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Download Current View
            </a>
        </div>
    </div>

    {{-- Headline numbers --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
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
            <p class="text-xs text-gray-500 mb-1">Products</p>
            <p class="text-2xl font-bold text-gray-800" x-text="scanned.toLocaleString()">0</p>
        </div>
        <div class="bg-white rounded-xl border border-green-100 p-5">
            <p class="text-xs text-gray-500 mb-1">Clean</p>
            <p class="text-2xl font-bold text-green-600" x-text="clean.toLocaleString()">0</p>
        </div>
        <div class="bg-white rounded-xl border border-red-100 p-5">
            <p class="text-xs text-gray-500 mb-1">With Issues</p>
            <p class="text-2xl font-bold text-red-500" x-text="withIssues.toLocaleString()">0</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Average Score</p>
            <p class="text-2xl font-bold"
               :class="averageScore >= 80 ? 'text-green-600' : (averageScore >= 50 ? 'text-amber-600' : 'text-red-500')"
               x-text="averageScore">0</p>
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
    @if($session->status === 'failed')
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700">
        <strong>Audit failed:</strong> {{ $session->error_message }}
    </div>
    @endif

    {{-- Issue breakdown: each tile is also the filter for that issue --}}
    <div x-show="status === 'completed' && issueSummary.length > 0" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-800">What needs fixing</h3>
            <p class="text-xs text-gray-500 mt-0.5">Click an issue to filter the table below.</p>
        </div>
        <div class="p-6 grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3">
            <template x-for="issue in issueSummary" :key="issue.code">
                <button @click="setFilter(issue.code)"
                    class="text-left rounded-lg border p-4 transition-colors"
                    :class="filter === issue.code
                        ? 'border-brand-500 bg-brand-50'
                        : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="w-1.5 h-1.5 rounded-full"
                              :class="{
                                  'bg-red-500':   issue.severity === 'high',
                                  'bg-amber-500': issue.severity === 'medium',
                                  'bg-gray-400':  issue.severity === 'low'
                              }"></span>
                        <p class="text-xs text-gray-500" x-text="issue.label"></p>
                    </div>
                    <p class="text-xl font-bold text-gray-800" x-text="issue.count.toLocaleString()"></p>
                </button>
            </template>
        </div>
    </div>

    {{-- Results table --}}
    <div x-show="status === 'completed'" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap items-center gap-4">
            <div class="flex gap-2">
                <button @click="setFilter('issues')"
                    :class="filter === 'issues' ? 'bg-red-600 text-white' : 'bg-red-50 text-red-600 hover:bg-red-100'"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">
                    With Issues (<span x-text="withIssues.toLocaleString()"></span>)
                </button>
                <button @click="setFilter('clean')"
                    :class="filter === 'clean' ? 'bg-green-600 text-white' : 'bg-green-50 text-green-600 hover:bg-green-100'"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">
                    Clean (<span x-text="clean.toLocaleString()"></span>)
                </button>
                <button @click="setFilter('all')"
                    :class="filter === 'all' ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">
                    All (<span x-text="scanned.toLocaleString()"></span>)
                </button>
            </div>
            <div class="flex-1 max-w-xs">
                <input type="text" x-model.debounce.400ms="search" @input="loadItems(1)"
                    placeholder="Search SKU, title or handle…"
                    class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
            <p class="text-xs text-gray-400 ml-auto"><span x-text="itemTotal.toLocaleString()"></span> results</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">#</th>
                        <th class="text-center px-6 py-3 font-medium text-gray-600">Score</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">SKU</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">Product</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">Meta Title</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">Meta Description</th>
                        <th class="text-center px-6 py-3 font-medium text-gray-600">Alt</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">Issues</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <template x-for="(item, i) in items" :key="item.product_id">
                        <tr class="hover:bg-gray-50 transition-colors align-top">
                            <td class="px-6 py-3 text-gray-400 text-xs" x-text="(currentPage - 1) * 100 + i + 1"></td>
                            <td class="px-6 py-3 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold"
                                      :class="item.score >= 80 ? 'bg-green-50 text-green-700'
                                            : (item.score >= 50 ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-red-600')"
                                      x-text="item.score"></span>
                            </td>
                            <td class="px-6 py-3 font-mono font-medium text-gray-800" x-text="item.sku || '—'"></td>
                            <td class="px-6 py-3 text-gray-600 max-w-xs truncate" x-text="item.product_title"></td>
                            <td class="px-6 py-3 text-gray-600 max-w-xs">
                                <p class="truncate" x-text="item.meta_title || '—'"></p>
                                <p class="text-xs mt-0.5"
                                   :class="item.title_length > 60 || (item.title_length > 0 && item.title_length < 30)
                                        ? 'text-red-500' : 'text-gray-400'"
                                   x-text="item.title_length + ' chars'"></p>
                            </td>
                            <td class="px-6 py-3 text-gray-600 max-w-xs">
                                <p class="truncate" x-text="item.meta_description || '—'"></p>
                                <p class="text-xs mt-0.5"
                                   :class="item.desc_length > 160 || (item.desc_length > 0 && item.desc_length < 70)
                                        ? 'text-red-500' : 'text-gray-400'"
                                   x-text="item.desc_length + ' chars'"></p>
                            </td>
                            <td class="px-6 py-3 text-center">
                                <span :class="item.missing_alt > 0 ? 'text-red-500 font-semibold' : 'text-gray-500'"
                                      x-text="(item.image_count - item.missing_alt) + '/' + item.image_count"></span>
                            </td>
                            <td class="px-6 py-3">
                                <div class="flex flex-wrap gap-1 max-w-sm">
                                    <template x-for="label in item.issues" :key="label">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600"
                                              x-text="label"></span>
                                    </template>
                                    <template x-if="item.issues.length === 0">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-green-50 text-green-700">Clean</span>
                                    </template>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="items.length === 0 && status === 'completed'">
                        <td colspan="8" class="px-6 py-8 text-center text-gray-400 text-sm">No results found.</td>
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
function seoAuditPage(sessionId, initialStatus) {
    return {
        status:        initialStatus,
        progress:      {{ $session->progressPercent() }},
        scanned:       {{ $session->scanned_products }},
        totalProducts: {{ $session->total_products }},
        clean:         {{ $session->clean_products }},
        withIssues:    {{ $session->products_with_issues }},
        averageScore:  {{ $session->average_score }},
        issueSummary:  @json($session->issueSummary()),
        filter:        'issues',
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
            const res  = await fetch(`/seo-audit/${sessionId}/status`);
            const data = await res.json();

            this.status        = data.status;
            this.progress      = data.progress;
            this.scanned       = data.scanned_products;
            this.totalProducts = data.total_products;
            this.clean         = data.clean_products;
            this.withIssues    = data.products_with_issues;
            this.averageScore  = data.average_score;
            this.issueSummary  = data.issue_summary;

            if (data.status === 'completed' || data.status === 'failed') {
                clearInterval(this.pollTimer);
                if (data.status === 'completed') this.loadItems(1);
            }
        },

        setFilter(f) {
            this.filter = f;
            this.loadItems(1);
        },

        /**
         * Says the count, the money and — the part people get wrong — that this
         * writes nothing to Shopify yet. Without that last line "Fix" reads like
         * it is about to change 217 live product pages.
         */
        confirmFix(event) {
            const cost = (this.itemTotal * {{ \App\Http\Controllers\SeoAuditController::COST_PER_PRODUCT_USD }}).toFixed(2);

            const ok = confirm(
                `Generate SEO content for ${this.itemTotal.toLocaleString()} product(s)?\n\n` +
                `Estimated cost: about $${cost}.\n\n` +
                `Nothing is written to Shopify yet — you will review everything first, ` +
                `then choose what to push.`
            );

            if (!ok) event.preventDefault();

            return ok;
        },

        query() {
            return `filter=${encodeURIComponent(this.filter)}&search=${encodeURIComponent(this.search)}`;
        },

        downloadUrl() {
            return `/seo-audit/${sessionId}/download?${this.query()}`;
        },

        async loadItems(page) {
            this.currentPage = page;

            const res  = await fetch(`/seo-audit/${sessionId}/items?${this.query()}&page=${page}`);
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
