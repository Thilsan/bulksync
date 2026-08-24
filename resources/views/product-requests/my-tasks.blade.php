@extends('layouts.app')

@section('title', 'Assigned to Me')
@section('page-title', 'Product Creation Request')

@section('content')
@php $me = auth()->user(); @endphp

<div class="space-y-5">

    <div class="flex items-start justify-between">
        <div>
            <nav class="text-xs text-gray-400 mb-1">
                <a href="{{ route('product-requests.index') }}" class="hover:text-gray-600">Product Creation Request</a>
                <span class="mx-1.5">&gt;</span>
                <span class="text-gray-600">Assigned to Me</span>
            </nav>
            <h2 class="text-lg font-semibold text-gray-800">Assigned to Me</h2>
            @if($brandManager ?? false)
                <p class="text-sm text-gray-500">
                    What is waiting on you — SKUs to map in Cegid, and images the team has asked for.
                    Everything in your brands is on the
                    <a href="{{ route('product-requests.index') }}" class="text-brand-600 hover:text-brand-700 font-medium">dashboard</a>.
                </p>
            @else
                <p class="text-sm text-gray-500">Requests where a role has been given to you — Brand Manager or E-Commerce.</p>
            @endif
        </div>
        <a href="{{ route('product-requests.index') }}"
           class="inline-flex items-center gap-2 border border-gray-300 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Back to Dashboard
        </a>
    </div>

    {{-- Summary --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm px-5 py-4 flex flex-wrap items-center gap-x-8 gap-y-3">
        <div>
            <p class="text-xs text-gray-500">{{ ($brandManager ?? false) ? 'Waiting on you' : 'Open tasks' }}</p>
            <p class="text-2xl font-semibold text-gray-900 leading-tight">{{ $requests->count() }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-500">Overdue</p>
            <p class="text-2xl font-semibold leading-tight {{ $overdue > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $overdue }}</p>
        </div>

        <div class="flex-1"></div>

        @if($closedCount > 0)
            @if(request()->boolean('include_closed'))
                <a href="{{ route('product-requests.my-tasks') }}"
                   class="text-xs font-medium px-3 py-1.5 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors">
                    Hide completed &amp; cancelled
                </a>
            @else
                <a href="{{ route('product-requests.my-tasks', ['include_closed' => 1]) }}"
                   class="text-xs font-medium px-3 py-1.5 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors">
                    Show {{ $closedCount }} completed / cancelled
                </a>
            @endif
        @endif
    </div>

    {{-- Unclaimed work your role owns. This is how a team member finds a job
         that has their team's name on it but nobody's signature. --}}
    @if($teamTasks->isNotEmpty())
    <div class="bg-amber-50 rounded-xl border border-amber-200 overflow-hidden">
        <div class="px-5 py-3.5 border-b border-amber-200">
            <h3 class="text-sm font-semibold text-amber-900">
                Waiting on your team &mdash; nobody has taken these ({{ $teamTasks->count() }})
            </h3>
            <p class="text-xs text-amber-700 mt-0.5">
                These are sitting at a stage your role owns. Take one to put your name on it.
            </p>
        </div>
        <div class="divide-y divide-amber-100">
            @foreach($teamTasks as $item)
                @php $days = $item->daysToOnlineLaunch(); @endphp
                <div class="flex flex-wrap items-center gap-3 px-5 py-3">
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('product-requests.show', $item) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">{{ $item->displayName() }}</a>
                        <span class="text-xs text-gray-400 ml-1.5">{{ $item->reference }}</span>
                        <p class="text-xs text-gray-500">
                            {{ $item->statusLabel() }}
                            @if($days !== null)
                                &middot; <span class="{{ $days < 0 ? 'text-red-600 font-medium' : '' }}">
                                    @if($days < 0) {{ abs($days) }}d overdue @elseif($days === 0) launches today @else {{ $days }}d to launch @endif
                                </span>
                            @endif
                        </p>
                    </div>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border shrink-0 {{ $item->priorityColor() }}">
                        {{ $item->priorityLabel() }}
                    </span>
                    @if($item->claimableBy($me))
                    <form method="POST" action="{{ route('product-requests.claim', $item) }}" class="shrink-0">
                        @csrf
                        <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white text-xs font-medium px-3 py-1.5 rounded-lg transition-colors">
                            Take this task
                        </button>
                    </form>
                    @else
                    <a href="{{ route('product-requests.show', $item) }}"
                       class="shrink-0 text-xs font-medium text-amber-800 hover:text-amber-900 px-3 py-1.5">Open &rarr;</a>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    @endif

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        @if($requests->isNotEmpty())
            <div class="px-5 py-3 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-gray-800">Assigned to you by name</h3>
            </div>
        @endif
        @if($requests->isEmpty())
            <div class="px-5 py-16 text-center">
                <p class="text-sm text-gray-500">
                    Nothing is assigned to you by name right now.
                    @if($teamTasks->isNotEmpty()) Your team has unclaimed work above. @endif
                </p>

                {{-- An empty list reads as "there is nothing to do", which is wrong
                     for a coordinator whose work is all on the other screen. --}}
                @if(auth()->user()->pcr_role === 'photographer')
                    <p class="text-sm text-gray-500 mt-1">
                        Photoshoots are run from the
                        <a href="{{ route('product-requests.photoshoot-room') }}" class="text-brand-600 hover:text-brand-700 font-medium">Photoshoot Schedule</a>,
                        not from here.
                    </p>
                @endif

                <a href="{{ route('product-requests.list') }}" class="text-sm text-brand-600 hover:text-brand-700 font-medium mt-2 inline-block">
                    View all requests &rarr;
                </a>
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 border-b border-gray-100 bg-gray-50/60">
                        <th class="px-5 py-2.5 font-medium">Request</th>
                        <th class="px-3 py-2.5 font-medium">Brand / Category</th>
                        <th class="px-3 py-2.5 font-medium text-right">SKUs</th>
                        <th class="px-3 py-2.5 font-medium">Mapping</th>
                        <th class="px-3 py-2.5 font-medium">Launch</th>
                        <th class="px-3 py-2.5 font-medium">Status</th>
                        <th class="px-3 py-2.5 font-medium">Priority</th>
                        <th class="px-5 py-2.5 font-medium">{{ ($brandManager ?? false) ? 'What is needed' : 'My Role' }}</th>
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
                                    &middot; by {{ $item->requesterName() }}
                                </p>
                            </td>
                            <td class="px-3 py-3 text-gray-700">
                                {{ $item->brand }} / {{ $item->category }}
                                <p class="text-xs font-medium" style="color:#b4540a">{{ $item->store?->name ?? 'no website' }}</p>
                            </td>
                            <td class="px-3 py-3 text-right text-gray-700 tabular-nums">{{ number_format($item->total_skus) }}</td>
                            <td class="px-3 py-3">
                                <div class="flex items-center gap-1.5 text-xs">
                                    <span class="inline-flex items-center gap-1" title="Mapped"><span class="w-2 h-2 rounded-full bg-green-500"></span>{{ $item->mapped_skus }}</span>
                                    <span class="inline-flex items-center gap-1" title="Pending mapping"><span class="w-2 h-2 rounded-full bg-amber-500"></span>{{ $item->pending_skus }}</span>
                                    <span class="inline-flex items-center gap-1" title="Not mapped"><span class="w-2 h-2 rounded-full bg-red-500"></span>{{ $item->not_mapped_skus }}</span>
                                </div>
                                @if($item->hasSkuBalance())
                                    <p class="text-[11px] text-amber-700 font-medium mt-1">{{ $item->skuCompletionPercent() }}% ready</p>
                                @endif
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap {{ $item->isOverdue() ? 'text-red-600 font-medium' : 'text-gray-600' }}">
                                {{ $item->online_launch_date?->format('d M Y') ?? '—' }}
                                @if($item->online_launch_date)
                                    <p class="text-xs {{ $item->isOverdue() ? 'text-red-500' : 'text-gray-400' }}">{{ $item->online_launch_date->format('H:i') }}</p>
                                @endif
                            </td>
                            <td class="px-3 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border {{ $item->statusColor() }} whitespace-nowrap">
                                    {{ $item->statusLabel() }}
                                </span>
                                @if($item->isOnHold())
                                    <p class="text-xs text-red-600 font-medium mt-0.5 truncate max-w-[10rem]" title="{{ $item->hold_reason }}">On hold: {{ $item->hold_reason }}</p>
                                @endif
                            </td>
                            <td class="px-3 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border {{ $item->priorityColor() }}">
                                    {{ $item->priorityLabel() }}
                                </span>
                            </td>
                            {{-- The one column the request list cannot show: which hat
                                 puts this on your desk, and any deadline that came
                                 with it. The brief itself is on the request — a
                                 paragraph per row buried everything else. --}}
                            @php
                                $mine      = $item->assignments->where('user_id', $me->id)->where('ended_at', null);
                                $withDates = $mine->filter->due_date;
                            @endphp
                            <td class="px-5 py-3">
                                {{-- Every row would read "Brand Manager", which is not
                                     news. What the request is waiting on them for is. --}}
                                @if(($brandManager ?? false) && $task = $item->brandManagerTask())
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-amber-50 text-amber-800 border border-amber-200">
                                        {{ $task }}
                                    </span>
                                @else
                                @forelse($mine as $brief)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-brand-50 text-brand-700 border border-brand-100 whitespace-nowrap">
                                        {{ $brief->roleLabel() }}
                                    </span>
                                @empty
                                    @foreach($item->rolesFor($me) as $role)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-brand-50 text-brand-700 border border-brand-100 whitespace-nowrap">
                                            {{ $role }}
                                        </span>
                                    @endforeach
                                @endforelse

                                @endif

                                @foreach($withDates as $brief)
                                    <p class="mt-0.5">
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold {{ $brief->dueTone() }}">
                                            {{ $brief->dueLabel() }}
                                        </span>
                                    </p>
                                @endforeach
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
