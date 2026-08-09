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
            <p class="text-sm text-gray-500">Requests where you are the E-Commerce owner, photographer, content owner or QA reviewer.</p>
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

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        @if($requests->isEmpty())
            <div class="px-5 py-16 text-center">
                <p class="text-sm text-gray-500">Nothing is assigned to you right now.</p>
                <a href="{{ route('product-requests.list') }}" class="text-sm text-brand-600 hover:text-brand-700 font-medium mt-2 inline-block">
                    View all requests &rarr;
                </a>
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 border-b border-gray-100 bg-gray-50/60">
                        <th class="px-5 py-2.5 font-medium">Request ID</th>
                        <th class="px-3 py-2.5 font-medium">Brand / Category</th>
                        <th class="px-3 py-2.5 font-medium">My Role</th>
                        <th class="px-3 py-2.5 font-medium">Current Stage</th>
                        <th class="px-3 py-2.5 font-medium">Online Launch</th>
                        <th class="px-5 py-2.5 font-medium">Priority</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($requests as $item)
                        @php $days = $item->daysToOnlineLaunch(); @endphp
                        <tr class="hover:bg-gray-50/70 transition-colors">
                            <td class="px-5 py-3">
                                <a href="{{ route('product-requests.show', $item) }}" class="text-brand-600 hover:text-brand-700 font-medium">{{ $item->reference }}</a>
                                <p class="text-xs text-gray-400">{{ $item->store?->name }}</p>
                            </td>
                            <td class="px-3 py-3 text-gray-700">{{ $item->brand }} / {{ $item->category }}</td>
                            <td class="px-3 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @foreach($item->rolesFor($me) as $role)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-brand-50 text-brand-700 border border-brand-100 whitespace-nowrap">
                                            {{ $role }}
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border whitespace-nowrap {{ $item->statusColor() }}">
                                    {{ $item->statusLabel() }}
                                </span>
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap">
                                @if($days === null)
                                    <span class="text-gray-400">—</span>
                                @else
                                    <span class="{{ $days < 0 ? 'text-red-600 font-medium' : ($days <= 3 ? 'text-amber-600 font-medium' : 'text-gray-600') }}">
                                        {{ $item->online_launch_date->format('d M Y') }}
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
