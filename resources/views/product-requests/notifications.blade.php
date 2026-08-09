@extends('layouts.app')

@section('title', 'Notifications')
@section('page-title', 'Product Creation Request')

@section('content')
<div class="max-w-3xl space-y-5">

    <div class="flex items-center justify-between">
        <div>
            <nav class="text-xs text-gray-400 mb-1">
                <a href="{{ route('product-requests.index') }}" class="hover:text-gray-600">Product Creation Request</a>
                <span class="mx-1.5">&gt;</span>
                <span class="text-gray-600">Notifications</span>
            </nav>
            <p class="text-sm text-gray-500">
                {{ auth()->user()->unreadNotifications()->count() }} unread of {{ number_format($notifications->total()) }} total.
            </p>
        </div>
        @if(auth()->user()->unreadNotifications()->exists())
        <form method="POST" action="{{ route('product-requests.notifications.read') }}">
            @csrf
            <button type="submit" class="border border-gray-300 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors">
                Mark all as read
            </button>
        </form>
        @endif
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden divide-y divide-gray-50">
        @forelse($notifications as $notification)
            @php $data = $notification->data; @endphp
            <div class="flex items-start gap-3 px-5 py-3.5 {{ $notification->read_at ? '' : 'bg-brand-50/40' }}">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 {{ $notification->read_at ? 'bg-gray-100 text-gray-400' : 'bg-brand-100 text-brand-700' }}">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1h6z"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm text-gray-800">
                        @if(!empty($data['request_id']))
                            <a href="{{ route('product-requests.show', $data['request_id']) }}" class="text-brand-600 hover:text-brand-700 font-medium">{{ $data['reference'] ?? 'Request' }}</a>
                        @else
                            <span class="font-medium">{{ $data['reference'] ?? 'Request' }}</span>
                        @endif
                        &middot; {{ $data['brand'] ?? '' }} is now
                        <span class="font-medium">{{ $data['status_label'] ?? $data['to_status'] ?? 'updated' }}</span>
                    </p>
                    @if(!empty($data['remarks']))
                        <p class="text-xs text-gray-500 mt-0.5">{{ $data['remarks'] }}</p>
                    @endif
                    <p class="text-xs text-gray-400 mt-0.5">
                        by {{ $data['actor'] ?? 'System' }} &middot; {{ $notification->created_at->diffForHumans() }}
                    </p>
                </div>
                @unless($notification->read_at)
                <form method="POST" action="{{ route('product-requests.notifications.read') }}" class="shrink-0">
                    @csrf
                    <input type="hidden" name="notification_id" value="{{ $notification->id }}">
                    <button type="submit" class="text-xs text-gray-400 hover:text-gray-700">Mark read</button>
                </form>
                @endunless
            </div>
        @empty
            <p class="px-5 py-16 text-sm text-gray-400 text-center">No notifications yet.</p>
        @endforelse
    </div>

    @if($notifications->hasPages())
        <div>{{ $notifications->links() }}</div>
    @endif

</div>
@endsection
