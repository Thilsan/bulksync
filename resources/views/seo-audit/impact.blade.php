@extends('layouts.app')
@section('title', 'SEO Impact')
@section('page-title', 'SEO Impact')

@section('content')
<div class="space-y-6">

    {{-- Header --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex items-start justify-between gap-6">
            <div>
                <h2 class="font-semibold text-gray-800 text-lg">Did the content work?</h2>
                <p class="text-sm text-gray-500 mt-1">
                    Organic search sessions to each product's page in the
                    {{ $summary['window_days'] }} days before its SEO content was rewritten,
                    against the {{ $summary['window_days'] }} days after.
                </p>
                <p class="text-sm text-gray-500 mt-1">
                    A push is read {{ $summary['settle_days'] }} days later — sooner than that
                    and the numbers mostly measure how long Google took to recrawl the page.
                    Paid search is excluded.
                </p>
            </div>
            <a href="{{ route('seo-audit.index') }}" class="text-sm text-brand-600 hover:text-brand-800 whitespace-nowrap">SEO Audit →</a>
        </div>

        @unless($store?->ga4_property_id)
        <div class="mt-4 bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-800">
            <strong>No Google Analytics property is set for this website.</strong>
            Pushes are still recorded, but nothing can be measured until a GA4 property ID
            is added under <a href="{{ route('stores.index') }}" class="underline font-medium">Stores</a>.
        </div>
        @endunless
    </div>

    {{-- Headline numbers --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Products measured</p>
            <p class="text-2xl font-bold text-gray-800">{{ number_format($summary['measured']) }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ number_format($summary['pending']) }} still settling</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Organic sessions before</p>
            <p class="text-2xl font-bold text-gray-800">{{ number_format($summary['sessions_before']) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Organic sessions after</p>
            <p class="text-2xl font-bold text-gray-800">{{ number_format($summary['sessions_after']) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Change</p>
            @if($summary['change_percent'] === null)
                <p class="text-2xl font-bold text-gray-300">—</p>
                <p class="text-xs text-gray-400 mt-1">Nothing measured yet</p>
            @else
                @php $up = $summary['change_percent'] >= 0; @endphp
                <p class="text-2xl font-bold {{ $up ? 'text-green-600' : 'text-red-500' }}">
                    {{ $up ? '+' : '' }}{{ $summary['change_percent'] }}%
                </p>
            @endif
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Up / down</p>
            <p class="text-2xl font-bold">
                <span class="text-green-600">{{ number_format($summary['improved']) }}</span>
                <span class="text-gray-300 font-normal">/</span>
                <span class="text-red-500">{{ number_format($summary['declined']) }}</span>
            </p>
        </div>
    </div>

    {{-- Pushes --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap items-center gap-2">
            <h3 class="font-semibold text-gray-800 mr-2">Content pushes</h3>
            @foreach(['measured' => 'Measured', 'pending' => 'Still settling', 'all' => 'All'] as $key => $label)
            <a href="{{ route('seo-audit.impact') }}?filter={{ $key }}"
               class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors
                      {{ $filter === $key ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                {{ $label }}
            </a>
            @endforeach
        </div>

        @if($pushes->isEmpty())
        <div class="px-6 py-12 text-center text-gray-400 text-sm">
            Nothing here yet. Push content from the
            <a href="{{ route('ai-content.index') }}" class="text-brand-600 hover:text-brand-800 font-medium">AI Content Generator</a>
            and it will appear once it has been measured.
        </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-6 py-3 text-left">Pushed</th>
                        <th class="px-6 py-3 text-left">Product</th>
                        <th class="px-6 py-3 text-left">Meta Title Written</th>
                        <th class="px-6 py-3 text-center">Before</th>
                        <th class="px-6 py-3 text-center">After</th>
                        <th class="px-6 py-3 text-center">Change</th>
                        <th class="px-6 py-3 text-left">By</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($pushes as $push)
                    <tr class="hover:bg-gray-50 transition-colors align-top">
                        <td class="px-6 py-3 text-gray-600 whitespace-nowrap">{{ $push->pushed_at->format('d M Y') }}</td>
                        <td class="px-6 py-3 text-gray-700 max-w-xs">
                            <p class="truncate">{{ $push->product_title ?: '—' }}</p>
                            @if($push->handle)
                            <p class="text-xs text-gray-400 font-mono truncate">/products/{{ $push->handle }}</p>
                            @endif
                        </td>
                        <td class="px-6 py-3 text-gray-500 max-w-xs">
                            <p class="truncate">{{ $push->meta_title ?: '—' }}</p>
                        </td>

                        @if($push->measurement_status === 'measured')
                            <td class="px-6 py-3 text-center text-gray-700">{{ number_format($push->sessions_before) }}</td>
                            <td class="px-6 py-3 text-center text-gray-700">{{ number_format($push->sessions_after) }}</td>
                            <td class="px-6 py-3 text-center">
                                @php $change = $push->changePercent(); @endphp
                                @if($change === null)
                                    <span class="text-gray-300">—</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold
                                        {{ $change >= 0 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-600' }}">
                                        {{ $change >= 0 ? '+' : '' }}{{ $change }}%
                                    </span>
                                @endif
                            </td>
                        @else
                            <td colspan="3" class="px-6 py-3 text-center">
                                @php
                                    $tone = ['pending' => 'gray', 'no_data' => 'amber', 'failed' => 'red'][$push->measurement_status] ?? 'gray';
                                    $text = [
                                        'pending' => 'Settling — measured ' . $push->pushed_at->copy()->addDays($summary['settle_days'])->format('d M Y'),
                                        'no_data' => $push->measurement_note ?: 'No data',
                                        'failed'  => $push->measurement_note ?: 'Measurement failed',
                                    ][$push->measurement_status] ?? ucfirst($push->measurement_status);
                                @endphp
                                <span class="text-xs text-{{ $tone }}-600">{{ $text }}</span>
                            </td>
                        @endif

                        <td class="px-6 py-3 text-gray-500 text-xs">{{ $push->user?->name ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($pushes->hasPages())
        <div class="px-6 py-4 border-t border-gray-100">{{ $pushes->links() }}</div>
        @endif
        @endif
    </div>

</div>
@endsection
