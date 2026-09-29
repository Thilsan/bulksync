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
                <input type="hidden" name="type" :value="type">
                <input type="hidden" name="status" :value="statusFilter">
                <button type="submit" :disabled="itemTotal === 0"
                    class="bg-brand-600 hover:bg-brand-700 text-white px-4 py-1.5 rounded-lg text-xs font-semibold transition-colors flex items-center gap-1.5 disabled:opacity-40 disabled:cursor-not-allowed">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
                    </svg>
                    {{-- Says the cap before the click rather than after it: a
                         button promising 690 that quietly sends 500 is worse
                         than one that reads "500 of 690" from the start. --}}
                    <span x-show="itemTotal <= maxBatch">
                        Fix <span x-text="itemTotal.toLocaleString()"></span> with AI
                    </span>
                    <span x-show="itemTotal > maxBatch" x-cloak>
                        Fix <span x-text="maxBatch.toLocaleString()"></span> of <span x-text="itemTotal.toLocaleString()"></span> with AI
                    </span>
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
    <div class="grid grid-cols-2 lg:grid-cols-6 gap-4">
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
            <p class="text-xs text-gray-500 mb-1">Pages audited</p>
            <p class="text-2xl font-bold text-gray-800" x-text="scanned.toLocaleString()">0</p>
            {{-- Spelled out because "clean" and "with issues" count both kinds,
                 and a single number labelled "products" made those two look
                 like they did not add up. --}}
            <p class="text-xs text-gray-400 mt-1">
                <span x-text="products.toLocaleString()"></span> products
                <span x-show="collections > 0">
                    · <span x-text="collections.toLocaleString()"></span> collections
                </span>
            </p>
        </div>
        <div class="bg-white rounded-xl border border-green-100 p-5">
            <p class="text-xs text-gray-500 mb-1">Clean (live)</p>
            <p class="text-2xl font-bold text-green-600" x-text="clean.toLocaleString()">0</p>
        </div>
        <div class="bg-white rounded-xl border border-red-100 p-5">
            <p class="text-xs text-gray-500 mb-1">With Issues (live)</p>
            <p class="text-2xl font-bold text-red-500" x-text="withIssues.toLocaleString()">0</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5" x-show="notLive > 0">
            <p class="text-xs text-gray-500 mb-1">Not live</p>
            <p class="text-2xl font-bold text-gray-400" x-text="notLive.toLocaleString()">0</p>
            <p class="text-xs text-gray-400 mt-1">Draft or archived — not graded</p>
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

    {{-- Duplicate clusters: which pages are actually competing with each other --}}
    <div x-show="status === 'completed' && hasDuplicates()" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap items-center gap-3">
            <div>
                <h3 class="font-semibold text-gray-800">Pages competing with each other</h3>
                <p class="text-xs text-gray-500 mt-0.5">Same text on more than one page — they split the same search result.</p>
            </div>
            <div class="flex gap-2 ml-auto">
                <button @click="loadDuplicates('meta_title')"
                    :class="dupField === 'meta_title' ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">Meta titles</button>
                <button @click="loadDuplicates('meta_description')"
                    :class="dupField === 'meta_description' ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">Meta descriptions</button>
            </div>
        </div>

        <div class="divide-y divide-gray-100">
            <template x-for="cluster in duplicates" :key="cluster.value">
                <div class="px-6 py-4">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex shrink-0 items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-red-50 text-red-600"
                              x-text="cluster.pages + ' pages'"></span>
                        <p class="text-sm text-gray-700 italic" x-text="'“' + cluster.value + '”'"></p>
                    </div>
                    <div class="mt-2 ml-1 flex flex-wrap gap-x-6 gap-y-1">
                        <template x-for="page in cluster.shown" :key="page.path">
                            <div class="text-xs text-gray-500 flex items-center gap-1.5">
                                <span class="inline-flex px-1.5 rounded bg-gray-100 text-gray-500"
                                      x-text="page.type === 'collection' ? 'collection' : (page.sku || 'product')"></span>
                                <span x-text="page.title"></span>
                            </div>
                        </template>
                        <span class="text-xs text-gray-400" x-show="cluster.pages > cluster.shown.length"
                              x-text="'+ ' + (cluster.pages - cluster.shown.length) + ' more'"></span>
                    </div>
                </div>
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
                    All (<span x-text="typeTotal().toLocaleString()"></span>)
                </button>
            </div>
            <div class="flex gap-2 border-l border-gray-200 pl-4">
                <template x-for="option in [{k:'live',l:'Live'},{k:'not_live',l:'Draft'},{k:'all',l:'Any status'}]" :key="option.k">
                    <button @click="setStatus(option.k)"
                        :class="statusFilter === option.k ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors" x-text="option.l"></button>
                </template>
            </div>

            <div class="flex gap-2 border-l border-gray-200 pl-4">
                <template x-for="option in [{k:'all',l:'Everything'},{k:'product',l:'Products'},{k:'collection',l:'Collections'}]" :key="option.k">
                    <button @click="setType(option.k)"
                        :class="type === option.k ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors" x-text="option.l"></button>
                </template>
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
                            <td class="px-6 py-3 font-mono font-medium text-gray-800">
                                <template x-if="item.resource_type === 'collection'">
                                    <span class="font-sans inline-flex px-2 py-0.5 rounded-full text-xs bg-violet-50 text-violet-700">Collection</span>
                                </template>
                                <template x-if="item.resource_type !== 'collection'">
                                    <span>
                                        <span x-text="item.sku || '—'"></span>
                                        <span class="font-sans block mt-0.5 text-xs" x-show="!item.is_live"
                                              :class="item.status === 'archived' ? 'text-gray-400' : 'text-amber-600'"
                                              x-text="item.status"></span>
                                    </span>
                                </template>
                            </td>
                            <td class="px-6 py-3 text-gray-600 max-w-xs">
                                <p class="truncate" x-text="item.product_title"></p>
                                <p class="text-xs text-gray-400 font-mono truncate" x-text="item.path"></p>
                            </td>
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
        scanned:       {{ $session->scannedTotal() }},
        products:      {{ $session->scanned_products }},
        collections:   {{ $session->scanned_collections }},
        totalProducts: {{ $session->total_products }},
        clean:         {{ $session->clean_products }},
        withIssues:    {{ $session->products_with_issues }},
        averageScore:  {{ $session->average_score }},
        issueSummary:  @json($session->issueSummary()),
        filter:        'issues',
        search:        '',
        type:          'all',
        statusFilter:  'live',
        maxBatch:      {{ \App\Http\Controllers\SeoAuditController::MAX_FIX_BATCH }},
        notLive:       {{ $session->not_live_pages }},
        duplicates:    [],
        dupField:      'meta_title',
        items:         [],
        itemTotal:     0,
        currentPage:   1,
        lastPage:      1,
        pollTimer:     null,

        init() {
            if (this.status === 'completed') {
                this.loadItems(1);
                this.loadDuplicates(this.dupField);
            } else if (this.status !== 'failed') {
                this.startPolling();
            }
        },

        /** Whether the audit found any clashing text worth drawing a panel for. */
        hasDuplicates() {
            return this.issueSummary.some(
                i => i.code === 'duplicate_meta_title' || i.code === 'duplicate_meta_description'
            );
        },

        async loadDuplicates(field) {
            this.dupField = field;

            const res  = await fetch(`/seo-audit/${sessionId}/duplicates?field=${field}`);
            const data = await res.json();

            this.duplicates = data.clusters;
        },

        setType(t) {
            this.type = t;
            this.loadItems(1);
        },

        setStatus(s) {
            this.statusFilter = s;
            this.loadItems(1);
        },

        /** How many rows "All" covers, given whichever type tab is active. */
        typeTotal() {
            if (this.type === 'product')    return this.products;
            if (this.type === 'collection') return this.collections;

            return this.scanned;
        },

        startPolling() {
            this.pollTimer = setInterval(() => this.poll(), 3000);
        },

        async poll() {
            const res  = await fetch(`/seo-audit/${sessionId}/status`);
            const data = await res.json();

            this.status        = data.status;
            this.progress      = data.progress;
            this.scanned       = data.scanned_total;
            this.products      = data.scanned_products;
            this.collections   = data.scanned_collections;
            this.notLive       = data.not_live_pages;
            this.totalProducts = data.total_products;
            this.clean         = data.clean_products;
            this.withIssues    = data.products_with_issues;
            this.averageScore  = data.average_score;
            this.issueSummary  = data.issue_summary;

            if (data.status === 'completed' || data.status === 'failed') {
                clearInterval(this.pollTimer);
                if (data.status === 'completed') {
                    this.loadItems(1);
                    this.loadDuplicates(this.dupField);
                }
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
            const sending = Math.min(this.itemTotal, this.maxBatch);
            const cost    = (sending * {{ \App\Http\Controllers\SeoAuditController::COST_PER_PRODUCT_USD }}).toFixed(2);

            let message = `Generate SEO content for ${sending.toLocaleString()} product(s)?\n\n`;

            if (this.itemTotal > this.maxBatch) {
                message += `That is the ${sending.toLocaleString()} worst-scoring of `
                         + `${this.itemTotal.toLocaleString()} — run Fix again afterwards `
                         + `for the remaining ${(this.itemTotal - sending).toLocaleString()}.\n\n`;
            }

            message += `Estimated cost: about $${cost}.\n\n`
                     + `Nothing is written to Shopify yet — you will review everything first, `
                     + `then choose what to push.`;

            const ok = confirm(message);

            if (!ok) event.preventDefault();

            return ok;
        },

        query() {
            return `filter=${encodeURIComponent(this.filter)}`
                 + `&search=${encodeURIComponent(this.search)}`
                 + `&type=${encodeURIComponent(this.type)}`
                 + `&status=${encodeURIComponent(this.statusFilter)}`;
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
