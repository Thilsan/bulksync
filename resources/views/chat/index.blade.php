@extends('layouts.app')
@section('title', 'Chat')
@section('page-title', 'Chat')

@section('content')
<div class="space-y-5">

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">People</h2>
            <p class="mt-0.5 text-sm text-gray-500">
                Direct messages that are never saved — a quiet conversation disappears on its own.
            </p>
        </div>
    </div>

    {{-- Said once, here, so the chat window itself does not need a banner. --}}
    <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-sm text-amber-800">
            <span class="font-semibold">Nothing here is stored.</span>
            Messages are held in memory only, for {{ (int) (\App\Support\EphemeralChat::IDLE_TTL / 60) }} minutes of quiet
            at most, and the last {{ \App\Support\EphemeralChat::MAX_MESSAGES }} lines of a conversation.
            There is no history to come back to, and someone offline will not receive what you send.
        </p>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        @forelse($people as $row)
            @php($peer = $row['user'])
            <a href="{{ route('chat.show', $peer) }}"
               class="flex items-center gap-4 border-b border-gray-100 px-4 py-3 transition-colors last:border-b-0 hover:bg-gray-50">

                {{-- Initials stand in for avatars; there are no uploads for these accounts. --}}
                <div class="relative shrink-0">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700">
                        {{ \Illuminate\Support\Str::of($peer->name)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}
                    </div>
                    <span class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full ring-2 ring-white {{ $row['online'] ? 'bg-emerald-500' : 'bg-gray-300' }}"></span>
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <p class="truncate text-sm font-semibold text-gray-900">{{ $peer->name }}</p>
                        @if($row['unread'] > 0)
                            <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-600 px-1.5 text-[11px] font-semibold text-white">
                                {{ $row['unread'] > 9 ? '9+' : $row['unread'] }}
                            </span>
                        @endif
                    </div>
                    <p class="truncate text-xs text-gray-500">
                        {{ $peer->pcrRoleLabel() ?? $peer->email }}
                    </p>
                </div>

                <span class="shrink-0 text-xs font-medium {{ $row['online'] ? 'text-emerald-600' : 'text-gray-400' }}">
                    {{ $row['online'] ? 'Online' : 'Offline' }}
                </span>
            </a>
        @empty
            <div class="px-4 py-10 text-center">
                <p class="text-sm text-gray-500">No one else is set up on this account yet.</p>
            </div>
        @endforelse
    </div>
</div>
@endsection
