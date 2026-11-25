{{--
    Google Ads spend per website, and what it brought in — read through each
    website's GA4 property, not the Ads API.

    Revenue and conversions here are GA4's: what it credits to sessions that
    arrived from an ad click. The Ads account counts conversions under its own
    attribution and will not agree, so the tab says whose figures these are.

    A website that cannot answer, or whose property has no Ads account linked,
    still gets a card saying which. A row of zeros would read as "we spent
    nothing", which is a decision, not a missing link.
--}}
@php
    $currency = $totals['currency'];

    $num   = fn ($v) => number_format((int) round((float) $v));
    $money = fn ($v) => $v === null ? '—' : $currency . ' ' . number_format((float) $v, (float) $v >= 1000 ? 0 : 2);
    $cpc   = fn ($v) => $v === null ? '—' : $currency . ' ' . number_format((float) $v, 2);
    $roas  = fn ($v) => $v === null ? '—' : number_format((float) $v, 2) . '×';
    $conv  = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');

    $share = fn ($v, $max) => $max > 0 ? max(1.5, $v / $max * 100) : 0;

    $states = [
        'not_linked'     => ['dot' => 'bg-gray-300',  'text' => 'text-gray-500',  'label' => 'Google Ads not linked to this GA4 property'],
        'no_spend'       => ['dot' => 'bg-gray-300',  'text' => 'text-gray-500',  'label' => 'No Google Ads spend in this range'],
        'not_configured' => ['dot' => 'bg-gray-300',  'text' => 'text-gray-400',  'label' => 'No GA4 property set'],
        'no_access'      => ['dot' => 'bg-amber-400', 'text' => 'text-amber-700', 'label' => 'No access to this property'],
        'no_credentials' => ['dot' => 'bg-amber-400', 'text' => 'text-amber-700', 'label' => 'Analytics key missing on this server'],
        'unavailable'    => ['dot' => 'bg-rose-400',  'text' => 'text-rose-600',  'label' => 'Unavailable'],
    ];

    /*
     * Which way is good, per figure. Spend going up is neither — it is a
     * budget somebody set — so it is shown without a colour. A cheaper click
     * is the one figure where down is the good direction.
     */
    $delta = function (?float $d, ?string $better) {
        if ($d === null) {
            return null;
        }

        $up   = $d >= 0;
        $tone = match (true) {
            $better === null           => 'text-gray-500',
            ($better === 'up') === $up => 'text-emerald-600',
            default                    => 'text-rose-600',
        };

        return ['text' => ($up ? '▲ ' : '▼ ') . number_format(abs($d), 1) . '%', 'tone' => $tone];
    };

    $compareNote = $filters['compare'] ? 'vs the previous ' . $filters['days'] . ' ' . str('day')->plural($filters['days']) : null;
@endphp

{{-- ── Filters ───────────────────────────────────────────────────────────── --}}
<form method="GET" action="{{ route('orders.dashboard') }}" @submit="busy = true"
      class="bg-white rounded-xl border border-gray-200 shadow-sm p-3 flex flex-wrap items-center gap-2">
    <input type="hidden" name="tab" value="google_ads">

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
                class="px-3 py-1.5 text-xs font-medium text-white rounded-lg bg-brand-600 hover:bg-brand-700 transition-colors">
            <span x-show="!busy">Apply</span>
            <span x-show="busy" x-cloak>Loading…</span>
        </button>
    </div>
</form>

<template x-if="busy">
    @include('orders.loading')
</template>

<div x-show="!busy" class="space-y-5">

{{-- ── Headline, across every website with spend ────────────────────────── --}}
@php
    $c = $totals['current'];
    $d = $totals['deltas'];

    $tiles = [
        ['label' => 'Spend',            'value' => $money($c['cost']),    'delta' => $delta($d['cost'], null),    'note' => $totals['reporting'] . ' of ' . $totals['total'] . ' websites with Google Ads'],
        ['label' => 'Clicks',           'value' => $num($c['clicks']),    'delta' => $delta($d['clicks'], 'up'),  'note' => $num($c['impressions']) . ' impressions'],
        ['label' => 'Cost per click',   'value' => $cpc($c['cpc']),       'delta' => $delta($d['cpc'], 'down'),   'note' => null],
        ['label' => 'ROAS',             'value' => $roas($c['roas']),     'delta' => $delta($d['roas'], 'up'),    'note' => 'Revenue from ads ÷ spend'],
        ['label' => 'Revenue from ads', 'value' => $money($c['revenue']), 'delta' => $delta($d['revenue'], 'up'), 'note' => $conv($c['conversions']) . ' conversions'],
    ];
