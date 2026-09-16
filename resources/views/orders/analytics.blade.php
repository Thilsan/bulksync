{{--
    Sales per website, read straight from each store's own Shopify — not the
    pre-aggregated ecommerce-server endpoint the Orders tab uses.

    One card per website rather than one table row: a card holds the store's
    own product and channel split without the table growing a column nobody
    can read sideways. A store with no token, or whose token cannot read
    orders, still gets a card — dropping it would read as "this website sold
    nothing" instead of "this website isn't wired up".

    Visitors and sessions are deliberately absent. Shopify exposes no
    storefront traffic through the Admin API under any scope, so there is
    nothing honest to put here until a separate analytics source is wired up.
--}}
@php
    $money = fn ($v) => number_format((float) $v, 2);
    $num   = fn ($v) => number_format((int) $v);
    $pct   = fn ($v) => number_format((float) $v, 1) . '%';

    // Every bar is a share of the biggest value beside it, with a floor so a
    // tiny-but-real value is still a visible sliver rather than nothing.
    $share = fn ($v, $max) => $max > 0 ? max(1.5, $v / $max * 100) : 0;

    $selling  = collect($rows)->where('status', 'ok');
    $maxStore = (float) ($selling->max('revenue') ?? 0);

    // Tailwind's JIT never sees a class built at runtime, so the states are
    // spelled out here and looked up by key.
    $states = [
        'not_connected' => ['dot' => 'bg-gray-300',   'text' => 'text-gray-400',  'label' => 'Not connected'],
        'missing_scope' => ['dot' => 'bg-amber-400',  'text' => 'text-amber-700', 'label' => 'Needs read_orders permission'],
        'unavailable'   => ['dot' => 'bg-rose-400',   'text' => 'text-rose-600',  'label' => 'Unavailable'],
    ];
@endphp

{{-- ── Filters ──────────────────────────────────────────────────────────────
     The same GET-form-in-the-URL idea as the Orders tab, minus two controls
     that would be lies here: the platform picker (each card is already one
     storefront) and the date basis (Shopify only filters these on order
     date). The hidden tab field is what keeps Apply on this tab. --}}
<form method="GET" action="{{ route('orders.dashboard') }}" @submit="busy = true"
      class="bg-white rounded-xl border border-gray-200 shadow-sm p-3 flex flex-wrap items-center gap-2">
    <input type="hidden" name="tab" value="analytics">

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

