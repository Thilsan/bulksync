{{--
    Who visited each website, from Google Analytics — the half Shopify cannot
    answer. It is deliberately a separate tab from the sales figures rather
    than more columns beside them: the two come from different systems, fail
    independently, and a website can report one without the other.

    A website that cannot answer still gets a card. An absent row would read
    as "nobody visited", which is a very different claim from "this website
    has no analytics property".
--}}
@php
    $num = fn ($v) => number_format((int) $v);
    $pct = fn ($v) => number_format((float) $v, 1) . '%';

    $share = fn ($v, $max) => $max > 0 ? max(1.5, $v / $max * 100) : 0;

    $reporting = collect($rows)->where('status', 'ok');
    $busiest   = (int) ($reporting->max('sessions') ?? 0);

    $states = [
        'not_configured' => ['dot' => 'bg-gray-300',  'text' => 'text-gray-400',  'label' => 'No GA4 property set'],
        'no_access'      => ['dot' => 'bg-amber-400', 'text' => 'text-amber-700', 'label' => 'No access to this property'],
        'unavailable'    => ['dot' => 'bg-rose-400',  'text' => 'text-rose-600',  'label' => 'Unavailable'],
    ];
@endphp

{{-- ── Filters ───────────────────────────────────────────────────────────── --}}
<form method="GET" action="{{ route('orders.dashboard') }}" @submit="busy = true"
      class="bg-white rounded-xl border border-gray-200 shadow-sm p-3 flex flex-wrap items-center gap-2">
    <input type="hidden" name="tab" value="sessions">

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

        <button type="submit" name="preset" value="custom"
                class="px-3 py-1.5 text-xs font-medium text-white rounded-lg" style="background-color:#1d5a74">
            <span x-show="!busy">Apply</span>
            <span x-show="busy" x-cloak>Loading…</span>
        </button>
    </div>
</form>

{{-- Google answers a range it has not been asked for before in seconds, not
     milliseconds. The filter bar above stays live throughout, so a mis-click
     can be corrected without waiting for the wrong answer to arrive. --}}
<template x-if="busy">
    @include('orders.loading')
</template>

<div x-show="!busy" class="space-y-5">

