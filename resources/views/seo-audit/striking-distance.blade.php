@extends('layouts.app')
@section('title', 'Almost Ranking')
@section('page-title', 'Almost Ranking')

@section('content')
<div class="space-y-6">

    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex items-start justify-between gap-6">
            <div>
                <h2 class="font-semibold text-gray-800 text-lg">Searches you almost rank for</h2>
                <p class="text-sm text-gray-500 mt-1">
                    Queries where a page sits between position 11 and 30 over the last {{ $window }} days —
                    page two, where almost nobody looks. Google already considers these pages relevant enough
                    to show; they are a few places short of being seen.
                </p>
                <p class="text-sm text-gray-500 mt-1">
                    Moving position 14 to position 8 is a fraction of the work of ranking something new,
                    and worth several times the traffic. Sorted by impressions: the biggest prize for the same effort.
                </p>
            </div>
            <a href="{{ route('seo-audit.index') }}" class="text-sm text-brand-600 hover:text-brand-800 whitespace-nowrap">SEO Audit →</a>
        </div>

        @if($unavailable)
        <div class="mt-4 bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-800">
            {{ $unavailable }}
        </div>
        @endif
    </div>

    @if(!$unavailable)
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap items-center gap-4">
            <h3 class="font-semibold text-gray-800">{{ number_format(count($rows)) }} opportunities</h3>
            <form method="GET" class="flex-1 max-w-xs">
                <input type="text" name="search" value="{{ $search }}"
                       placeholder="Filter by query or page…"
                       class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </form>
        </div>

        @if(empty($rows))
        <div class="px-6 py-12 text-center text-gray-400 text-sm">
            @if($search)
                Nothing matches “{{ $search }}”.
            @else
                Nothing sitting between positions 11 and 30 yet. That usually means the site is too new to
                Search Console, or the pages have not been indexed long enough to have a position at all.
            @endif
        </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-6 py-3 text-left">Search term</th>
                        <th class="px-6 py-3 text-left">Page</th>
                        <th class="px-6 py-3 text-center">Position</th>
                        <th class="px-6 py-3 text-center">Impressions</th>
                        <th class="px-6 py-3 text-center">Clicks</th>
                        <th class="px-6 py-3 text-center">CTR</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-6 py-3 font-medium text-gray-800">{{ $row['query'] }}</td>
                        <td class="px-6 py-3 text-gray-500 font-mono text-xs max-w-md truncate">
                            {{ $row['handle'] ? ($row['page']) : $row['page'] }}
                        </td>
                        <td class="px-6 py-3 text-center">
                            {{-- Under 15 is the near end of page two: those move
                                 with the least work, so they are marked out. --}}
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold
                                {{ $row['position'] < 15 ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                {{ $row['position'] }}
                            </span>
                        </td>
                        <td class="px-6 py-3 text-center font-semibold text-gray-800">{{ number_format($row['impressions']) }}</td>
                        <td class="px-6 py-3 text-center text-gray-600">{{ number_format($row['clicks']) }}</td>
                        <td class="px-6 py-3 text-center text-gray-500">{{ $row['ctr'] }}%</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-6 py-3 bg-gray-50 border-t border-gray-100">
            <p class="text-xs text-gray-500">
                What to do with a row: put the search term into that page's meta title and copy, where it
                honestly describes what the page is. The Target search terms box on the AI Content Generator
                and Collection SEO takes them directly.
            </p>
        </div>
        @endif
    </div>
    @endif

</div>
@endsection
