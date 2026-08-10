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
            <p class="text-sm text-gray-500">Requests where a role has been given to you — E-Commerce, Supply Chain, Photographer, Photo Editor, Content or QA.</p>
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
            <p class="text-xs text-gray-500">Open tasks</p>
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
                        <th class="px-3 py-2.5 font-medium">My Task</th>
                        <th class="px-3 py-2.5 font-medium">My Deadline</th>
                        <th class="px-3 py-2.5 font-medium">Current Stage</th>
                        <th class="px-3 py-2.5 font-medium">Launch</th>
                        <th class="px-5 py-2.5 font-medium">Priority</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($requests as $item)
                        @php $days = $item->daysToOnlineLaunch(); @endphp
                        <tr class="hover:bg-gray-50/70 transition-colors">
                            <td class="px-5 py-3">
                                <a href="{{ route('product-requests.show', $item) }}"
                                   class="text-brand-600 hover:text-brand-700 font-medium">{{ $item->displayName() }}</a>
                                <p class="text-xs text-gray-400">
                                    {{ $item->reference }} &middot; requested by
                                    <span class="text-gray-600 font-medium">{{ $item->user?->name ?? 'Unknown' }}</span>
                                </p>
                            </td>
                            <td class="px-3 py-3 text-gray-700">{{ $item->brand }} / {{ $item->category }}</td>
                            @php $mine = $item->assignments->where('user_id', $me->id); @endphp
                            <td class="px-3 py-3">
                                <div class="space-y-1">
                                    @forelse($mine as $brief)
                                        <div>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-brand-50 text-brand-700 border border-brand-100 whitespace-nowrap">
                                                {{ $brief->roleLabel() }}
                                            </span>
                                            @if($brief->title)
                                                <p class="text-xs text-gray-600 mt-0.5">{{ $brief->title }}</p>
                                            @endif
                                        </div>
                                    @empty
                                        @foreach($item->rolesFor($me) as $role)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-brand-50 text-brand-700 border border-brand-100 whitespace-nowrap">
                                                {{ $role }}
                                            </span>
                                        @endforeach
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                @php $withDates = $mine->filter->due_date; @endphp
                                @forelse($withDates as $brief)
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold {{ $brief->dueTone() }}">
                                        {{ $brief->dueLabel() }}
                                    </span>
                                    <p class="text-xs text-gray-400">{{ $brief->due_date->format('d M Y') }}</p>
                                @empty
                                    <span class="text-gray-300 text-xs">—</span>
                                @endforelse
                            </td>
                            <td class="px-3 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border whitespace-nowrap {{ $item->statusColor() }}">
                                    {{ $item->statusLabel() }}
                                </span>
                                @if($item->isOnHold())
                                    <p class="text-xs text-red-600 font-medium mt-0.5">On hold: {{ $item->hold_reason }}</p>
                                @endif
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap">
                                @if($days === null)
                                    <span class="text-gray-400">—</span>
                                @else
                                    <span class="{{ $days < 0 ? 'text-red-600 font-medium' : ($days <= 3 ? 'text-amber-600 font-medium' : 'text-gray-600') }}">
                                        {{ $item->launchLabel('d M Y, H:i') }}
                                    </span>
                                    <p class="text-xs {{ $days < 0 ? 'text-red-500' : 'text-gray-400' }}">
                                        @if($days < 0) {{ abs($days) }}d overdue
                                        @elseif($days === 0) today
                                        @else in {{ $days }}d @endif
                                    </p>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border {{ $item->priorityColor() }}">
                                    {{ $item->priorityLabel() }}
                                </span>
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
