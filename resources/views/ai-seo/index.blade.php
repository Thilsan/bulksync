@extends('layouts.app')
@section('title', 'AI SEO Support')
@section('page-title', 'AI SEO Support')

@php
    $storeName = $properties[$property] ?? $property;
    $fmtDate   = function (?string $iso) {
        try { return $iso ? \Illuminate\Support\Carbon::parse($iso)->timezone(config('app.timezone'))->format('j M Y, H:i') : null; }
        catch (\Throwable) { return null; }
    };
@endphp

@section('content')
<div class="space-y-6">

    {{-- Store switcher and a page to analyse directly ─────────────────────── --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex flex-wrap items-start justify-between gap-6">
            <div class="max-w-2xl">
                <h2 class="font-semibold text-gray-800 text-lg">Quick wins</h2>
                <p class="text-sm text-gray-500 mt-1">
                    The pages with the most to gain, ranked: pages ranking 5 to 15 that a focused rewrite can push
                    onto page one, and pages Google shows often that people rarely click. Open one for an AI audit
                    with a rewritten title, meta description and content.
                </p>
            </div>

            <form method="GET" action="{{ route('ai-seo.index') }}" class="flex items-center gap-2">
                <label for="property" class="text-sm text-gray-500">Store</label>
                <select id="property" name="property" onchange="this.form.submit()"
                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    @foreach($properties as $value => $label)
                        <option value="{{ $value }}" @selected($value === $property)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        </div>

        <form method="GET" action="{{ route('ai-seo.analyze') }}" class="mt-5 flex flex-wrap gap-2">
            <input type="hidden" name="property" value="{{ $property }}">
            <input type="url" name="url" required
                   placeholder="Or paste a page from {{ $storeName }} to analyse it…"
                   class="flex-1 min-w-[16rem] rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            <button type="submit" class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">
                Analyse page
            </button>
        </form>
    </div>

    @if($error)
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-5 text-sm text-amber-800 flex flex-wrap items-center justify-between gap-3">
            <span>{{ $error }}</span>
            <a href="{{ route('ai-seo.index', ['property' => $property]) }}" class="font-medium underline">Try again</a>
        </div>
    @elseif($summary && empty($summary['hasData']))
        <div class="bg-white rounded-xl border border-gray-200 px-6 py-12 text-center text-sm text-gray-400">
            Search Console has no data for {{ $storeName }} yet. This happens with a new store, and over weekends,
            because Search Console runs about two days behind. Check back tomorrow.
        </div>
    @else
        {{-- Headline numbers ───────────────────────────────────────────────── --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach([
                ['Pages scanned',      $summary['totalPages'] ?? 0],
                ['Quick wins',         $summary['quickWins'] ?? count($quickWins)],
                ['Almost on page one', $summary['strikingDistance'] ?? 0],
                ['Shown, not clicked', $summary['lowCtr'] ?? 0],
            ] as [$label, $value])
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <p class="text-xs text-gray-500 mb-1">{{ $label }}</p>
                    <p class="text-2xl font-bold text-gray-800 tabular-nums">{{ number_format((int) $value) }}</p>
                </div>
            @endforeach
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-semibold text-gray-800">{{ number_format(count($quickWins)) }} pages to work on</h3>
                @if($when = $fmtDate($summary['generatedAt'] ?? null))
                    <p class="text-xs text-gray-400">Scanned {{ $when }} · Search Console, last 90 days</p>
                @endif
            </div>

            @if(empty($quickWins))
                <div class="px-6 py-12 text-center text-sm text-gray-400">
                    No quick wins for {{ $storeName }} right now.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                            <tr>
                                <th class="px-6 py-3 text-left">Page</th>
                                <th class="px-6 py-3 text-left">Opportunity</th>
                                <th class="px-6 py-3 text-center">Position</th>
                                <th class="px-6 py-3 text-center">Impressions</th>
                                <th class="px-6 py-3 text-center">Clicks</th>
                                <th class="px-6 py-3 text-center">CTR</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            {{-- Already sorted by the engine, highest upside first. --}}
                            @foreach($quickWins as $row)
                                @php
                                    $severity = $row['severity'] ?? '';
                                    $badge = match ($severity) {
                                        'high'   => 'bg-red-50 text-red-700',
                                        'medium' => 'bg-amber-50 text-amber-700',
                                        default  => 'bg-gray-100 text-gray-600',
                                    };
                                    $path = parse_url($row['url'] ?? '', PHP_URL_PATH) ?: ($row['url'] ?? '');
                                    $open = route('ai-seo.analyze', ['property' => $property, 'url' => $row['url'] ?? '']);
                                @endphp
                                <tr class="hover:bg-gray-50 transition-colors align-top">
                                    <td class="px-6 py-3 max-w-sm">
                                        <a href="{{ $open }}" class="font-mono text-xs text-brand-700 hover:underline break-all">{{ $path }}</a>
                                        @if(!empty($row['why']))
                                            <p class="text-xs text-gray-500 mt-1">{{ $row['why'] }}</p>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold {{ $badge }}">
                                            {{ $row['type'] ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 text-center tabular-nums">{{ isset($row['position']) ? number_format((float) $row['position'], 1) : '—' }}</td>
                                    <td class="px-6 py-3 text-center tabular-nums font-semibold text-gray-800">{{ number_format((int) ($row['impressions'] ?? 0)) }}</td>
                                    <td class="px-6 py-3 text-center tabular-nums">{{ number_format((int) ($row['clicks'] ?? 0)) }}</td>
                                    <td class="px-6 py-3 text-center tabular-nums">{{ isset($row['ctr']) ? number_format((float) $row['ctr'], 1) . '%' : '—' }}</td>
                                    <td class="px-6 py-3 text-right whitespace-nowrap">
                                        <a href="{{ $open }}" class="text-sm text-brand-600 hover:text-brand-800 font-medium">Analyse →</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</div>
@endsection
