@extends('layouts.app')
@section('title', 'Management Dashboard')
@section('page-title', 'Management Dashboard')

@php
    /*
     * This tab counts parcels and says nothing about money — the revenue,
     * average order value and net figures it used to carry belong to Ecom
     * Order Analytics, which is the tab people open to ask that question.
     *
     * Nulls arrive on an empty range — see the empty state below — so every
     * formatter here has to survive one rather than print NaN.
     */
    $num   = fn ($v) => $v === null ? '—' : number_format((int) $v);
    $pct   = fn ($v) => $v === null ? '—' : number_format((float) $v, 1) . '%';

    // Every bar on the page is a percentage of the biggest value beside it.
    $share = fn ($v, $max) => $max > 0 ? max(1.5, $v / $max * 100) : 0;

    /*
     * Tailwind's JIT never sees a class that was built at runtime, so the
     * tones are spelled out here and looked up by key — the same reason the
     * main dashboard keeps a table like this one.
     */
    $fill = [
        'emerald' => 'bg-emerald-500',
        'sky'     => 'bg-sky-500',
        'rose'    => 'bg-rose-500',
        'amber'   => 'bg-amber-400',
        'gray'    => 'bg-gray-400',
    ];
@endphp

@section('content')
<div class="space-y-5" x-data="{ busy: false, going: null }">

    {{-- ── Tabs ─────────────────────────────────────────────────────────────
         Views of the same business: what shipped, what sold, who visited, and
         what the studio is making. Plain links, so each tab is its own
         shareable URL.

         A tab that has to ask Shopify or Google for a range nobody has looked
         at yet takes seconds to answer, and a plain link gives no sign it was
         even clicked. Marking the click busy puts the skeleton up on the page
         being left, which is the only page there is until the next one
         arrives. --}}
    <div class="border-b border-gray-200 flex items-center gap-1" role="tablist">
        @foreach($tabs as $key => $label)
            {{-- Every tab but Studio shares the date range, so switching keeps
                 it; Studio runs on its own clock and ignores it. --}}
            <a href="{{ route('orders.dashboard', $key === 'studio' ? ['tab' => $key] : array_merge(request()->query(), ['tab' => $key])) }}"
               role="tab" @if($tab === $key) aria-selected="true" @endif
               @if($tab !== $key) @click="busy = true; going = '{{ $key }}'" @endif
               class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors inline-flex items-center gap-1.5
                      {{ $tab === $key
                          ? 'border-brand-600 text-brand-700'
                          : 'border-transparent text-gray-500 hover:text-gray-800 hover:border-gray-300' }}">
                {{ $label }}
                <svg x-show="going === '{{ $key }}'" x-cloak class="h-3.5 w-3.5 animate-spin text-brand-600"
                     fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                </svg>
            </a>
        @endforeach
    </div>

    @if($tab === 'studio')
        {{-- Read-only by design: management asked to see the numbers, not to
             start work from here. The marker lets a test hold that line.

             Nothing here waits on an API, but leaving for a tab that does
             still needs to look like it is happening. --}}
        <template x-if="busy">
            @include('orders.loading')
        </template>
        <div data-tab="studio" x-show="!busy">
            @include('orders.studio', $workspace)
        </div>
    @elseif($tab === 'analytics')
        {{-- space-y here, not on the partial: its filter bar and its content
             are siblings, and without it the bar sits flush on the tiles. --}}
        <div data-tab="analytics" class="space-y-5">
            @include('orders.analytics', ['rows' => $analytics, 'totals' => $analyticsTotals])
        </div>
    @elseif($tab === 'sessions')
        <div data-tab="sessions" class="space-y-5">
            @include('orders.sessions', ['rows' => $sessions, 'totals' => $sessionsTotals])
        </div>
    @else

    {{-- ── Filters ──────────────────────────────────────────────────────────
         One GET form, so the whole view lives in the URL and can be sent to
         somebody. The preset chips are submit buttons rather than links, which
         is what keeps the date basis and the platform picker with them. --}}
    <form method="GET" action="{{ route('orders.dashboard') }}" @submit="busy = true"
          class="bg-white rounded-xl border border-gray-200 shadow-sm p-3 flex flex-wrap items-center gap-2">

        <div class="flex flex-wrap items-center gap-1">
            @foreach($presets as $key => $label)
                <button type="submit" name="preset" value="{{ $key }}"
                        class="px-2.5 py-1.5 text-xs font-medium rounded-lg border transition-colors
                               {{ $filters['preset'] === $key
                                   ? 'bg-brand-50 border-brand-200 text-brand-700'
                                   : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="flex items-center gap-1.5 ml-auto">
            <input type="date" name="from" value="{{ $filters['from']->format('Y-m-d') }}"
                   aria-label="From date"
                   class="rounded-lg border border-gray-300 px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500">
            <span class="text-gray-400 text-xs">to</span>
            <input type="date" name="to" value="{{ $filters['to']->format('Y-m-d') }}"
                   aria-label="To date"
                   class="rounded-lg border border-gray-300 px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500">

            <select name="basis" aria-label="Which date to filter on"
                    class="rounded-lg border border-gray-300 px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500">
                @foreach($bases as $key => $label)
                    <option value="{{ $key }}" @selected($filters['basis'] === $key)>{{ $label }}</option>
                @endforeach
            </select>

            {{-- Platform picker. Twenty-three storefronts is too many for a row
                 of checkboxes and too few to need a search. --}}
            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" @click="open = !open"
                        class="rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs text-gray-700 hover:bg-gray-50 flex items-center gap-1.5">
                    {{ $filters['platforms'] ? count($filters['platforms']) . ' platforms' : 'All platforms' }}
                    <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                <div x-show="open" x-cloak x-transition.opacity
                     class="absolute right-0 mt-1 w-56 max-h-72 overflow-y-auto bg-white rounded-lg border border-gray-200 shadow-lg z-20 p-2">
                    <div class="flex gap-2 px-1 pb-2 border-b border-gray-100 mb-1">
                        <button type="button" class="text-xs text-brand-600 hover:underline"
                                @click="$el.closest('div').parentElement.querySelectorAll('input[type=checkbox]').forEach(c => c.checked = true)">All</button>
                        <button type="button" class="text-xs text-gray-500 hover:underline"
                                @click="$el.closest('div').parentElement.querySelectorAll('input[type=checkbox]').forEach(c => c.checked = false)">None</button>
                    </div>
                    @foreach($platforms as $slug)
                        <label class="flex items-center gap-2 px-1 py-1 rounded hover:bg-gray-50 cursor-pointer">
                            <input type="checkbox" name="platforms[]" value="{{ $slug }}"
                                   @checked(in_array($slug, $filters['platforms'], true))
                                   class="w-3.5 h-3.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                            <span class="text-xs text-gray-700">{{ $slug }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <button type="submit" name="preset" value="custom"
                    class="px-3 py-1.5 text-xs font-medium text-white rounded-lg" style="background-color:#1d5a74">
                <span x-show="!busy">Apply</span>
                <span x-show="busy" x-cloak>Loading…</span>
            </button>
        </div>
    </form>

    {{-- Skeletons while the next range is on its way. The filter bar above
         stays live, so a mis-click can be corrected without waiting. --}}
    <template x-if="busy">
        @include('orders.loading')
    </template>

    {{-- space-y-5 here, not on the sections inside: the skeleton above
         replaces this whole block, so the gap between the cards has to belong
         to the wrapper rather than to what it happens to be holding. --}}
    <div x-show="!busy" class="space-y-5">

    {{-- ── Error ────────────────────────────────────────────────────────────
         The endpoint writes its messages for people, so they are shown as
         written. A 401 is a configuration problem and says so — retrying it
         forever is the one thing that will not help. --}}
    @if(! $result['ok'])
        @php $isAuth = in_array($result['status'], [401, 403], true); @endphp
        <div class="bg-white rounded-xl border {{ $isAuth ? 'border-amber-200' : 'border-red-200' }} shadow-sm p-5">
            <p class="text-sm font-semibold {{ $isAuth ? 'text-amber-800' : 'text-red-800' }}">
                {{ $isAuth ? 'Orders service not authorised' : 'Could not load orders' }}
            </p>
            <p class="text-sm text-gray-600 mt-1">{{ $result['message'] }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                @unless($isAuth)
                    <a href="{{ request()->fullUrl() }}"
                       class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">Retry</a>
                @endunless
                @if($fallback)
                    <a href="{{ route('orders.dashboard', $fallback) }}"
                       class="px-3 py-1.5 text-xs font-medium rounded-lg text-white" style="background-color:#1d5a74">
                        Try 2024 onwards
                    </a>
                @endif
            </div>
        </div>
    @endif

    @if($summary)
        @php
            $t = $summary['totals']; $d = $summary['deltas'];

            // Read once, in a fixed Web-then-Manual order rather than
            // whatever order the endpoint happens to return, so the KPI
            // tiles and the Web vs Manual card below always agree.
            $web      = collect($summary['types'])->firstWhere('order_type', 'Web');
            $manual   = collect($summary['types'])->firstWhere('order_type', 'Manual');
            $typeRows = collect([$web, $manual])->filter();
        @endphp

        {{-- ── Empty ────────────────────────────────────────────────────── --}}
        @if($summary['empty'])
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-10 text-center">
                <p class="text-sm font-medium text-gray-800">No orders in this range</p>
                <p class="text-sm text-gray-500 mt-1">
                    Widen the dates, or switch off
                    <span class="font-medium">{{ $bases[$filters['basis']] }}</span> —
                    delivery dates exclude anything not yet delivered.
                </p>
            </div>
        @else

        {{-- ── KPIs ───────────────────────────────────────────────────────
             How many orders there are, and where they are. The three status
             tiles read off the same grouping as the Order status card below,
             so the headline and the breakdown cannot drift apart. --}}
        @php
            $counts  = $summary['outcome_counts'];
            $ordered = max(1, (int) ($t['total_orders'] ?? 0));

            // Not $share — that one is the bar-width helper at the top of the
            // page, and is still needed by everything below these tiles.
            $ofOrders = fn (int $n) => $pct($n / $ordered * 100) . ' of orders';

            $tiles = [
                ['label' => 'Orders',     'value' => $num($t['total_orders'] ?? 0), 'delta' => $d['orders'], 'tone' => null],
                ['label' => 'Completed',  'value' => $num($counts['completed']),    'delta' => null, 'tone' => 'emerald', 'note' => $ofOrders($counts['completed'])],
                ['label' => 'Processing', 'value' => $num($counts['processing']),   'delta' => null, 'tone' => 'sky',     'note' => $ofOrders($counts['processing'])],
                ['label' => 'Cancelled',  'value' => $num($counts['cancelled']),    'delta' => null, 'tone' => 'rose',    'note' => $ofOrders($counts['cancelled'])],
            ];
        @endphp
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach($tiles as $i => $tile)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide inline-flex items-center gap-1.5">
                            @if($tile['tone'])
                                <span class="w-2 h-2 rounded-sm {{ $fill[$tile['tone']] }}"></span>
                            @endif
                            {{ $tile['label'] }}
                        </p>
                        @if($tile['delta'] !== null)
                            @php $up = $tile['delta'] >= 0; @endphp
                            <span class="text-xs font-medium tabular-nums {{ $up ? 'text-emerald-600' : 'text-rose-600' }}">
                                {{ $up ? '▲' : '▼' }} {{ number_format(abs($tile['delta']), 1) }}%
                            </span>
                        @endif
                    </div>
                    <p class="mt-3 text-2xl font-semibold text-gray-900 tabular-nums leading-none">{{ $tile['value'] }}</p>

                    @if($i === 0)
                        {{-- How the orders were placed, so the most-read tile on
                             the page does not blend a staff-entered order into a
                             website one silently. --}}
                        @if($typeRows->isNotEmpty())
                            <p class="text-xs text-gray-400 mt-2">
                                {{ $typeRows->map(fn ($r) => $num($r['orders']) . ' ' . strtolower($r['order_type']))->join(' · ') }}
                            </p>
                        @endif
                    @else
                        <p class="text-xs text-gray-400 mt-2">{{ $tile['note'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- ── Over time ────────────────────────────────────────────────── --}}
        @php
            $series    = $summary['series'];
            $maxOrders = max(1, ...array_map(fn ($r) => $r['orders'], $series ?: [['orders' => 0]]));
            $unit      = $summary['monthly'] ? 'Monthly' : 'Daily';
        @endphp
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-gray-800">{{ $unit }} orders</h3>
                <span class="text-xs text-gray-400">Peak {{ $num($maxOrders) }}</span>
            </div>
            <div class="px-5 py-4 overflow-x-auto">
                <div class="flex items-end gap-[3px] h-44 min-w-full" style="min-width: {{ max(300, count($series) * 14) }}px">
                    @foreach($series as $bucket)
                        <div class="flex-1 flex items-end justify-center h-full group relative"
                             title="{{ $bucket['label'] }} — {{ $num($bucket['orders']) }} orders">
                            <div class="w-full bg-brand-500 rounded-t-sm" style="height: {{ $share($bucket['orders'], $maxOrders) }}%"></div>
                        </div>
                    @endforeach
                </div>
                @php $lastBucket = $series ? end($series) : null; @endphp
                <div class="flex justify-between text-xs text-gray-400 mt-2">
                    <span>{{ $series[0]['label'] ?? '' }}</span>
                    <span>{{ $lastBucket['label'] ?? '' }}</span>
                </div>
            </div>
            {{-- The chart carries meaning in bar height alone, so the same
                 numbers are here as text for anyone who cannot use it. --}}
            <details class="border-t border-gray-100">
                <summary class="px-5 py-2.5 text-xs text-gray-500 cursor-pointer hover:text-gray-700">Show as table</summary>
                <div class="px-5 pb-4 max-h-72 overflow-y-auto">
                    <table class="w-full text-xs">
                        <thead class="text-gray-500 text-left"><tr><th class="py-1 font-medium">Period</th><th class="py-1 font-medium text-right">Orders</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($series as $bucket)
                                <tr><td class="py-1 text-gray-700">{{ $bucket['label'] }}</td>
                                    <td class="py-1 text-right tabular-nums text-gray-700">{{ $num($bucket['orders']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </div>

        {{-- ── Platforms ──────────────────────────────────────────────────
             One table rather than a bar chart with the same numbers listed
             underneath it. The bar lives in the orders cell, so size and
             figure are read in one place instead of two. --}}
        @php
            $rows    = $summary['platforms'];
            $maxRow  = max(1, ...array_map(fn ($r) => (int) $r['orders'], $rows ?: [['orders' => 0]]));
        @endphp
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
            {{-- The selling-and-quiet tally that used to sit here is gone. The
                 table below is the same fact in full: a row each for the shops
                 that sold, and the quiet ones named by the footer. --}}
            <div class="px-5 py-3.5 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-gray-800">Platforms</h3>
            </div>

            <div class="overflow-x-auto" x-data="{ dir: {} }">
                <table class="w-full text-sm min-w-[680px]" x-ref="table">
                    <thead class="text-xs text-gray-500 border-b border-gray-100">
                        <tr>
                            @foreach([
                                'name'    => ['Platform',  'text-left'],
                                'orders'  => ['Orders',    'text-right'],
                                'last'    => ['Last order','text-right'],
                            ] as $key => [$label, $align])
                                <th class="px-5 py-2.5 font-medium {{ $align }}">
                                    {{-- Sorting reorders the rows already on the
                                         page; the endpoint is not asked again. --}}
                                    <button type="button" class="hover:text-gray-800"
                                            @click="
                                                dir['{{ $key }}'] = dir['{{ $key }}'] === 'asc' ? 'desc' : 'asc';
                                                const b = $refs.table.tBodies[0];
                                                const s = dir['{{ $key }}'] === 'asc' ? 1 : -1;
                                                [...b.rows].sort((x, y) => {
                                                    const a = x.dataset['{{ $key }}'], c = y.dataset['{{ $key }}'];
                                                    return (isNaN(a) ? String(a).localeCompare(c) : a - c) * s;
                                                }).forEach(r => b.appendChild(r));
                                            ">{{ $label }}</button>
                                </th>
                            @endforeach

                            {{-- Not sortable: the endpoint answers platform and
                                 order-type as two separate breakdowns, so these
                                 two cells start blank and are fetched per row,
                                 on click, rather than for all of them upfront. --}}
                            <th class="px-5 py-2.5 font-medium text-right">Web</th>
                            <th class="px-5 py-2.5 font-medium text-right">Manual</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($rows as $row)
                            @php
                                $name = \App\Support\OrdersSummary::platform($row['platform']);
                                $last = $row['last_order_at'] ? \Illuminate\Support\Carbon::parse($row['last_order_at']) : null;
                            @endphp
                            <tr class="hover:bg-gray-50/60"
                                data-name="{{ $name }}" data-orders="{{ $row['orders'] }}"
                                data-last="{{ $row['last_order_at'] ?? '' }}"
                                x-data="{
                                    loading: false, loaded: false, failed: false, web: null, manual: null,
                                    load() {
                                        if (this.loaded || this.loading) return;
                                        this.loading = true; this.failed = false;
                                        fetch('{{ route('orders.dashboard.platform-order-types', $row['platform']) }}?from={{ $filters['from']->format('Y-m-d') }}&to={{ $filters['to']->format('Y-m-d') }}&basis={{ $filters['basis'] }}', { headers: { 'Accept': 'application/json' } })
                                            .then(r => r.ok ? r.json() : Promise.reject())
                                            .then(d => { this.web = d.web; this.manual = d.manual; this.loaded = true; })
                                            .catch(() => { this.failed = true; })
                                            .finally(() => { this.loading = false; });
                                    },
                                }">

                                <td class="px-5 py-2.5">
                                    {{-- The slug is what the endpoint calls it, kept
                                         within reach in case a name here is wrong. --}}
                                    <span class="font-medium text-gray-800" title="{{ $row['platform'] }}">{{ $name }}</span>
                                </td>

                                <td class="px-5 py-2.5 text-right">
                                    <span class="tabular-nums font-medium text-gray-900">{{ $num($row['orders']) }}</span>
                                    <span class="block text-xs text-gray-400 tabular-nums">{{ $pct($row['share_of_orders']) }}</span>
                                    <span class="block mt-1 h-1 rounded-full bg-brand-500 ml-auto"
                                          style="width: {{ $share($row['orders'], $maxRow) }}%"
                                          role="presentation"></span>
                                </td>

                                {{-- "3 hours ago" answers "is this shop still
                                     trading" at a glance; the exact stamp is a
                                     hover away for anyone who needs it. --}}
                                <td class="px-5 py-2.5 text-right text-gray-500 whitespace-nowrap"
                                    @if($last) title="{{ $row['last_order_at'] }}" @endif>
                                    {{ $last ? $last->diffForHumans(short: true) : '—' }}
                                </td>

                                {{-- One click fetches both cells at once — they
                                     come from the same scoped request, so there
                                     is nothing a second button here would save. --}}
                                <td class="px-5 py-2.5 text-right tabular-nums text-gray-600">
                                    <button type="button" x-show="!loaded && !loading" x-cloak
                                            @click="load()" class="text-brand-600 hover:underline text-xs font-medium">
                                        <span x-show="!failed">Show</span>
                                        <span x-show="failed" class="text-rose-600">Retry</span>
                                    </button>
                                    <span x-show="loading" x-cloak class="text-gray-300">…</span>
                                    <template x-if="loaded">
                                        <span x-text="web.orders.toLocaleString()"></span>
                                    </template>
                                </td>
                                <td class="px-5 py-2.5 text-right tabular-nums text-gray-600">
                                    <span x-show="!loaded" x-cloak class="text-gray-300">—</span>
                                    <template x-if="loaded">
                                        <span x-text="manual.orders.toLocaleString()"></span>
                                    </template>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>

                    {{-- Storefronts that sold nothing in this range. One quiet
                         line rather than a screen of zeros — a shop that has
                         stopped selling still has to be visible, but it does
                         not deserve the same room as one that is trading. --}}
                    @if($summary['dormant'])
                        <tfoot>
                            <tr class="border-t border-gray-100">
                                <td colspan="5" class="px-5 py-3 text-xs text-gray-400">
                                    No orders in this range:
                                    <span class="text-gray-500">{{ collect($summary['dormant'])->map(fn ($p) => \App\Support\OrdersSummary::platform($p))->join(', ') }}</span>
                                </td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ── Breakdowns ───────────────────────────────────────────────────
             Five cards answering the same question five ways, so they share
             one grid rather than sitting in rows of their own. Splitting them
             would set its own column width and leave the edges not lining up
             with the row above — which is exactly what happened when the
             revenue quality panel was removed from the end of this list. --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

            @php $outcomeTotal = max(1, array_sum(array_column($summary['outcomes'], 'orders'))); @endphp
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
                <h3 class="px-5 py-3.5 border-b border-gray-100 text-sm font-semibold text-gray-800">Order status</h3>
                <div class="p-5">
                    <div class="flex h-2.5 rounded-full overflow-hidden bg-gray-100">
                        @foreach($summary['outcomes'] as $group)
                            <div class="{{ $fill[$group['tone']] ?? $fill['gray'] }}" style="width: {{ $group['orders'] / $outcomeTotal * 100 }}%"
                                 title="{{ $group['label'] }}: {{ $num($group['orders']) }}"></div>
                        @endforeach
                    </div>
                    <div class="mt-4 space-y-2.5">
                        @foreach($summary['outcomes'] as $group)
                            <div>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="inline-flex items-center gap-1.5 font-medium text-gray-700">
                                        <span class="w-2 h-2 rounded-sm {{ $fill[$group['tone']] ?? $fill['gray'] }}"></span>{{ $group['label'] }}
                                    </span>
                                    <span class="tabular-nums text-gray-700">{{ $num($group['orders']) }} · {{ $pct($group['orders'] / $outcomeTotal * 100) }}</span>
                                </div>
                                {{-- The raw statuses folded into this bucket, so
                                     anyone who works in those words can still
                                     find the one they were looking for. --}}
                                <p class="text-xs text-gray-400 mt-0.5 pl-3.5">
                                    {{ collect($group['statuses'])->map(fn ($s) => $s['status'] . ' ' . number_format($s['orders']))->join(', ') }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            @php $maxPay = max(1, ...array_map(fn ($r) => $r['orders'], $summary['payments'] ?: [['orders' => 0]])); @endphp
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm" x-data="{ raw: false }">
                <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-800">Payment</h3>
                    {{-- The raw values are unreadable in a chart and essential
                         when somebody is auditing where a figure came from. --}}
                    <button type="button" @click="raw = !raw" class="text-xs text-gray-400 hover:text-gray-600"
                            x-text="raw ? 'Hide raw' : 'Show raw'"></button>
                </div>
                <div class="p-5 space-y-2.5">
                    @foreach($summary['payments'] as $row)
                        <div>
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="text-gray-700 truncate">{{ $row['label'] }}</span>
                                <span class="tabular-nums text-gray-500">{{ $num($row['orders']) }} · {{ $pct($row['share_of_orders']) }}</span>
                            </div>
                            <div class="bg-gray-100 rounded-full h-2 overflow-hidden">
                                <div class="h-full bg-violet-500 rounded-full" style="width: {{ $share($row['orders'], $maxPay) }}%"></div>
                            </div>
                            <p x-show="raw" x-cloak class="text-xs text-gray-400 mt-1 break-all">{{ implode(' · ', $row['raw']) }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
                <h3 class="px-5 py-3.5 border-b border-gray-100 text-sm font-semibold text-gray-800">Web vs Manual</h3>
                <div class="p-5">
                    @php $typeTotal = max(1, array_sum(array_column($summary['types'], 'orders'))); @endphp
                    <div class="flex h-2.5 rounded-full overflow-hidden bg-gray-100">
                        @foreach($summary['types'] as $row)
                            <div class="{{ $row['order_type'] === 'Manual' ? 'bg-amber-500' : 'bg-brand-500' }}"
                                 style="width: {{ $row['orders'] / $typeTotal * 100 }}%"></div>
                        @endforeach
                    </div>
                    <div class="mt-4 space-y-2.5">
                        @foreach($summary['types'] as $row)
                            <div class="flex items-center justify-between text-xs">
                                <span class="inline-flex items-center gap-1.5 font-medium text-gray-700">
                                    <span class="w-2 h-2 rounded-sm {{ $row['order_type'] === 'Manual' ? 'bg-amber-500' : 'bg-brand-500' }}"></span>
                                    {{ $row['order_type'] }}
                                </span>
                                <span class="tabular-nums text-gray-700">{{ $num($row['orders']) }} · {{ $pct($row['share_of_orders'] ?? null) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- The data-quality panel that used to close this row is gone with
                 the revenue it qualified: every line in it counted orders with
                 an unusable or outlying *price*, and there is no price on this
                 tab for it to cast doubt on any more. --}}
            @foreach([['Source', $summary['sources'], 'Mostly not recorded'], ['Shipping', $summary['shipping'], null]] as [$title, $rows, $caveat])
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
                    <div class="px-5 py-3.5 border-b border-gray-100 flex items-baseline justify-between gap-3">
                        <h3 class="text-sm font-semibold text-gray-800">{{ $title }}</h3>
                        @if($caveat)<span class="text-xs text-gray-400">{{ $caveat }}</span>@endif
                    </div>
                    <div class="p-5 space-y-2.5">
                        @foreach($rows as $row)
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-700 truncate">{{ $row['label'] }}</span>
                                <span class="tabular-nums text-gray-500">{{ $num($row['orders']) }} · {{ $pct($row['share_of_orders']) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
        @endif
    @endif

    </div>{{-- /busy --}}
    @endif
</div>
@endsection
