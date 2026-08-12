@extends('layouts.app')
@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@php
    /**
     * Tailwind's JIT cannot see classes that are built at runtime, so every tone
     * used on this page is written out in full here and looked up by key.
     */
    $tones = [
        'brand'   => ['bg' => 'bg-brand-50',   'text' => 'text-brand-600',   'fill' => 'bg-brand-500',   'ring' => 'border-brand-200'],
        'emerald' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600', 'fill' => 'bg-emerald-500', 'ring' => 'border-emerald-200'],
        'sky'     => ['bg' => 'bg-sky-50',     'text' => 'text-sky-600',     'fill' => 'bg-sky-500',     'ring' => 'border-sky-200'],
        'violet'  => ['bg' => 'bg-violet-50',  'text' => 'text-violet-600',  'fill' => 'bg-violet-500',  'ring' => 'border-violet-200'],
        'indigo'  => ['bg' => 'bg-indigo-50',  'text' => 'text-indigo-600',  'fill' => 'bg-indigo-500',  'ring' => 'border-indigo-200'],
        'amber'   => ['bg' => 'bg-amber-50',   'text' => 'text-amber-600',   'fill' => 'bg-amber-500',   'ring' => 'border-amber-200'],
        'rose'    => ['bg' => 'bg-rose-50',    'text' => 'text-rose-600',    'fill' => 'bg-rose-500',    'ring' => 'border-rose-200'],
        'gray'    => ['bg' => 'bg-gray-100',   'text' => 'text-gray-600',    'fill' => 'bg-gray-400',    'ring' => 'border-gray-200'],
    ];

    // Modules disagree on wording — "running" here, "processing" there, "ready"
    // and "done" both meaning finished — so every value they can hold is mapped.
    $statusPill = [
        'completed'   => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'done'        => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'ready'       => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'processing'  => 'bg-blue-50 text-blue-700 border-blue-200',
        'running'     => 'bg-blue-50 text-blue-700 border-blue-200',
        'translating' => 'bg-violet-50 text-violet-700 border-violet-200',
        'pending'     => 'bg-gray-100 text-gray-600 border-gray-200',
        'failed'      => 'bg-red-50 text-red-700 border-red-200',
    ];
@endphp

