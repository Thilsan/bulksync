@extends('layouts.app')
@section('title', $session->name)
@section('page-title', 'Photo Editor')

@section('content')
<style>
    /* Transparent cutouts need something behind them, or a PNG with its
       background removed reads as a blank card. */
    .checkerboard {
        background-image:
            linear-gradient(45deg, #e9edf2 25%, transparent 25%),
            linear-gradient(-45deg, #e9edf2 25%, transparent 25%),
            linear-gradient(45deg, transparent 75%, #e9edf2 75%),
            linear-gradient(-45deg, transparent 75%, #e9edf2 75%);
        background-size: 16px 16px;
        background-position: 0 0, 0 8px, 8px -8px, -8px 0;
    }
</style>

<div x-data="photoReview({{ $session->id }})" x-init="init()" class="space-y-5">

    {{-- ── Heading ─────────────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="truncate text-lg font-semibold text-gray-900">{{ $session->name }}</h2>
            <p class="mt-0.5 text-sm text-gray-500">
                {{ $session->editSummary() }}
                &middot; {{ $session->store?->name ?? 'No store' }}
                &middot; {{ $session->created_at->format('d M Y H:i') }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('photo-editor.index') }}"
               class="rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-600 transition-colors hover:border-gray-300 hover:text-gray-800">
                New edit
            </a>
            <form method="POST" action="{{ route('photo-editor.destroy', $session) }}"
                  onsubmit="return confirm('Delete this session and every edited file it produced?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="rounded-lg border border-red-200 bg-white px-3.5 py-2 text-sm font-medium text-red-600 transition-colors hover:bg-red-50">
                    Delete
                </button>
            </form>
        </div>
    </div>

    @if (session('info'))
    <div class="rounded-xl border border-brand-200 bg-brand-50 px-5 py-3 text-sm text-brand-800">{{ session('info') }}</div>
    @endif

    @if ($isSandbox)
    <div class="rounded-xl border border-brand-200 bg-brand-50 px-5 py-3 text-sm text-brand-800">
        <strong>Sandbox key</strong> — these results carry a Photoroom watermark. Switch to the live key before
        pushing anything you intend customers to see.
    </div>
    @endif

    {{-- A run that never started has one thing worth saying, and it is this. --}}
    <template x-if="sessionError">
        <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-4">
            <p class="text-sm font-medium text-red-800">This run stopped</p>
            <p class="mt-0.5 text-sm text-red-700" x-text="sessionError"></p>
        </div>
    </template>

    {{-- ── Progress ────────────────────────────────────────────────────── --}}
    <div class="rounded-xl border border-gray-200 bg-white p-5" x-show="!isFinished || stats.working > 0" x-cloak>
        <div class="flex items-baseline justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand-400 opacity-75"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-brand-500"></span>
                </span>
                <h3 class="text-sm font-semibold text-gray-800"
                    x-text="scanStatus === 'scanning' ? 'Reading your OneDrive folder…' : 'Editing images…'"></h3>
            </div>
            <span class="text-sm font-semibold tabular-nums text-brand-700" x-text="progress + '%'"></span>
        </div>
        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-gray-100">
            <div class="h-full rounded-full bg-brand-500 transition-all" :style="`width: ${progress}%`"></div>
        </div>
        <p class="mt-2 text-xs tabular-nums text-gray-400">
            <span x-text="stats.edited + stats.pushed"></span> of <span x-text="stats.total"></span> done
            <template x-if="stats.pending > 0"><span> · <span x-text="stats.pending"></span> queued</span></template>
        </p>
    </div>

    {{-- ── Counts ──────────────────────────────────────────────────────── --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ([
            ['total',   'Found',       'gray'],
            ['edited',  'Ready',       'emerald'],
            ['pushed',  'On Shopify',  'brand'],
            ['skipped', 'No match',    'amber'],
            ['failed',  'Failed',      'red'],
        ] as [$key, $label, $color])
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-400">{{ $label }}</p>
            <p class="mt-1.5 text-2xl font-semibold tabular-nums text-{{ $color }}-600" x-text="stats.{{ $key }}"></p>
        </div>
        @endforeach
    </div>

    {{-- ── Selection toolbar ───────────────────────────────────────────── --}}
    <div class="sticky top-2 z-20 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white/95 px-5 py-3 shadow-sm backdrop-blur"
         x-show="readyCount > 0" x-cloak>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" @click="selectAll()"
                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 transition-colors hover:border-gray-400">
                Select all ready
            </button>
            <button type="button" @click="selectNone()"
                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 transition-colors hover:border-gray-400">
                Clear
            </button>
            <span class="text-xs text-gray-500">
                <strong class="tabular-nums text-gray-800" x-text="selectedCount"></strong>
                of <span class="tabular-nums" x-text="readyCount"></span> selected
            </span>
        </div>

        <div class="flex items-center gap-3">
            <template x-if="pushResult">
                <span class="text-xs font-medium"
                      :class="pushResult.error ? 'text-red-600' : 'text-emerald-700'"
                      x-text="pushResult.error ? pushResult.error : pushResult.queued + ' queued for Shopify'"></span>
            </template>
            <button type="button" @click="confirmOpen = true" :disabled="selectedCount === 0 || pushing"
                class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50">
                <svg x-show="pushing" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4z"/>
                </svg>
                <span x-text="pushing ? 'Sending…' : `Push ${selectedCount} to Shopify`"></span>
            </button>
        </div>
    </div>

    {{-- ── Confirm before writing to the shop ──────────────────────────────

         Pushing is the one action here that leaves the app and cannot be taken
         back: it writes to a live storefront, and with the ordering in place it
         also decides which photo customers see first in every collection grid.
         Everything before this point is reversible by re-running.
    --}}
    <div x-show="confirmOpen" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="confirmOpen = false"
         role="dialog" aria-modal="true" aria-labelledby="push-confirm-title">

        <div class="absolute inset-0 bg-gray-900/50" @click="confirmOpen = false"></div>

        <div class="relative w-full max-w-md overflow-hidden rounded-xl bg-white shadow-xl">
            <div class="px-5 py-4">
                <h2 id="push-confirm-title" class="text-sm font-semibold text-gray-900">
                    Send <span x-text="selectedCount"></span>
                    <span x-text="selectedCount === 1 ? 'image' : 'images'"></span> to
                    {{ $session->store?->name ?? 'the active website' }}?
                </h2>

                <ul class="mt-3 space-y-2 text-xs text-gray-600">
                    <li class="flex gap-2">
                        <span class="text-gray-400">&bull;</span>
                        <span>
                            They go to
                            <strong class="font-semibold text-gray-800" x-text="skuCount"></strong>
                            <span x-text="skuCount === 1 ? 'product' : 'products'"></span>,
                            matched by SKU.
                        </span>
                    </li>
                    <li class="flex gap-2">
                        <span class="text-gray-400">&bull;</span>
                        <span>
                            The first photo of each product becomes its <strong class="font-semibold text-gray-800">main
                            image</strong>. Photos already on that product move down.
                        </span>
                    </li>
                    <li class="flex gap-2">
                        <span class="text-gray-400">&bull;</span>
                        <span>This appears on the live website. Removing an image afterwards is done in Shopify, not here.</span>
                    </li>
                </ul>
            </div>

            <div class="flex justify-end gap-2 border-t border-gray-100 bg-gray-50 px-5 py-3">
                <button type="button" @click="confirmOpen = false"
                        class="rounded-lg border border-gray-300 bg-white px-4 py-1.5 text-xs font-medium text-gray-700 transition-colors hover:border-gray-400">
                    Cancel
                </button>
                <button type="button" @click="confirmOpen = false; push()"
                        class="rounded-lg bg-brand-600 px-4 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-brand-700">
                    Send <span x-text="selectedCount"></span> to Shopify
                </button>
            </div>
        </div>
    </div>

    {{-- ── Results grid ────────────────────────────────────────────────── --}}
    <template x-if="items.length === 0 && isFinished">
        <div class="rounded-xl border border-gray-200 bg-white px-6 py-16 text-center">
            <p class="font-semibold text-gray-800">Nothing to show</p>
            <p class="mx-auto mt-1 max-w-sm text-sm text-gray-500">No images came back from that folder.</p>
        </div>
    </template>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <template x-for="item in items" :key="item.id">
            <div class="overflow-hidden rounded-xl border bg-white transition-colors"
                 :class="isSelected(item) ? 'border-brand-500 ring-1 ring-brand-500' : 'border-gray-200'">

                {{-- Image. Hovering swaps to the original, which is the fastest
                     way to judge whether the edit actually worked. --}}
                <div class="group relative aspect-square checkerboard">
                    <template x-if="item.after_url">
                        <img :src="item.after_url" :alt="item.filename"
                             class="absolute inset-0 h-full w-full object-contain">
                    </template>

                    <template x-if="item.before_url">
                        <img :src="item.before_url" :alt="'Original ' + item.filename"
                             class="absolute inset-0 h-full w-full bg-white object-contain opacity-0 transition-opacity group-hover:opacity-100">
                    </template>

                    <template x-if="!item.after_url && !item.before_url">
                        <div class="absolute inset-0 grid place-items-center bg-gray-50">
                            <span class="text-xs text-gray-400" x-text="item.status_label"></span>
                        </div>
                    </template>

                    {{-- Only shows while hovering, so the label never lies about
                         which of the two is on screen. --}}
                    <span x-show="item.before_url"
                          class="pointer-events-none absolute left-2 top-2 hidden rounded-md bg-gray-900/80 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white group-hover:block">
                        Before
                    </span>

                    {{-- The generative apparel modes are never actually used —
                         every item that asked for one gets a plain cutout
                         instead, to keep the original color and orientation
                         intact. This flags that a redraw was requested but
                         not applied, so it is not mistaken for a failure.
                         Bottom-left, clear of both the "Before" hover label
                         and the status badge, so nothing overlaps on hover. --}}
                    <template x-if="item.view_type">
                        <span class="pointer-events-none absolute bottom-2 left-2 rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                              :class="item.apparel_mode_applied === 'none' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'"
                              x-text="item.view_type.replace('_', ' ') + ' view · ' + ({
                                  mannequin_removed: 'mannequin removed',
                                  segmented:         'mannequin segmented out',
                                  generative:        'redrawn by AI',
                              }[item.apparel_mode_applied] || 'cutout only')"></span>
                    </template>

                    {{-- Photoroom reports how sure it was of each cutout, free,
                         in a response header. A batch is mostly fine and the
                         eye glazes over by the tenth thumbnail, so the ones it
                         doubted are called out rather than left to be spotted.
                         Top-left: the status badge owns the right, the view
                         label the bottom. --}}
                    <template x-if="item.looks_uncertain">
                        <span class="pointer-events-none absolute left-2 top-2 rounded-md bg-amber-500 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white"
                              :title="'Photoroom rated this cutout ' + Math.round(item.uncertainty_score * 100) + '% uncertain'">
                            Check cutout
                        </span>
                    </template>

                    <span class="absolute right-2 top-2 rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                          :class="{
                              'bg-emerald-100 text-emerald-700': item.status === 'edited',
                              'bg-brand-100 text-brand-700':     item.status === 'pushed',
                              'bg-indigo-100 text-indigo-700':   item.status === 'editing' || item.status === 'pushing',
                              'bg-red-100 text-red-700':         item.status === 'failed',
                              'bg-amber-100 text-amber-700':     item.status === 'skipped',
                              'bg-gray-100 text-gray-600':       item.status === 'pending',
                          }"
                          x-text="item.status_label"></span>

                    <div class="absolute bottom-2 right-2 flex items-center gap-1.5">
                        {{-- Generative steps (AI backgrounds, mannequin removal) are
                             non-deterministic, so a disliked result is often worth
                             just trying again rather than reworking the whole session. --}}
                        <button type="button"
                                x-show="item.after_url && !['editing', 'pushing', 'pending'].includes(item.status)"
                                @click="reedit(item)"
                                class="rounded-md bg-gray-900/70 px-2 py-1 text-[10px] font-medium text-white opacity-0 transition-opacity hover:bg-gray-900 group-hover:opacity-100">
                            Re-edit
                        </button>
                        <button type="button" x-show="item.full_url" @click="lightbox = item"
                                class="rounded-md bg-gray-900/70 px-2 py-1 text-[10px] font-medium text-white opacity-0 transition-opacity group-hover:opacity-100">
                            Full size
                        </button>
                    </div>
                </div>

                <div class="space-y-2 border-t border-gray-100 px-3.5 py-3">
                    <label class="flex cursor-pointer items-start gap-2.5"
                           :class="item.pushable ? '' : 'cursor-not-allowed opacity-60'">
                        <input type="checkbox" :disabled="!item.pushable"
                               :checked="isSelected(item)" @change="toggle(item)"
                               class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        <span class="min-w-0">
                            <span class="block truncate text-xs font-medium text-gray-800" x-text="item.filename"></span>
                            <span class="block truncate font-mono text-[11px] text-gray-400" x-text="item.sku"></span>
                        </span>
                    </label>

                    <template x-if="item.product_title">
                        <p class="truncate text-[11px] text-gray-500" x-text="item.product_title"></p>
                    </template>

                    <template x-if="item.error">
                        <p class="text-[11px] leading-relaxed text-red-600" x-text="item.error"></p>
                    </template>

                    <template x-if="item.edited_kb > 0">
                        <p class="text-[11px] tabular-nums text-gray-400">
                            <span x-text="item.original_kb"></span> KB → <span x-text="item.edited_kb"></span> KB
                        </p>
                    </template>
                </div>
            </div>
        </template>
    </div>

    {{-- Pagination — only earns its space once there is more than one page. --}}
    <div class="flex items-center justify-center gap-2" x-show="pagination.last_page > 1" x-cloak>
        <button @click="goToPage(pagination.current_page - 1)" :disabled="pagination.current_page <= 1"
            class="rounded border border-gray-200 px-3 py-1.5 text-xs text-gray-600 transition-colors hover:bg-gray-50 disabled:opacity-30">
            ← Prev
        </button>
        <span class="px-3 py-1.5 text-xs font-medium tabular-nums text-gray-600"
              x-text="pagination.current_page + ' / ' + pagination.last_page"></span>
        <button @click="goToPage(pagination.current_page + 1)" :disabled="pagination.current_page >= pagination.last_page"
            class="rounded border border-gray-200 px-3 py-1.5 text-xs text-gray-600 transition-colors hover:bg-gray-50 disabled:opacity-30">
            Next →
        </button>
    </div>

    <p class="text-center text-xs text-gray-400">
        Edited files are kept for {{ $retentionDays }} days, then cleared automatically.
        Images already pushed stay on Shopify.
    </p>

    {{-- ── Lightbox ────────────────────────────────────────────────────── --}}
    <div x-show="lightbox" x-cloak @keydown.escape.window="lightbox = null"
         class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/80 p-6 backdrop-blur-sm">
        <div class="max-h-full w-full max-w-4xl overflow-hidden rounded-2xl bg-white shadow-2xl" @click.outside="lightbox = null">
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-gray-800" x-text="lightbox?.filename"></p>
                    <p class="truncate font-mono text-xs text-gray-400" x-text="lightbox?.sku"></p>
                </div>
                <button type="button" @click="lightbox = null" class="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div class="grid gap-px bg-gray-100 sm:grid-cols-2">
                <div class="bg-white p-4">
                    <p class="mb-2 text-center text-[10px] font-semibold uppercase tracking-wide text-gray-400">Before</p>
                    <img :src="lightbox?.before_url" class="mx-auto max-h-[60vh] object-contain" alt="Original">
                </div>
                <div class="bg-white p-4">
                    <p class="mb-2 text-center text-[10px] font-semibold uppercase tracking-wide text-gray-400">After</p>
                    <div class="checkerboard">
                        <img :src="lightbox?.full_url" class="mx-auto max-h-[60vh] object-contain" alt="Edited">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function photoReview(sessionId) {
    return {
        items:        [],
        stats:        { total: 0, edited: 0, pushed: 0, failed: 0, skipped: 0, working: 0, pending: 0 },
        progress:     0,
        isFinished:   {{ $session->isFinished() ? 'true' : 'false' }},
        scanStatus:   '{{ $session->scan_status }}',
        sessionError: @json($session->error_message),
        pagination:   { current_page: 1, last_page: 1, total: 0 },

        /*
         * Selection is held here rather than read back off the server on every
         * poll: the poll runs every four seconds while editing, and adopting the
         * server's answer each time would undo a tick made a moment earlier.
         * `known` is what lets a newly-finished image arrive pre-selected
         * without re-selecting one that was deliberately unticked.
         */
        selectedIds: {},
        known:       {},

        pushing:    false,
        pushResult: null,

        // Pushing is the one action that leaves the app, so it is asked about
        // rather than done on the first click.
        confirmOpen: false,
        lightbox:   null,
        timer:      null,

        init() {
            this.poll();
            if (!this.isFinished) {
                this.timer = setInterval(() => this.poll(), 4000);
            }
        },

        get readyCount() {
            return this.items.filter(i => i.pushable).length;
        },

        get selectedCount() {
            return this.items.filter(i => i.pushable && this.selectedIds[i.id]).length;
        },

        /*
         * Products, not photos. Five images across two products is a smaller
         * thing than five images across five, and it is the products that get
         * their main image rearranged.
         */
        get skuCount() {
            return new Set(
                this.items
                    .filter(i => i.pushable && this.selectedIds[i.id])
                    .map(i => i.sku)
            ).size;
        },

        isSelected(item) {
            return !!this.selectedIds[item.id];
        },

        toggle(item) {
            if (!item.pushable) return;
            this.selectedIds[item.id] = !this.selectedIds[item.id];
        },

        selectAll() {
            this.items.filter(i => i.pushable).forEach(i => { this.selectedIds[i.id] = true; });
        },

        selectNone() {
            this.items.forEach(i => { this.selectedIds[i.id] = false; });
        },

        async poll() {
            try {
                const res  = await fetch(`/photo-editor/${sessionId}/status?page=${this.pagination.current_page}`, {
                    headers: { 'Accept': 'application/json' }
                });
                if (!res.ok) return;

                const data = await res.json();
                const s    = data.session;

                this.progress     = s.progress;
                this.isFinished   = s.is_finished;
                this.scanStatus   = s.scan_status;
                this.sessionError = s.error;
                this.stats        = {
                    total:   s.total,   edited:  s.edited,  pushed:  s.pushed,
                    failed:  s.failed,  skipped: s.skipped, working: s.working,
                    pending: s.pending,
                };

                this.items      = data.items;
                this.pagination = data.pagination ?? this.pagination;

                // Anything seen for the first time takes the server's answer;
                // everything else keeps whatever the person chose here.
                data.items.forEach(i => {
                    if (!this.known[i.id]) {
                        this.known[i.id] = true;
                        if (i.pushable) this.selectedIds[i.id] = i.selected;
                    }
                });

                if (s.is_finished && s.working === 0 && this.timer) {
                    clearInterval(this.timer);
                    this.timer = null;
                }
            } catch (e) {
                console.error('Poll error', e);
            }
        },

        async goToPage(p) {
            const clamped = Math.max(1, Math.min(p, this.pagination.last_page));
            this.pagination = { ...this.pagination, current_page: clamped };
            await this.poll();
        },

        async push() {
            const ids = this.items.filter(i => i.pushable && this.selectedIds[i.id]).map(i => i.id);
            if (ids.length === 0) return;

            this.pushing    = true;
            this.pushResult = null;

            try {
                const res = await fetch(`/photo-editor/${sessionId}/push`, {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({ item_ids: ids }),
                });

                if (res.status === 419) throw new Error('Session expired — refresh the page and try again.');
                if (!res.ok)            throw new Error('Shopify push could not be started.');

                this.pushResult = await res.json();

                // Statuses move as the queue works through them, so start
                // watching again even if editing had already finished.
                if (!this.timer) {
                    this.timer = setInterval(() => this.poll(), 4000);
                }
                this.poll();
            } catch (e) {
                this.pushResult = { error: e.message || String(e) };
            } finally {
                this.pushing = false;
            }
        },

        /*
         * Sends one photo back through Photoroom. The item is mutated in
         * place (rather than waiting for the next poll) so the button
         * disappears immediately instead of staying clickable for up to 4s.
         */
        async reedit(item) {
            if (['editing', 'pushing', 'pending'].includes(item.status)) return;

            const previous = { status: item.status, status_label: item.status_label, error: item.error, pushable: item.pushable };
            item.status       = 'pending';
            item.status_label = 'Queued';
            item.error         = null;
            item.pushable      = false;
            this.selectedIds[item.id] = false;

            try {
                const res = await fetch(`/photo-editor/${sessionId}/item/${item.id}/reedit`, {
                    method:  'POST',
                    headers: {
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                });

                if (res.status === 419) throw new Error('Session expired — refresh the page and try again.');
                if (!res.ok) {
                    const body = await res.json().catch(() => null);
                    throw new Error(body?.error || 'Could not queue this photo for re-edit.');
                }

                // Polling may have stopped once the session first finished;
                // start it again so the new result actually shows up.
                this.isFinished = false;
                if (!this.timer) {
                    this.timer = setInterval(() => this.poll(), 4000);
                }
                this.poll();
            } catch (e) {
                item.status       = previous.status;
                item.status_label = previous.status_label;
                item.pushable      = previous.pushable;
                item.error         = e.message || String(e);
            }
        },
    };
}
</script>
@endsection