{{-- ── Headline ─────────────────────────────────────────────────────────── --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    @php
        $tiles = [
            ['label' => 'Revenue',        'value' => $totals['currency'] . ' ' . $money($totals['revenue'])],
            ['label' => 'Orders',         'value' => $num($totals['orders'])],
            ['label' => 'Avg order',      'value' => $money($totals['aov'])],
            // "Reporting", not "selling": a store whose token cannot read
            // orders has not sold nothing, it has told us nothing, and the
            // tile must not quietly turn one into the other.
            ['label' => 'Websites reporting', 'value' => $totals['connected'] . ' of ' . count($rows)],
        ];
    @endphp
    @foreach($tiles as $i => $tile)
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
            <p class="text-xs font-medium text-gray-500">{{ $tile['label'] }}</p>
            <p class="mt-3 text-2xl font-semibold text-gray-900 tabular-nums leading-none">{{ $tile['value'] }}</p>

            @if($i === 0 && $totals['other_currencies'] > 0)
                {{-- Never add one currency to another under a single symbol. --}}
                <p class="text-xs text-amber-700 mt-2">
                    {{ $totals['other_currencies'] }} website(s) bill in another currency and are not in this total.
                </p>
            @elseif($i === 3)
                <p class="text-xs text-gray-400 mt-2">{{ $totals['selling'] }} with orders in this range.</p>
            @endif
        </div>
    @endforeach
</div>

{{-- ── Revenue by website ───────────────────────────────────────────────────
     The one picture that answers "which site is carrying the quarter", which
     a grid of cards cannot show at a glance however tidy each card is. --}}
@if($selling->isNotEmpty())
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-5 py-3.5 border-b border-gray-100 flex items-baseline justify-between gap-3">
            <h3 class="text-sm font-semibold text-gray-800">Revenue by website</h3>
            <span class="text-xs text-gray-400">{{ $filters['from']->format('d M') }} – {{ $filters['to']->format('d M Y') }}</span>
        </div>
        <div class="px-5 py-4 space-y-2.5">
            @foreach($selling as $row)
                @php $sharePct = $totals['revenue'] > 0 ? $row['revenue'] / $totals['revenue'] * 100 : 0; @endphp
                <div class="grid grid-cols-[minmax(7rem,12rem)_1fr_auto] items-center gap-3">
                    <span class="text-xs font-medium text-gray-700 truncate" title="{{ $row['store'] }}">{{ $row['store'] }}</span>
                    <span class="h-2.5 rounded-full bg-gray-100 overflow-hidden" role="presentation">
                        <span class="block h-full rounded-full bg-brand-500"
                              style="width: {{ $share($row['revenue'], $maxStore) }}%"></span>
                    </span>
                    {{-- The figure sits beside the bar, so the chart never
                         carries meaning in length alone. --}}
                    <span class="text-xs tabular-nums text-gray-600 whitespace-nowrap">
                        {{ $money($row['revenue']) }}
                        <span class="text-gray-400">· {{ $pct($sharePct) }}</span>
                    </span>
                </div>
            @endforeach
        </div>
    </div>
@endif

{{-- ── One card per website ───────────────────────────────────────────────── --}}
<div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4">
    @forelse($rows as $row)
        @php
            $ok       = $row['status'] === 'ok';
            $state    = $states[$row['status']] ?? $states['unavailable'];
            $products = $row['top_products'] ?? [];
            $channels = $row['by_channel'] ?? [];
            $maxProd  = (float) (collect($products)->max('revenue') ?? 0);
            $maxChan  = (float) (collect($channels)->max('revenue') ?? 0);
        @endphp

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm flex flex-col {{ $ok ? '' : 'opacity-75' }}">

            {{-- Card header ─────────────────────────────────────────────── --}}
            <div class="px-5 py-3.5 border-b border-gray-100">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="text-sm font-semibold text-gray-900">{{ $row['store'] }}</h3>
                    @if($row['capped'] ?? false)
                        <span class="shrink-0 text-[10px] font-semibold text-amber-700 bg-amber-50 rounded px-1.5 py-0.5"
                              title="This range holds more orders than were read — the figures below are a partial count.">partial</span>
                    @endif
                </div>

                @if($ok)
                    <p class="mt-2 text-2xl font-semibold text-gray-900 tabular-nums leading-none">
                        <span class="text-sm font-medium text-gray-400">{{ $row['currency'] }}</span>
                        {{ $money($row['revenue']) }}
                    </p>
                    <div class="mt-2 flex items-center gap-4 text-xs text-gray-500">
                        <span class="tabular-nums">{{ $num($row['orders']) }} orders</span>
                        <span class="tabular-nums">{{ $money($row['average_order_value']) }} avg</span>
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

            @if($ok)
                {{-- Channels ───────────────────────────────────────────── --}}
                <div class="px-5 py-3.5 border-b border-gray-100">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">By channel</p>
                    @if(count($channels))
                        <div class="mt-2 space-y-1.5">
                            @foreach($channels as $channel)
                                <div class="grid grid-cols-[minmax(5rem,8rem)_1fr_auto] items-center gap-2">
                                    <span class="text-xs text-gray-600 truncate" title="{{ $channel['channel'] }}">{{ $channel['channel'] }}</span>
                                    <span class="h-1.5 rounded-full bg-gray-100 overflow-hidden" role="presentation">
                                        <span class="block h-full rounded-full bg-emerald-400"
                                              style="width: {{ $share($channel['revenue'], $maxChan) }}%"></span>
                                    </span>
                                    <span class="text-xs tabular-nums text-gray-400 whitespace-nowrap">
                                        {{ $num($channel['orders']) }} · {{ $money($channel['revenue']) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="mt-1.5 text-xs text-gray-300">No orders in this range.</p>
                    @endif
                </div>

                {{-- Products ───────────────────────────────────────────── --}}
                <div class="px-5 py-3.5 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">By product</p>
                    @if(count($products))
                        <div class="mt-2 space-y-1.5">
                            @foreach(array_slice($products, 0, 5) as $product)
                                <div class="grid grid-cols-[minmax(5rem,8rem)_1fr_auto] items-center gap-2">
                                    <span class="text-xs text-gray-600 truncate" title="{{ $product['title'] }}">{{ $product['title'] }}</span>
                                    <span class="h-1.5 rounded-full bg-gray-100 overflow-hidden" role="presentation">
                                        <span class="block h-full rounded-full bg-brand-400"
                                              style="width: {{ $share($product['revenue'], $maxProd) }}%"></span>
                                    </span>
                                    <span class="text-xs tabular-nums text-gray-400 whitespace-nowrap">
                                        {{ $num($product['quantity']) }} · {{ $money($product['revenue']) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>

                        {{-- The rest of the twenty, folded away: a card that
                             lists them all stops being glanceable. --}}
                        @if(count($products) > 5)
                            <details class="mt-2">
                                <summary class="cursor-pointer text-xs text-brand-700 hover:text-brand-800">
                                    {{ count($products) - 5 }} more product(s)
                                </summary>
                                <ul class="mt-1.5 space-y-1">
                                    @foreach(array_slice($products, 5) as $product)
                                        <li class="flex items-center justify-between gap-3 text-xs text-gray-600">
                                            <span class="truncate">{{ $product['title'] }}</span>
                                            <span class="tabular-nums text-gray-400 whitespace-nowrap">
                                                {{ $num($product['quantity']) }} · {{ $money($product['revenue']) }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    @else
                        <p class="mt-1.5 text-xs text-gray-300">No products sold in this range.</p>
                    @endif
                </div>
            @endif
        </div>
    @empty
        <div class="lg:col-span-2 xl:col-span-3 bg-white rounded-xl border border-gray-200 shadow-sm px-5 py-10 text-center">
            <p class="text-sm text-gray-400">No websites yet.</p>
        </div>
    @endforelse
</div>
