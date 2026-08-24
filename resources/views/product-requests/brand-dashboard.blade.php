@extends('layouts.app')

@section('title', 'Product Creation Request')
@section('page-title', 'Product Creation Request')

@section('content')
{{--
    The brand manager's dashboard.

    They hand over the SKUs and the pictures; running the pipeline is somebody
    else's job. So the stage counters — Waiting for Mapping, Photoshoot
    Scheduled, QA — describe work they can neither do nor hurry, and burying the
    one thing they came to find out. The question is whether their brands are
    live, and what is waiting on them.
--}}
<div class="space-y-5">

    <div class="flex items-start justify-between">
        <div>
            <nav class="text-xs text-gray-400 mb-1">
                <a href="{{ route('dashboard') }}" class="hover:text-gray-600">Home</a>
                <span class="mx-1.5">&gt;</span>
                <span class="text-gray-600">Product Creation Request</span>
            </nav>
            <h2 class="text-lg font-semibold text-gray-800">My Brands</h2>
            <p class="text-sm text-gray-500">Every request in your categories, and whether it is live yet.</p>
        </div>

        <a href="{{ route('product-requests.my-tasks') }}"
           class="inline-flex items-center gap-2 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors shrink-0"
           style="background-color:#1d5a74">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            Waiting on me ({{ count($waiting) }})
        </a>
    </div>

    {{-- Two numbers, because there are only two states worth telling apart. --}}
    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm px-4 py-3.5">
            <p class="text-xs font-medium text-gray-500">Live on the website</p>
            <p class="text-2xl font-semibold text-emerald-700 leading-tight mt-0.5">{{ number_format($live) }}</p>
            <p class="text-xs text-gray-400">Published and nothing outstanding</p>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm px-4 py-3.5">
            <p class="text-xs font-medium text-gray-500">Still in progress</p>
            <p class="text-2xl font-semibold text-gray-900 leading-tight mt-0.5">{{ number_format($openCount) }}</p>
            <p class="text-xs text-gray-400">With the e-commerce team</p>
        </div>

        <a href="{{ route('product-requests.my-tasks') }}"
           class="bg-white rounded-xl border shadow-sm px-4 py-3.5 transition-all hover:shadow
                  {{ count($waiting) > 0 ? 'border-amber-300' : 'border-gray-200' }}">
            <p class="text-xs font-medium text-gray-500">Waiting on you</p>
            <p class="text-2xl font-semibold leading-tight mt-0.5 {{ count($waiting) > 0 ? 'text-amber-700' : 'text-gray-900' }}">
                {{ number_format(count($waiting)) }}
            </p>
            <p class="text-xs text-gray-400">SKUs to map, images to send</p>
        </a>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-gray-800">Requests in your categories</h3>
            <p class="text-xs text-gray-400 mt-0.5">
                Whoever raised them — including the ones imported from the tracking sheet.
            </p>
        </div>

        @if($requests->isEmpty())
            <p class="px-5 py-12 text-sm text-gray-400 text-center">
                No requests in your categories yet.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 border-b border-gray-100 bg-gray-50/60">
                            <th class="px-5 py-2.5 font-medium">Request</th>
                            <th class="px-3 py-2.5 font-medium">Brand / Category</th>
                            <th class="px-3 py-2.5 font-medium text-right">SKUs</th>
                            <th class="px-3 py-2.5 font-medium">Launch</th>
                            <th class="px-3 py-2.5 font-medium">Live?</th>
                            <th class="px-5 py-2.5 font-medium">Waiting on you</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($requests as $item)
                            <tr class="hover:bg-gray-50/70 transition-colors">
                                <td class="px-5 py-3">
                                    <a href="{{ route('product-requests.show', $item) }}"
                                       class="text-brand-600 hover:text-brand-700 font-medium">{{ $item->displayName() }}</a>
                                    <p class="text-xs text-gray-400">
                                        {{ $item->reference }}
                                        @if($label = $item->sheetLabel())
                                            &middot; <span title="Request No and Request Date on the tracking sheet">{{ $label }}</span>
                                        @endif
                                    </p>
                                </td>
                                <td class="px-3 py-3 text-gray-700">
                                    {{ $item->brand }} / {{ $item->category }}
                                    <p class="text-xs font-medium" style="color:#b4540a">{{ $item->store?->name ?? 'no website' }}</p>
                                </td>
                                <td class="px-3 py-3 text-right text-gray-700 tabular-nums">{{ number_format($item->total_skus) }}</td>
                                <td class="px-3 py-3 whitespace-nowrap {{ $item->isOverdue() ? 'text-red-600 font-medium' : 'text-gray-600' }}">
                                    {{ $item->online_launch_date?->format('d M Y') ?? '—' }}
                                </td>

                                {{-- Published but still waiting on the photoshoot is not
                                     live in the way anybody means it, so it is not
                                     claimed as such here either. --}}
                                <td class="px-3 py-3 whitespace-nowrap">
                                    @if($item->status === \App\Models\ProductRequest::PUBLISHED && !$item->isWaitingOnPhotoshoot())
                                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Live
                                        </span>
                                    @elseif($item->isWaitingOnPhotoshoot())
                                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-purple-700">
                                            <span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span> Waiting on photos
                                        </span>
                                    @elseif($item->status === \App\Models\ProductRequest::CANCELLED)
                                        <span class="text-xs text-gray-400">Cancelled</span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 text-xs text-gray-500">
                                            <span class="w-1.5 h-1.5 rounded-full bg-gray-300"></span> Not yet
                                        </span>
                                    @endif

                                    @if($item->isOnHold())
                                        <p class="text-xs text-red-600 font-medium mt-0.5">On hold</p>
                                    @endif
                                </td>

                                <td class="px-5 py-3">
                                    @if(isset($waiting[$item->id]) && $task = $item->brandManagerTask())
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-amber-50 text-amber-800 border border-amber-200">
                                            {{ $task }}
                                        </span>
                                    @else
                                        <span class="text-xs text-gray-300">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
