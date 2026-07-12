@extends('layouts.app')

@section('title', 'Activity Log')
@section('page-title', 'Activity Log')

@section('content')
<div class="max-w-6xl mx-auto space-y-6">

    {{-- Filters --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <form method="GET" action="{{ route('super-admin.activity') }}" class="flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">User</label>
                <select name="user_id"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    <option value="">All users</option>
                    @foreach($users as $u)
                    <option value="{{ $u->id }}" @selected(request('user_id') == $u->id)>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Action</label>
                <select name="action"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    <option value="">All actions</option>
                    <option value="login" @selected(request('action') === 'login')>Login</option>
                    <option value="login_failed" @selected(request('action') === 'login_failed')>Failed Login</option>
                    <option value="logout" @selected(request('action') === 'logout')>Logout</option>
                    <option value="page_view" @selected(request('action') === 'page_view')>Page View</option>
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
                @if(request()->hasAny(['user_id', 'action', 'date']))
                <a href="{{ route('super-admin.activity') }}"
                   class="text-sm text-gray-500 hover:text-gray-700 px-3 py-2 transition-colors">
                    Clear
                </a>
                @endif
            </div>
        </form>
    </div>

    {{-- Log table --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="text-left px-6 py-3 font-medium text-gray-600">Time</th>
                    <th class="text-left px-6 py-3 font-medium text-gray-600">User</th>
                    <th class="text-left px-6 py-3 font-medium text-gray-600">Action</th>
                    <th class="text-left px-6 py-3 font-medium text-gray-600">Details</th>
                    <th class="text-left px-6 py-3 font-medium text-gray-600">Device</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($logs as $log)
                <tr class="hover:bg-gray-50/50">
                    <td class="px-6 py-3 text-gray-500 whitespace-nowrap">
                        {{ $log->created_at->format('d M Y, H:i:s') }}
                    </td>
                    <td class="px-6 py-3 text-gray-800 font-medium whitespace-nowrap">
                        {{ $log->user?->name ?? '—' }}
                    </td>
                    <td class="px-6 py-3 whitespace-nowrap">
                        @if($log->action === 'login')
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700 border border-green-200">Login</span>
                        @elseif($log->action === 'login_failed')
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700 border border-red-200">Failed Login</span>
                        @elseif($log->action === 'logout')
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 border border-gray-200">Logout</span>
                        @else
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700 border border-blue-200">Page View</span>
                        @endif
                    </td>
                    <td class="px-6 py-3 text-gray-600">{{ $log->description }}</td>
                    <td class="px-6 py-3 text-gray-500 text-xs whitespace-nowrap">{{ $log->device ?? '—' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-6 py-10 text-center text-gray-400">No activity recorded yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>

        @if($logs->hasPages())
        <div class="px-6 py-4 border-t border-gray-100">{{ $logs->links() }}</div>
        @endif
    </div>

</div>
@endsection