{{-- ── Headline ─────────────────────────────────────────────────────────── --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    @php
        $tiles = [
            ['label' => 'Sessions',   'value' => $num($totals['sessions']),   'note' => $totals['reporting'] . ' of ' . $totals['total'] . ' websites reporting'],
            ['label' => 'Visitors',   'value' => $num($totals['users']),      'note' => $num($totals['new_users']) . ' of them new'],
            ['label' => 'Page views', 'value' => $num($totals['page_views']), 'note' => null],
            ['label' => 'Pages per session', 'value' => $totals['sessions'] > 0
                ? number_format($totals['page_views'] / $totals['sessions'], 2)
                : '—', 'note' => null],
        ];
    @endphp
    @foreach($tiles as $tile)
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
            <p class="text-xs font-medium text-gray-500">{{ $tile['label'] }}</p>
            <p class="mt-3 text-2xl font-semibold text-gray-900 tabular-nums leading-none">{{ $tile['value'] }}</p>
            @if($tile['note'])
                <p class="text-xs text-gray-400 mt-2">{{ $tile['note'] }}</p>
            @endif
        </div>
    @endforeach
</div>

{{-- ── Sessions by website ──────────────────────────────────────────────── --}}
@if($reporting->isNotEmpty())
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-5 py-3.5 border-b border-gray-100 flex items-baseline justify-between gap-3">
            <h3 class="text-sm font-semibold text-gray-800">Sessions by website</h3>
            <span class="text-xs text-gray-400">{{ $filters['from']->format('d M') }} – {{ $filters['to']->format('d M Y') }}</span>
        </div>
        <div class="px-5 py-4 space-y-2.5">
            @foreach($reporting as $row)
                @php $sharePct = $totals['sessions'] > 0 ? $row['sessions'] / $totals['sessions'] * 100 : 0; @endphp
                <div class="grid grid-cols-[minmax(7rem,12rem)_1fr_auto] items-center gap-3">
                    <span class="text-xs font-medium text-gray-700 truncate" title="{{ $row['store'] }}">{{ $row['store'] }}</span>
                    <span class="h-2.5 rounded-full bg-gray-100 overflow-hidden" role="presentation">
                        <span class="block h-full rounded-full bg-sky-500" style="width: {{ $share($row['sessions'], $busiest) }}%"></span>
                    </span>
                    <span class="text-xs tabular-nums text-gray-600 whitespace-nowrap">
                        {{ $num($row['sessions']) }}
                        <span class="text-gray-400">· {{ $pct($sharePct) }}</span>
                    </span>
                </div>
            @endforeach
        </div>
    </div>
@endif

{{-- ── One card per website ─────────────────────────────────────────────── --}}
<div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4">
    @forelse($rows as $row)
        @php
            $ok    = $row['status'] === 'ok';
            $state = $states[$row['status']] ?? $states['unavailable'];

            $panels = $ok ? [
                ['title' => 'By device',       'rows' => $row['by_device'],       'fill' => 'bg-sky-400'],
                ['title' => 'By location',     'rows' => $row['by_location'] ?? [], 'fill' => 'bg-violet-400'],
                ['title' => 'By channel',      'rows' => $row['by_channel'],      'fill' => 'bg-emerald-400'],
                ['title' => 'By landing page', 'rows' => $row['by_landing_page'], 'fill' => 'bg-brand-400'],
            ] : [];
        @endphp

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm flex flex-col {{ $ok ? '' : 'opacity-75' }}">
            <div class="px-5 py-3.5 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-gray-900">{{ $row['store'] }}</h3>

                @if($ok)
                    <p class="mt-2 text-2xl font-semibold text-gray-900 tabular-nums leading-none">{{ $num($row['sessions']) }}</p>
                    <div class="mt-2 flex items-center gap-4 text-xs text-gray-500">
                        <span class="tabular-nums">{{ $num($row['users']) }} visitors</span>
                        <span class="tabular-nums">{{ $num($row['page_views']) }} views</span>
                    </div>
                @else
                    <p class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium {{ $state['text'] }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $state['dot'] }}"></span>
                        {{ $state['label'] }}
                    </p>
                    @isset($row['message'])
                        <p class="mt-1 text-xs text-gray-400">{{ $row['message'] }}</p>
                    @endisset
                @endif
            </div>

            @foreach($panels as $panel)
                @php $max = (float) (collect($panel['rows'])->max('sessions') ?? 0); @endphp
                <div class="px-5 py-3.5 {{ $loop->last ? 'flex-1' : 'border-b border-gray-100' }}">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ $panel['title'] }}</p>

                    @if(count($panel['rows']))
                        <div class="mt-2 space-y-1.5">
                            @foreach($panel['rows'] as $line)
                                <div class="grid grid-cols-[minmax(5rem,9rem)_1fr_auto] items-center gap-2">
                                    <span class="text-xs text-gray-600 truncate" title="{{ $line['label'] }}">{{ $line['label'] }}</span>
                                    <span class="h-1.5 rounded-full bg-gray-100 overflow-hidden" role="presentation">
                                        <span class="block h-full rounded-full {{ $panel['fill'] }}"
                                              style="width: {{ $share($line['sessions'], $max) }}%"></span>
                                    </span>
                                    <span class="text-xs tabular-nums text-gray-400 whitespace-nowrap">{{ $num($line['sessions']) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="mt-1.5 text-xs text-gray-300">Nothing recorded in this range.</p>
                    @endif
                </div>
            @endforeach
        </div>
    @empty
        <div class="lg:col-span-2 xl:col-span-3 bg-white rounded-xl border border-gray-200 shadow-sm px-5 py-10 text-center">
            <p class="text-sm text-gray-400">No websites yet.</p>
        </div>
    @endforelse
</div>

</div>{{-- /busy --}}
