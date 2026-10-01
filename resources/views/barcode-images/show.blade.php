@extends('layouts.app')
@section('title', 'Image grab')
@section('page-title', $session->name ?: 'Image grab')

@section('content')
<div class="space-y-5"
     x-data="barcodeGrab({{ $session->id }}, @js($session->status))"
     x-init="init()">

    @if(session('success'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-5 py-3 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @php($issues = $session->site_issues ?? [])

    {{-- ── Header ──────────────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-gray-200 bg-white p-5">
        <div class="min-w-0">
            <h2 class="text-lg font-semibold text-gray-900">{{ $session->name ?: 'Image grab' }}</h2>
            <p class="mt-1 truncate text-sm text-gray-500">
                @foreach($session->sites() as $i => $siteUrl)
                    @php($issue = $session->issueWith($siteUrl))
                    @if($i > 0)<span class="text-gray-300"> · </span>@endif
                    <a href="{{ $siteUrl }}" target="_blank" rel="noopener noreferrer"
                       class="{{ $issue ? 'text-gray-400 line-through' : 'text-brand-600 hover:text-brand-800' }}">{{ parse_url($siteUrl, PHP_URL_HOST) ?: $siteUrl }}</a>
                    @if($issue)
                        {{-- Said where the site is named, because a note at the
                             bottom of the screen is not where somebody looking
                             at a table of misses is looking. --}}
                        <span class="ml-1 rounded-full bg-{{ $issue['kind'] === 'blocked' ? 'red' : 'amber' }}-100 px-2 py-0.5 text-xs font-medium text-{{ $issue['kind'] === 'blocked' ? 'red' : 'amber' }}-700">
                            {{ $issue['label'] }}
                        </span>
                    @endif
                @endforeach
                · started {{ $session->created_at->format('d M Y, h:i A') }}
                @if($session->user_id !== auth()->id())
                    {{-- Only a super admin reaches somebody else's run, and
                         the Delete button below is destructive, so whose it is
                         belongs on the screen rather than in the URL. --}}
                    · by <span class="font-medium text-gray-700">{{ $session->user?->name ?? 'a deleted user' }}</span>
                @endif
            </p>
        </div>

        <div class="flex items-center gap-2">
            <button type="button" x-show="images > 0 && status === 'completed'" x-cloak
                    @click="showPush = !showPush"
                    class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-gray-700">
                Push to Shopify
            </button>
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

    {{-- ── Push to Shopify ─────────────────────────────────────────────── --}}
    {{--
        The website is chosen every time rather than taken from the active
        store. These pictures were grabbed from one site to be put on another,
        so whatever store the session happens to be on is as likely to be wrong
        as right — and a wrong answer writes images to a live catalogue.
    --}}
    <div x-show="showPush" x-cloak class="rounded-xl border border-gray-200 bg-white p-5">
        <form method="POST" action="{{ route('barcode-images.push', $session) }}" class="space-y-5"
              @submit="pushing = true">
            @csrf

            <div>
                <h3 class="font-semibold text-gray-900">Push these images to Shopify</h3>
                <p class="mt-1 text-sm text-gray-500">
                    Each barcode's pictures are added to the product that barcode finds, in the order they
                    were downloaded. Images already on a product are left alone — these are added after them.
                </p>
            </div>

            <div>
                <label for="store_id" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Which website?
                </label>
                <select id="store_id" name="store_id" required
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">Choose the store to push to…</option>
                    @foreach($stores as $store)
                        <option value="{{ $store->id }}" @selected($session->push_store_id === $store->id)>{{ $store->name }}</option>
                    @endforeach
                </select>
                @error('store_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-gray-500">
                    How should each barcode find its product?
                </span>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ([
                        ['sku_barcode', 'SKU / Barcode', 'The barcode is matched to the product SKU, falling back to the barcode field. The first picture also becomes the variant image.'],
                        ['style_code',  'Style Code',    'The number starting the barcode is matched to the style code starting the product title. Pictures go to the gallery only.'],
                    ] as [$value, $label, $help])
                        <label class="cursor-pointer rounded-xl border p-4 transition-all"
                               :class="matchingMode === '{{ $value }}'
                                   ? 'border-brand-600 bg-brand-50/70 ring-1 ring-brand-600'
                                   : 'border-gray-200 hover:border-brand-300 hover:bg-brand-50/30'">
                            <input type="radio" name="matching_mode" value="{{ $value }}" x-model="matchingMode" class="sr-only">
                            <span class="text-sm font-semibold text-gray-900">{{ $label }}</span>
                            <p class="mt-1 text-xs leading-relaxed text-gray-500">{{ $help }}</p>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" x-bind:disabled="pushing"
                        class="rounded-lg bg-gray-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-gray-700 disabled:opacity-50">
                    <span x-text="pushing ? 'Starting…' : 'Push to Shopify'"></span>
                </button>
                <button type="button" @click="showPush = false" class="text-sm font-medium text-gray-500 hover:text-gray-700">
                    Cancel
                </button>
            </div>
        </form>
    </div>

    {{-- ── How the push is going ───────────────────────────────────────── --}}
    <div x-show="push.status" x-cloak class="rounded-xl border border-gray-200 bg-white p-5">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-sm">
            <span class="font-medium text-gray-700">
                <span x-text="push.status === 'completed' ? 'Pushed' : (push.status === 'failed' ? 'Push failed' : 'Pushing to Shopify')"></span>
                <span class="text-gray-400" x-show="push.store">· <span x-text="push.store"></span></span>
            </span>
            <span class="figure text-gray-500"><span x-text="push.done"></span> of <span x-text="push.total"></span></span>
        </div>

        <div class="h-2 overflow-hidden rounded-full bg-gray-100">
            <div class="h-full rounded-full bg-gray-900 transition-all duration-500" :style="`width: ${push.progress}%`"></div>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <p class="text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">On a product</p>
                <p class="figure mt-1 text-2xl text-green-600" x-text="push.pushed"></p>
            </div>
            <div>
                <p class="text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">Not matched</p>
                <p class="figure mt-1 text-2xl text-gray-500" x-text="push.failed"></p>
            </div>
        </div>

        <p x-show="push.error" x-cloak class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700" x-text="push.error"></p>
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

        @if($issues)
            <div class="mt-4 space-y-3">
                @foreach($issues as $siteUrl => $issue)
                    <div class="rounded-lg border px-4 py-3 text-sm {{ $issue['kind'] === 'blocked' ? 'border-red-200 bg-red-50 text-red-800' : 'border-amber-200 bg-amber-50 text-amber-800' }}">
                        <p class="font-semibold">
                            {{ parse_url($siteUrl, PHP_URL_HOST) ?: $siteUrl }} — {{ $issue['label'] }}
                        </p>
                        <p class="mt-1 leading-relaxed">{{ $issue['why'] }}</p>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- The same words are already above, site by site, when there are
             site notes — one copy of a reason is enough. --}}
        @if(!$issues)
            <p x-show="error" x-cloak class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700" x-text="error"></p>
        @endif
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
                        <th class="px-5 py-3 text-left" x-show="push.status">On Shopify</th>
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
                                {{-- Which of the run's websites these pictures came from, so a
                                     source whose photography is not wanted can be spotted. --}}
                                <span x-show="item.source_site && {{ count($session->sites()) > 1 ? 'true' : 'false' }}"
                                      class="mt-0.5 block truncate text-xs text-gray-400"
                                      x-text="item.source_site && item.source_site.replace(/^https?:\/\//, '')"></span>
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
                            <td class="px-5 py-3" x-show="push.status">
                                <template x-if="item.push_status">
                                    <div>
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium"
                                              :class="item.push_status === 'pushed'
                                                  ? 'bg-green-100 text-green-700'
                                                  : (item.push_status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700')"
                                              x-text="item.push_status === 'pushed'
                                                  ? item.pushed_images + ' sent'
                                                  : (item.push_status === 'failed' ? 'Failed' : 'Skipped')"></span>
                                        <p x-show="item.shopify_product_title" class="mt-1 truncate text-xs text-gray-500"
                                           x-text="item.shopify_product_title"></p>
                                        <p x-show="item.push_message" class="mt-1 text-xs text-gray-400" x-text="item.push_message"></p>
                                    </div>
                                </template>
                                <span x-show="!item.push_status" class="text-gray-300">—</span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <a x-show="item.image_count > 0"
                                   :href="`/barcode-images/{{ $session->id }}/download/${encodeURIComponent(item.barcode)}`"
                                   class="text-xs font-medium text-gray-500 hover:text-gray-800">Download</a>
                            </td>
                        </tr>
                    </template>

                    <tr x-show="items.length === 0">
                        <td colspan="6" class="px-5 py-12 text-center text-sm text-gray-400">
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

        showPush:     false,
        pushing:      false,
        matchingMode: @js($session->push_matching_mode ?: 'sku_barcode'),
        push: {
            status:   @js($session->push_status),
            total:    {{ $session->push_total }},
            done:     {{ $session->push_done }},
            pushed:   {{ $session->push_pushed }},
            failed:   {{ $session->push_failed }},
            progress: {{ $session->pushProgressPercent() }},
            store:    @js($session->pushStore?->name),
            error:    @js($session->push_error),
        },

        filter:    'all',
        search:    '',
        items:     [],
        page:      1,
        lastPage:  1,
        pollTimer: null,

        init() {
            this.loadItems(1);

            // A run in flight fills the table as it goes, so the rows are
            // reloaded on the same beat as the counters. A push does the same
            // to the push column, so either one being live keeps the poll
            // going and finishing both stops it.
            if (this.busy()) {
                this.pollTimer = setInterval(() => this.poll(), 3000);
            }
        },

        busy() {
            const grabbing = this.status !== 'completed' && this.status !== 'failed';
            const pushing  = this.push.status === 'pending' || this.push.status === 'pushing';

            return grabbing || pushing;
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
            this.push      = data.push;

            this.loadItems(this.page);

            if (!this.busy()) {
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
