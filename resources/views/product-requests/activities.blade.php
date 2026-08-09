@extends('layouts.app')

@section('title', $request->reference . ' – Activity')
@section('page-title', 'Product Creation Request')

@section('content')
<div class="max-w-5xl space-y-5">

    <div class="flex items-center justify-between">
        <div>
            <nav class="text-xs text-gray-400 mb-1">
                <a href="{{ route('product-requests.index') }}" class="hover:text-gray-600">Product Creation Request</a>
                <span class="mx-1.5">&gt;</span>
                <a href="{{ route('product-requests.show', $request) }}" class="hover:text-gray-600">{{ $request->reference }}</a>
                <span class="mx-1.5">&gt;</span>
                <span class="text-gray-600">Activity Log</span>
            </nav>
            <p class="text-sm text-gray-500">Full audit trail for {{ $request->displayName() }} ({{ $request->reference }}).</p>
        </div>
        <a href="{{ route('product-requests.show', $request) }}"
           class="inline-flex items-center gap-2 border border-gray-300 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Back to Request
        </a>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 border-b border-gray-100 bg-gray-50/60">
                        <th class="px-5 py-2.5 font-medium whitespace-nowrap">Date &amp; Time</th>
                        <th class="px-3 py-2.5 font-medium">User</th>
                        <th class="px-3 py-2.5 font-medium">Action Performed</th>
                        <th class="px-3 py-2.5 font-medium">Status</th>
                        <th class="px-5 py-2.5 font-medium">Comments / Remarks</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($activities as $entry)
                    <tr class="hover:bg-gray-50/70 transition-colors align-top">
                        <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ $entry->created_at->format('d M Y, h:i A') }}</td>
                        <td class="px-3 py-3">
                            <p class="text-gray-800">{{ $entry->actorName() }}</p>
                            @if($entry->actorRole())
                                <p class="text-xs text-gray-400">{{ $entry->actorRole() }}</p>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-gray-700">{{ $entry->description }}</td>
                        <td class="px-3 py-3">
                            @if($entry->to_status)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border whitespace-nowrap {{ $entry->statusColor() }}">
                                    {{ $entry->statusLabel() }}
                                </span>
                            @else
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-gray-600 whitespace-pre-line">{{ $entry->remarks ?: '—' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="px-5 py-16 text-center text-sm text-gray-400">No activity recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($activities->hasPages())
        <div>{{ $activities->links() }}</div>
    @endif

</div>
@endsection
