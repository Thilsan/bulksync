@extends('layouts.app')
@section('title', 'Image Upload')
@section('page-title', 'Image Upload')

@section('content')
@php
    $total    = (int) $totals->total;
    $uploaded = (int) $totals->uploaded;
    $skipped  = (int) $totals->skipped;
    $failed   = (int) $totals->failed;
    $sessions = (int) $totals->sessions;
    $rate     = $total > 0 ? round($uploaded / $total * 100) : null;

    $peak = $daily->max('uploaded');
    $span = max($peak, 1);

    // Status treatment is shared by the tiles, the running list and the table.
    $statusStyles = [
        'completed'  => ['dot' => 'bg-emerald-500', 'pill' => 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'label' => 'Completed'],
        'processing' => ['dot' => 'bg-brand-500',   'pill' => 'bg-brand-50 text-brand-700 ring-brand-200',       'label' => 'Processing'],
        'pending'    => ['dot' => 'bg-gray-400',    'pill' => 'bg-gray-50 text-gray-600 ring-gray-200',          'label' => 'Pending'],
        'failed'     => ['dot' => 'bg-red-500',     'pill' => 'bg-red-50 text-red-700 ring-red-200',             'label' => 'Failed'],
    ];
@endphp

{{-- Full-bleed, matching the main dashboard — no max-width wrapper. --}}
<div class="space-y-5">

    {{-- Intro + actions --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Overview</h2>
            <p class="mt-0.5 text-sm text-gray-500">
                {{ auth()->user()->is_super_admin ? 'Every upload session across the team.' : 'Your upload sessions.' }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('upload.history') }}"
               class="rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-600 transition-colors hover:border-gray-300 hover:text-gray-800">
                Full history
            </a>
            <a href="{{ route('upload.create') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                New Upload
            </a>
        </div>
    </div>

    {{-- Configuration gate: without these credentials an upload cannot run at all --}}
    @if (!$shopifyConfigured || !$onedriveConfigured)
    <div class="flex gap-3 rounded-xl border border-amber-300 bg-amber-50 px-5 py-4">
        <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
        </svg>
        <div>
            <p class="text-sm font-medium text-amber-800">Uploads can't run yet</p>
            <p class="mt-0.5 text-sm text-amber-700">
                @if (!$shopifyConfigured) Shopify credentials are missing. @endif
                @if (!$onedriveConfigured) OneDrive is not connected. @endif
                <a href="{{ route('settings.index') }}" class="font-medium underline">Open Settings →</a>
            </p>
        </div>
    </div>
    @endif

    @if ($sessions === 0)

        {{-- Nothing to summarise yet --}}
        <div class="rounded-xl border border-gray-200 bg-white px-6 py-16 text-center">
            <div class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-brand-50 text-brand-600">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                </svg>
            </div>
            <p class="mt-4 font-semibold text-gray-800">No uploads yet</p>
            <p class="mx-auto mt-1 max-w-sm text-sm text-gray-500">
                Point the uploader at a OneDrive folder whose subfolders are named by item code, and the images
                get matched to your Shopify products.
            </p>
            <a href="{{ route('upload.create') }}"
               class="mt-5 inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-700">
                Start your first upload
            </a>
        </div>

    @else

        {{-- Headline numbers --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Images uploaded</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums text-gray-900">{{ number_format($uploaded) }}</p>
                @if ($rate !== null)
                    <div class="mt-3">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-gray-500">of {{ number_format($total) }} found</span>
                            <span class="font-semibold tabular-nums text-brand-700">{{ $rate }}%</span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-100">
                            <div class="h-full rounded-full bg-brand-500" style="width: {{ $rate }}%"></div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Sessions</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums text-gray-900">{{ number_format($sessions) }}</p>
                <p class="mt-3 text-xs text-gray-500">
                    {{ $running->isNotEmpty() ? $running->count() . ' running now' : 'None running' }}
                </p>
            </div>

            {{-- Amber only earns its colour when there is actually something to look at --}}
            <div class="rounded-xl border bg-white p-5 {{ $skipped > 0 ? 'border-amber-200' : 'border-gray-200' }}">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-400">No match</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums {{ $skipped > 0 ? 'text-amber-600' : 'text-gray-900' }}">
                    {{ number_format($skipped) }}
                </p>
                <p class="mt-3 text-xs text-gray-500">
                    {{ $skipped > 0 ? 'No SKU, barcode or style code matched' : 'Everything matched a product' }}
                </p>
            </div>

            <div class="rounded-xl border bg-white p-5 {{ $failed > 0 ? 'border-red-200' : 'border-gray-200' }}">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Failed</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums {{ $failed > 0 ? 'text-red-600' : 'text-gray-900' }}">
                    {{ number_format($failed) }}
                </p>
                <p class="mt-3 text-xs text-gray-500">
                    {{ $failed > 0 ? 'Errors while processing or sending' : 'No failures' }}
                </p>
            </div>
        </div>

        {{-- Still running --}}
        @if ($running->isNotEmpty())
        <div class="rounded-xl border border-brand-200 bg-white overflow-hidden">
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
                    <a href="{{ route('upload.show', $session) }}" class="block px-5 py-4 transition-colors hover:bg-gray-50">
                        <div class="flex items-center justify-between gap-4">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-800">{{ $session->name }}</p>
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
                            {{ number_format($session->uploaded_files) }} uploaded
                            @if ($session->total_files) of {{ number_format($session->total_files) }} @endif
                        </p>
                    </a>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Throughput. One series, so the heading names it and no legend is needed. --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-sm font-semibold text-gray-800">Images uploaded</h3>
                <p class="text-xs text-gray-400">Last 14 days</p>
            </div>

            @if ($peak === 0)
                <p class="py-12 text-center text-sm text-gray-400">No uploads in the last 14 days.</p>
            @else
                <div class="mt-5">
                    <div class="flex gap-3">
                        {{-- y axis --}}
                        <div class="flex w-10 shrink-0 flex-col justify-between pb-6 text-right text-[10px] tabular-nums text-gray-400">
                            <span>{{ number_format($peak) }}</span>
                            <span>{{ number_format(round($peak / 2)) }}</span>
                            <span>0</span>
                        </div>

                        <div class="min-w-0 flex-1">
                            {{-- plot --}}
                            <div class="relative h-40">
                                {{-- recessive grid --}}
                                <div class="pointer-events-none absolute inset-0 flex flex-col justify-between">
                                    <div class="h-px bg-gray-100"></div>
                                    <div class="h-px bg-gray-100"></div>
                                    <div class="h-px bg-gray-200"></div>
                                </div>

                                <div class="absolute inset-0 flex items-end gap-0.5">
                                    @foreach ($daily as $day)
                                        @php
                                            $h    = $day['uploaded'] > 0 ? max(2, round($day['uploaded'] / $span * 100)) : 0;
                                            $isPeak = $peak > 0 && $day['uploaded'] === $peak;
                                        @endphp
                                        <div class="group relative flex h-full flex-1 items-end">
                                            {{-- max-w keeps bars readable rather than slab-wide on a full-bleed page --}}
                                            {{-- A zero day still gets a hairline, so "none" never reads as "no data" --}}
                                            @if ($day['uploaded'] === 0)
                                                <div class="mx-auto h-px w-full max-w-16 rounded-full bg-gray-200"></div>
                                            @else
                                                <div class="mx-auto w-full max-w-16 rounded-t transition-colors group-hover:bg-brand-700 {{ $isPeak ? 'bg-brand-600' : 'bg-brand-500' }}"
                                                     style="height: {{ $h }}%"></div>
                                            @endif

                                            {{-- hover tooltip --}}
                                            <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1.5 hidden -translate-x-1/2 whitespace-nowrap rounded-lg bg-gray-900 px-2 py-1 text-[11px] font-medium text-white shadow-lg group-hover:block">
                                                {{ $day['date']->format('D, d M') }} &middot;
                                                <span class="tabular-nums">{{ number_format($day['uploaded']) }}</span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            {{-- x axis --}}
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
                <a href="{{ route('upload.history') }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">
                    View all &rarr;
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[46rem] text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider text-gray-400">
                            <th class="px-5 py-2.5 font-medium">Session</th>
                            <th class="px-5 py-2.5 font-medium">Status</th>
                            <th class="px-5 py-2.5 text-right font-medium">Uploaded</th>
                            <th class="px-5 py-2.5 text-right font-medium">No match</th>
                            <th class="px-5 py-2.5 text-right font-medium">Failed</th>
                            <th class="px-5 py-2.5 font-medium">Created</th>
                            <th class="px-5 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($recent as $session)
                            @php $s = $statusStyles[$session->status] ?? $statusStyles['pending']; @endphp
                            <tr class="transition-colors hover:bg-gray-50">
                                <td class="px-5 py-3">
                                    <p class="max-w-[16rem] truncate font-medium text-gray-800">{{ $session->name }}</p>
                                    <p class="mt-0.5 text-xs text-gray-400">
                                        {{ $session->store?->name ?? 'No store' }} &middot; {{ $session->dimensionLabel() }}
                                    </p>
                                </td>
                                <td class="px-5 py-3">
                                    {{-- Colour plus wording: the state is never carried by hue alone --}}
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $s['pill'] }}">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $s['dot'] }}"></span>
                                        {{ $s['label'] }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right font-medium tabular-nums text-gray-800">{{ number_format($session->uploaded_files) }}</td>
                                <td class="px-5 py-3 text-right tabular-nums {{ $session->skipped_files > 0 ? 'text-amber-600' : 'text-gray-300' }}">
                                    {{ number_format($session->skipped_files) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums {{ $session->failed_files > 0 ? 'text-red-600' : 'text-gray-300' }}">
                                    {{ number_format($session->failed_files) }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-gray-500">{{ $session->created_at->format('d M Y H:i') }}</td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('upload.show', $session) }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">View &rarr;</a>
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
