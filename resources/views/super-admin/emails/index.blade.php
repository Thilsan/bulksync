@extends('layouts.app')

@section('title', 'Email Log')
@section('page-title', 'Email Log')

@section('content')
<div class="w-full space-y-6">

    {{-- Filters --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <form method="GET" action="{{ route('super-admin.emails.index') }}" class="flex flex-wrap items-end gap-4">
            <div class="flex-1 min-w-[16rem]">
                <label class="block text-xs font-medium text-gray-500 mb-1">Recipient, subject or request reference</label>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="nada.rezeg@… or PCR-2026-00335"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
                <select name="status"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    <option value="">All</option>
                    <option value="sent" @selected(request('status') === 'sent')>Sent</option>
                    <option value="failed" @selected(request('status') === 'failed')>Failed</option>
                    <option value="sending" @selected(request('status') === 'sending')>Sending</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Date</label>
                <input type="date" name="date" value="{{ request('date') }}"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            </div>
            <div class="flex gap-2">
                <button type="submit"
                    class="bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
                    Filter
                </button>
                @if(request()->hasAny(['q', 'status', 'date']))
                <a href="{{ route('super-admin.emails.index') }}"
                   class="text-sm text-gray-500 hover:text-gray-700 px-3 py-2 transition-colors">
                    Clear
                </a>
                @endif
            </div>
        </form>
    </div>

    {{-- Log table --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="text-left px-6 py-3 font-medium text-gray-600">Time</th>
                    <th class="text-left px-6 py-3 font-medium text-gray-600">To</th>
                    <th class="text-left px-6 py-3 font-medium text-gray-600">Subject</th>
                    <th class="text-left px-6 py-3 font-medium text-gray-600">Type</th>
                    <th class="text-left px-6 py-3 font-medium text-gray-600">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($logs as $log)
                <tr class="hover:bg-gray-50/50">
                    <td class="px-6 py-3 text-gray-500 whitespace-nowrap">
                        {{ $log->created_at->format('d M Y, H:i') }}
                    </td>
                    <td class="px-6 py-3 whitespace-nowrap">
                        <div class="text-gray-800 font-medium">{{ $log->user?->name ?? '—' }}</div>
                        <div class="text-xs text-gray-400">{{ $log->to_address }}</div>
                    </td>
                    <td class="px-6 py-3 text-gray-700">
                        <a href="{{ route('super-admin.emails.show', $log) }}" class="hover:text-brand-700 hover:underline">
                            {{ $log->subject ?: '(no subject)' }}
                        </a>
                    </td>
                    <td class="px-6 py-3 text-gray-500 whitespace-nowrap">{{ $log->kind() }}</td>
                    <td class="px-6 py-3 whitespace-nowrap">
                        @include('super-admin.emails.partials.status', ['status' => $log->status])
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-6 py-10 text-center text-gray-400">No emails recorded yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>

        @if($logs->hasPages())
        <div class="px-6 py-4 border-t border-gray-100">{{ $logs->links() }}</div>
        @endif
    </div>

    <p class="text-xs text-gray-400">
        Email bodies are kept for {{ \App\Models\EmailLog::KEEP_BODY_DAYS }} days, the rest of the record for {{ \App\Models\EmailLog::KEEP_DAYS }}.
    </p>

</div>
@endsection