@endphp
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4" data-ads-totals>
    @foreach($tiles as $tile)
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
            <div class="flex items-start justify-between gap-2">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">{{ $tile['label'] }}</p>
                @if($tile['delta'])
                    <span class="text-xs font-medium tabular-nums whitespace-nowrap {{ $tile['delta']['tone'] }}"
                          title="{{ $compareNote }}">{{ $tile['delta']['text'] }}</span>
                @endif
            </div>
            <p class="mt-3 text-2xl font-semibold text-gray-900 tabular-nums leading-none">{{ $tile['value'] }}</p>
            @if($tile['note'])
                <p class="text-xs text-gray-400 mt-2">{{ $tile['note'] }}</p>
            @endif
        </div>
    @endforeach
</div>

<p class="text-xs text-gray-400 -mt-2">
    Amounts in {{ $currency }}, the GA4 properties' currency.
    Revenue and conversions are what GA4 credits to sessions from a Google Ads click, so they will not match the Ads account's own conversion figures.
    @if($compareNote) Changes are {{ $compareNote }}. @endif
</p>

{{-- ── Combined trend ───────────────────────────────────────────────────── --}}
@if($totals['reporting'] > 0 && count($totals['trend']))
    @php
        $trend   = $totals['trend'];
        $peak    = max(1, ...array_map(fn ($p) => max($p['cost'], $p['revenue']), $trend));
        $monthly = strlen($trend[0]['key']) === 6;
    @endphp
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between gap-3">
            <h3 class="text-sm font-semibold text-gray-800">{{ $monthly ? 'Monthly' : 'Daily' }} spend and revenue from ads</h3>
            <span class="flex items-center gap-3 text-xs text-gray-500">
                <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-sm bg-brand-500"></span>Spend</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-sm bg-emerald-400"></span>Revenue</span>
            </span>
        </div>
        <div class="px-5 py-4 overflow-x-auto">
            <div class="flex items-end gap-[3px] h-40 min-w-full" style="min-width: {{ max(300, count($trend) * 16) }}px">
                @foreach($trend as $p)
                    <div class="flex-1 flex items-end justify-center gap-px h-full"
                         title="{{ $p['label'] }} — spend {{ $money($p['cost']) }}, revenue {{ $money($p['revenue']) }}">
                        <div class="w-1/2 bg-brand-500 rounded-t-sm" style="height: {{ $p['cost'] > 0 ? $share($p['cost'], $peak) : 0 }}%"></div>
                        <div class="w-1/2 bg-emerald-400 rounded-t-sm" style="height: {{ $p['revenue'] > 0 ? $share($p['revenue'], $peak) : 0 }}%"></div>
                    </div>
                @endforeach
            </div>
            <div class="flex justify-between text-xs text-gray-400 mt-2">
                <span>{{ $trend[0]['label'] }}</span>
                <span>{{ end($trend)['label'] }}</span>
            </div>
        </div>
        {{-- Bar height alone carries the meaning, so the numbers are here
             as text too. --}}
        <details class="border-t border-gray-100">
            <summary class="px-5 py-2.5 text-xs text-gray-500 cursor-pointer hover:text-gray-700">Show as table</summary>
            <div class="px-5 pb-4 max-h-72 overflow-y-auto">
                <table class="w-full text-xs">
                    <thead class="text-gray-500 text-left"><tr>
                        <th class="py-1 font-medium">Period</th>
                        <th class="py-1 font-medium text-right">Spend</th>
                        <th class="py-1 font-medium text-right">Revenue</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($trend as $p)
                            <tr>
                                <td class="py-1 text-gray-700">{{ $p['label'] }}</td>
                                <td class="py-1 text-right tabular-nums text-gray-700">{{ $money($p['cost']) }}</td>
                                <td class="py-1 text-right tabular-nums text-gray-700">{{ $money($p['revenue']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </div>
@endif

{{-- ── One card per website ─────────────────────────────────────────────── --}}
<div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
    @forelse($rows as $row)
        @php
            $ok    = $row['status'] === 'ok';
            $state = $states[$row['status']] ?? $states['unavailable'];
        @endphp

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm flex flex-col {{ $ok ? '' : 'opacity-75' }}"
             data-ads-store="{{ $row['store_id'] }}">
            <div class="px-5 py-3.5 border-b border-gray-100">
                <div class="flex items-baseline justify-between gap-3">
                    <h3 class="text-sm font-semibold text-gray-900">{{ $row['store'] }}</h3>
                    @if($ok)
                        <span class="text-xs text-gray-400">{{ $row['currency'] }}</span>
                    @endif
                </div>

                @if($ok)
                    @php
                        $rc = $row['current'];
                        $rd = $row['deltas'];
                        $figures = [
                            ['label' => 'Spend',   'value' => $money($rc['cost']),    'delta' => $delta($rd['cost'], null)],
                            ['label' => 'Clicks',  'value' => $num($rc['clicks']),    'delta' => $delta($rd['clicks'], 'up')],
                            ['label' => 'CPC',     'value' => $cpc($rc['cpc']),       'delta' => $delta($rd['cpc'], 'down')],
                            ['label' => 'ROAS',    'value' => $roas($rc['roas']),     'delta' => $delta($rd['roas'], 'up')],
                            ['label' => 'Revenue', 'value' => $money($rc['revenue']), 'delta' => $delta($rd['revenue'], 'up')],
                        ];
                    @endphp
                    <dl class="mt-3 grid grid-cols-2 sm:grid-cols-5 gap-x-4 gap-y-3">
                        @foreach($figures as $f)
                            <div>
                                <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ $f['label'] }}</dt>
                                <dd class="mt-1 text-sm font-semibold text-gray-900 tabular-nums whitespace-nowrap">{{ $f['value'] }}</dd>
                                @if($f['delta'])
                                    <dd class="text-[11px] tabular-nums {{ $f['delta']['tone'] }}" title="{{ $compareNote }}">{{ $f['delta']['text'] }}</dd>
                                @endif
                            </div>
                        @endforeach
                    </dl>

                    {{-- A small spend trend, one bar per day (or month). --}}
                    @php $rowPeak = max(0.0, ...array_map(fn ($p) => $p['cost'], $row['trend'] ?: [['cost' => 0.0]])); @endphp
                    @if($rowPeak > 0)
                        <div class="mt-3 flex items-end gap-px h-10" aria-hidden="true">
                            @foreach($row['trend'] as $p)
                                <div class="flex-1 h-full flex items-end" title="{{ $p['label'] }} — {{ $money($p['cost']) }}">
                                    <div class="w-full bg-brand-400 rounded-t-sm" style="height: {{ $p['cost'] > 0 ? $share($p['cost'], $rowPeak) : 0 }}%"></div>
                                </div>
                            @endforeach
                        </div>
                    @endif
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

            @if($ok)
                <div class="px-5 py-3.5 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Top campaigns by spend</p>
                    @if(count($row['campaigns']))
                        <div class="mt-2 overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead class="text-gray-400 text-left">
                                    <tr>
                                        <th class="py-1 font-medium">Campaign</th>
                                        <th class="py-1 font-medium text-right">Spend</th>
                                        <th class="py-1 font-medium text-right">Clicks</th>
                                        <th class="py-1 font-medium text-right">Conv.</th>
                                        <th class="py-1 font-medium text-right">Revenue</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($row['campaigns'] as $campaign)
                                        <tr>
                                            <td class="py-1.5 pr-3 text-gray-700 max-w-[14rem] truncate" title="{{ $campaign['name'] }}">{{ $campaign['name'] }}</td>
                                            <td class="py-1.5 text-right tabular-nums text-gray-700 whitespace-nowrap">{{ $money($campaign['cost']) }}</td>
                                            <td class="py-1.5 text-right tabular-nums text-gray-500">{{ $num($campaign['clicks']) }}</td>
                                            <td class="py-1.5 text-right tabular-nums text-gray-500">{{ $conv($campaign['conversions']) }}</td>
                                            <td class="py-1.5 text-right tabular-nums text-gray-700 whitespace-nowrap">{{ $money($campaign['revenue']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="mt-1.5 text-xs text-gray-300">No campaigns recorded in this range.</p>
                    @endif
                </div>
            @endif
        </div>
    @empty
        <div class="xl:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm px-5 py-10 text-center">
            <p class="text-sm text-gray-400">No websites yet.</p>
        </div>
    @endforelse
</div>

</div>{{-- /busy --}}
