{{--
    Sales per website, read straight from each store's own Shopify — not the
    pre-aggregated ecommerce-server endpoint the Orders tab uses. A store with
    no Shopify token, or whose call failed, still gets a row: silently
    dropping it would read as "this website sold nothing" instead of "this
    website isn't wired up".
--}}
@php
    $money = fn ($v) => number_format((float) $v, 2);
    $num   = fn ($v) => number_format((int) $v);
@endphp

<div class="bg-white rounded-xl border border-gray-200 shadow-sm">
    <div class="px-5 py-3.5 border-b border-gray-100 flex items-baseline justify-between gap-3">
        <h3 class="text-sm font-semibold text-gray-800">Analytics by website</h3>
        <span class="text-xs text-gray-400">{{ count($rows) }} website{{ count($rows) === 1 ? '' : 's' }}</span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[680px]">
            <thead class="text-xs text-gray-500 border-b border-gray-100">
                <tr>
                    <th class="px-5 py-2.5 font-medium text-left">Website</th>
                    <th class="px-5 py-2.5 font-medium text-right">Orders</th>
                    <th class="px-5 py-2.5 font-medium text-right">Revenue</th>
                    <th class="px-5 py-2.5 font-medium text-right">Avg order</th>
                    <th class="px-5 py-2.5 font-medium text-left">Breakdown</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($rows as $row)
                    <tr class="hover:bg-gray-50/60">
                        <td class="px-5 py-2.5 font-medium text-gray-800">
                            {{ $row['store'] }}
                            @if(($row['capped'] ?? false))
                                <span class="ml-1.5 text-[10px] font-semibold text-amber-700 bg-amber-50 rounded px-1 py-0.5 align-middle"
                                      title="This range has more orders than were walked — the totals shown are a partial count.">partial</span>
                            @endif
                        </td>

                        @if($row['status'] === 'ok')
                            <td class="px-5 py-2.5 text-right tabular-nums text-gray-600">{{ $num($row['orders']) }}</td>
                            <td class="px-5 py-2.5 text-right tabular-nums font-medium text-gray-900">
                                {{ $row['currency'] }} {{ $money($row['revenue']) }}
                            </td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-gray-600">{{ $money($row['average_order_value']) }}</td>
                            <td class="px-5 py-2.5 space-y-1.5">
                                @php $products = $row['top_products'] ?? []; @endphp
                                @if(count($products))
                                    <details>
                                        <summary class="cursor-pointer text-xs text-brand-700 hover:text-brand-800">
                                            By product: {{ $products[0]['title'] }}{{ count($products) > 1 ? ' + ' . (count($products) - 1) . ' more' : '' }}
                                        </summary>
                                        <ul class="mt-1.5 space-y-1 text-xs text-gray-600">
                                            @foreach($products as $product)
                                                <li class="flex items-center justify-between gap-3">
                                                    <span>{{ $product['title'] }}</span>
                                                    <span class="tabular-nums text-gray-400">{{ $num($product['quantity']) }} sold · {{ $money($product['revenue']) }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif

                                @php $channels = $row['by_channel'] ?? []; @endphp
                                @if(count($channels))
                                    <details>
                                        <summary class="cursor-pointer text-xs text-brand-700 hover:text-brand-800">
                                            By channel: {{ $channels[0]['channel'] }}{{ count($channels) > 1 ? ' + ' . (count($channels) - 1) . ' more' : '' }}
                                        </summary>
                                        <ul class="mt-1.5 space-y-1 text-xs text-gray-600">
                                            @foreach($channels as $channel)
                                                <li class="flex items-center justify-between gap-3">
                                                    <span>{{ $channel['channel'] }}</span>
                                                    <span class="tabular-nums text-gray-400">{{ $num($channel['orders']) }} orders · {{ $money($channel['revenue']) }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif

                                @if(!count($products) && !count($channels))
                                    <span class="text-xs text-gray-300">—</span>
                                @endif
                            </td>
                        @else
                            @php
                                $label = match ($row['status']) {
                                    'not_connected' => ['text-gray-400', 'Not connected'],
                                    'missing_scope' => ['text-amber-700', 'Needs read_orders permission'],
                                    default         => ['text-rose-600', 'Unavailable'],
                                };
                            @endphp
                            <td class="px-5 py-2.5 text-right text-gray-300" colspan="3">
                                <span class="text-xs font-medium {{ $label[0] }}"
                                      @if(isset($row['message'])) title="{{ $row['message'] }}" @endif>
                                    {{ $label[1] }}
                                </span>
                            </td>
                            <td class="px-5 py-2.5"></td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-6 text-center text-sm text-gray-400">No websites yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
