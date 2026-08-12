@extends('layouts.app')
@section('title', 'AI Content Sessions')
@section('page-title', 'AI Content Sessions')

@section('content')
@php
    $statusStyles = [
        'pending'     => ['dot' => 'bg-gray-400',    'pill' => 'bg-gray-50 text-gray-600 ring-gray-200'],
        'processing'  => ['dot' => 'bg-brand-500',   'pill' => 'bg-brand-50 text-brand-700 ring-brand-200'],
        'translating' => ['dot' => 'bg-brand-500',   'pill' => 'bg-brand-50 text-brand-700 ring-brand-200'],
        'ready'       => ['dot' => 'bg-amber-500',   'pill' => 'bg-amber-50 text-amber-700 ring-amber-200'],
        'pushing'     => ['dot' => 'bg-brand-500',   'pill' => 'bg-brand-50 text-brand-700 ring-brand-200'],
        'done'        => ['dot' => 'bg-emerald-500', 'pill' => 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
        'failed'      => ['dot' => 'bg-red-500',     'pill' => 'bg-red-50 text-red-700 ring-red-200'],
    ];
@endphp

<div class="space-y-5">

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">All sessions</h2>
            <p class="mt-0.5 text-sm text-gray-500">
                {{ number_format($sessions->total()) }} session{{ $sessions->total() === 1 ? '' : 's' }} on your account.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('ai-content.dashboard') }}"
               class="rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-600 transition-colors hover:border-gray-300 hover:text-gray-800">
                Overview
            </a>
            <a href="{{ route('ai-content.index') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                New Content
            </a>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        @if ($sessions->isEmpty())
            <div class="px-6 py-16 text-center">
                <p class="font-semibold text-gray-800">No sessions yet</p>
                <p class="mt-1 text-sm text-gray-500">Generated content will be listed here.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[46rem] text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider text-gray-400">
                            <th class="px-5 py-2.5 font-medium">Source</th>
                            <th class="px-5 py-2.5 font-medium">Store</th>
                            <th class="px-5 py-2.5 font-medium">Status</th>
                            <th class="px-5 py-2.5 text-right font-medium">Products</th>
                            <th class="px-5 py-2.5 font-medium">Progress</th>
                            <th class="px-5 py-2.5 font-medium">Created</th>
                            <th class="px-5 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($sessions as $session)
                            @php
                                $s  = $statusStyles[$session->status] ?? $statusStyles['pending'];
                                $pc = $session->progressPercent();
                            @endphp
                            <tr class="transition-colors hover:bg-gray-50">
                                <td class="px-5 py-3 font-medium text-gray-800">
                                    {{ $session->input_type === 'csv_upload' ? 'CSV upload' : 'SKU list' }}
                                </td>
                                <td class="px-5 py-3 text-gray-500">{{ $session->store?->name ?? '—' }}</td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $s['pill'] }}">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $s['dot'] }}"></span>
                                        {{ $session->statusLabel() }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums text-gray-800">
                                    <span class="font-medium">{{ number_format($session->processed_items) }}</span>
                                    <span class="text-gray-400">/ {{ number_format($session->total_items) }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-24 overflow-hidden rounded-full bg-gray-100">
                                            <div class="h-full rounded-full {{ $session->status === 'failed' ? 'bg-red-400' : 'bg-brand-500' }}"
                                                 style="width: {{ $pc }}%"></div>
                                        </div>
                                        <span class="text-xs tabular-nums text-gray-500">{{ $pc }}%</span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-gray-500">{{ $session->created_at->format('d M Y H:i') }}</td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-3">
                                        <a href="{{ route('ai-content.show', $session) }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">View &rarr;</a>
                                        <form method="POST" action="{{ route('ai-content.destroy', $session) }}"
                                              onsubmit="return confirm('Delete this session?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-xs font-medium text-red-400 transition-colors hover:text-red-600">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-gray-100 px-5 py-4">
                {{ $sessions->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
