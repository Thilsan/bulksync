@extends('layouts.app')
@section('title', 'AI Content')
@section('page-title', 'AI Content Generator')

@section('content')
@php
    $items      = (int) $totals['items'];
    $processed  = (int) $totals['processed'];
    $translated = (int) $totals['translated'];
    $confirmed  = (int) $totals['confirmed'];
    $sessions   = (int) $totals['sessions'];

    $rate     = $items > 0 ? round($processed / $items * 100) : null;
    $arabicPc = $processed > 0 ? round($translated / $processed * 100) : null;

    $peak = $daily->max('processed');
    $span = max($peak, 1);

    // Lifecycle, not severity: in-flight reads brand, "waiting on you" reads amber.
    $statusStyles = [
        'pending'     => ['dot' => 'bg-gray-400',    'pill' => 'bg-gray-50 text-gray-600 ring-gray-200'],
        'processing'  => ['dot' => 'bg-brand-500',   'pill' => 'bg-brand-50 text-brand-700 ring-brand-200'],
        'translating' => ['dot' => 'bg-brand-500',   'pill' => 'bg-brand-50 text-brand-700 ring-brand-200'],
        'ready'       => ['dot' => 'bg-amber-500',   'pill' => 'bg-amber-50 text-amber-700 ring-amber-200'],
        'pushing'     => ['dot' => 'bg-brand-500',   'pill' => 'bg-brand-50 text-brand-700 ring-brand-200'],
        'done'        => ['dot' => 'bg-emerald-500', 'pill' => 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
        'failed'      => ['dot' => 'bg-red-500',     'pill' => 'bg-red-50 text-red-700 ring-red-200'],
    ];
@endphp

{{-- Full-bleed, matching the other module dashboards --}}
<div class="space-y-5">

    {{-- Intro + actions --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Overview</h2>
            <p class="mt-0.5 text-sm text-gray-500">Descriptions, titles and metadata your account has generated.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('ai-content.history') }}"
               class="rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-600 transition-colors hover:border-gray-300 hover:text-gray-800">
                All sessions
            </a>
            <a href="{{ route('ai-content.index') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                New Content
            </a>
        </div>
    </div>

    @if ($sessions === 0)

        <div class="rounded-xl border border-gray-200 bg-white px-6 py-16 text-center">
            <div class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-brand-50 text-brand-600">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
            <p class="mt-4 font-semibold text-gray-800">No content generated yet</p>
            <p class="mx-auto mt-1 max-w-sm text-sm text-gray-500">
                Paste a list of SKUs and the generator reads each product's images, then writes a description,
                a title and metadata you can review before anything reaches Shopify.
            </p>
            <a href="{{ route('ai-content.index') }}"
               class="mt-5 inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-700">
                Generate your first batch
            </a>
        </div>

    @else

        {{-- Headline numbers --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Products written</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums text-gray-900">{{ number_format($processed) }}</p>
                @if ($rate !== null)
                    <div class="mt-3">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-gray-500">of {{ number_format($items) }} requested</span>
                            <span class="font-semibold tabular-nums text-brand-700">{{ $rate }}%</span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-100">
                            <div class="h-full rounded-full bg-brand-500" style="width: {{ $rate }}%"></div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Also in Arabic</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums text-gray-900">{{ number_format($translated) }}</p>
                @if ($arabicPc !== null)
                    <div class="mt-3">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-gray-500">of what was written</span>
                            <span class="font-semibold tabular-nums text-gray-600">{{ $arabicPc }}%</span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-100">
                            <div class="h-full rounded-full bg-brand-300" style="width: {{ $arabicPc }}%"></div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Approved</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums text-gray-900">{{ number_format($confirmed) }}</p>
                <p class="mt-3 text-xs text-gray-500">Signed off by a human before pushing</p>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Sessions</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums text-gray-900">{{ number_format($sessions) }}</p>
                <p class="mt-3 text-xs text-gray-500">
                    {{ $running->isNotEmpty() ? $running->count() . ' running now' : 'None running' }}
                </p>
            </div>
        </div>

        {{-- Still running --}}
        @if ($running->isNotEmpty())
        <div class="overflow-hidden rounded-xl border border-brand-200 bg-white">
            <div class="flex items-center gap-2 border-b border-gray-100 px-5 py-3">
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand-400 opacity-75"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-brand-500"></span>
                </span>
                <h3 class="text-sm font-semibold text-gray-800">In progress</h3>
            </div>
            <div class="divide-y divide-gray-100">
                @foreach ($running as $session)
                    @php $pc = $session->progressPercent(); @endphp
                    <a href="{{ route('ai-content.show', $session) }}" class="block px-5 py-4 transition-colors hover:bg-gray-50">
                        <div class="flex items-center justify-between gap-4">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-800">{{ $session->statusLabel() }}</p>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    {{ $session->store?->name ?? 'No store' }}
                                    &middot; {{ $session->created_at->diffForHumans() }}
                                </p>
                            </div>
                            <span class="shrink-0 text-sm font-semibold tabular-nums text-brand-700">{{ $pc }}%</span>
                        </div>
                        <div class="mt-2.5 h-1.5 overflow-hidden rounded-full bg-gray-100">
                            <div class="h-full rounded-full bg-brand-500 transition-all" style="width: {{ $pc }}%"></div>
                        </div>
                        <p class="mt-1.5 text-xs tabular-nums text-gray-400">
                            {{ number_format($session->processed_items) }} of {{ number_format($session->total_items) }} products
                        </p>
                    </a>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Throughput. One series, so the heading names it and no legend is needed. --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-sm font-semibold text-gray-800">Products written</h3>
                <p class="text-xs text-gray-400">Last 14 days</p>
            </div>

            @if ($peak === 0)
                <p class="py-12 text-center text-sm text-gray-400">Nothing generated in the last 14 days.</p>
            @else
                <div class="mt-5">
                    <div class="flex gap-3">
                        <div class="flex w-10 shrink-0 flex-col justify-between pb-8 text-right text-[10px] tabular-nums text-gray-400">
                            <span>{{ number_format($peak) }}</span>
                            <span>{{ number_format(round($peak / 2)) }}</span>
                            <span>0</span>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="relative h-40">
                                <div class="pointer-events-none absolute inset-0 flex flex-col justify-between">
                                    <div class="h-px bg-gray-100"></div>
                                    <div class="h-px bg-gray-100"></div>
                                    <div class="h-px bg-gray-200"></div>
                                </div>

                                <div class="absolute inset-0 flex items-end gap-0.5">
                                    @foreach ($daily as $day)
                                        @php
                                            $h      = $day['processed'] > 0 ? max(2, round($day['processed'] / $span * 100)) : 0;
                                            $isPeak = $day['processed'] === $peak;
                                        @endphp
                                        <div class="group relative flex h-full flex-1 items-end">
                                            {{-- max-w keeps bars readable rather than slab-wide on a full-bleed page --}}
                                            {{-- A zero day still gets a hairline, so "none" never reads as "no data" --}}
                                            @if ($day['processed'] === 0)
                                                <div class="mx-auto h-px w-full max-w-16 rounded-full bg-gray-200"></div>
                                            @else
                                                <div class="mx-auto w-full max-w-16 rounded-t transition-colors group-hover:bg-brand-700 {{ $isPeak ? 'bg-brand-600' : 'bg-brand-500' }}"
                                                     style="height: {{ $h }}%"></div>
                                            @endif

                                            <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1.5 hidden -translate-x-1/2 whitespace-nowrap rounded-lg bg-gray-900 px-2 py-1 text-[11px] font-medium text-white shadow-lg group-hover:block">
                                                {{ $day['date']->format('D, d M') }} &middot;
                                                <span class="tabular-nums">{{ number_format($day['processed']) }}</span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="mt-2 flex gap-0.5">
                                @foreach ($daily as $day)
                                    <div class="flex-1 text-center">
                                        <div class="text-[10px] tabular-nums text-gray-400">{{ $day['date']->format('d') }}</div>
                                        <div class="text-[9px] uppercase tracking-wide text-gray-300">{{ $day['date']->format('D') }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Recent sessions --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                <h3 class="text-sm font-semibold text-gray-800">Recent sessions</h3>
                <a href="{{ route('ai-content.history') }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">
                    View all &rarr;
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[44rem] text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider text-gray-400">
                            <th class="px-5 py-2.5 font-medium">Source</th>
                            <th class="px-5 py-2.5 font-medium">Status</th>
                            <th class="px-5 py-2.5 text-right font-medium">Products</th>
                            <th class="px-5 py-2.5 font-medium">Progress</th>
                            <th class="px-5 py-2.5 font-medium">Created</th>
                            <th class="px-5 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($recent as $session)
                            @php
                                $s  = $statusStyles[$session->status] ?? $statusStyles['pending'];
                                $pc = $session->progressPercent();
                            @endphp
                            <tr class="transition-colors hover:bg-gray-50">
                                <td class="px-5 py-3">
                                    <p class="font-medium text-gray-800">
                                        {{ $session->input_type === 'csv_upload' ? 'CSV upload' : 'SKU list' }}
                                    </p>
                                    <p class="mt-0.5 text-xs text-gray-400">{{ $session->store?->name ?? 'No store' }}</p>
                                </td>
                                <td class="px-5 py-3">
                                    {{-- Colour plus wording: the state is never carried by hue alone --}}
                                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $s['pill'] }}">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $s['dot'] }}"></span>
                                        {{ $session->statusLabel() }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums text-gray-800">
                                    <span class="font-medium">{{ number_format($session->processed_items) }}</span>
                                    <span class="text-gray-400">/ {{ number_format($session->total_items) }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-24 overflow-hidden rounded-full bg-gray-100">
                                            <div class="h-full rounded-full {{ $session->status === 'failed' ? 'bg-red-400' : 'bg-brand-500' }}"
                                                 style="width: {{ $pc }}%"></div>
                                        </div>
                                        <span class="text-xs tabular-nums text-gray-500">{{ $pc }}%</span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-gray-500">{{ $session->created_at->format('d M Y H:i') }}</td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('ai-content.show', $session) }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">View &rarr;</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

    @endif

</div>
@endsection
