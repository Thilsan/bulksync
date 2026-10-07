@extends('layouts.app')

@section('title', 'Product Creation')
@section('page-title', 'Product Creation')

@section('content')
<div class="space-y-5" x-data="{
        newRequestOpen: {{ $errors->any() && old('brand') ? 'true' : 'false' }},
        syncAsking: false,
        syncing: false,
     }">

    @php
        $me       = auth()->user();
        $hour     = (int) now()->format('G');
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $card     = 'bg-white rounded-2xl border border-gray-200/80 shadow-sm';
        $palette  = ['bg-sky-100 text-sky-700', 'bg-violet-100 text-violet-700', 'bg-amber-100 text-amber-800',
                     'bg-emerald-100 text-emerald-700', 'bg-rose-100 text-rose-700', 'bg-indigo-100 text-indigo-700'];
        $tone     = fn (?string $t) => $palette[abs(crc32((string) $t)) % count($palette)];
        $initials = fn (?string $t) => strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $t) ?: '?', 0, 2));
    @endphp

    {{-- Header --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-900">{{ $greeting }}, {{ \Illuminate\Support\Str::of($me->name)->before(' ') }}</h2>
            <p class="text-sm text-gray-500 mt-0.5">Every new product, from brand request to live on the website.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            {{-- The browser's own confirm box cannot say this in a way anybody
                 reads, and it leaves the page looking frozen for the several
                 minutes the sync actually takes. --}}
            <button type="button" @click="syncAsking = true"
                    class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                Sync from Sheet
            </button>
            <a href="{{ route('product-requests.list') }}"
               class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                View Requests
            </a>
            <button type="button" @click="newRequestOpen = true" data-action="new-request"
                    class="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                New Request
            </button>
        </div>
    </div>

    @include('product-requests.partials.stat-cards')

    {{-- Recent requests, in the same row style as the full list --}}
    <div class="{{ $card }} overflow-hidden">
        <div class="px-6 py-4 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-gray-900">Recent Requests</h3>
                <p class="text-xs text-gray-400">The latest {{ $recent->count() }} on your desk</p>
            </div>
            <a href="{{ route('product-requests.list') }}" class="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700">
                View all
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
            </a>
        </div>

        @if($recent->isEmpty())
            <div class="px-6 pb-12 pt-4 text-center">
                <p class="text-sm text-gray-500">No product creation requests yet.</p>
                <button type="button" @click="newRequestOpen = true" class="mt-2 text-sm text-brand-600 hover:text-brand-700 font-medium">
                    Create the first request &rarr;
                </button>
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wider text-gray-400 border-y border-gray-100 bg-gray-50/50">
                        <th class="pl-6 pr-3 py-2.5 font-medium">Request</th>
                        <th class="px-3 py-2.5 font-medium">Products</th>
                        <th class="px-3 py-2.5 font-medium">Website go-live</th>
                        <th class="px-3 py-2.5 font-medium">Status</th>
                        <th class="px-3 py-2.5 font-medium">Priority</th>
                        <th class="pl-3 pr-6 py-2.5 font-medium">Requested by</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($recent as $item)
                        @php
                            $url  = route('product-requests.show', $item);
                            $days = $item->daysToOnlineLaunch();
                        @endphp
                        <tr class="cursor-pointer hover:bg-gray-50 transition-colors"
                            @click="if (!$event.target.closest('a, button')) window.location = '{{ $url }}'">
                            <td class="pl-6 pr-3 py-3.5">
                                <div class="flex items-center gap-3 min-w-0">
                                    <span class="w-9 h-9 rounded-xl text-xs font-bold flex items-center justify-center shrink-0 {{ $tone($item->brand) }}">{{ $initials($item->brand) }}</span>
                                    <div class="min-w-0">
                                        <a href="{{ $url }}" class="font-medium text-gray-900 hover:text-brand-700 truncate block max-w-[22rem]">{{ $item->displayName() }}</a>
                                        <p class="text-xs text-gray-400 truncate">
                                            {{ $item->reference }} &middot; {{ $item->brand }} / {{ $item->category }}
                                            @if($label = $item->sheetLabel())
                                                &middot; <span title="Request No and Request Date on the tracking sheet">{{ $label }}</span>
                                            @endif
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3.5 whitespace-nowrap">
                                <p class="text-gray-900 font-medium tabular-nums">{{ number_format($item->total_skus) }} <span class="font-normal text-gray-400">SKUs</span></p>
                                <p class="text-[11px] text-gray-400 truncate max-w-[10rem]">{{ $item->imageSourceLabel() }}</p>
                            </td>
                            <td class="px-3 py-3.5 whitespace-nowrap">
                                @if($item->online_launch_date)
                                    <p class="text-gray-900">{{ $item->online_launch_date->format('d M Y') }}</p>
                                    @unless($item->isClosed())
                                        <p class="text-[11px] {{ $days < 0 ? 'text-red-600 font-medium' : ($days <= 7 ? 'text-amber-700' : 'text-gray-400') }}">
                                            {{ $days < 0 ? 'Overdue ' . abs($days) . 'd' : ($days === 0 ? 'Today' : 'In ' . $days . ' ' . \Illuminate\Support\Str::plural('day', $days)) }}
                                        </p>
                                    @endunless
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3.5">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border {{ $item->listStatusColor() }} whitespace-nowrap">
                                    <span class="w-1.5 h-1.5 rounded-full bg-current opacity-70"></span>
                                    {{ $item->listStatusLabel() }}
                                </span>
                            </td>
                            <td class="px-3 py-3.5">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border {{ $item->priorityColor() }}">{{ $item->priorityLabel() }}</span>
                            </td>
                            <td class="pl-3 pr-6 py-3.5 whitespace-nowrap">
                                <span class="inline-flex items-center gap-2">
                                    <span class="w-7 h-7 rounded-full text-[11px] font-semibold flex items-center justify-center {{ $tone($item->requesterName()) }}">{{ $initials($item->requesterName()) }}</span>
                                    <span class="leading-tight">
                                        <span class="block text-gray-700">{{ $item->requesterName() }}</span>
                                        <span class="block text-[11px] text-gray-400">{{ $item->created_at->format('d M Y') }}</span>
                                    </span>
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

        {{-- Status overview --}}
        <div class="{{ $card }}">
            <div class="px-6 pt-5 pb-1">
                <h3 class="text-sm font-semibold text-gray-900">Where everything is</h3>
                <p class="text-xs text-gray-400">Requests by stage</p>
            </div>
            <div class="px-6 py-5">
                @php
                    $chartColors = [
                        \App\Models\ProductRequest::SUBMITTED            => '#34d399',
                        \App\Models\ProductRequest::WAITING_MAPPING      => '#fbbf24',
                        \App\Models\ProductRequest::SKU_VERIFIED         => '#2dd4bf',
                        \App\Models\ProductRequest::WAITING_IMAGES       => '#fb923c',
                        \App\Models\ProductRequest::PHOTOSHOOT_SCHEDULED => '#c084fc',
                        \App\Models\ProductRequest::PHOTOSHOOT_COMPLETED => '#a78bfa',
                        \App\Models\ProductRequest::IMAGE_EDITING        => '#818cf8',
                        \App\Models\ProductRequest::AI_CONTENT           => '#f59e0b',
                        \App\Models\ProductRequest::QA_REVIEW            => '#38bdf8',
                        \App\Models\ProductRequest::READY_FOR_UPLOAD     => '#60a5fa',
                        \App\Models\ProductRequest::PUBLISHED            => '#10b981',
                        \App\Models\ProductRequest::COMPLETED            => '#9ca3af',
                        \App\Models\ProductRequest::CANCELLED            => '#f87171',
                    ];
                    $totalForChart = max(1, $breakdown->sum());
                    // Donut geometry: r=52 → circumference ≈ 326.7. Each slice consumes
                    // its share of that length and the next starts where it left off.
                    $circumference = 2 * M_PI * 52;
                    $offset        = 0.0;
                @endphp

                <div class="flex flex-col sm:flex-row items-center gap-6">
                    <div class="relative shrink-0">
                        <svg width="150" height="150" viewBox="0 0 140 140" class="-rotate-90">
                            <circle cx="70" cy="70" r="52" fill="none" stroke="#f3f4f6" stroke-width="16"/>
                            @foreach($chartColors as $status => $color)
                                @php $count = (int) ($breakdown[$status] ?? 0); @endphp
                                @if($count > 0)
                                    @php $length = $count / $totalForChart * $circumference; @endphp
                                    <circle cx="70" cy="70" r="52" fill="none" stroke="{{ $color }}" stroke-width="16"
                                            stroke-dasharray="{{ round($length, 2) }} {{ round($circumference - $length, 2) }}"
                                            stroke-dashoffset="{{ round(-$offset, 2) }}"/>
                                    @php $offset += $length; @endphp
                                @endif
                            @endforeach
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-3xl font-bold text-gray-900 tabular-nums">{{ number_format($breakdown->sum()) }}</span>
                            <span class="text-xs text-gray-400">requests</span>
                        </div>
                    </div>

                    <div class="flex-1 w-full min-w-0 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1.5">
                        @foreach($chartColors as $status => $color)
                            @php $count = (int) ($breakdown[$status] ?? 0); @endphp
                            @if($count > 0)
                                <a href="{{ route('product-requests.list', ['status' => $status]) }}" class="flex items-center gap-2 rounded-lg px-2 py-1 text-xs hover:bg-gray-50">
                                    <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $color }}"></span>
                                    <span class="flex-1 text-gray-600 truncate">{{ \App\Models\ProductRequest::STATUS_LABELS[$status] }}</span>
                                    <span class="text-gray-900 font-semibold tabular-nums">{{ $count }}</span>
                                </a>
                            @endif
                        @endforeach

                        @if($breakdown->sum() === 0)
                            <p class="text-sm text-gray-400">Nothing to chart yet.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Upcoming launches --}}
        <div class="{{ $card }}">
            <div class="px-6 pt-5 pb-3">
                <h3 class="text-sm font-semibold text-gray-900">Upcoming Deadlines</h3>
                <p class="text-xs text-gray-400">Next website go-live dates</p>
            </div>
            <div class="px-4 pb-4 space-y-1.5">
                @forelse($deadlines as $item)
                    @php $days = $item->daysToOnlineLaunch(); @endphp
                    <a href="{{ route('product-requests.show', $item) }}" class="flex items-center gap-4 rounded-xl px-2 py-2 hover:bg-gray-50 transition-colors">
                        <div class="w-12 shrink-0 rounded-xl overflow-hidden border text-center {{ $days < 0 ? 'border-red-200' : 'border-gray-200' }}">
                            <p class="text-[10px] font-semibold uppercase py-0.5 {{ $days < 0 ? 'bg-red-500 text-white' : ($days <= 7 ? 'bg-amber-400 text-white' : 'bg-gray-100 text-gray-500') }}">{{ $item->online_launch_date->format('M') }}</p>
                            <p class="text-lg font-bold leading-tight py-1 {{ $days < 0 ? 'text-red-600' : 'text-gray-800' }}">{{ $item->online_launch_date->format('d') }}</p>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ $item->displayName() }}</p>
                            <p class="text-xs truncate">
                                @if($days < 0)
                                    <span class="text-red-600 font-medium">Overdue by {{ abs($days) }} {{ \Illuminate\Support\Str::plural('day', abs($days)) }}</span>
                                @elseif($days === 0)
                                    <span class="text-amber-600 font-medium">Launches today</span>
                                @else
                                    <span class="{{ $days <= 7 ? 'text-amber-700' : 'text-gray-500' }}">In {{ $days }} {{ \Illuminate\Support\Str::plural('day', $days) }}</span>
                                @endif
                                <span class="text-gray-400">&middot; {{ $item->statusLabel() }}</span>
                            </p>
                        </div>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border shrink-0 {{ $item->priorityColor() }}">{{ $item->priorityLabel() }}</span>
                    </a>
                @empty
                    <p class="px-2 py-10 text-sm text-gray-400 text-center">No upcoming launches scheduled.</p>
                @endforelse
            </div>
        </div>

        {{-- Recent activity, as a timeline --}}
        <div class="{{ $card }}">
            <div class="px-6 pt-5 pb-3">
                <h3 class="text-sm font-semibold text-gray-900">Recent Activity</h3>
                <p class="text-xs text-gray-400">What happened last</p>
            </div>
            <div class="px-6 pb-5">
                @forelse($activity as $entry)
                    <div class="relative flex gap-3 pb-4">
                        @unless($loop->last)
                            <span class="absolute left-[13px] top-8 bottom-0 w-px bg-gray-100"></span>
                        @endunless
                        <span class="relative w-7 h-7 rounded-full text-[10px] font-semibold flex items-center justify-center shrink-0 {{ $tone($entry->actorName()) }}">{{ $initials($entry->actorName()) }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-gray-700">
                                <span class="font-medium text-gray-900">{{ $entry->actorName() }}</span>
                                <span class="text-gray-600">{{ \Illuminate\Support\Str::lcfirst($entry->description) }}</span>
                            </p>
                            <p class="text-xs text-gray-400 mt-0.5">
                                @if($entry->productRequest)
                                    <a href="{{ route('product-requests.show', $entry->productRequest) }}" class="text-brand-600 hover:text-brand-700 font-medium">{{ $entry->productRequest->displayName() }}</a> &middot;
                                @endif
                                {{ $entry->created_at->diffForHumans() }}
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="py-8 text-sm text-gray-400 text-center">No activity recorded yet.</p>
                @endforelse
            </div>
        </div>

        {{-- Top brands, with a bar for how much of the work each one is --}}
        <div class="{{ $card }}">
            <div class="px-6 pt-5 pb-3">
                <h3 class="text-sm font-semibold text-gray-900">Top Brands</h3>
                <p class="text-xs text-gray-400">Most requests</p>
            </div>
            <div class="px-4 pb-4 space-y-1">
                @php $topMax = max(1, (int) $topBrands->max()); @endphp
                @forelse($topBrands as $brand => $count)
                    <a href="{{ route('product-requests.list', ['brand' => $brand]) }}"
                       class="flex items-center gap-3 rounded-xl px-2 py-2 hover:bg-gray-50 transition-colors">
                        <span class="w-9 h-9 rounded-xl text-xs font-bold flex items-center justify-center shrink-0 {{ $tone($brand) }}">{{ $initials($brand) }}</span>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-medium text-gray-800 truncate">{{ $brand }}</span>
                                <span class="text-xs text-gray-500 tabular-nums shrink-0">{{ $count }} {{ \Illuminate\Support\Str::plural('request', $count) }}</span>
                            </div>
                            <div class="mt-1.5 h-1.5 rounded-full bg-gray-100 overflow-hidden">
                                <div class="h-full rounded-full bg-brand-500" style="width: {{ round(100 * $count / $topMax) }}%"></div>
                            </div>
                        </div>
                    </a>
                @empty
                    <p class="px-2 py-10 text-sm text-gray-400 text-center">No brands yet.</p>
                @endforelse
            </div>
        </div>

    </div>

    @include('product-requests.partials.new-request-form')

    {{-- Confirming the sync, then waiting for it. Both live here rather than in
         the header so the overlay can cover the page while it runs. --}}
    <div x-show="syncAsking" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="syncAsking = false">
        <div class="absolute inset-0 bg-gray-900/50" @click="syncAsking = false"></div>

        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md">
            <div class="px-5 py-4 border-b border-gray-100 flex items-start gap-3">
                <div class="w-9 h-9 rounded-lg bg-brand-50 text-brand-600 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-gray-800">Sync from the tracking sheet</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Reads the SharePoint sheet and creates any requests it does not have yet.</p>
                </div>
            </div>

            <div class="px-5 py-4 space-y-2.5 text-sm text-gray-600">
                <p class="flex gap-2">
                    <span class="text-gray-400">&bull;</span>
                    <span>It can create <span class="font-medium text-gray-800">many requests at once</span> — one per website on each sheet row.</span>
                </p>
                <p class="flex gap-2">
                    <span class="text-gray-400">&bull;</span>
                    <span>Requests already here are left alone. Nothing is duplicated and nothing is deleted.</span>
                </p>
                <p class="flex gap-2">
                    <span class="text-gray-400">&bull;</span>
                    <span>Rows that cannot be matched are reported, never skipped quietly.</span>
                </p>
                <p class="flex gap-2">
                    <span class="text-gray-400">&bull;</span>
                    <span>It reads every category tab, so it usually takes <span class="font-medium text-gray-800">a few minutes</span>.</span>
                </p>
                <p class="flex gap-2">
                    <span class="text-gray-400">&bull;</span>
                    <span>This also runs <span class="font-medium text-gray-800">automatically every 2 hours</span> — press it only when you need a row picked up right now.</span>
                </p>
            </div>

            <div class="px-5 py-3.5 border-t border-gray-100 flex items-center justify-end gap-2">
                <button type="button" @click="syncAsking = false"
                        class="text-sm font-medium text-gray-600 hover:text-gray-800 px-3 py-2 rounded-lg hover:bg-gray-50">
                    Cancel
                </button>

                <form method="POST" action="{{ route('product-requests.sync-sheet') }}"
                      @submit="syncAsking = false; syncing = true">
                    @csrf
                    <button type="submit"
                            class="inline-flex items-center gap-2 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors bg-brand-600 hover:bg-brand-700 transition-colors">
                        Start sync
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Held until the response lands, which is the whole point: the request is
         synchronous and several minutes of a still page reads as a crash. --}}
    <div x-show="syncing" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-gray-900/60"></div>

        <div class="relative bg-white rounded-xl shadow-xl px-6 py-6 w-full max-w-sm text-center">
            <svg class="w-10 h-10 mx-auto text-brand-600 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>

            <h3 class="text-sm font-semibold text-gray-800 mt-4">Syncing from the tracking sheet…</h3>
            <p class="text-xs text-gray-500 mt-1.5">
                Reading the master tab and every category tab. This usually takes a few minutes —
                please leave this page open.
            </p>
            <p class="text-xs text-gray-400 mt-3">The results appear here as soon as it finishes.</p>
        </div>
    </div>
</div>

@endsection
