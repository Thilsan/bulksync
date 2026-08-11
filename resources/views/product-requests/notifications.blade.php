@extends('layouts.app')

@section('title', 'Notifications')
@section('page-title', 'Product Creation Request')

@section('content')
<div class="max-w-3xl space-y-5">

    <div class="flex items-start justify-between gap-4">
        <div>
            <nav class="text-xs text-gray-400 mb-1">
                <a href="{{ route('product-requests.index') }}" class="hover:text-gray-600">Product Creation Request</a>
                <span class="mx-1.5">&gt;</span>
                <span class="text-gray-600">Notifications</span>
            </nav>
            <p class="text-sm text-gray-500">
                {{ $scope === 'mine'
                    ? $mineUnread . ' unread about your own work'
                    : $allUnread . ' unread in total' }}
                &middot; {{ number_format($notifications->total()) }} shown.
            </p>
        </div>
        @if($allUnread > 0)
        <form method="POST" action="{{ route('product-requests.notifications.read') }}">
            @csrf
            <button type="submit" class="border border-gray-300 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors">
                Mark all as read
            </button>
        </form>
        @endif
    </div>

    {{-- Mine first: a status change sent to a whole team is not the same thing as
         a task with your name on it, and mixing them made both easy to ignore. --}}
    <div class="inline-flex rounded-lg border border-gray-200 p-0.5 bg-gray-50">
        @foreach([
            'mine' => ['My work', $mineUnread],
            'all'  => ['Everything', $allUnread],
        ] as $key => [$label, $count])
            <a href="{{ route('product-requests.notifications', $key === 'mine' ? [] : ['scope' => 'all']) }}"
               class="px-3.5 py-1.5 text-xs font-medium rounded-md transition-colors
                      {{ $scope === $key ? 'bg-brand-600 text-white' : 'text-gray-600 hover:text-gray-900' }}">
                {{ $label }}
                @if($count > 0)
                    <span class="ml-1 {{ $scope === $key ? 'text-white/80' : 'text-red-500' }}">{{ $count }}</span>
                @endif
            </a>
        @endforeach
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden divide-y divide-gray-50">
        @forelse($notifications as $notification)
            @php
                $data = $notification->data;
                $kind = $data['kind'] ?? 'status';
                $mine = ($data['for_me'] ?? false) === true;

                // Each kind reads as what it is. Before this everything said
                // "is now <status>", including comments and assignments.
                [$tone, $icon] = match ($kind) {
                    'assigned'       => ['bg-amber-100 text-amber-700',   'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
                    'handed_off'     => ['bg-orange-100 text-orange-700', 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'],
                    'comment'        => ['bg-sky-100 text-sky-700',       'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'],
                    'on_hold'        => ['bg-red-100 text-red-700',       'M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z'],
                    'resumed'        => ['bg-green-100 text-green-700',   'M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                    'balance_mapped' => ['bg-teal-100 text-teal-700',     'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                    'reminder'       => ['bg-violet-100 text-violet-700', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
                    default          => ['bg-brand-100 text-brand-700',   'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
                };

                $line = match ($kind) {
                    'assigned'       => !empty($data['assignee'])
                                            ? $data['assignee'] . ' is now the ' . ($data['role'] ?? 'owner')
                                            : 'assigned to you as ' . ($data['role'] ?? 'owner'),
                    'handed_off'     => ($data['role'] ?? 'A role') . ' ' . ($data['status_label'] ?? 'handed over'),
                    'comment'        => $data['status_label'] ?? 'commented',
                    'on_hold'        => 'is on hold',
                    'resumed'        => 'is back in progress',
                    'balance_mapped' => $data['status_label'] ?? 'SKU mapping updated',
                    'reminder'       => 'needs your attention',
                    default          => 'is now ' . ($data['status_label'] ?? $data['to_status'] ?? 'updated'),
                };
            @endphp
            <div class="flex items-start gap-3 px-5 py-3.5 {{ $notification->read_at ? '' : 'bg-brand-50/40' }}">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 {{ $notification->read_at ? 'bg-gray-100 text-gray-400' : $tone }}">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm text-gray-800">
                        @if(!empty($data['request_id']))
                            <a href="{{ route('product-requests.show', $data['request_id']) }}" class="text-brand-600 hover:text-brand-700 font-medium">{{ $data['reference'] ?? 'Request' }}</a>
                        @else
                            <span class="font-medium">{{ $data['reference'] ?? 'Request' }}</span>
                        @endif
                        @if(!empty($data['brand']))
                            &middot; <span class="text-gray-600">{{ $data['brand'] }}</span>
                        @endif
                        {{ $line }}
                        @if($scope === 'all' && !$mine)
                            <span class="ml-1 text-[11px] text-gray-400 border border-gray-200 rounded px-1 py-0.5">FYI</span>
                        @endif
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
            <p class="px-5 py-16 text-sm text-gray-400 text-center">
                {{ $scope === 'mine'
                    ? 'Nothing waiting on you. Team updates are under Everything.'
                    : 'No notifications yet.' }}
            </p>
        @endforelse
    </div>

    @if($notifications->hasPages())
        <div>{{ $notifications->links() }}</div>
    @endif

</div>
@endsection