@section('content')
<div class="space-y-5">

    {{-- ── Greeting ─────────────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">{{ $greeting }}, {{ Str::before($user->name, ' ') }}</h2>
            <p class="text-sm text-gray-500">
                {{ now()->format('l, d F Y') }}
                @if($running->isNotEmpty())
                    · <span class="text-brand-600 font-medium">{{ $running->count() }} {{ Str::plural('job', $running->count()) }} running right now</span>
                @else
                    · Nothing is running right now
                @endif
            </p>
        </div>
        <div class="flex items-center gap-2">
            @if($user->hasFeature('bulk_upload'))
                <a href="{{ route('upload.create') }}"
                   class="inline-flex items-center gap-2 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors"
                   style="background-color:#1d5a74" onmouseover="this.style.backgroundColor='#164659'" onmouseout="this.style.backgroundColor='#1d5a74'">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    New Upload
                </a>
            @endif
            @if($user->hasFeature('product_request'))
                <a href="{{ route('product-requests.index') }}"
                   class="inline-flex items-center gap-2 border border-gray-300 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors">
                    Product Requests
                </a>
            @endif
        </div>
    </div>

    {{-- ── Headline numbers ─────────────────────────────────────────────── --}}
    @if(count($headline))
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach($headline as $tile)
            @php $t = $tones[$tile['tone']] ?? $tones['gray']; @endphp
            <a href="{{ route($tile['route']) }}"
               class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 hover:border-gray-300 hover:shadow transition-all group">
                <div class="flex items-start justify-between">
                    <div class="w-10 h-10 rounded-lg {{ $t['bg'] }} {{ $t['text'] }} flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $tile['icon'] }}"/>
                        </svg>
                    </div>
                    <svg class="w-4 h-4 text-gray-300 group-hover:text-gray-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </div>
                <p class="mt-4 text-3xl font-semibold text-gray-900 tabular-nums leading-none">{{ number_format($tile['value']) }}</p>
                <p class="mt-1.5 text-sm font-medium text-gray-700">{{ $tile['label'] }}</p>
                <p class="text-xs text-gray-400 mt-0.5">{{ $tile['note'] }}</p>
            </a>
        @endforeach
    </div>
    @endif

    {{-- ── Throughput + live work ───────────────────────────────────────── --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

        {{-- Jobs per day. Plain divs rather than a chart library: the numbers are
             small integers and the page has to render without any JS. --}}
        <div class="xl:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-800">Workload — last {{ $trendDays }} days</h3>
                    <p class="text-xs text-gray-400">{{ number_format($trend['total']) }} {{ Str::plural('job', $trend['total']) }} started across every module</p>
                </div>
                <div class="hidden sm:flex flex-wrap items-center gap-x-3 gap-y-1 justify-end">
                    @foreach($trend['legend'] as $label => $line)
                        <span class="inline-flex items-center gap-1.5 text-xs text-gray-500">
                            <span class="w-2 h-2 rounded-sm {{ $line['class'] }}"></span>
                            {{ $label }}
                            <span class="text-gray-400 tabular-nums">{{ number_format($line['total']) }}</span>
                        </span>
                    @endforeach
                </div>
            </div>

            <div class="px-5 pt-5 pb-3 relative">
                @if($trend['total'] === 0)
                    <div class="absolute inset-0 flex flex-col items-center justify-center text-center pointer-events-none">
                        <p class="text-sm text-gray-500">No jobs in the last {{ $trendDays }} days</p>
                        <p class="text-xs text-gray-400 mt-0.5">Bars appear here as work is run.</p>
                    </div>
                @endif
                <div class="flex items-end gap-1.5 h-40 {{ $trend['total'] === 0 ? 'opacity-40' : '' }}">
                    @foreach($trend['bars'] as $bar)
                        <div class="flex-1 flex flex-col justify-end items-center h-full group relative">
                            @if($bar['total'] > 0)
                                <span class="text-[10px] text-gray-400 tabular-nums mb-1">{{ $bar['total'] }}</span>
                                <div class="w-full flex flex-col-reverse rounded-t overflow-hidden"
                                     style="height: {{ max(4, round($bar['total'] / $trend['peak'] * 100)) }}%">
                                    @foreach($bar['stack'] as $piece)
                                        <div class="{{ $piece['class'] }} w-full"
                                             style="flex: {{ $piece['count'] }} 1 0"
                                             title="{{ $piece['label'] }}: {{ $piece['count'] }}"></div>
                                    @endforeach
                                </div>
                            @else
                                <div class="w-full h-1 rounded-t bg-gray-100"></div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="flex gap-1.5 mt-2 border-t border-gray-100 pt-2">
                    @foreach($trend['bars'] as $bar)
                        <div class="flex-1 text-center">
                            <p class="text-[10px] text-gray-400 leading-tight">{{ $bar['date']->format('d') }}</p>
                            <p class="text-[9px] text-gray-300 leading-tight uppercase">{{ $bar['date']->format('D') }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Live work --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm flex flex-col">
            <div class="px-5 py-3.5 border-b border-gray-100 flex items-center gap-2">
                <span class="relative flex h-2 w-2">
                    @if($running->isNotEmpty())
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-brand-400 opacity-75"></span>
                    @endif
                    <span class="relative inline-flex rounded-full h-2 w-2 {{ $running->isNotEmpty() ? 'bg-brand-500' : 'bg-gray-300' }}"></span>
                </span>
                <h3 class="text-sm font-semibold text-gray-800">Running now</h3>
                <span class="ml-auto text-xs text-gray-400">{{ $running->count() }} active</span>
            </div>

            @forelse($running as $job)
                @php $t = $tones[$job['tone']] ?? $tones['gray']; @endphp
                <a href="{{ $job['url'] }}" class="px-5 py-3 border-b border-gray-50 hover:bg-gray-50/70 transition-colors block">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-medium text-gray-800 truncate">{{ $job['title'] }}</p>
                        <span class="text-xs {{ $t['text'] }} shrink-0">{{ $job['percent'] }}%</span>
                    </div>
                    <p class="text-xs text-gray-400 mt-0.5">{{ $job['module'] }} · {{ $job['detail'] }}</p>
                    <div class="mt-2 h-1.5 rounded-full bg-gray-100 overflow-hidden">
                        <div class="h-full {{ $t['fill'] }} rounded-full" style="width: {{ max(2, $job['percent']) }}%"></div>
                    </div>
                </a>
            @empty
                <div class="flex-1 flex flex-col items-center justify-center px-5 py-10 text-center">
                    <div class="w-10 h-10 rounded-full bg-gray-50 flex items-center justify-center mb-2">
                        <svg class="w-5 h-5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <p class="text-sm text-gray-500">Everything is finished</p>
                    <p class="text-xs text-gray-400 mt-0.5">No queued or running jobs.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- ── Module cards ─────────────────────────────────────────────────── --}}
    @if(count($modules))
    <div>
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-gray-800">Your modules</h3>
            <p class="text-xs text-gray-400">{{ count($modules) }} {{ Str::plural('tool', count($modules)) }} enabled on this account</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach($modules as $module)
                @php $t = $tones[$module['tone']] ?? $tones['gray']; @endphp
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 flex flex-col">
                    <div class="flex items-start gap-3">
                        <div class="w-9 h-9 rounded-lg {{ $t['bg'] }} {{ $t['text'] }} flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $module['icon'] }}"/>
                            </svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <h4 class="text-sm font-semibold text-gray-800 truncate">{{ $module['name'] }}</h4>
                                @if($module['running'] > 0)
                                    <span class="shrink-0 text-[10px] font-medium px-1.5 py-0.5 rounded-full {{ $t['bg'] }} {{ $t['text'] }}">
                                        {{ $module['running'] }} active
                                    </span>
                                @endif
                            </div>
                            <p class="text-xs text-gray-400 leading-snug mt-0.5">{{ $module['blurb'] }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-2 mt-4">
                        @foreach($module['metrics'] as [$label, $value])
                            <div class="rounded-lg bg-gray-50 px-2.5 py-2">
                                <p class="text-base font-semibold text-gray-900 tabular-nums leading-none">{{ $value }}</p>
                                <p class="text-[11px] text-gray-500 mt-1 truncate">{{ $label }}</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4">
                        <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden">
                            <div class="h-full {{ $t['fill'] }} rounded-full" style="width: {{ $module['bar'] }}%"></div>
                        </div>
                        <p class="text-[11px] text-gray-400 mt-1.5">{{ $module['barNote'] }}</p>
                    </div>

                    <a href="{{ route($module['route']) }}"
                       class="mt-4 pt-3 border-t border-gray-100 text-xs font-medium {{ $t['text'] }} hover:underline inline-flex items-center gap-1">
                        {{ $module['link'] }}
                        <span aria-hidden="true">&rarr;</span>
                    </a>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ── Product creation pipeline ────────────────────────────────────── --}}
    @if($requests)
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-gray-800">Product creation pipeline</h3>
                <p class="text-xs text-gray-400">
                    {{ number_format($requests['open']) }} open ·
                    {{ number_format($requests['mine']) }} waiting on you ·
                    {{ number_format($requests['on_hold']) }} on hold
                </p>
            </div>
            <a href="{{ route('product-requests.list') }}" class="text-xs text-brand-600 hover:text-brand-700 font-medium">All requests &rarr;</a>
        </div>

        {{-- Stage counters, in workflow order. Retired stages only appear when
             historic requests are still sitting in them. The row wraps rather
             than scrolling sideways — a stage nobody can see is a stage nobody
             chases. --}}
        <div class="px-5 py-4">
            @php
                $stages = collect(\App\Models\ProductRequest::PIPELINE)
                    ->reject(fn ($s) => in_array($s, \App\Models\ProductRequest::RETIRED_STAGES, true)
                                        && ($requests['by_stage'][$s] ?? 0) === 0)
                    ->values();
            @endphp
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
                @foreach($stages as $stage)
                    @php
                        $stage = (string) $stage;
                        $count = (int) ($requests['by_stage'][$stage] ?? 0);
                    @endphp
                    <div class="rounded-lg border px-3 py-2 {{ $count > 0 ? 'border-brand-200 bg-brand-50/60' : 'border-gray-200 bg-white' }}">
                        <div class="flex items-baseline gap-1.5">
                            <p class="text-lg font-semibold tabular-nums leading-none {{ $count > 0 ? 'text-brand-700' : 'text-gray-300' }}">{{ $count }}</p>
                            <span class="text-[10px] text-gray-300 tabular-nums">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                        </div>
                        <p class="text-[11px] mt-1 leading-tight {{ $count > 0 ? 'text-gray-600' : 'text-gray-400' }}">
                            {{ \App\Models\ProductRequest::STATUS_LABELS[$stage] ?? $stage }}
                        </p>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 border-t border-gray-100 divide-y lg:divide-y-0 lg:divide-x divide-gray-100">

            {{-- Recent requests --}}
            <div class="lg:col-span-2">
                <div class="px-5 py-3 flex items-center justify-between">
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Latest requests</h4>
                    <span class="text-[11px] text-gray-400">
                        {{ number_format($requests['mapped']) }} / {{ number_format($requests['skus']) }} SKUs mapped
                    </span>
                </div>
                @if($requests['recent']->isEmpty())
                    <p class="px-5 pb-5 text-sm text-gray-400">No requests on your desk yet.</p>
                @else
                    <div class="divide-y divide-gray-50">
                        @foreach($requests['recent'] as $r)
                            <a href="{{ route('product-requests.show', $r) }}" class="flex items-center gap-3 px-5 py-2.5 hover:bg-gray-50/70 transition-colors">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-800 truncate">
                                        {{ $r->reference }} · {{ $r->displayName() }}
                                    </p>
                                    <p class="text-xs text-gray-400 truncate">
                                        {{ $r->brand }} · {{ $r->category }} · {{ number_format($r->total_skus) }} SKUs
                                        @if($r->online_launch_date)
                                            · launches {{ $r->online_launch_date->format('d M') }}
                                        @endif
                                    </p>
                                </div>
                                <span class="shrink-0 text-[11px] font-medium px-2 py-0.5 rounded-full border {{ $r->statusColor() }}">
                                    {{ $r->statusLabel() }}
                                </span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Dates that matter --}}
            <div class="px-5 py-3">
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Upcoming launches</h4>
                @if($launches->isEmpty())
                    <p class="text-sm text-gray-400 mt-2">No launch dates set.</p>
                @else
                    <ul class="mt-2 space-y-2">
                        @foreach($launches as $r)
                            @php
                                // Whole days, so a launch dated today reads "today"
                                // rather than "0 seconds ago".
                                $days = (int) now()->startOfDay()->diffInDays($r->online_launch_date->copy()->startOfDay(), false);
                                $late = $days < 0;
                                $when = match (true) {
                                    $days === 0 => 'today',
                                    $days === 1 => 'tomorrow',
                                    $days > 1   => 'in ' . $days . ' days',
                                    default     => abs($days) . ' ' . Str::plural('day', abs($days)) . ' late',
                                };
                            @endphp
                            <li class="flex items-start gap-2">
                                <span class="w-1.5 h-1.5 rounded-full mt-1.5 shrink-0 {{ $late ? 'bg-rose-500' : 'bg-emerald-500' }}"></span>
                                <div class="min-w-0">
                                    <a href="{{ route('product-requests.show', $r) }}" class="text-xs font-medium text-gray-700 hover:text-brand-700 truncate block">
                                        {{ $r->reference }} · {{ $r->brand }}
                                    </a>
                                    <p class="text-[11px] {{ $late ? 'text-rose-600' : 'text-gray-400' }}">
                                        {{ $r->online_launch_date->format('d M Y') }} · {{ $when }}
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mt-5">Photoshoot room</h4>
                @if($photoshoots->isEmpty())
                    <p class="text-sm text-gray-400 mt-2">No shoots booked.</p>
                @else
                    <ul class="mt-2 space-y-2">
                        @foreach($photoshoots as $r)
                            <li class="flex items-start gap-2">
                                <span class="w-1.5 h-1.5 rounded-full mt-1.5 shrink-0 {{ $r->shootDotColor() }}"></span>
                                <div class="min-w-0">
                                    <a href="{{ route('product-requests.show', $r) }}" class="text-xs font-medium text-gray-700 hover:text-brand-700 truncate block">
                                        {{ $r->reference }} · {{ $r->brand }}
                                    </a>
                                    <p class="text-[11px] text-gray-400">
                                        {{ $r->shootStatusLabel() }}
                                        @if($r->photoshoot_scheduled_at)
                                            · {{ $r->photoshoot_scheduled_at->format('d M, H:i') }}
                                        @endif
                                        @if($r->photoshoot_studio)
                                            · {{ $r->photoshoot_studio }}
                                        @endif
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <a href="{{ route('product-requests.photoshoot-room') }}" class="mt-4 inline-block text-xs text-brand-600 hover:text-brand-700 font-medium">
                    Open photoshoot room &rarr;
                </a>
            </div>
        </div>
    </div>
    @endif

    {{-- ── Timeline + right rail ────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">

        {{-- Merged activity across every module --}}
        <div class="xl:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="px-5 py-3.5 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-gray-800">Recent activity</h3>
                <p class="text-xs text-gray-400">Every module, newest first</p>
            </div>

            @if($feed->isEmpty())
                <div class="px-5 py-12 text-center">
                    <p class="text-sm text-gray-500">Nothing has run yet.</p>
                    <p class="text-xs text-gray-400 mt-1">Start a job from any module and it will show up here.</p>
                </div>
            @else
                <ul class="divide-y divide-gray-50">
                    @foreach($feed as $event)
                        @php $t = $tones[$event['tone']] ?? $tones['gray']; @endphp
                        <li>
                            <a href="{{ $event['url'] }}" class="flex items-start gap-3 px-5 py-3 hover:bg-gray-50/70 transition-colors">
                                <span class="w-7 h-7 rounded-lg {{ $t['bg'] }} {{ $t['text'] }} flex items-center justify-center shrink-0 mt-0.5">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $t['fill'] }}"></span>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="text-sm font-medium text-gray-800 truncate">{{ $event['title'] }}</p>
                                        <span class="text-[10px] uppercase tracking-wide {{ $t['text'] }}">{{ $event['module'] }}</span>
                                    </div>
                                    <p class="text-xs text-gray-400 mt-0.5">{{ $event['detail'] }}</p>
                                </div>
                                <div class="text-right shrink-0">
                                    <span class="inline-block text-[11px] font-medium px-2 py-0.5 rounded-full border {{ $statusPill[$event['status']] ?? $statusPill['pending'] }}">
                                        {{ ucfirst($event['status']) }}
                                    </span>
                                    <p class="text-[11px] text-gray-400 mt-1">
                                        {{ $event['at']->diffForHumans(null, true) }} ago
                                        @if($user->is_super_admin && $event['who'])
                                            · {{ Str::before($event['who'], ' ') }}
                                        @endif
                                    </p>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Right rail: connections, stores, team --}}
        <div class="space-y-5">

            {{-- Integration health --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
                <div class="px-5 py-3.5 border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-800">Connections</h3>
                </div>
                <ul class="divide-y divide-gray-50">
                    @foreach($health as $item)
                        <li class="px-5 py-2.5 flex items-center gap-3">
                            <span class="w-2 h-2 rounded-full shrink-0 {{ $item['ok'] ? 'bg-emerald-500' : 'bg-amber-400' }}"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-800">{{ $item['name'] }}</p>
                                <p class="text-xs text-gray-400 truncate">{{ $item['detail'] }}</p>
                            </div>
                            @if(! $item['ok'])
                                <a href="{{ route($item['route']) }}" class="text-xs text-brand-600 hover:text-brand-700 font-medium shrink-0">Fix</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Stores --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
                <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-800">Stores</h3>
                    <a href="{{ route('stores.index') }}" class="text-xs text-brand-600 hover:text-brand-700 font-medium">Manage &rarr;</a>
                </div>
                @if($stores->isEmpty())
                    <p class="px-5 py-6 text-sm text-gray-400 text-center">No store connected yet.</p>
                @else
                    <ul class="divide-y divide-gray-50">
                        @foreach($stores as $row)
                            <li class="px-5 py-3">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-medium text-gray-800 truncate">{{ $row['model']->name }}</p>
                                    @if($row['model']->is_active)
                                        <span class="text-[10px] font-medium px-1.5 py-0.5 rounded-full bg-brand-50 text-brand-600 shrink-0">Active</span>
                                    @endif
                                    <span class="ml-auto w-2 h-2 rounded-full shrink-0 {{ $row['connected'] ? 'bg-emerald-500' : 'bg-red-400' }}"
                                          title="{{ $row['connected'] ? 'Access token present' : 'Not authenticated' }}"></span>
                                </div>
                                <p class="text-xs text-gray-400 truncate">{{ $row['model']->shopify_domain }}</p>
                                <div class="flex items-center gap-3 mt-1.5 text-[11px] text-gray-500">
                                    <span>{{ number_format($row['uploads']) }} uploads</span>
                                    <span>{{ number_format($row['checks']) }} checks</span>
                                    <span>{{ number_format($row['requests']) }} requests</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Team, super admin only --}}
            @if($team->isNotEmpty())
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
                <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-800">Team</h3>
                    <a href="{{ route('super-admin.index') }}" class="text-xs text-brand-600 hover:text-brand-700 font-medium">Admin panel &rarr;</a>
                </div>
                <ul class="divide-y divide-gray-50">
                    @foreach($team as $member)
                        <li class="px-5 py-2.5 flex items-center gap-3">
                            <span class="w-7 h-7 rounded-full bg-gray-100 text-gray-600 text-[11px] font-semibold flex items-center justify-center shrink-0">
                                {{ Str::of($member['name'])->explode(' ')->take(2)->map(fn ($p) => Str::substr($p, 0, 1))->implode('') }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-800 truncate">{{ $member['name'] }}</p>
                                <p class="text-xs text-gray-400 truncate">{{ $member['role'] }}</p>
                            </div>
                            <p class="text-[11px] text-gray-400 shrink-0 text-right">
                                @if(! $member['active'])
                                    <span class="text-red-500">Disabled</span>
                                @elseif($member['seen_at'])
                                    {{ $member['seen_at']->diffForHumans(null, true) }} ago
                                @else
                                    Never signed in
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ul>
            </div>
            @endif
        </div>
    </div>

</div>
@endsection
